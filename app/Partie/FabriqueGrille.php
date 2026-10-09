<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\GroupeMercenaire;
use App\Models\Mobilier;
use App\Models\Quete;
use App\Models\Terrain;
use Illuminate\Validation\ValidationException;

/**
 * Fabrique la grille tactique OCCUPÉE d'une quête (carte + figures présentes) —
 * source de vérité UNIQUE de l'occupation ET de l'opacité, partagée par le
 * déplacement, le ciblage ET la ligne de vue (ResolveurTour, MoteurSorts,
 * MenuMoteur). Règles d'occupation (doc 03) : héros DEBOUT (un tombé
 * s'enjambe, C4), monstres `actif` avec leur emprise (3.9), alliés `actif`
 * (3.5), et depuis doc 17 le mobilier de la carte (table, coffre, armoire…)
 * avec SA propre emprise l×h — même mécanisme `cellulesEmprise()` que les
 * grandes figurines, réutilisé plutôt que dupliqué. Les `except*` retirent
 * une figure du plateau (la sienne, pour se déplacer / voir depuis sa propre
 * case) ; le mobilier, lui, n'a pas d'`except` : il ne bouge jamais en cours
 * de quête.
 *
 * Le mobilier porte DEUX drapeaux INDÉPENDANTS (doc 17, portage) : une table
 * bloque le mouvement (`bloque_mouvement` → `Grille::obstruer()`) mais pas la
 * vue, une bibliothèque bloque les deux (`bloque_vue` → `Grille::occulter()`,
 * EN PLUS de `obstruer()`). Le mobilier ne passe JAMAIS par `Grille::occuper()`
 * (réservé aux FIGURES — héros, monstres, alliés) : un meuble bloquant le
 * mouvement mais pas la vue ne doit jamais participer au test
 * `figuresBloquent` de `ligneDeVue()`, sans quoi une simple table arrêterait
 * les flèches — exactement le bug corrigé par cette séparation.
 *
 * Le TERRAIN (doc 18 §4, phase 4a) suit le même principe qu'une cinquième
 * couche : `bloque_mouvement`/`bloque_vue` alimentent les MÊMES jeux
 * `$obstacles`/`$opaques` que le mobilier (jamais `$occupees`, un terrain
 * n'est pas une figure), et `cout_deplacement` alimente
 * `Grille::definirCoutsDeplacement()` — posé ici mais pas encore consulté par
 * aucune BFS (voir les commentaires de `Grille::distance()`/`chemin()`).
 */
