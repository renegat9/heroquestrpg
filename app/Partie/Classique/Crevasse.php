<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Engine\Des\FaceDeCombat;
use App\Engine\Des\LanceurDes;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\MoteurDegats;
use App\Partie\Salles;
use App\Partie\TamponAnnonces;

/**
 * LE REBORD DE CREVASSE (mode classique, lot E2 — *The Frozen Horror* q. 4 note E, q. 6 G, q. 8 F) : « When a hero steps
 * through a door and moves onto their first square in this room, they must roll 1 combat die. The hero is safe and can
 * continue moving if a skull or white shield is rolled. If a black shield is rolled, however, the hero begins slipping
 * into the crevasse, suffering 1 Body Point of damage. The hero must immediately roll another combat die. If another
 * black shield is rolled, the hero plummets into the crevasse, never to be seen again. Any other roll means the hero
 * returns to the square in which they entered the room, ending their turn. At the beginning of any turn in which a hero
 * is in this room, they must first roll to see if they slip into the crevasse. »
 *
 * `regles.crevasse: [id de salle]`. Deux moments : la PREMIÈRE case de la salle ({@see entrer()}, depuis
 * `ResolveurTour::signalerEntrees()`) et le DÉBUT DU TOUR d'un héros qui s'y trouve ({@see debutDeTour()}, le crochet du
 * menu, comme le fardeau). La case d'entrée de chaque héros est retenue en base (`quetes.drapeaux._crevasse`).
 *
 * « Never to be seen again » : la mort définitive (`MortHeroic::mourir()`), affaires comprises — sauf en mode STORY, où un
 * héros ne meurt pas : il tombe au bord, à terre (décision écrite, le livret de *The Frozen Horror* précède les modes).
 */
final class Crevasse
{
    public const DRAPEAU = '_crevasse';

    public const MARQUEUR = 'crevasse';

    public function __construct(
        private readonly LanceurDes $des,
        private readonly MoteurDegats $degats,
    ) {}

    /** L'id de la salle à crevasse qui contient (x, y), ou `null`. */
    public function salle(Quete $quete, int $x, int $y): ?string
    {
        $salles = (array) data_get($quete->documentConstruit(), 'regles.crevasse', []);
        if ($salles === [] || ! $quete->estConstruite()) {
            return null;
        }
        $index = Salles::indexDe((array) ($quete->carte?->grille['salles'] ?? []), $x, $y);
        $id = $index === null ? null : data_get($quete->carte?->grille, "salles.{$index}.id");

        return in_array($id, $salles, true) ? $id : null;
    }

    /**
     * La première case d'une salle à crevasse : le jet. Rend `{payload, arret}` — `arret` : la marche s'arrête (le héros a
     * glissé : ramené à `$entree`, ou perdu) ; `null` hors crevasse.
     *
     * @param  array{x: int, y: int}  $entree  la case d'où il est entré
     * @return array{payload: array<string, mixed>, arret: bool}|null
     */
    public function entrer(Groupe $groupe, Quete $quete, Personnage $heros, EtatPersonnageQuete $etat, array $case, array $entree): ?array
    {
        if ($this->salle($quete, (int) $case['x'], (int) $case['y']) === null) {
            return null;
        }
        $drapeaux = (array) $quete->relire('drapeaux')->drapeaux;
        $drapeaux[self::DRAPEAU][(string) $heros->id] = $entree;
        $quete->update(['drapeaux' => $drapeaux]);

        return $this->jet($groupe, $quete, $heros, $etat, $entree);
    }

    /** Le début du tour d'un héros DANS la salle : il roule d'abord (une fois par tour, marqueur `capacites_tour`). */
    public function debutDeTour(Groupe $groupe, Quete $quete, Personnage $heros, EtatPersonnageQuete $etat): ?array
    {
        if ($etat->tombe || $etat->position_x === null
            || $this->salle($quete, (int) $etat->position_x, (int) $etat->position_y) === null
            || in_array(self::MARQUEUR, (array) ($etat->capacites_tour ?? []), true)) {
            return null;
        }
        $etat->update(['capacites_tour' => [...(array) ($etat->capacites_tour ?? []), self::MARQUEUR]]);
        $entree = data_get($quete->relire('drapeaux')->drapeaux, self::DRAPEAU.'.'.$heros->id)
            ?? ['x' => (int) $etat->position_x, 'y' => (int) $etat->position_y];

        return $this->jet($groupe, $quete, $heros, $etat, (array) $entree)['payload'];
    }

    /** @return array{payload: array<string, mixed>, arret: bool} */
    private function jet(Groupe $groupe, Quete $quete, Personnage $heros, EtatPersonnageQuete $etat, array $entree): array
    {
        $premier = $this->des->deCombat();
        $payload = ['type' => 'crevasse', 'nom' => $heros->nom, 'faces' => [$premier->value], 'issue' => 'sauf'];
        $arret = false;

        if ($premier === FaceDeCombat::BouclierNoir) {
            $this->degats->infligerAHeros($heros, 1, MoteurDegats::SOURCE_PIEGE, ['piege' => 'Crevasse']);
            $second = $this->des->deCombat();
            $payload['faces'][] = $second->value;
            $arret = true;
            if ($second === FaceDeCombat::BouclierNoir) {
                $payload['issue'] = 'perdu';
                if ($groupe->mode_mort === Groupe::MORT_STORY) {
                    // Mode Story : on ne meurt pas — il tombe au bord, à terre (décision écrite).
                    $etat->update(['tombe' => true, 'a_joue' => true, 'position_x' => (int) $entree['x'], 'position_y' => (int) $entree['y']]);
                    $payload['issue'] = 'au_bord';
                } else {
                    $heros->update(['pv_body' => 0]);
                    app(MortHeroic::class)->mourir($groupe, $quete, $etat->fresh(), affairesPerdues: true);
                }
            } else {
                $payload['issue'] = 'ramene';
                $etat->update(['position_x' => (int) $entree['x'], 'position_y' => (int) $entree['y'], 'a_joue' => true]);
            }
        }

        app(TamponAnnonces::class)->annoncer($groupe, $payload, ['type' => 'personnage', 'id' => $heros->id, 'nom' => $heros->nom]);

        return ['payload' => $payload, 'arret' => $arret];
    }
}
