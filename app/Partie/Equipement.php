<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\ClasseHeros;
use App\Models\Competence;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Partie\Marche\CapaciteSac;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Équiper / déséquiper une pièce d'équipement (doc 01 §7).
 *
 * Choix d'implémentation : les effets CHIFFRÉS de combat de l'objet
 * (`des_attaque`, `des_defense`) sont appliqués aux COLONNES du personnage à
 * l'équipement et révoqués au déséquipement — même patron que les nœuds de
 * compétence (App\Http\Controllers\Api\CompetenceController). Ainsi le moteur
 * de combat, la fiche (/moi), le score de puissance et le budget de rencontre
 * lisent tous automatiquement l'équipement via les colonnes, sans calcul
 * « effectif » dupliqué partout.
 *
 * Les autres propriétés d'un objet (jetable, attaque_diagonale, portee…) sont
 * des comportements de ciblage/portée, hors périmètre de ce service : elles ne
 * modifient pas les dés et seront lues à la volée par le moteur si/quand
 * elles sont implémentées. Exception : l'ACCÈS est vérifié ici — chaque pièce
 * porte un `tag_equipement` (maîtrise requise), chaque classe en autorise un
 * ensemble de base, et les nœuds `acces_equipement` en ajoutent ; sinon 422
 * (doc 01 §6/§7).
 */
final class Equipement
{
    /** Emplacements « portés » (par opposition à sac / consommable). */
    public const SLOTS = ['arme_principale', 'arme_secondaire', 'armure'];

    /** Clés d'`effet` d'objet appliquées comme delta de colonne au personnage. */
    private const COLONNES = [
        'des_attaque' => 'des_attaque',
        'des_defense' => 'des_defense',
    ];

    /**
     * Clés d'`effet` d'amélioration de Forge (ForgeAmeliorationSeeder, préfixe
     * `bonus_` — même convention que CompetenceController::EFFETS_PASSIFS)
     * appliquées comme delta de colonne au personnage.
     */
    private const AMELIORATIONS_COLONNES = [
        'bonus_des_attaque' => 'des_attaque',
        'bonus_des_defense' => 'des_defense',
    ];

    /**
     * Équipe une ligne d'inventaire du SAC dans l'emplacement naturel de l'objet
     * (objet.emplacement). L'occupant actuel du slot repart au sac (auto-swap :
     * capacité de sac neutre, une pièce sort, une pièce entre).
     */
    public function equiper(Personnage $personnage, Inventaire $ligne): Inventaire
    {
        $objet = $this->objetDeLaLigne($personnage, $ligne);

        if ($ligne->emplacement !== 'sac') {
            throw ValidationException::withMessages(['inventaire_id' => 'Cet objet n\'est pas dans le sac.']);
        }

        $slot = $objet->emplacement;
        if (! in_array($slot, self::SLOTS, true)) {
            throw ValidationException::withMessages([
                'inventaire_id' => "« {$objet->nom} » n'est pas une pièce d'équipement.",
            ]);
        }

        $this->verifierMains($personnage, $objet);
        $this->verifierAccesEquipement($personnage, $objet);

        return DB::transaction(function () use ($personnage, $ligne, $objet, $slot) {
            // Auto-swap : l'occupant actuel du slot retourne au sac (effet révoqué).
            $occupant = $personnage->inventaire()->where('emplacement', $slot)->with('objet')->first();
            if ($occupant !== null) {
                // (le recalcul complet en fin de transaction reprend tout)
                $occupant->update(['emplacement' => 'sac']);
            }

            $ligne->update(['emplacement' => $slot]);
            $this->recalculerCombat($personnage->refresh());

            return $ligne->fresh();
        });
    }

    /**
     * Déséquipe une pièce portée : elle retourne au sac (si la capacité le
     * permet) et son effet de combat est révoqué.
     */
    public function desequiper(Personnage $personnage, Inventaire $ligne): Inventaire
    {
        $objet = $this->objetDeLaLigne($personnage, $ligne);

        if (! in_array($ligne->emplacement, self::SLOTS, true)) {
            throw ValidationException::withMessages(['inventaire_id' => 'Cet objet n\'est pas équipé.']);
        }

        if (CapaciteSac::occupation($personnage) + 1 > CapaciteSac::pour($personnage)) {
            throw ValidationException::withMessages([
                'inventaire_id' => 'Sac plein : fais de la place avant de déséquiper.',
            ]);
        }

        return DB::transaction(function () use ($personnage, $ligne, $objet) {
            $ligne->update(['emplacement' => 'sac']);
            // Recalcul APRÈS le retrait : sinon l'arme est encore comptée comme
            // portée et le héros garde ses dés.
            $this->recalculerCombat($personnage->refresh());

            return $ligne->fresh();
        });
    }

