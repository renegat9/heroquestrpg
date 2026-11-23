<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Engine\Des\FaceDeCombat;
use App\Engine\Des\LanceurDes;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Grille;
use App\Partie\MoteurPortes;

/**
 * LES PORTES D'UN LIVRET (mode classique, lot E2 — *Rise of the Dread Moon*). Deux portes, chacune sa règle :
 *
 * LA PORTE QU'ON FRAPPE (`portes[].frapper`, q. 3) : « When a hero attempts to open a door marked X, tell the hero that the
 * door is locked, and they instead knock on it (no action required). When they do so, they roll one combat die. Each skull
 * rolled counts as a success […] After the combat die is rolled, the door is removed from the board. When the heroes
 * accumulate three successes, the next X door they knock on will be the correct door. If all X doors but one have been
 * knocked on, the last X door will automatically be the correct one. When the correct door is knocked on, the door opens
 * and the quest ends. » Le seuil et le drapeau de fin : `regles.portes_a_frapper {succes?: 3, drapeau?}`. Une porte frappée
 * sans être la bonne est murée (`muree`, comme l'effet `murer_porte`).
 *
 * LA PORTE ASSIÉGÉE (`portes[].assiegee {pv, defense, drapeau?}`, q. 8 F) : « Monsters are trying to break through this
 * door […] The door has 2 Body Points and rolls 3 Defend dice. Defeating all the monsters before they can destroy the door
 * will earn the heroes one reputation token. » Au tour du MJ, un monstre posté sur une case de la porte et qu'aucun héros
 * ne touche la frappe au lieu de jouer ({@see assaut()}) : ses crânes contre les boucliers noirs de la porte. À 0, elle
 * cède (ouverte, `brisee`) et pose le drapeau — la récompense « avant qu'ils ne la brisent » est une note de l'auteur
 * (`sauf_si_drapeau`).
 *
 * État durable : la grille (`portes[i].pv_assiegee`, `muree`) et `quetes.drapeaux._frappes` (le compte des succès).
 */
final class PortesSpeciales
{
    public const DRAPEAU_FRAPPES = '_frappes';

    public const SUCCES_PAR_DEFAUT = 3;

    public function __construct(
        private readonly LanceurDes $des,
        private readonly MoteurPortes $portes,
    ) {}

    /** Les succès déjà réunis aux portes qu'on frappe. */
    public function succes(Quete $quete): int
    {
        return (int) data_get($quete->relire('drapeaux')->drapeaux, self::DRAPEAU_FRAPPES, 0);
    }

    /**
     * Frapper la porte d'index `$index` (le menu l'a offerte : `MoteurPortes::portesAFrapperAdjacentes()`).
     *
     * @return array<string, mixed> le résultat `porte_frappee` (journalisé par le résolveur)
     */
    public function frapper(Groupe $groupe, Quete $quete, Personnage $heros, int $index): array
    {
        $carte = $quete->carte;
        $regle = (array) data_get($quete->documentConstruit(), 'regles.portes_a_frapper', []);
        $seuil = (int) ($regle['succes'] ?? self::SUCCES_PAR_DEFAUT);
        $succes = $this->succes($quete);
        $restantes = collect($this->portes->portes($carte))->filter(fn (array $p) => self::aFrapper($p))->count();
        $bonne = $succes >= $seuil || $restantes <= 1;

        if ($bonne) {
            $this->portes->ouvrir($groupe, $carte, $index, 'frappe', ['type' => 'personnage', 'id' => $heros->id, 'nom' => $heros->nom]);
            $this->portes->changer($carte->fresh(), $index, ['frapper' => false]);
            if (is_string($regle['drapeau'] ?? null)) {
                $quete->poserDrapeau((string) $regle['drapeau']);
            }
            $payload = ['type' => 'porte_frappee', 'nom' => $heros->nom, 'bonne' => true, 'succes' => $succes, 'seuil' => $seuil];
        } else {
            $face = $this->des->deCombat();
            if ($face->estCrane()) {
                $succes++;
                $drapeaux = (array) $quete->relire('drapeaux')->drapeaux;
                $drapeaux[self::DRAPEAU_FRAPPES] = $succes;
                $quete->update(['drapeaux' => $drapeaux]);
            }
            // « the door is removed from the board » : murée, comme l'effet `murer_porte`.
            $this->portes->changer($carte, $index, ['frapper' => false, 'muree' => true, 'etat' => MoteurPortes::ETAT_VERROUILLEE]);
            $payload = ['type' => 'porte_frappee', 'nom' => $heros->nom, 'bonne' => false, 'face' => $face->value,
                'reussi' => $face->estCrane(), 'succes' => $succes, 'seuil' => $seuil];
        }

        return $payload;
    }