final class FabriqueGrille
{
    public static function pour(
        Quete $quete,
        ?int $exceptPersonnageId = null,
        ?int $exceptInstanceId = null,
        ?int $exceptMercenaireId = null,
        bool $traverseRoche = false,
        bool $franchitAllies = false,
    ): Grille {
        $carte = $quete->carte;

        if ($carte === null) {
            throw ValidationException::withMessages(['groupe' => 'La quête en cours n\'a pas de carte assemblée.']);
        }

        $grille = Grille::depuisCarte($carte);

        // Traverser la Pierre : le héros qui bouge ignore la roche et les
        // portes closes. Posé AVANT les figures et le mobilier, qui eux
        // continuent de bloquer — on ne traverse pas un compagnon.
        if ($traverseRoche) {
            $grille->autoriserLaRoche();
        }

        $occupees = [];
        $alliees = [];
        $obstacles = [];
        $meubles = [];
        $opaques = [];

        // ⚠ QUI BOUGE décide de qui est un allié — et c'est le paramètre
        // `except*` qui le dit déjà : un héros retire SA figure, un monstre
        // retire la SIENNE. Sans mouvement identifié (grille générique de
        // ciblage), tout le monde reste dans `$occupees` : le comportement des
        // appelants qui demandent seulement « qui est où » ne change pas.
        //
        // « On peut traverser la case d'un autre héros (pas s'y arrêter) »
        // (LR p. 12, doc 16 §5) : la règle était ÉCRITE dans la doc depuis le
        // portage des livrets, et le moteur ne l'appliquait pas — deux héros
        // dans un couloir se bloquaient mutuellement.
        //
        // ⚠ La réciproque côté monstres est de NOUS : aucun livret ne dit qu'un
        // monstre franchit un autre monstre. Elle est retenue par symétrie et
        // pour une raison pratique — sans elle, une file de créatures dans un
        // couloir se paralyse elle-même, le premier bloquant tous les autres.
        // ⚠ OPT-IN, et c'est délibéré. `estTraversable()` répondait jusqu'ici à
        // DEUX questions à la fois — « puis-je passer ici ? » et « puis-je m'y
        // tenir ? » — parce qu'elles avaient la même réponse. Elles divergent
        // désormais, et une grille qui franchit les alliés par défaut aurait
        // changé en silence le sens de huit sites d'appel qui parlent de
        // PLACEMENT (invocation, poussée, téléportation, atterrissage). Seuls
        // les chemins de DÉPLACEMENT le demandent, et ils le disent.
        $moteurHeros = $franchitAllies && ($exceptPersonnageId !== null || $exceptMercenaireId !== null);
        $moteurMonstre = $franchitAllies && $exceptInstanceId !== null;

        foreach ($quete->etatsPersonnages()->get() as $etat) {
            // Un héros TOMBÉ (à terre) ne bloque ni le passage ni la ligne de vue :
            // il gît au sol, on l'enjambe. Il reste secourable (resoudreRelever) tant
            // qu'aucune AUTRE figure ne se tient sur sa case.
            if ($etat->personnage_id !== $exceptPersonnageId && $etat->position_x !== null && ! $etat->tombe) {
                $case = ['x' => (int) $etat->position_x, 'y' => (int) $etat->position_y];

                if ($moteurHeros) {
                    $alliees[] = $case;
                } else {
                    $occupees[] = $case;
                }
            }
        }

        foreach ($quete->instancesMonstres()->where('etat', 'actif')->with('monstre')->get() as $instance) {
            // BOMBE FUMIGÈNE — « all heroes move unseen through the monster's
            // space » : la créature noyée dans la fumée cesse d'occuper sa case.
            // Le retrait vaut du même coup pour le MOUVEMENT et pour la LIGNE
            // DE VUE, parce que `$occupees` est la seule et unique liste que
            // `figuresBloquent` consulte — c'est exactement ce que dit la carte,
            // et il n'y a rien d'autre à câbler.
            $enfume = (bool) ($instance->habillage['conditions'][MoteurSorts::MONSTRE_ENFUME] ?? false);

            if ($instance->id !== $exceptInstanceId && $instance->position_x !== null && ! $enfume) {
                // 3.9 : une grande figurine occupe TOUTE son emprise (1×1 → une
                // seule case, identique au comportement antérieur).
                $e = $instance->monstre->emprise();
                $emprise = $grille->cellulesEmprise(
                    (int) $instance->position_x, (int) $instance->position_y, $e['l'], $e['h'],
                );

                if ($moteurMonstre) {
                    $alliees = array_merge($alliees, $emprise);
                } else {
                    $occupees = array_merge($occupees, $emprise);
                }
            }
        }

        // Alliés (3.5) : figures sur le plateau → cases infranchissables.
        foreach (GroupeMercenaire::where('groupe_id', $quete->groupe_id)->where('etat', 'actif')->get() as $allie) {
            if ($allie->id !== $exceptMercenaireId && $allie->position_x !== null) {
                $case = ['x' => (int) $allie->position_x, 'y' => (int) $allie->position_y];

                // Un mercenaire combat AVEC les héros : il est du même camp
                // qu'eux, donc traversable par un héros — et un obstacle pour un
                // monstre, comme n'importe quel ennemi.
                if ($moteurHeros || $exceptMercenaireId !== null) {
                    $alliees[] = $case;
                } else {
                    $occupees[] = $case;
                }
            }
        }

        // CAPTIF (mission « secourir », 2026-10-04) : une figure posée sur la
        // carte avant sa libération. Il n'est d'aucun camp tant qu'on ne l'a
        // pas libéré — ni traversable par un héros, ni par un monstre — et
        // personne ne doit pouvoir finir sur sa case (on le libère AU CONTACT).
        foreach (GroupeMercenaire::where('groupe_id', $quete->groupe_id)->where('etat', 'captif')->whereNotNull('position_x')->get() as $captif) {
            $occupees[] = ['x' => (int) $captif->position_x, 'y' => (int) $captif->position_y];
        }

        // Mobilier (doc 17) : troisième couche de la carte (AssembleurCarte),
        // au même niveau que `leviers`/`pieges`. SEULE boucle d'occupation ET
        // d'opacité du mobilier dans tout le moteur — n'en ouvrir aucune autre
        // ailleurs (déplacement, ciblage, ligne de vue divergeraient sinon).
        // `bloque_mouvement` et `bloque_vue` sont lus INDÉPENDAMMENT, dans
        // deux jeux de cases DISTINCTS de `$occupees` (réservé aux figures) :
        // un meuble peut bloquer le mouvement sans la vue (table → `obstacles`
        // seul), les deux (bibliothèque → `obstacles` ET `opaques`), ou ni
        // l'un ni l'autre (aucun cas aujourd'hui, cf. MobilierSeeder, mais le
        // catalogue le permet).
        $mobilier = (array) ($carte->grille['mobilier'] ?? []);
        if ($mobilier !== []) {
            $types = Mobilier::query()
                ->whereIn('id', array_values(array_unique(array_column($mobilier, 'mobilier_id'))))
                ->get(['id', 'bloque_mouvement', 'bloque_vue'])
                ->keyBy('id');

            foreach ($mobilier as $meuble) {
                $type = $types->get($meuble['mobilier_id'] ?? null);
                // ⚠ Une pièce MISE EN PIÈCES (jet de Body, 2026-08-24) cesse de
                // bloquer le mouvement ET la vue — et il suffit de l'écarter
                // ICI, précisément parce que c'est la seule boucle du mobilier
                // de tout le moteur. C'est tout l'intérêt de n'en avoir qu'une.
                if ($type === null || MoteurMobilier::estDetruite($meuble)) {
                    continue;
                }

                $cellules = $grille->cellulesEmprise(
                    (int) $meuble['x'], (int) $meuble['y'], (int) $meuble['l'], (int) $meuble['h'],
                );

                if ($type->bloque_mouvement) {
                    $obstacles = array_merge($obstacles, $cellules);
                    // Marque « c'est un MEUBLE » : ce que les Bracers of the Wild
                    // et l'Élixir de pas d'araignée peuvent lever sans lever un
                    // mur de glace (`Grille::franchirMobilier()`).
                    $meubles = array_merge($meubles, $cellules);
                }
                if ($type->bloque_vue) {
                    $opaques = array_merge($opaques, $cellules);
                }
            }
        }

        // Terrain (doc 18 §4, phase 4a) : cinquième couche de la carte, lue
        // dans CETTE MÊME MÉTHODE — jamais ailleurs — pour la même raison que
        // le mobilier ci-dessus : un second point de lecture ferait diverger
        // déplacement, ciblage et ligne de vue. Boucle SÉPARÉE de celle du
        // mobilier (catalogue distinct, `Mobilier` vs `Terrain`) mais qui en
        // partage le principe à la lettre : un terrain, comme un meuble, ne
        // passe JAMAIS par `Grille::occuper()` — ce n'est pas une figure — et
        // ses deux drapeaux sont lus INDÉPENDAMMENT, dans les MÊMES jeux de
        // cases ($obstacles/$opaques) que le mobilier, pour que déplacement,
        // ciblage et ligne de vue continuent de raisonner sur une seule
        // grille tactique cohérente.
        $terrain = (array) ($carte->grille['terrain'] ?? []);
        $couts = [];
        $sansArret = [];
        if ($terrain !== []) {
            $typesTerrain = Terrain::query()
                ->whereIn('id', array_values(array_unique(array_column($terrain, 'terrain_id'))))
                ->get(['id', 'cout_deplacement', 'bloque_mouvement', 'bloque_vue', 'effet'])
                ->keyBy('id');

            // ⚠ QUI BOUGE, encore une fois dit par `except*` (cf. plus haut) :
            // une grille bâtie pour un MONSTRE retire la figure de ce monstre.
            // Le livret immunise les monstres à la glace — « Monsters suffer
            // neither movement penalties nor damage from the icy river », « Monsters
            // cannot move onto ice slide squares » (Frozen Horror p. 5-6) —, et
            // c'est ICI, seule boucle du terrain, que la règle se lit
            // (`docs/plan-correctifs-2026-10-04.md` C2). Les DÉGÂTS et la fin de
            // tour du terrain ne sont appelés que dans le déplacement du héros :
            // cette moitié de la carte était déjà tenue par construction.
            $pourMonstre = $exceptInstanceId !== null;

            foreach ($terrain as $entree) {
                $type = $typesTerrain->get($entree['terrain_id'] ?? null);
                if ($type === null) {
                    continue;
                }

                $case = ['x' => (int) $entree['x'], 'y' => (int) $entree['y']];

                $effetTerrain = (array) ($type->effet ?? []);

                if ($type->bloque_mouvement || ($pourMonstre && ! empty($effetTerrain['interdit_aux_monstres']))) {
                    $obstacles[] = $case;
                }
                if ($type->bloque_vue) {
                    $opaques[] = $case;
                }
                // Coût de déplacement (Rivière Gelée = 2), lu par le parcours
                // pondéré (`Grille::parcoursPondere()`). Un monstre ne le paie
                // pas quand la tuile porte `ignore_par_monstres`.
                if ($type->cout_deplacement !== 1 && ! ($pourMonstre && ! empty($effetTerrain['ignore_par_monstres']))) {
                    // `entravant` (terrain GÊNANT de Jungles of Delthrak) : la
                    // case porte aussi son drapeau, pour que `Grille` sache
                    // QUOI lever quand Agile, un talent ou les Bracers
                    // l'ignorent (`Grille::ignorerTerrainEntravant()`).
                    $couts[] = [...$case, 'cout' => (int) $type->cout_deplacement,
                        'entravant' => ! empty($effetTerrain['entravant'])];
                }
                // Mare, Brasier : on les PASSE, on ne finit pas dessus. Un jeu
                // de cases à part (`Grille::interdireArret()`) — ni obstacle
                // (on traverse), ni figure (on voit à travers).
                if (! empty($effetTerrain['interdit_arret'])) {
                    $sansArret[] = $case;
                }
            }
        }

        // MUR DE GLACE (doc 18 §4, sort du boss — Ice Wall, plan glace phase
        // 2) : couche DÉDIÉE `carte.grille['glace']`, PAS le catalogue
        // `terrains` — cette pose est un effet de sort en cours de quête (le
        // précédent exact est `chausse_trappes`), là où `terrains` est un
        // catalogue de référence posé une fois à l'assemblage et dont une
        // entrée figurerait, à tort, dans le pool de tirage statique de
        // `AssembleurCarte::placerTerrains()`. « Bloque le déplacement mais
        // pas la vue » (texte de carte, mot pour mot) : `obstruer()`
        // SEULEMENT, jamais `occuper()` — un mur de glace n'est pas une
        // figure, il ne doit jamais entrer dans `$occupees` (le seul jeu que
        // `figuresBloquent` consulte), sinon il arrêterait les flèches
        // exactement comme le bug historique du mobilier. Boucle DÉDIÉE, dans
        // CETTE MÊME méthode — jamais ailleurs — pour la même raison que le
        // mobilier et le terrain au-dessus : un second point de lecture
        // ferait diverger déplacement, ciblage et ligne de vue.
        foreach ((array) ($carte->grille['glace'] ?? []) as $cellule) {
            $obstacles[] = ['x' => (int) $cellule['x'], 'y' => (int) $cellule['y']];
        }

        // CHUTE DE BLOCS DÉCLENCHÉE (contrat « Les trois pièges de sol »,
        // 2026-09-24 — livret p. 14 : « the trap space is now a permanent
        // block in the game »). Boucle DÉDIÉE, dans CETTE MÊME méthode —
        // jamais ailleurs, même raison que le mobilier/le terrain/le mur de
        // glace ci-dessus : deux points de lecture feraient diverger
        // déplacement, ciblage et ligne de vue. Bloque le mouvement ET LA VUE
        // — René, 2026-09-24 : « comme un mur » — donc `$obstacles` ET
        // `$opaques`, jamais `$occupees` : ce n'est pas une figure.
        foreach ((array) ($carte->grille['pieges'] ?? []) as $entree) {
            if (($entree['etat'] ?? null) === MoteurPieges::ETAT_BLOC) {
                $case = ['x' => (int) $entree['x'], 'y' => (int) $entree['y']];
                $obstacles[] = $case;
                $opaques[] = $case;
            }
        }

        // VOILE D'OMBRE (*Cloak of Shadows*, Wizards of Morcar, 2026-10-08) :
        // « The darkness blocks line of sight into and through it. » Couche
        // DÉDIÉE `carte.grille['ombre']`, lue dans CETTE MÊME méthode — jamais
        // ailleurs, même raison que la glace et les blocs ci-dessus. Les cases
        // vont dans `$opaques` (« through », comme un mur de vue) ET dans
        // `Grille::assombrir()` (« into » : une figure DANS l'ombre ne se voit
        // pas, et ne voit rien). Elles ne vont NI dans `$obstacles` — on marche
        // sous l'ombre — NI dans `$occupees` : ce n'est pas une figure.
        $ombre = app(MoteurOmbre::class)->cellules($carte);
        if ($ombre !== []) {
            $opaques = array_merge($opaques, $ombre);
            $grille->assombrir($ombre);
        }

        $grille->occuper($occupees);
        $grille->occuperAllie($alliees);
        $grille->obstruer($obstacles);
        $grille->marquerMobilier($meubles);
        $grille->occulter($opaques);
        $grille->definirCoutsDeplacement($couts);
        $grille->interdireArret($sansArret);

        return $grille;
    }
}
