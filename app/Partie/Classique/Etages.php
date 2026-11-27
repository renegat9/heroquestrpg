<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Quete;
use App\Partie\CaseLibre;
use App\Partie\FabriqueGrille;
use App\Partie\Salles;
use App\Partie\TamponAnnonces;

/**
 * LA QUÊTE À ÉTAGES (mode classique, lot E2 — *Prophecy of Telor* q. 3, « The Great Stairwell ») : « There are twelve levels.
 * In this quest, only use the center room. The heroes begin each level on the stairway tile. […] They must clear the level
 * of all monsters before they can move back onto the stairwell and up to the next level. Once all heroes are back on the
 * stairwell, reset the room for the next level. »
 *
 * `quete.etages {escalier: [{x, y}…], niveaux: [{titre, monstres?, mobilier?, pieges?, notes?}…]}` — les niveaux SUIVANTS ;
 * le premier est la quête elle-même. Quand un héros REMONTE sur l'escalier ({@see apresMarche()}), que plus aucune figure
 * ne se tient sur le plateau et que tous les héros debout sont sur l'escalier, l'étage suivant se pose ({@see monter()}) :
 *  - le document de la quête prend ses couches (`ValidationCampagne::etageEnQuete()`) — ses notes remplacent les
 *    précédentes, et elles rejouent à neuf (`notes_jouees` vidé) ;
 *  - les meubles et pièges de l'étage d'avant quittent la grille, ceux de l'étage arrivent (`CarteConstruite::meubleMoteur()`,
 *    `piegeMoteur()`) ; ses monstres sont posés, vus d'emblée ; la salle se fouille à nouveau ;
 *  - l'index d'un monstre posé se compte à partir du premier de l'étage (`NotesDeQuete::figuresPosees()`) ;
 *  - annonce `etage {numero, total, titre}` : « Étage 4 sur 12 : The Storeroom ».
 * État durable : `quetes.drapeaux._etage {numero, premier_id}`. Écart écrit : « Heroes must move off the stairway tile before
 * they can perform any actions » n'est pas joué.
 */
final class Etages
{
    public const DRAPEAU = '_etage';

    public function __construct(
        private readonly CatalogueConstructeur $catalogue,
        private readonly CarteConstruite $cartes,
    ) {}

    /** @return array{escalier: list<array{x: int, y: int}>, niveaux: list<array<string, mixed>>}|null */
    public static function regle(?Quete $quete): ?array
    {
        if ($quete === null || ! $quete->estConstruite()) {
            return null;
        }
        $etages = $quete->documentConstruit()['etages'] ?? null;

        return is_array($etages) ? $etages : null;
    }

    /** Le numéro de l'étage courant (1 = la quête elle-même). */
    public static function numero(Quete $quete): int
    {
        return (int) data_get($quete->drapeaux, self::DRAPEAU.'.numero', 1);
    }

    /** Le plus petit identifiant des figures posées de l'étage courant (0 au premier étage). */
    public static function premierIdentifiant(Quete $quete): int
    {
        return (int) data_get($quete->drapeaux, self::DRAPEAU.'.premier_id', 0);
    }

    private static function surEscalier(array $regle, ?int $x, ?int $y): bool
    {
        return $x !== null && collect($regle['escalier'])->contains(fn ($c) => (int) $c['x'] === $x && (int) $c['y'] === $y);
    }

    /**
     * Après une marche : le héros vient de REMONTER sur l'escalier (il n'y était pas) — l'étage suivant se pose si l'étage est
     * vidé et que tous les héros debout y sont. Rend l'annonce, ou `null`.
     *
     * @param  array{x: int, y: int}|null  $avant
     */
    public function apresMarche(Groupe $groupe, Quete $quete, ?array $avant, ?array $arrivee): ?array
    {
        $regle = self::regle($quete);
        if ($regle === null || $arrivee === null || ! self::surEscalier($regle, $arrivee['x'], $arrivee['y'])
            || ($avant !== null && self::surEscalier($regle, $avant['x'], $avant['y']))
            || ! isset($regle['niveaux'][self::numero($quete) - 1])) {
            return null;
        }
        if ($quete->instancesMonstres()->where('etat', 'actif')->whereNotNull('position_x')->exists()) {
            return null;
        }
        $debout = $quete->etatsPersonnages()->where('tombe', false)->whereNotNull('position_x')->get();
        if ($debout->isEmpty() || $debout->contains(fn ($e) => ! self::surEscalier($regle, (int) $e->position_x, (int) $e->position_y))) {
            return null;
        }

        return $this->monter($groupe, $quete, $regle);
    }