    /**
     * Incompatibilité main(s) (doc 01 §7) : une arme à deux mains et un bouclier
     * ne coexistent pas. Rejet explicite (pas d'auto-déséquipement croisé).
     *
     * INDÉPENDANT du tag de maîtrise : `deux_mains` dit « pas de bouclier avec »,
     * le tag dit « qui a le droit d'en porter ». Le Bâton des Sept Sceaux est à
     * deux mains ET `arme_legere`, donc jouable par le magicien.
     */
    private function verifierMains(Personnage $personnage, Objet $aEquiper): void
    {
        $portes = $personnage->inventaire()->whereIn('emplacement', self::SLOTS)->with('objet')->get();
        $estDeuxMains = fn (?Objet $o) => (bool) ($o?->effet['deux_mains'] ?? false);
        $estBouclier = fn (?Objet $o) => (bool) ($o?->effet['incompatible_deux_mains'] ?? false);

        if ($estDeuxMains($aEquiper) && $portes->contains(fn ($l) => $estBouclier($l->objet))) {
            throw ValidationException::withMessages([
                'inventaire_id' => "« {$aEquiper->nom} » se manie à deux mains — déséquipe d'abord ton bouclier.",
            ]);
        }

        if ($estBouclier($aEquiper) && $portes->contains(fn ($l) => $estDeuxMains($l->objet))) {
            throw ValidationException::withMessages([
                'inventaire_id' => 'Tu manies une arme à deux mains — impossible d\'y ajouter un bouclier.',
            ]);
        }
    }

    /**
     * Accès à la pièce (doc 01 §7) : son `tag_equipement` doit figurer parmi
     * ceux de la classe du héros ou parmi ceux qu'ouvrent ses nœuds.
     *
     * Profil « canon HeroQuest » : barbare/nain/elfe prennent tout sauf le lourd
     * (nœud Maîtrise lourde) ; le magicien est limité à `arme_legere` et ne porte
     * aucune armure, ses deux nœuds de déblocage levant chaque limite.
     */
    private function verifierAccesEquipement(Personnage $personnage, Objet $objet): void
    {
        $tag = $objet->tag_equipement;

        // Pièce sans exigence de maîtrise (outil, consommable, parchemin, ou
        // objet d'un catalogue antérieur aux tags) : toujours portable.
        if ($tag === null || $tag === '') {
            return;
        }

        $accessibles = $this->tagsAccessibles($personnage);

        // Aucune maîtrise déclarée pour cette classe (catalogue non semé, base
        // antérieure aux tags) : on N'APPLIQUE AUCUNE restriction. Échouer
        // « fermé » verrouillerait le héros hors de son propre équipement, y
        // compris celui de départ — une donnée de référence manquante ne doit
        // jamais rendre un personnage injouable.
        if ($accessibles === []) {
            return;
        }

        if (in_array($tag, $accessibles, true)) {
            return;
        }

        $noeud = $this->noeudQuiDebloque($personnage, $tag);

        throw ValidationException::withMessages([
            'inventaire_id' => $noeud === null
                ? "« {$objet->nom} » est hors de portée d'un {$personnage->classe}."
                : "« {$objet->nom} » exige le nœud {$noeud} — à prendre dans ton arbre de compétences.",
        ]);
    }

