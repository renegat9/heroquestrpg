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
    /**
     * Classes qui ne portent AUCUNE armure métallique (dos des cartes) : le
     * Druide et le Rogue par interdiction, le Barde parce que son dé de défense
     * supplémentaire en dépend — chez lui ce n'est pas un refus mais une
     * condition, appliquée par `CapacitesInnees`, et il garde donc le droit de
     * l'équiper. Il n'est PAS listé ici pour cette raison.
     */
    private const SANS_METAL = ['druide', 'rogue'];

    /**
     * Emplacements « portés » (par opposition à sac / consommable).
     *
     * `casque` est un slot À PART depuis le 2026-08-08 : au plateau les pièces
     * d'armure se CUMULENT (« may be combined with the helmet and/or shield »,
     * LR p. 7). Tant que casque et cotte partageaient `armure`, le casque
     * n'était qu'un achat de dépannage qu'on jetait dès la première vraie
     * armure, et la défense plafonnait à 5 au lieu de 6.
     */
    public const SLOTS = ['arme_principale', 'arme_secondaire', 'casque', 'armure', 'talisman'];

    /**
     * Clés d'`effet` d'objet qui AUGMENTENT une jauge maximale du héros.
     *
     * Les talismans (Amulette du Nord, Brassards elfiques…) « add 2 Body points
     * and 1 Mind point to the … totals » : ils ne donnent pas de dés, ils
     * relèvent le plafond. Appliqué comme les dés — recalcul complet depuis
     * l'équipement porté, jamais un delta ±N qui dérive.
     */
    private const JAUGES = [
        'bonus_pv_body_max' => ['pv_body_max', 'pv_body'],
        'bonus_pv_mind_max' => ['pv_mind_max', 'pv_mind'],
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
     * Les emplacements où cette pièce peut être montée, dans l'ordre naturel.
     *
     * Une pièce n'en a qu'un — SAUF une arme à UNE main, qui va en main droite
     * **ou** en main gauche (dual-wielding, décision de René 2026-08-12 ; carte
     * du deck : « you may use these weapons **instead of a shield in your off
     * hand** »). C'est ce qui rend le slot paramétrable au lieu d'être déduit
     * de `objets.emplacement`, valeur unique par pièce.
     *
     * @return list<string>
     */
    public function slotsPossibles(Objet $objet): array
    {
        $naturel = (string) $objet->emplacement;

        if (! in_array($naturel, self::SLOTS, true)) {
            return [];
        }

        // Deux mains : elle occupe les deux, il n'y a pas de choix à faire.
        if ($naturel !== 'arme_principale' || ! empty($objet->effet['deux_mains'])) {
            return [$naturel];
        }

        return ['arme_principale', 'arme_secondaire'];
    }

    /**
     * Équipe une ligne d'inventaire du SAC. Sans `$slot`, l'emplacement naturel
     * de l'objet (objet.emplacement) ; une arme à une main accepte aussi
     * `arme_secondaire` — la main gauche. L'occupant actuel du slot repart au
     * sac (auto-swap : capacité de sac neutre, une pièce sort, une pièce entre).
     */
    public function equiper(Personnage $personnage, Inventaire $ligne, ?string $slot = null): Inventaire
    {
        $objet = $this->objetDeLaLigne($personnage, $ligne);

        if ($ligne->emplacement !== 'sac') {
            throw ValidationException::withMessages(['inventaire_id' => 'Cet objet n\'est pas dans le sac.']);
        }

        $possibles = $this->slotsPossibles($objet);

        if ($possibles === []) {
            throw ValidationException::withMessages([
                'inventaire_id' => "« {$objet->nom} » n'est pas une pièce d'équipement.",
            ]);
        }

        $slot ??= $possibles[0];

        if (! in_array($slot, $possibles, true)) {
            throw ValidationException::withMessages([
                'emplacement' => "« {$objet->nom} » ne se porte pas à cet emplacement.",
            ]);
        }

        $this->verifierMains($personnage, $objet, $slot);
        $this->verifierAccesEquipement($personnage, $objet);

        return DB::transaction(function () use ($personnage, $ligne, $slot) {
            $jaugesAvant = $this->bonusJauges($personnage);

            // Auto-swap : l'occupant actuel du slot retourne au sac (effet révoqué).
            $occupant = $personnage->inventaire()->where('emplacement', $slot)->with('objet')->first();
            if ($occupant !== null) {
                // (le recalcul complet en fin de transaction reprend tout)
                $occupant->update(['emplacement' => 'sac']);
            }

            $ligne->update(['emplacement' => $slot]);
            $personnage->refresh();
            $this->appliquerEcartJauges($personnage, $jaugesAvant);
            $this->recalculerCombat($personnage);
            $this->declencherAEquipement($personnage, $ligne->fresh());

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

        return DB::transaction(function () use ($personnage, $ligne) {
            $jaugesAvant = $this->bonusJauges($personnage);

            $ligne->update(['emplacement' => 'sac']);
            // Recalcul APRÈS le retrait : sinon l'arme est encore comptée comme
            // portée et le héros garde ses dés.
            $personnage->refresh();
            $this->appliquerEcartJauges($personnage, $jaugesAvant);
            $this->recalculerCombat($personnage);

            return $ligne->fresh();
        });
    }

    /**
     * LES DEUX MAINS (doc 01 §7, règle de René 2026-08-12). Quatre tenues, et
     * quatre seulement :
     *
     *  - deux armes à UNE main ;
     *  - une arme à deux mains, seule ;
     *  - une arme à une main + un bouclier ;
     *  - une arme à une main, seule (ou rien du tout).
     *
     * Une arme à deux mains prend donc les deux mains : ni bouclier, ni seconde
     * arme. Rejet explicite (jamais d'auto-déséquipement croisé) : c'est au
     * joueur de choisir ce qu'il pose.
     *
     * INDÉPENDANT du tag de maîtrise : `deux_mains` dit « les deux mains »,
     * le tag dit « qui a le droit d'en porter ». Le Bâton est à deux mains ET
     * `arme_legere`, donc jouable par le magicien.
     */
    private function verifierMains(Personnage $personnage, Objet $aEquiper, string $slot): void
    {
        if (! in_array($slot, ['arme_principale', 'arme_secondaire'], true)) {
            return; // casque, armure, talisman : les mains ne les concernent pas
        }

        $estDeuxMains = fn (?Objet $o) => (bool) ($o?->effet['deux_mains'] ?? false);
        $portes = $personnage->inventaire()
            ->whereIn('emplacement', ['arme_principale', 'arme_secondaire'])
            ->with('objet')
            ->get();

        // L'occupant du slot visé s'en va de toute façon (auto-swap) : seule
        // l'AUTRE main compte.
        $autreMain = $slot === 'arme_principale' ? 'arme_secondaire' : 'arme_principale';
        $occupantAutreMain = $portes->firstWhere('emplacement', $autreMain)?->objet;

        if ($occupantAutreMain === null) {
            return;
        }

        if ($estDeuxMains($aEquiper)) {
            throw ValidationException::withMessages([
                'inventaire_id' => "« {$aEquiper->nom} » se manie à deux mains — libère d'abord ton autre main (« {$occupantAutreMain->nom} »).",
            ]);
        }

        if ($estDeuxMains($occupantAutreMain)) {
            throw ValidationException::withMessages([
                'inventaire_id' => "Tu manies « {$occupantAutreMain->nom} » à deux mains : il ne te reste aucune main libre.",
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
        if ($this->estAccessible($personnage, $objet)) {
            return;
        }

        $noeud = $this->noeudQuiDebloque($personnage, (string) $objet->tag_equipement);

        throw ValidationException::withMessages([
            'inventaire_id' => $noeud === null
                ? "« {$objet->nom} » est hors de portée d'un {$personnage->classe}."
                : "« {$objet->nom} » exige le nœud {$noeud} — à prendre dans ton arbre de compétences.",
        ]);
    }

    /**
     * Ce héros a-t-il le droit d'utiliser cette pièce ? La DÉCISION, séparée du
     * refus, parce qu'elle sert désormais à trois endroits qui ne lèvent pas
     * tous une exception.
     *
     * Extraite de `verifierAccesEquipement()` quand les cartes officielles ont
     * apporté des **potions réservées à une classe** (trois au Barbare, deux à
     * l'Elfe, doc 16 §2.1bis) : un consommable ne passe jamais par `equiper()`,
     * donc rien ne l'aurait contrôlé. Les trois lecteurs :
     *
     *  - `MoteurPotions::boire()` — REFUSE, c'est l'autorité ;
     *  - `/moi` `consommables` — pose un badge `utilisable`, sans filtrer : un
     *    héros a le droit de PORTER la potion d'un compagnon ;
     *  - `MoteurReactions::soinsDisponibles()` — FILTRE l'offre, car proposer un
     *    soin que la résolution refusera est pire que ne rien proposer.
     */
    /**
     * Malus de déplacement de l'équipement PORTÉ — zéro pour le Chevalier.
     *
     * « Les armures ne nuisent pas à son mouvement » (dos de carte, René
     * 2026-08-22) : c'est ce qui fait de lui le seul à pouvoir enfiler la plate
     * sans y perdre deux cases, et c'est tout l'intérêt de la classe.
     *
     * Encapsulé ici plutôt que testé chez les appelants : `MenuMoteur` et
     * `ResolveurTour` lisent tous deux ce malus, et deux exemptions à tenir en
     * cohérence finiraient par diverger — le menu proposerait des cases que le
     * résolveur refuserait, l'anti-patron que ce projet traque partout.
     */
    public function malusDeplacement(Personnage $personnage): int
    {
        if ($personnage->classe === 'chevalier') {
            return 0;
        }

        return (int) $this->valeurEffetPorte($personnage, 'malus_deplacement');
    }

    public function estAccessible(Personnage $personnage, Objet $objet): bool
    {
        // ⚠ `Personnage::classeHeros` n'existe PAS (piège documenté dans
        // CLAUDE.md, il rend `null` en silence) : la classe se résout par
        // requête sur le nom, comme partout ailleurs dans ce fichier.
        $classe = ClasseHeros::where('nom', $personnage->classe)->first();

        // ⚠ LISTE BLANCHE NOMINATIVE, quand la classe en déclare une : elle
        // REMPLACE le contrôle par tags, elle ne s'y ajoute pas. Le Moine ne
        // manie que dague, arbalète, hachette, épée courte et bâton — et aucune
        // combinaison de tags ne dit cela, hachette et épée courte partageant
        // `arme_courante` avec l'épée large, l'épée longue et la rapière qui lui
        // sont interdites.
        //
        // Ne porte QUE sur les pièces qui exigent une maîtrise : une potion, un
        // parchemin ou une trousse à outils ne sont pas des armes, et une liste
        // d'armes n'a pas à les interdire.
        $liste = $classe?->objets_autorises;

        if (is_array($liste) && $liste !== [] && filled($objet->tag_equipement)) {
            return in_array($objet->nom, $liste, true);
        }

        // ⚠ ARMURE MÉTALLIQUE — une matière, pas un poids. Les tags disent
        // `armure_legere`/`armure_lourde` ; Barde, Druide et Rogue raisonnent
        // sur le métal, d'où `objets.metallique`. Le Rogue perd aussi son tag
        // d'armure, mais la colonne reste la seule autorité sur la MATIÈRE :
        // le jour où une armure de cuir entre au catalogue, elle passera sans
        // qu'on ait à y revenir.
        if ($objet->metallique && in_array($classe?->nom, self::SANS_METAL, true)) {
            return false;
        }

        $tag = $objet->tag_equipement;

        // Pièce sans exigence de maîtrise (outil, consommable, parchemin, ou
        // objet d'un catalogue antérieur aux tags) : toujours portable.
        if ($tag === null || $tag === '') {
            return true;
        }

        $accessibles = $this->tagsAccessibles($personnage);

        // Aucune maîtrise déclarée pour cette classe (catalogue non semé, base
        // antérieure aux tags) : on N'APPLIQUE AUCUNE restriction. Échouer
        // « fermé » verrouillerait le héros hors de son propre équipement, y
        // compris celui de départ — une donnée de référence manquante ne doit
        // jamais rendre un personnage injouable.
        if ($accessibles === []) {
            return true;
        }

        return in_array($tag, $accessibles, true);
    }

    /**
     * Union des maîtrises d'un ENSEMBLE de héros — ce que le groupe présent
     * peut porter, toutes classes confondues.
     *
     * Sert à ne pas offrir en butin une pièce que personne sur place ne pourra
     * utiliser : les artefacts de classe le faisaient déjà (`DeckFouille`), les
     * potions réservées au Barbare et à l'Elfe le demandent depuis qu'elles
     * existent (décision de René, 2026-08-17). Une seule règle, un seul endroit
     * — deux implémentations de « ce que ce groupe peut porter » finiraient par
     * diverger.
     *
     * ⚠ Rend un tableau VIDE si aucune classe n'est connue : les appelants
     * traitent ce cas en « aucune restriction » (fail open), comme partout
     * ailleurs ici — une donnée de référence manquante ne doit jamais appauvrir
     * une partie.
     *
     * @param  iterable<int, Personnage>  $personnages
     * @return list<string>
     */
    public function tagsAccessiblesAux(iterable $personnages): array
    {
        $tags = [];

        foreach ($personnages as $personnage) {
            $tags = [...$tags, ...$this->tagsAccessibles($personnage)];
        }

        return array_values(array_unique($tags));
    }

    /**
     * Cette pièce est-elle utilisable par AU MOINS UN des héros présents ?
     *
     * @param  list<string>  $tagsAccessibles  union rendue par `tagsAccessiblesAux()`
     */
    public static function utilisableParLeGroupe(?string $tag, array $tagsAccessibles): bool
    {
        // Pas de maîtrise exigée, ou aucune classe connue : on n'écarte rien.
        return $tag === null || $tag === '' || $tagsAccessibles === []
            || in_array($tag, $tagsAccessibles, true);
    }

    /**
     * Le héros est-il « toujours considéré armé » de cette arme ? — Bandoulière
     * du Rogue, « you are always considered to be armed with a dagger »
     * (`compte_comme_arme`, doc 16 §2.1bis).
     *
     * ⚠ Balaie TOUT l'inventaire, sac compris : la bandoulière se porte, elle
     * ne s'équipe pas dans un slot. Et elle n'ajoute AUCUN dé — le Rogue à
     * mains nues en lance déjà un, autant que la dague. Ce qu'elle donne, ce
     * sont les règles qui exigent une dague : l'Ambidextrie, et la fermeture
     * des techniques mains nues.
     */
    public function compteCommeArme(Personnage $personnage, string $nomArme): bool
    {
        return $personnage->inventaire()->with('objet')->get()
            ->contains(fn ($ligne) => ($ligne->objet?->effet['compte_comme_arme'] ?? null) === $nomArme);
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
    /**
     * Une pièce ÉQUIPÉE porte-t-elle cet effet booléen ?
     *
     * Les effets d'objet chiffrés (`des_attaque`, `des_defense`) sont recopiés
     * sur les colonnes du personnage par `recalculerCombat`, mais les effets
     * BOOLÉENS n'ont nulle part où se poser — d'où cette interrogation directe
     * de l'équipement porté, au moment où la règle s'applique.
     *
     * Premier usage : `incompatible_deux_mains` du Bouclier.
     */
    public function effetPorte(Personnage $personnage, string $cle): bool
    {
        return $personnage->inventaire()
            ->whereIn('emplacement', self::SLOTS)
            ->with('objet')
            ->get()
            ->contains(fn ($ligne) => (bool) (($ligne->objet?->effet ?? [])[$cle] ?? false));
    }

    /**
     * Effets qui se déclenchent AU MOMENT D'ENFILER la pièce, une fois.
     *
     * Un seul aujourd'hui : la Baguette de Galimatias, « immediately upon
     * acquiring this item, the adventurer will recover all spells he has used
     * so far during this quest ». C'est une charge, pas un passif — sans elle,
     * déséquiper puis rééquiper rendrait les sorts en boucle.
     *
     * Le `+2 Mind` de la même baguette, lui, reste un passif porté (`JAUGES`) :
     * une carte peut mêler les deux natures, et il faut les traiter séparément.
     */
    private function declencherAEquipement(Personnage $personnage, Inventaire $ligne): void
    {
        $effet = (array) ($ligne->objet?->effet ?? []);

        if (empty($effet['restaure_sorts'])) {
            return;
        }

        $charges = app(MoteurCharges::class);

        if ($charges->consommer($ligne)) {
            app(MoteurSorts::class)->restaurerTousLesSorts($personnage);
        }
    }

    /**
     * Bonus de jauges MAXIMALES actuellement portés, par clé d'effet.
     *
     * @return array<string, int>
     */
    private function bonusJauges(Personnage $personnage): array
    {
        $portes = $personnage->inventaire()
            ->whereIn('emplacement', self::SLOTS)
            ->with('objet')
            ->get();

        $bonus = [];

        foreach (array_keys(self::JAUGES) as $cle) {
            $bonus[$cle] = (int) $portes->sum(fn ($ligne) => (int) (($ligne->objet?->effet ?? [])[$cle] ?? 0));
        }

        return $bonus;
    }

    /**
     * Applique l'ÉCART de bonus de jauges entre deux états de l'équipement.
     *
     * Un écart réconcilié, pas un delta qui s'accumule : les deux termes sont
     * chacun un recalcul complet depuis l'équipement porté, donc une exécution
     * manquée ne peut pas décaler durablement la jauge (le prochain équipement
     * repart des vraies pièces). C'est la seule façon de toucher `pv_body_max`
     * sans stocker une valeur « de base » quelque part : ce maximum encaisse
     * aussi les montées de niveau, on ne peut pas le recalculer depuis zéro.
     *
     * Gagner le talisman DONNE les points (« adds 2 Body points … to the
     * totals ») ; le retirer les reprend, en écrêtant la valeur courante — un
     * héros ne reste jamais au-dessus de son maximum.
     *
     * @param  array<string, int>  $avant
     */
    private function appliquerEcartJauges(Personnage $personnage, array $avant): void
    {
        $apres = $this->bonusJauges($personnage);
        $modifs = [];

        foreach (self::JAUGES as $cle => [$colonneMax, $colonneCourante]) {
            $ecart = ($apres[$cle] ?? 0) - ($avant[$cle] ?? 0);

            if ($ecart === 0) {
                continue;
            }

            // Plancher à 1 : un maximum nul rendrait le héros mort-né, et
            // aucune pièce ne doit pouvoir produire ça.
            $max = max(1, (int) $personnage->{$colonneMax} + $ecart);
            $modifs[$colonneMax] = $max;
            $modifs[$colonneCourante] = min($max, max(0, (int) $personnage->{$colonneCourante} + max(0, $ecart)));
        }

        if ($modifs !== []) {
            $personnage->update($modifs);
            $personnage->refresh();
        }
    }

    /**
     * Valeur CHIFFRÉE la plus forte portée par l'équipement pour cette clé, 0
     * si aucune pièce ne la porte.
     *
     * Le max, pas la somme : `malus_deplacement` est une pénalité d'encombrement
     * (« a 2 square movement penalty » sur l'armure de plates), pas un coût qui
     * s'additionnerait pièce par pièce — deux armures lourdes ne se cumulent
     * d'ailleurs pas, elles partagent un slot.
     */
    /**
     * Le héros porte-t-il du MÉTAL (armure) ou un bouclier ?
     *
     * Carte du Barde : « when you are wearing no "metal" armor and carrying no
     * shield you have 1 extra defend die ». Le métal se reconnaît au tag de
     * maîtrise — `armure_legere` et `armure_lourde` sont les deux familles
     * d'armure du catalogue, la cape et les brassards du magicien
     * (`armure_magicien`) n'en sont pas.
     */
    public function porteMetalOuBouclier(Personnage $personnage): bool
    {
        // ⚠ Le MÉTAL se lit dans `objets.metallique`, plus dans les tags. Ceux-ci
        // disent le POIDS (`armure_legere`/`armure_lourde`), pas la matière :
        // la déduction tenait tant que les deux seules armures du catalogue
        // étaient métalliques, et serait tombée en silence à la première armure
        // de cuir — le Barde aurait perdu son dé sans que rien ne le signale.
        //
        // Le BOUCLIER, lui, reste désigné par son tag : les cartes le nomment
        // séparément du métal, et le marquer métallique retirerait au passage
        // son bouclier au Druide, à qui elles ne l'interdisent pas.
        return $personnage->inventaire()
            ->whereIn('emplacement', self::SLOTS)
            ->with('objet')
            ->get()
            ->contains(fn ($ligne) => (bool) $ligne->objet?->metallique
                || $ligne->objet?->tag_equipement === 'bouclier');
    }

    public function valeurEffetPorte(Personnage $personnage, string $cle): int
    {
        return (int) $personnage->inventaire()
            ->whereIn('emplacement', self::SLOTS)
            ->with('objet')
            ->get()
            ->max(fn ($ligne) => (int) (($ligne->objet?->effet ?? [])[$cle] ?? 0));
    }

    /**
     * Reconstruit `des_attaque` / `des_defense` depuis TOUTES leurs sources :
     * les dés de classe, les **nœuds passifs permanents** (arbre de
     * compétences), l'équipement porté et les améliorations de Forge.
     *
     * ⚠ C'est un recalcul COMPLET, pas un delta — et c'est ce qui impose d'y
     * faire entrer les compétences. La méthode écrase la colonne : tant qu'elle
     * ignorait l'arbre, le premier « équiper » venu effaçait en silence le +1
     * permanent qu'un nœud y avait posé (le mapping de
     * `CompetenceController::EFFETS_PASSIFS` l'y écrivait bel et bien). Aucun
     * nœud du catalogue n'est aujourd'hui dans ce cas — les neuf nœuds à dés
     * portent tous une `condition` — mais la trappe était armée pour le
     * premier qui n'en aurait pas.
     *
     * Un passif CONDITIONNEL n'y entre jamais : Frénésie et Garde tenace sont
     * lues en situation par `ResolveurTour`, les compter ici les doublerait.
     */
    public function recalculerCombat(Personnage $personnage): void
    {
        $base = ClasseHeros::where('nom', $personnage->classe)->first();
        $defense = (int) ($base?->des_defense ?? 2) + $this->bonusPermanent($personnage, 'bonus_des_defense');

        $portes = $personnage->inventaire()
            ->whereIn('emplacement', self::SLOTS)
            ->with('objet')
            ->get();

        foreach ($portes as $ligne) {
            $defense += (int) (($ligne->objet?->effet ?? [])['des_defense'] ?? 0);

            foreach ((array) ($ligne->ameliorations ?? []) as $amelioration) {
                $defense += (int) ($amelioration['effet']['bonus_des_defense'] ?? 0);
            }
        }

        $personnage->update([
            // La COLONNE reste celle de la main droite : c'est elle que lisent
            // la fiche, le score de puissance et le budget de rencontre. La main
            // gauche ne s'y ajoute pas — elle ne donne aucun dé, elle offre un
            // second choix d'arme au moment d'attaquer (décision de René).
            'des_attaque' => $this->desAttaqueAvec($personnage, $portes->firstWhere('emplacement', 'arme_principale')),
            'des_defense' => max(0, $defense),
        ]);
    }

    /**
     * Dés d'attaque du héros AVEC cette arme — `null` = à mains nues.
     *
     * Existe parce qu'un héros peut désormais tenir DEUX armes et choisir la
     * sienne au moment de frapper : la colonne `des_attaque` ne peut plus être
     * la seule vérité, elle ne connaît que la main droite. Même calcul dans les
     * deux cas — l'arme REMPLACE les dés de classe (elle ne s'y ajoute pas), les
     * améliorations de Forge de toutes les pièces portées s'ajoutent.
     */
    public function desAttaqueAvec(Personnage $personnage, ?Inventaire $arme): int
    {
        $base = ClasseHeros::where('nom', $personnage->classe)->first();
        $attaque = (int) ($base?->des_attaque ?? 1);

        $effet = (array) ($arme?->objet?->effet ?? []);

        // L'ARME REMPLACE les dés de classe (l'arme fait l'attaque, doc 03 §8) ;
        // le bonus d'un nœud permanent, lui, s'AJOUTE — c'est le héros qui
        // frappe mieux, pas l'arme qui coupe plus.
        if (isset($effet['des_attaque'])) {
            $attaque = (int) $effet['des_attaque'];
        }

        $attaque += $this->bonusPermanent($personnage, 'bonus_des_attaque');

        $portes = $personnage->inventaire()
            ->whereIn('emplacement', self::SLOTS)
            ->with('objet')
            ->get();

        foreach ($portes as $ligne) {
            foreach ((array) ($ligne->ameliorations ?? []) as $amelioration) {
                $attaque += (int) ($amelioration['effet']['bonus_des_attaque'] ?? 0);
            }
        }

        return max(0, $attaque);
    }

    /**
     * Somme des nœuds PASSIFS PERMANENTS portant cette mécanique.
     *
     * La règle « permanent » vit dans `Competence::estBonusPermanent()`, point
     * de passage unique partagé avec `CompetenceController` : c'est lui qui
     * pose le bonus à l'acquisition, c'est ici qu'on le rejoue à chaque
     * recalcul. Les deux doivent trancher pareil, sinon le bonus est doublé
     * d'un côté ou perdu de l'autre.
     */
    private function bonusPermanent(Personnage $personnage, string $mecanique): int
    {
        return (int) $personnage->competences()
            ->get(['competences.id', 'competences.type', 'competences.effet'])
            ->filter(fn (Competence $c) => ($c->effet['mecanique'] ?? null) === $mecanique
                && $c->estBonusPermanent())
            ->sum(fn (Competence $c) => (int) ($c->effet['valeur'] ?? 0));
    }

    /**
     * Les armes réellement EN MAIN, main droite d'abord — matière des options
     * d'attaque du menu (une par arme) et de la garde « à mains nues » du Moine.
     *
     * Un bouclier n'en est pas une : il occupe la main gauche sans jamais
     * frapper.
     *
     * @return list<Inventaire>
     */
    public function armesEnMain(Personnage $personnage): array
    {
        return $personnage->inventaire()
            ->whereIn('emplacement', ['arme_principale', 'arme_secondaire'])
            ->with('objet')
            ->get()
            ->filter(fn (Inventaire $l) => $l->objet !== null
                && empty($l->objet->effet['incompatible_deux_mains']))
            ->sortBy(fn (Inventaire $l) => $l->emplacement === 'arme_principale' ? 0 : 1)
            ->values()
            ->all();
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