    /** Une porte qu'on peut encore frapper. */
    public static function aFrapper(array $porte): bool
    {
        return ($porte['frapper'] ?? false) === true && empty($porte['muree']) && ($porte['etat'] ?? null) !== MoteurPortes::ETAT_OUVERTE;
    }

    /**
     * Au tour du MJ : ce monstre, posté sur une case d'une porte assiégée encore debout et qu'aucun héros ne touche, la
     * frappe. Rend l'action (`assaut_porte`) qui remplace son tour, ou `null` (il joue normalement).
     *
     * @param  iterable<EtatPersonnageQuete>  $cibles
     * @return array<string, mixed>|null
     */
    public function assaut(Groupe $groupe, Quete $quete, InstanceMonstre $instance, iterable $cibles): ?array
    {
        if (! $quete->estConstruite() || $quete->carte === null || $instance->position_x === null) {
            return null;
        }
        foreach ($cibles as $cible) {
            if ($cible->position_x !== null && $instance->touche((int) $cible->position_x, (int) $cible->position_y)) {
                return null;
            }
        }
        foreach ($this->portes->portes($quete->carte) as $index => $porte) {
            if (! is_array($porte['assiegee'] ?? null) || ($porte['etat'] ?? null) === MoteurPortes::ETAT_OUVERTE) {
                continue;
            }
            $poste = collect(Grille::casesPorte($porte))->contains(fn (array $c) => collect($instance->cases())
                ->contains(fn (array $m) => (int) $m['x'] === (int) $c['x'] && (int) $m['y'] === (int) $c['y']));
            if (! $poste) {
                continue;
            }

            return $this->frapperPorteAssiegee($groupe, $quete, $instance, (int) $index, $porte);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function frapperPorteAssiegee(Groupe $groupe, Quete $quete, InstanceMonstre $instance, int $index, array $porte): array
    {
        $attaque = array_map(fn (FaceDeCombat $f) => $f->value, $this->des->desCombat($instance->attaqueEffective()));
        $defense = array_map(fn (FaceDeCombat $f) => $f->value, $this->des->desCombat((int) ($porte['assiegee']['defense'] ?? 0)));
        $cranes = count(array_filter($attaque, fn (string $f) => $f === FaceDeCombat::Crane->value));
        $pares = count(array_filter($defense, fn (string $f) => $f === FaceDeCombat::BouclierNoir->value));
        $degats = max(0, $cranes - $pares);
        $pv = max(0, (int) ($porte['pv_assiegee'] ?? $porte['assiegee']['pv'] ?? 0) - $degats);
        $brisee = $pv === 0;

        $this->portes->changer($quete->carte, $index, ['pv_assiegee' => $pv]);
        if ($brisee) {
            $this->portes->ouvrir($groupe, $quete->carte->fresh(), $index, 'brisee', ['type' => 'monstre', 'id' => $instance->id, 'nom' => $instance->nomAffiche()]);
            $this->portes->changer($quete->carte->fresh(), $index, ['brisee' => true]);
            if (is_string($porte['assiegee']['drapeau'] ?? null)) {
                $quete->poserDrapeau((string) $porte['assiegee']['drapeau']);
            }
        }

        return [
            'type' => 'assaut_porte', 'monstre' => $instance->nomAffiche(), 'instance_id' => $instance->id,
            'faces_attaque' => $attaque, 'faces_defense' => $defense, 'degats' => $degats,
            'pv_restants' => $pv, 'pv_max' => (int) ($porte['assiegee']['pv'] ?? 0), 'brisee' => $brisee,
        ];
    }
}