    /**
     * Tags de maîtrise dont dispose ce héros : ceux de sa CLASSE, plus ceux
     * qu'ouvrent ses nœuds `acces_equipement`.
     *
     * PUBLIC pour que `/moi` et l'étal du marché exposent la même vérité que le
     * contrôle d'équipement : un badge « non maîtrisé » calculé à part finirait
     * par diverger de la règle qu'il annonce.
     *
     * Ces nœuds déclaraient leurs `tags` depuis le premier jour sans que rien ne
     * les lise ; le moteur testait un drapeau `necessite_maitrise_lourde` codé
     * en dur, si bien qu'aucune classe n'avait de vraie limite d'équipement.
     *
     * @return list<string>
     */
    public function tagsAccessibles(Personnage $personnage): array
    {
        $base = (array) (ClasseHeros::where('nom', $personnage->classe)->first()?->tags_equipement ?? []);

        // REQUÊTE, jamais `$personnage->competences` : la relation est mémoïsée,
        // si bien qu'un nœud acquis plus tôt dans la même requête HTTP restait
        // invisible — le héros se voyait refuser l'équipement qu'il venait
        // pourtant de débloquer.
        $debloques = $personnage->competences()->get()
            ->filter(fn ($c) => ($c->effet['mecanique'] ?? null) === 'acces_equipement')
            ->flatMap(fn ($c) => (array) ($c->effet['tags'] ?? []))
            ->all();

        return array_values(array_unique([...$base, ...$debloques]));
    }

    /**
     * Nom du nœud qui ouvrirait ce tag DANS L'ARBRE DE SA CLASSE, ou null si
     * aucun — pour distinguer « prends ce talent » de « ce n'est pas pour toi ».
     */
    private function noeudQuiDebloque(Personnage $personnage, string $tag): ?string
    {
        return Competence::query()
            ->where('classe', $personnage->classe)
            ->get()
            ->first(fn ($c) => ($c->effet['mecanique'] ?? null) === 'acces_equipement'
                && in_array($tag, (array) ($c->effet['tags'] ?? []), true))
            ?->nom;
    }

    /**
     * Recalcule DEPUIS ZÉRO les dés de combat du héros à partir de son
     * équipement porté. Remplace l'ancien jeu de deltas ±1, qui dérivait au
     * moindre chemin d'exécution manqué.
     *
     * **Attaque — l'arme REMPLACE** (doc 03 §8 : « la valeur d'Attaque vient de
     * l'arme équipée », comme au plateau) : à mains nues 1 dé, avec une épée
     * large 3 dés. Auparavant l'arme s'AJOUTAIT à une valeur de classe qui
     * encodait déjà l'arme de départ — un barbare (3) avec une épée large (3)
     * arrivait à 6 dés, et l'équipement n'était plus qu'une inflation.
     *
     * **Défense — l'armure S'AJOUTE** : les quatre classes ont 2 dés de base et
     * les pièces d'armure valent +1 chacune ; aucun double compte à corriger,
     * on garde le cumul (casque + bouclier = 2 + 1 + 1).
     *
     * Les améliorations de Forge (`bonus_des_attaque` / `bonus_des_defense`,
     * portées par la ligne d'inventaire) s'ajoutent par-dessus dans les deux cas.
     */
    public function recalculerCombat(Personnage $personnage): void
    {
        $base = ClasseHeros::where('nom', $personnage->classe)->first();

        $attaque = (int) ($base?->des_attaque ?? 1);
        $defense = (int) ($base?->des_defense ?? 2);

        $portes = $personnage->inventaire()
            ->whereIn('emplacement', self::SLOTS)
            ->with('objet')
            ->get();

        foreach ($portes as $ligne) {
            $effet = (array) ($ligne->objet?->effet ?? []);
            $ameliorations = (array) ($ligne->ameliorations ?? []);

            // L'ARME PRINCIPALE impose sa valeur d'attaque (remplacement).
            if ($ligne->emplacement === 'arme_principale' && isset($effet['des_attaque'])) {
                $attaque = (int) $effet['des_attaque'];
            }

            $defense += (int) ($effet['des_defense'] ?? 0);

            foreach ($ameliorations as $amelioration) {
                $attaque += (int) ($amelioration['effet']['bonus_des_attaque'] ?? 0);
                $defense += (int) ($amelioration['effet']['bonus_des_defense'] ?? 0);
            }
        }

        $personnage->update([
            'des_attaque' => max(0, $attaque),
            'des_defense' => max(0, $defense),
        ]);
    }

    private function objetDeLaLigne(Personnage $personnage, Inventaire $ligne): Objet
    {
        if ($ligne->personnage_id !== $personnage->id || $ligne->objet === null) {
            throw ValidationException::withMessages([
                'inventaire_id' => 'Objet introuvable dans l\'inventaire de ce héros.',
            ]);
        }

        return $ligne->objet;
    }
}
