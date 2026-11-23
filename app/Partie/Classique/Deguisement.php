<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Equipement;
use App\Partie\FabriqueGrille;
use App\Partie\Salles;
use App\Partie\TamponAnnonces;

/**
 * LE DÉGUISEMENT ET LA PLACE PUBLIQUE (mode classique, lot K6 — *Rise of the Dread Moon*, F6646 p. 4-5 et 9).
 *
 * « A hero may go disguised by taking a disguise token (no action required) at the start of quests that allow it. […]
 * Disguised heroes must always adhere to the following criteria, or else lose their disguise: • Attack unarmed or with a
 * dagger, shortsword, handaxe, or staff. • Refrain from casting spells. • Wear no armor other than bracers and helmets.
 * […] At any time on their turn, they may surrender their disguise token and disguise (no action required). Note: For the
 * purposes of disguises, artifacts are treated as the item they represent. »
 *
 * « Monsters in the plaza are inactive unless they are attacked or an undisguised hero (or active monster) enters their
 * line of sight. »
 *
 * `regles.deguisement: true` ; `salles_speciales[].place: true` pour la place. Décisions écrites :
 * - au départ, chaque héros prend le jeton S'IL ne porte aucune armure interdite (René, 2026-11-19 : équiper une armure
 *   interdite fait perdre le déguisement) ; il le rend quand il veut (`retirer_deguisement`, geste libre) ;
 * - les armes se reconnaissent sous leur nom d'armurerie (`Equipement::estArmeDeType()`, `est_une`) ; les armures
 *   permises en plus des casques sont nommées ci-dessous ;
 * - un monstre de la place s'éveille s'il est attaqué, ou, au tour du MJ, si un héros debout NON déguisé l'a en ligne de
 *   vue (`MoteurDormants`) ; « or active monster » n'est pas porté (aucun monstre n'y en éveille un autre aujourd'hui).
 */
final class Deguisement
{
    /** « dagger, shortsword, handaxe, or staff » — noms d'armurerie du catalogue. */
    public const ARMES_PERMISES = ['Dague', 'Épée courte', 'Hachette', 'Bâton'];

    /**
     * Les pièces de catégorie `armure` permises, en plus de tout ce qui se porte au casque : les brassards (« bracers »), et
     * les capes — « a Wizard's Cloak is not armor, and may be used when a hero is disguised » ; la Cape des Ombres est une
     * cape comme elle (décision écrite).
     */
    public const ARMURES_PERMISES = ['Brassards', 'Brassards du Sauvage', 'Cape du Magicien', 'Cape des Ombres'];

    public const DECLENCHEUR_PLACE = 'vue_non_deguise';

    public function __construct(private readonly Equipement $equipement) {}

    public static function permis(?Quete $quete): bool
    {
        return $quete !== null && $quete->estConstruite() && data_get($quete->documentConstruit(), 'regles.deguisement') === true;
    }

    /** Porte-t-il une armure interdite (équipée) ? */
    public function armureInterdite(Personnage $personnage): bool
    {
        return $personnage->inventaire()->whereIn('emplacement', Equipement::SLOTS)->with('objet')->get()
            ->contains(fn ($ligne) => $ligne->objet !== null && $ligne->objet->categorie === 'armure'
                && $ligne->emplacement !== 'casque' && ! in_array($ligne->objet->nom, self::ARMURES_PERMISES, true));
    }

    /** Au départ d'une quête qui le permet : chaque héros sans armure interdite prend le jeton. */
    public function poserAuDepart(Quete $quete): void
    {
        if (! self::permis($quete)) {
            return;
        }
        foreach ($quete->etatsPersonnages()->with('personnage')->get() as $etat) {
            $etat->update(['deguise' => $etat->personnage !== null && ! $this->armureInterdite($etat->personnage)]);
        }
    }

    /** Le héros attaque avec `$arme` (null = mains nues) : une arme interdite lui fait perdre le déguisement. */
    public function surAttaque(Groupe $groupe, ?EtatPersonnageQuete $etat, Personnage $personnage, ?Objet $arme): void
    {
        if ($etat?->deguise && $arme !== null
            && ! collect(self::ARMES_PERMISES)->contains(fn (string $type) => $this->equipement->estArmeDeType($arme, $type))) {
            $this->perdre($groupe, $etat, $personnage, 'arme');
        }
    }

    /** Le héros lance un sort : « Refrain from casting spells ». */
    public function surSort(Groupe $groupe, ?EtatPersonnageQuete $etat, Personnage $personnage): void
    {
        if ($etat?->deguise) {
            $this->perdre($groupe, $etat, $personnage, 'sort');
        }
    }

