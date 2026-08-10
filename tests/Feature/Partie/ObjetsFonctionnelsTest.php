<?php

declare(strict_types=1);

use App\Engine\MotsClesEquipement;
use App\Models\Objet;
use App\Models\Sort;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\SortSeeder;

/**
 * Les objets du catalogue sont-ils FONCTIONNELS ?
 *
 * Chaque clé de `objets.effet` est une promesse faite au joueur. Le projet a
 * déjà dû retirer `attaque_second_rang` (mécanisme inexistant) et `ligne_de_vue`
 * (clé sans lecteur) : ce sont des « clés décoratives », des règles annoncées
 * que le moteur n'applique pas.
 *
 * L'inventaire des clés vit désormais dans `App\Engine\MotsClesEquipement`
 * (ACTIVES / INERTES) et non plus ici : deux listes parallèles finissent par
 * diverger, et c'est la liste du CODE qui doit être opposable — le guide et la
 * doc s'y réfèrent. Ce fichier n'en est plus que le garde-fou : ajouter une clé
 * au seeder casse le test tant qu'on n'a pas tranché — lui écrire un lecteur,
 * ou la déclarer inerte en connaissance de cause.
 */
// SortSeeder D'ABORD : les parchemins sont dérivés des sorts (ObjetSeeder
// boucle sur Sort::all()), l'ordre inverse n'en crée aucun.
beforeEach(function () {
    $this->seed([SortSeeder::class, ObjetSeeder::class]);
});

it('n\'introduit aucune clé d\'effet inconnue dans le catalogue', function () {
    $cles = collect(Objet::all())
        ->flatMap(fn (Objet $o) => array_keys((array) $o->effet))
        ->unique()
        ->sort()
        ->values()
        ->all();

    $inconnues = array_values(array_filter(
        $cles,
        fn (string $cle) => ! MotsClesEquipement::estConnue($cle),
    ));

    expect($inconnues)->toBe([], implode(', ', $inconnues)
        .' — clé(s) d\'effet sans lecteur connu. Écris-lui un lecteur dans le moteur, '
        .'ou déclare-la dans MotsClesEquipement::INERTES si elle est décorative '
        .'en connaissance de cause.');
});

it('n\'accumule pas de mot-clé d\'équipement que plus aucun objet ne porte', function () {
    // L'inverse du test précédent : un mot déclaré que le catalogue n'utilise
    // plus est une règle qui n'existe que sur le papier. C'est exactement ce
    // qu'était `attaque_second_rang` avant qu'on le retire — déclaré, lu par le
    // guide, porté par une Lance… et sans mécanique derrière.
    $portees = collect(Objet::all())
        ->flatMap(fn (Objet $o) => array_keys((array) $o->effet))
        ->unique()
        ->all();

    $orphelins = array_values(array_diff(MotsClesEquipement::toutes(), $portees));

    expect($orphelins)->toBe([], 'mot(s)-clé déclaré(s) que plus aucun objet ne porte : '
        .implode(', ', $orphelins).' — retire-le du vocabulaire, ou donne-lui un porteur.');
});

it('garde la difficulté des parchemins synchronisée avec celle du sort', function () {
    // `objets.effet.difficulte_non_lanceur` est inerte : ResolveurTour roule
    // contre `sorts.difficulte_parchemin`. La copie est aujourd'hui exacte, mais
    // rien ne l'y oblige — si elle dérive, le guide documente une difficulté que
    // le jeu ne lance pas.
    $parchemins = Objet::where('categorie', 'parchemin')->get();
    expect($parchemins)->not->toBeEmpty();

    foreach ($parchemins as $parchemin) {
        $effet = (array) $parchemin->effet;
        $sort = Sort::find($effet['sort_id'] ?? 0);

        expect($sort)->not->toBeNull("{$parchemin->nom} : sort_id introuvable.")
            ->and((int) ($effet['difficulte_non_lanceur'] ?? -1))
            ->toBe((int) $sort->difficulte_parchemin, "{$parchemin->nom} : difficulté affichée ≠ difficulté lancée.");
    }
});

it('donne à toute arme et armure des dés, et à tout consommable un effet réel', function () {
    // Une arme ou une armure doit changer QUELQUE CHOSE au porteur : des dés,
    // des dégâts garantis (Dague de jet magique) ou une jauge maximale
    // (talismans de classe). Sans ça, l'équiper est un clic pour rien.
    $utilesPortes = ['des_attaque', 'des_defense', 'degats_fixes',
        'bonus_pv_body_max', 'bonus_pv_mind_max',
        // Artefacts d'économie de sorts : ils ne donnent ni dé ni PV, mais
        // changent bel et bien ce que le porteur peut faire de son tour.
        'restaure_sorts', 'second_sort_par_tour', 'sort_non_epuise',
        'sort_non_epuise_sur_bouclier_noir'];

    foreach (Objet::whereIn('categorie', ['arme', 'armure'])->get() as $piece) {
        expect(array_intersect($utilesPortes, array_keys((array) $piece->effet)))
            ->not->toBeEmpty("{$piece->nom} : ne change rien au porteur.");
    }

    // Un consommable doit soigner, retirer une condition, ou poser un buff —
    // sans quoi le boire est un clic pour rien.
    $utiles = ['soin_pv_body', 'soin_pv_body_de', 'soin_pv_mind', 'retire_condition',
        'bonus_des_attaque', 'bonus_des_defense', 'attaque_supplementaire',
        'restaure_sorts'];

    foreach (Objet::where('categorie', 'consommable')->get() as $potion) {
        expect(array_intersect($utiles, array_keys((array) $potion->effet)))
            ->not->toBeEmpty("{$potion->nom} : aucun effet que MoteurPotions sache appliquer.");
    }
});