    /** @return array<string, mixed> l'annonce `etage` */
    private function monter(Groupe $groupe, Quete $quete, array $regle): array
    {
        $numero = self::numero($quete);
        $niveau = (array) $regle['niveaux'][$numero - 1];
        $avant = $quete->documentConstruit();
        $carte = $quete->carte;
        $grille = (array) $carte->grille;
        $salles = (array) ($grille['salles'] ?? []);

        // Les meubles et pièges de l'étage d'avant quittent la grille (reconnus à leur case), ceux de l'étage arrivent.
        $deja = fn (array $liste) => collect($liste)->map(fn ($e) => ((int) $e['x']).','.((int) $e['y']))->all();
        $meublesAvant = $deja((array) ($avant['mobilier'] ?? []));
        $piegesAvant = $deja((array) ($avant['pieges'] ?? []));
        $grille['mobilier'] = [
            ...array_values(array_filter((array) ($grille['mobilier'] ?? []), fn ($m) => ! in_array(((int) $m['x']).','.((int) $m['y']), $meublesAvant, true))),
            ...array_map(fn ($m) => $this->cartes->meubleMoteur($m, Salles::indexDe($salles, (int) $m['x'], (int) $m['y'])), (array) ($niveau['mobilier'] ?? [])),
        ];
        $grille['pieges'] = [
            ...array_values(array_filter((array) ($grille['pieges'] ?? []), fn ($p) => ! in_array(((int) $p['x']).','.((int) $p['y']), $piegesAvant, true))),
            ...array_map(fn ($p) => $this->cartes->piegeMoteur($p), (array) ($niveau['pieges'] ?? [])),
        ];
        $carte->update(['grille' => $grille]);

        // Le document de l'étage : ses couches, et ses notes qui rejouent à neuf.
        $document = [...ValidationCampagne::etageEnQuete($avant, $niveau), 'etages' => $avant['etages']];

        // Les monstres de l'étage, vus d'emblée (la salle est sous les yeux des héros), sur leur case ou au plus près.
        $index = $this->catalogue->index('monstres');
        $premier = null;
        $quete->update(['document_construit' => $document]);
        foreach ((array) ($niveau['monstres'] ?? []) as $pose) {
            $monstre = $index[(string) ($pose['slug'] ?? '')] ?? null;
            $place = $monstre === null ? null : CaseLibre::iciOuProche(FabriqueGrille::pour($quete->fresh()), (int) $pose['x'], (int) $pose['y']);
            if ($place === null) {
                continue;
            }
            $pvBody = isset($pose['pv_body']) ? (int) $pose['pv_body'] : (int) $monstre->pv_body;
            $instance = $quete->instancesMonstres()->create([
                'monstre_id' => $monstre->id, 'pv_body' => $pvBody, 'pv_body_max' => $pvBody,
                'pv_mind' => isset($pose['pv_mind']) ? (int) $pose['pv_mind'] : (int) $monstre->pv_mind,
                'position_x' => $place['x'], 'position_y' => $place['y'],
                'etat' => 'actif', 'elite' => false, 'revele' => true,
                'habillage' => InstanceMonstre::habillageDeQuete((array) $pose, $this->catalogue),
            ]);
            $premier ??= (int) $instance->id;
        }

        // La salle se fouille à nouveau : les fouilles de trésor faites dans la salle de l'escalier s'effacent.
        $salleEscalier = Salles::indexDe($salles, (int) $regle['escalier'][0]['x'], (int) $regle['escalier'][0]['y']);
        $drapeaux = (array) $quete->relire('drapeaux')->drapeaux;
        $drapeaux[self::DRAPEAU] = ['numero' => $numero + 1, 'premier_id' => $premier ?? PHP_INT_MAX];
        $quete->update([
            'notes_jouees' => [],
            'drapeaux' => $drapeaux,
            'tresors_fouilles' => $salleEscalier === null ? $quete->tresors_fouilles
                : array_values(array_filter((array) ($quete->tresors_fouilles ?? []), fn ($f) => ! str_starts_with((string) $f, "{$salleEscalier}:"))),
        ]);

        $payload = ['type' => 'etage', 'numero' => $numero + 1, 'total' => count($regle['niveaux']) + 1, 'titre' => (string) ($niveau['titre'] ?? '')];
        app(TamponAnnonces::class)->annoncer($groupe, $payload);

        return $payload;
    }
}