    /** Le héros vient de s'équiper : une armure interdite portée lui fait perdre le déguisement. */
    public function surEquipement(Groupe $groupe, ?EtatPersonnageQuete $etat, Personnage $personnage): void
    {
        if ($etat?->deguise && $this->armureInterdite($personnage)) {
            $this->perdre($groupe, $etat, $personnage, 'armure');
        }
    }

    /** Le héros rend son jeton, de lui-même. @return array<string, mixed> */
    public function retirer(EtatPersonnageQuete $etat, Personnage $personnage): array
    {
        $etat->update(['deguise' => false]);

        return ['type' => 'deguisement_perdu', 'personnage_id' => (int) $personnage->id, 'nom' => $personnage->nom, 'raison' => 'rendu'];
    }

    private function perdre(Groupe $groupe, EtatPersonnageQuete $etat, Personnage $personnage, string $raison): void
    {
        $etat->update(['deguise' => false]);
        app(TamponAnnonces::class)->annoncer($groupe, [
            'type' => 'deguisement_perdu', 'personnage_id' => (int) $personnage->id, 'nom' => $personnage->nom, 'raison' => $raison,
        ]);
    }

    // ─── La place publique ────────────────────────────────────────────────────────────────────────────────────────

    /** Le déclencheur d'une figure posée sur une place : `vue_non_deguise`, ou `null`. Lu par `MoteurDormants`. */
    public function declencheurDePlace(InstanceMonstre $instance): ?string
    {
        $quete = $instance->quete;
        if ($quete === null || ! $quete->estConstruite() || $instance->position_x === null) {
            return null;
        }
        // Lot E2 (F6646 q. 2 B, C) : une figure qui « ignores disguised heroes » dort comme sur une place, où qu'elle soit.
        if ($instance->aCapacite('ignore_deguises')) {
            return self::DECLENCHEUR_PLACE;
        }
        $places = collect((array) ($quete->documentConstruit()['salles_speciales'] ?? []))->filter(fn ($s) => ($s['place'] ?? false) === true);
        if ($places->isEmpty()) {
            return null;
        }
        $salles = (array) ($quete->carte?->grille['salles'] ?? []);
        $ici = Salles::indexDe($salles, (int) $instance->position_x, (int) $instance->position_y);

        return $ici !== null && $places->contains(fn ($s) => $s['id'] === ($salles[$ici]['id'] ?? null)) ? self::DECLENCHEUR_PLACE : null;
    }

    /** Au tour du MJ : un héros debout, sur le plateau, NON déguisé, a-t-il cette figure en ligne de vue ? */
    /**
     * Lot E2 (F6646 q. 2 B, C ; q. 3 D) : « Remove an inactive [figure] from the map if a disguised hero is ever adjacent to
     * them (no action required) as that hero delivers a swift and silent strike. » Lu à chaque case de la marche d'un héros
     * déguisé (`ResolveurTour::signalerEntrees()`) ; seules les figures ENCORE ENDORMIES qui portent la capacité de profil
     * `retire_au_contact_deguise` partent. Chaque retrait s'annonce (`retrait_au_contact`).
     *
     * @return list<string> les noms retirés
     */
    public function retirerAuContact(Quete $quete, EtatPersonnageQuete $etat, Personnage $heros, int $x, int $y): array
    {
        if (! $quete->estConstruite() || ! $etat->deguise) {
            return [];
        }
        $dormants = app(\App\Partie\MoteurDormants::class);
        $retires = [];
        foreach ($quete->instancesMonstres()->where('etat', 'actif')->whereNotNull('position_x')->with('monstre')->get() as $figure) {
            if (! $figure->aCapacite('retire_au_contact_deguise') || ! $dormants->estDormant($figure) || ! $figure->touche($x, $y)) {
                continue;
            }
            $figure->update(['etat' => 'vaincu', 'position_x' => null, 'position_y' => null,
                'habillage' => [...(array) $figure->habillage, 'retire' => true]]);
            $retires[] = $figure->nomAffiche();
            app(TamponAnnonces::class)->annoncer($quete->groupe, ['type' => 'retrait_au_contact', 'nom' => $heros->nom, 'figure' => $figure->nomAffiche()],
                ['type' => 'personnage', 'id' => $heros->id, 'nom' => $heros->nom]);
        }

        return $retires;
    }

    public function vueParUnNonDeguise(Quete $quete, InstanceMonstre $instance): bool
    {
        $grille = FabriqueGrille::pour($quete);

        return $quete->etatsPersonnages()->where('tombe', false)->where('deguise', false)->whereNotNull('position_x')->get()
            ->contains(fn (EtatPersonnageQuete $e) => $grille->ligneDeVue((int) $e->position_x, (int) $e->position_y,
                (int) $instance->position_x, (int) $instance->position_y));
    }
}
