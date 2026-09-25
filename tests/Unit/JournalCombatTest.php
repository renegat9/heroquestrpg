<?php

declare(strict_types=1);

use App\Partie\JournalCombat;

function lignes(array $resultat, string $acteur = 'Borin'): array
{
    return (new JournalCombat)->depuisResultat($resultat, $acteur);
}

describe('JournalCombat — restitution mécanique (aucun LLM)', function () {

    it('décrit une attaque de héros qui touche', function () {
        $l = lignes(['type' => 'attaque', 'degats' => 2, 'cible' => ['nom' => 'Gargouille']]);
        expect($l)->toHaveCount(1)
            ->and($l[0]['ton'])->toBe('degats')
            ->and($l[0]['texte'])->toBe('Borin touche Gargouille (−2 PV)');
    });

    it('marque une cible vaincue par le héros', function () {
        $l = lignes(['type' => 'attaque', 'degats' => 3, 'cible_vaincue' => true, 'cible' => ['nom' => 'Gobelin']]);
        expect($l[0]['ton'])->toBe('mort')
            ->and($l[0]['texte'])->toBe('Borin terrasse Gobelin !');
    });

    it('distingue le coup MANQUÉ du coup PARÉ — ce n\'est pas la même nouvelle', function () {
        // ⚠ Ces deux issues partageaient la même phrase (« X pare l'assaut de
        // Y ») jusqu'au 2026-08-13, où une partie réelle l'a mis en évidence :
        // « Gobelin pare l'assaut de Borin · 0 crâne ». Le gobelin n'avait rien
        // paré — Borin avait raté. Dire au joueur que l'armure adverse tient
        // quand ce sont ses dés qui échouent lui fait changer de tactique pour
        // une mauvaise raison.

        // 0 crâne : l'attaquant a manqué, le défenseur n'a rien fait.
        $manque = lignes(['type' => 'attaque', 'degats' => 0, 'touches' => 0, 'boucliers' => 0,
            'cible' => ['nom' => 'Gargouille']]);

        expect($manque[0]['ton'])->toBe('echec')
            ->and($manque[0]['texte'])->toContain('Borin manque Gargouille');

        // Des crânes, tous bloqués : le défenseur a VRAIMENT paré.
        $pare = lignes(['type' => 'attaque', 'degats' => 0, 'touches' => 2, 'boucliers' => 2,
            'cible' => ['nom' => 'Gargouille']]);

        expect($pare[0]['ton'])->toBe('pare')
            ->and($pare[0]['texte'])->toContain('Gargouille pare');
    });

    it('fait la même distinction pour le coup d\'un MONSTRE', function () {
        $manque = lignes(['type' => 'attaque_monstre', 'monstre' => 'Gobelin', 'degats' => 0,
            'touches' => 0, 'cible' => ['nom' => 'Borin']]);

        expect($manque[0]['ton'])->toBe('echec')
            ->and($manque[0]['texte'])->toContain('Gobelin manque Borin');

        $pare = lignes(['type' => 'attaque_monstre', 'monstre' => 'Gobelin', 'degats' => 0,
            'touches' => 1, 'boucliers' => 1, 'cible' => ['nom' => 'Borin']]);

        expect($pare[0]['ton'])->toBe('pare')
            ->and($pare[0]['texte'])->toContain('Borin pare');
    });

    it('annexe le détail des dés quand le payload les porte (C1)', function () {
        // Attaque : 3 crânes touchés, 1 bouclier paré → 2 dégâts.
        $l = lignes(['type' => 'attaque', 'degats' => 2, 'touches' => 3, 'boucliers' => 1,
            'cible' => ['nom' => 'Gargouille']]);
        expect($l[0]['texte'])->toBe('Borin touche Gargouille (−2 PV) · 3 crânes / 1 bouclier');

        // Parade complète (0 dégât) : le détail des dés reste visible.
        $pare = lignes(['type' => 'attaque', 'degats' => 0, 'touches' => 1, 'boucliers' => 1,
            'cible' => ['nom' => 'Gargouille']]);
        expect($pare[0]['ton'])->toBe('pare')
            ->and($pare[0]['texte'])->toContain('· 1 crâne / 1 bouclier');

        // Sort de dégâts : même détail.
        $sort = lignes(['type' => 'sort', 'degats' => 5, 'touches' => 5, 'boucliers' => 0,
            'sort' => ['nom' => 'Génie'], 'cible' => ['nom' => 'Momie']]);
        expect($sort[0]['texte'])->toContain('· 5 crânes / 0 bouclier');
    });

    it('restitue le tour des monstres avec les dégâts subis et la chute', function () {
        $resultat = [
            'type' => 'attaque',
            'degats' => 1,
            'cible' => ['nom' => 'Gargouille'],
            'tour_monstres' => ['actions' => [
                ['type' => 'attaque_monstre', 'monstre' => 'Gargouille', 'degats' => 2,
                    'cible' => ['nom' => 'Borin'], 'cible_tombee' => true],
                ['type' => 'deplacement_monstre', 'monstre' => 'Gobelin'], // ignoré (bruit)
            ]],
        ];
        $l = lignes($resultat);
        // 1 ligne héros + 2 lignes monstre (touche + chute) ; le déplacement est muet.
        expect($l)->toHaveCount(3)
            ->and($l[1]['ton'])->toBe('subit')
            ->and($l[1]['texte'])->toBe('Gargouille touche Borin (−2 PV)')
            ->and($l[2]['ton'])->toBe('chute')
            ->and($l[2]['texte'])->toBe("Borin s'effondre !");
    });

    it('restitue une fouille de zone réussie (auparavant muette)', function () {
        $l = lignes([
            'type' => 'jet', 'option_id' => 'fouiller', 'succes' => true,
            'pieges_reveles' => [['x' => 1, 'y' => 2]], 'portes_revelees' => [],
        ]);
        expect($l[0]['ton'])->toBe('succes')
            ->and($l[0]['texte'])->toContain('1 piège');
    });

    it('restitue une fouille de zone infructueuse', function () {
        $l = lignes(['type' => 'jet', 'option_id' => 'fouiller', 'succes' => false]);
        expect($l[0]['ton'])->toBe('echec');
    });

    it('décrit un sort offensif qui blesse', function () {
        $l = lignes([
            'type' => 'sort', 'sort' => ['nom' => 'Génie'],
            'degats' => 1, 'cible' => ['nom' => 'Gargouille'],
        ], 'Sylvara');
        expect($l[0]['ton'])->toBe('degats')
            ->and($l[0]['texte'])->toContain('Génie');
    });

    it('reste muet sur un simple déplacement', function () {
        expect(lignes(['type' => 'deplacement']))->toBe([]);
    });

    it('restitue un piège marché PENDANT un déplacement (clé plurielle)', function () {
        // Les pièges du chemin arrivent sous `pieges_declenches` (liste), pas
        // sous le `declenchement` singulier des coffres piégés : le héros
        // perdait ses PV en silence (test de jeu 2026-08-05).
        $l = lignes([
            'type' => 'deplacement',
            'pieges_declenches' => [[
                'type' => 'piege_declenche',
                'contexte' => 'deplacement',
                'piege' => ['nom' => 'Fosse', 'x' => 25, 'y' => 42],
                'personnage' => ['id' => 17, 'nom' => 'Krogar'],
                'degats' => 1,
                'tombe' => false,
                'immobilise' => true,
            ]],
        ], 'Krogar');

        expect($l)->toHaveCount(2)
            ->and($l[0]['texte'])->toBe('Fosse se déclenche sur Krogar !')
            ->and($l[0]['ton'])->toBe('subit')
            ->and($l[1]['texte'])->toBe('Krogar encaisse −1 PV')
            ->and($l[1]['ton'])->toBe('degats');
    });

    it('restitue CHAQUE piège quand un chemin en croise plusieurs', function () {
        $piege = fn (string $nom) => [
            'piege' => ['nom' => $nom],
            'personnage' => ['nom' => 'Krogar'],
            'degats' => 1,
        ];

        $l = lignes([
            'type' => 'deplacement',
            'pieges_declenches' => [$piege('Fosse'), $piege('Lames')],
        ], 'Krogar');

        expect($l)->toHaveCount(4)
            ->and($l[0]['texte'])->toContain('Fosse')
            ->and($l[2]['texte'])->toContain('Lames');
    });

    it('agrège action du héros + tour des alliés + tour des monstres dans l\'ordre', function () {
        $resultat = [
            'type' => 'attaque', 'degats' => 2, 'cible' => ['nom' => 'Gargouille'],
            'tour_allies' => ['actions' => [
                ['type' => 'attaque_allie', 'allie' => 'Archer', 'degats' => 1, 'cible' => ['nom' => 'Gobelin']],
            ]],
            'tour_monstres' => ['actions' => [
                // 1 crâne bloqué par 1 bouclier : une VRAIE parade.
                ['type' => 'attaque_monstre', 'monstre' => 'Gobelin', 'degats' => 0,
                    'touches' => 1, 'boucliers' => 1, 'cible' => ['nom' => 'Borin']],
            ]],
        ];
        $l = lignes($resultat);
        expect($l)->toHaveCount(3)
            ->and($l[0]['texte'])->toContain('Borin touche Gargouille')
            ->and($l[1]['texte'])->toContain('Archer touche Gobelin')
            ->and($l[2]['ton'])->toBe('pare');
    });
});

it('raconte un objet trouvé dans un meuble, au lieu de « fouille en vain »', function () {
    // ⚠ Signalé par René en partie réelle (2026-09-11) : « on gagne des items
    // quand on cherche mais le message dit cherche en vain ». L'issue `objet`
    // — celle du MOBILIER (`MoteurMobilier::tirerButin()`) — manquait au `match`
    // et tombait sur le `default`. `ChoixController` la connaissait pourtant
    // déjà (`'objet' => 'mobilier_objet'`) : la narration était juste, le fil
    // de combat mentait.
    $l = lignes([
        'type' => 'fouille_mobilier', 'issue' => 'objet',
        'mobilier' => 'Coffre', 'objet' => ['nom' => 'Épée large'],
    ], 'Krogar');

    $textes = collect($l)->pluck('texte')->join(' | ');

    expect($textes)->toContain('Épée large')
        ->and($textes)->not->toContain('en vain');
});

it('distingue « rien à prendre » de « rien pour vous »', function () {
    // Butin tiré mais qu'AUCUN héros engagé ne peut utiliser (règle de l'étal :
    // « un meuble ne rend plus une potion que personne sur place ne peut boire »).
    $indispo = collect(lignes([
        'type' => 'fouille_mobilier', 'issue' => 'rien',
        'mobilier' => 'Armoire', 'objet_indisponible' => true,
    ], 'Krogar'))->pluck('texte')->join(' | ');

    // Meuble réellement vide.
    $vide = collect(lignes([
        'type' => 'fouille_mobilier', 'issue' => 'rien', 'mobilier' => 'Armoire',
    ], 'Krogar'))->pluck('texte')->join(' | ');

    expect($indispo)->not->toContain('en vain')
        ->and($indispo)->toContain('utiliser')
        ->and($vide)->toContain('en vain');
});

describe('JournalCombat::desJetUnilateral() — jets à une seule volée (2026-09-24)', function () {
    // ⚠ Les dés étaient CALCULÉS, PUBLIÉS, et DESSINÉS NULLE PART (René :
    // « pour les sorts d'attaque avec un lancer de dés pour résister, on ne
    // voit pas le lancer de dé »). Ces tests fixent la détection DIRECTEMENT,
    // avant de vérifier qu'elle est bien câblée dans le fil (plus bas).

    it('un dé rouge de résistance réussit sur 5 OU 6, jamais un crâne', function () {
        $des = (new JournalCombat)->desJetUnilateral([
            'des_resistance' => [1, 5, 6, 3], 'degats_annules' => 2,
            'cible' => ['nom' => 'Golem'],
        ]);

        expect($des['atk'])->toBe([])
            ->and($des['def'])->toBe([1, 5, 6, 3])
            ->and($des['defensive'])->toBe([5, 6])
            ->and($des['defenseur'])->toBe('Golem')
            ->and($des['boucliers'])->toBe(2)
            ->and($des['libelle_def'])->toBe('résiste');
    });

    it('la MÊME mécanique publiée sous `des_rouges` (Boule de Flammes du MJ) se dessine pareil', function () {
        // « One rule, both sides » (docs/regles/sorts-dread.md) : le sort du MJ
        // publie sa résistance sous un autre NOM que celui du héros, pas une
        // autre RÈGLE.
        $des = (new JournalCombat)->desJetUnilateral(['des_rouges' => [2, 6], 'cible' => ['nom' => 'Thora']]);

        expect($des['def'])->toBe([2, 6])
            ->and($des['defensive'])->toBe([5, 6]);
    });

    it('un jet de Mind réussit sur un crâne, le MÊME enum de faces que le combat', function () {
        $des = (new JournalCombat)->desJetUnilateral([
            'mind_cible' => 3, 'faces' => ['crane', 'bouclier_blanc'], 'succes' => 1,
            'cible' => ['nom' => 'Golem'],
        ]);

        expect($des['def'])->toBe(['crane', 'bouclier_blanc'])
            ->and($des['defensive'])->toBe('crane')
            ->and($des['boucliers'])->toBe(1)
            ->and($des['defenseur'])->toBe('Golem');
    });

    it('un jet de Mind IMMUNISÉ (Mind 0, faces vides) ne dessine RIEN', function () {
        // Le test explicite du brief : « un jet de résistance nul (cible sans
        // dés) ne dessine rien de vide ».
        expect((new JournalCombat)->desJetUnilateral(['mind_cible' => 0, 'faces' => []]))->toBeNull();
    });

    it('un dé rouge sans dé lancé (tableau vide) ne dessine rien non plus', function () {
        expect((new JournalCombat)->desJetUnilateral(['des_resistance' => [], 'cible' => ['nom' => 'Gobelin']]))
            ->toBeNull();
    });

    it('un piège de sol ATTAQUE seul, sans défense en face', function () {
        $des = (new JournalCombat)->desJetUnilateral([
            'piege' => ['nom' => 'Chute de blocs'], 'faces' => ['crane', 'crane', 'bouclier_blanc'], 'touches' => 2,
        ], 'Krogar');

        expect($des['atk'])->toHaveCount(3)
            ->and($des['def'])->toBe([])
            ->and($des['touchante'])->toBe('crane')
            ->and($des['attaquant'])->toBe('Chute de blocs')
            ->and($des['defenseur'])->toBe('Krogar')
            ->and($des['touches'])->toBe(2);
    });

    it('rend null quand aucune des trois formes n\'est publiée', function () {
        expect((new JournalCombat)->desJetUnilateral(['type' => 'deplacement']))->toBeNull();
    });
});

it('dessine les dés ROUGES d\'un sort de héros à dégâts fixes (Boule de Feu, Trait de Feu)', function () {
    $l = lignes([
        'type' => 'sort', 'sort' => ['nom' => 'Boule de Flammes'],
        'degats_fixes' => 3, 'des_resistance' => [2, 5, 6], 'degats_annules' => 2,
        'degats' => 1, 'cible' => ['nom' => 'Gargouille'],
    ], 'Aldric');

    expect($l[0]['des'])->not->toBeNull()
        ->and($l[0]['des']['def'])->toBe([2, 5, 6])
        ->and($l[0]['des']['defensive'])->toBe([5, 6])
        ->and($l[0]['des']['defenseur'])->toBe('Gargouille');
});

it('ne dessine rien pour un sort à dés rouges dont la cible n\'a lancé aucun dé', function () {
    $l = lignes([
        'type' => 'sort', 'sort' => ['nom' => 'Trait de Feu'],
        'degats_fixes' => 2, 'des_resistance' => [], 'degats_annules' => 0,
        'degats' => 2, 'cible' => ['nom' => 'Gobelin'],
    ], 'Aldric');

    expect($l[0])->not->toHaveKey('des');
});

it('dessine le jet de Mind d\'un sort de héros à résistance jet_mind (Sommeil, Terreur)', function () {
    $l = lignes([
        'type' => 'sort', 'sort' => ['nom' => 'Sommeil'],
        'cible' => ['nom' => 'Golem'], 'mind_cible' => 3,
        'issue' => 'subit_effet', 'succes' => 1, 'difficulte' => 1,
        'faces' => ['crane', 'bouclier_blanc', 'bouclier_noir'], 'effet_applique' => true,
    ], 'Sylvara');

    expect($l[0]['des']['def'])->toBe(['crane', 'bouclier_blanc', 'bouclier_noir'])
        ->and($l[0]['des']['defensive'])->toBe('crane')
        ->and($l[0]['des']['defenseur'])->toBe('Golem')
        ->and($l[0]['des']['boucliers'])->toBe(1);
});

it('dessine le jet de Mind du sort du MJ contre un héros, AU MOMENT où il frappe', function () {
    // ⚠ C'est le défaut nommé : `MoteurDread::sortDreadControle()` publiait déjà
    // `faces`/`mind_cible` par victime, et seul `ruptureSortDread()` les lisait
    // — APRÈS coup, pour BRISER la condition, jamais au moment où le sort prend.
    $l = lignes([
        'type' => 'sort_dread', 'sort' => 'Sommeil', 'condition' => 'Endormi',
        'resultats' => [[
            'cible' => ['personnage_id' => 9, 'nom' => 'Borin'],
            'mind_cible' => 2, 'issue' => 'subit_effet', 'succes' => 0, 'difficulte' => 1,
            'faces' => ['bouclier_blanc', 'bouclier_noir'],
            'effet_applique' => true,
        ]],
    ], 'Le Gardien');

    expect($l)->toHaveCount(1)
        ->and($l[0]['texte'])->toBe('Borin subit Sommeil — Endormi')
        ->and($l[0]['des']['def'])->toBe(['bouclier_blanc', 'bouclier_noir'])
        ->and($l[0]['des']['defensive'])->toBe('crane')
        ->and($l[0]['des']['defenseur'])->toBe('Borin');
});

it('dessine aussi le jet de Mind quand le héros RÉSISTE au sort du MJ', function () {
    $l = lignes([
        'type' => 'sort_dread', 'sort' => 'Terreur', 'condition' => 'Apeuré',
        'resultats' => [[
            'cible' => ['personnage_id' => 9, 'nom' => 'Grom'],
            'mind_cible' => 4, 'issue' => 'resiste', 'succes' => 2, 'difficulte' => 1,
            'faces' => ['crane', 'crane', 'bouclier_blanc', 'bouclier_noir'],
            'effet_applique' => false,
        ]],
    ], 'Le Gardien');

    expect($l[0]['texte'])->toBe('Grom résiste à Terreur')
        ->and($l[0]['des']['boucliers'])->toBe(2);
});

it('dessine les dés rouges d\'une Boule de Flammes lancée par le MJ (`des_rouges`)', function () {
    $l = lignes([
        'type' => 'sort_dread', 'sort' => 'Boule de Flammes',
        'resultats' => [[
            'cible' => ['personnage_id' => 3, 'nom' => 'Thora'],
            'degats' => 1, 'pv_body_apres' => 5, 'cible_tombee' => false,
            'des_rouges' => [1, 5, 3], 'degats_bruts' => 3,
        ]],
    ], 'Le Gardien');

    expect($l[0]['texte'])->toBe('Boule de Flammes frappe Thora (−1 PV)')
        ->and($l[0]['des']['def'])->toBe([1, 5, 3])
        ->and($l[0]['des']['defensive'])->toBe([5, 6]);
});

it('dessine le(s) dé(s) d\'un piège de sol — payload FABRIQUÉ, contrat § « Les trois pièges de sol »', function () {
    // ⚠ `MoteurPieges` ne publie pas encore `faces`/`touches` au moment de ce
    // test (un autre agent porte ce chantier) : la forme est fixée par le
    // contrat, ce test la fabrique plutôt que d'attendre le jeu réel.
    $unDe = lignes([
        'type' => 'piege_declenche',
        'piege' => ['nom' => 'Piège à lances'],
        'personnage' => ['id' => 4, 'nom' => 'Krogar'],
        'degats' => 1, 'tombe' => false,
        'faces' => ['crane'], 'touches' => 1,
    ], 'Krogar');

    expect($unDe[0]['des']['atk'])->toBe(['crane'])
        ->and($unDe[0]['des']['touchante'])->toBe('crane')
        ->and($unDe[0]['des']['attaquant'])->toBe('Piège à lances')
        ->and($unDe[0]['des']['defenseur'])->toBe('Krogar');

    $troisDes = lignes([
        'type' => 'piege_declenche',
        'piege' => ['nom' => 'Chute de blocs'],
        'personnage' => ['id' => 4, 'nom' => 'Krogar'],
        'degats' => 2, 'tombe' => false,
        'faces' => ['crane', 'crane', 'bouclier_blanc'], 'touches' => 2,
    ], 'Krogar');

    expect($troisDes[0]['des']['atk'])->toHaveCount(3)
        ->and($troisDes[0]['des']['touches'])->toBe(2);
});

it('dessine aussi les dés d\'un piège NESTED dans un déplacement (chemin croisé)', function () {
    $l = lignes([
        'type' => 'deplacement',
        'pieges_declenches' => [[
            'type' => 'piege_declenche',
            'piege' => ['nom' => 'Piège à lances'],
            'personnage' => ['id' => 17, 'nom' => 'Krogar'],
            'degats' => 1, 'faces' => ['crane'], 'touches' => 1,
        ]],
    ], 'Krogar');

    expect($l[0]['des']['attaquant'])->toBe('Piège à lances');
});

it('ne dessine rien pour un piège SANS faces publiées (fosse — aucun dé lancé)', function () {
    $l = lignes([
        'type' => 'piege_declenche',
        'piege' => ['nom' => 'Fosse'],
        'personnage' => ['id' => 4, 'nom' => 'Krogar'],
        'degats' => 1, 'tombe' => false, 'immobilise' => true,
    ], 'Krogar');

    expect($l[0])->not->toHaveKey('des');
});

it('distingue un levier forcé d\'un levier qui résiste', function () {
    // ⚠ Signalé par René en partie réelle (2026-09-11) : « on a un levier dans
    // un corridor qui est supposé ouvrir une porte dans la salle suivante mais
    // ça ne fait rien ». Le jet avait ÉCHOUÉ (3 dés, 0 succès) — le moteur avait
    // raison. Mais le fil de combat était MUET sur les leviers, et la narration
    // annonçait « la pierre gronde au loin » sans regarder le jet. Un levier de
    // couloir ouvre une porte hors de vue : ces lignes sont la SEULE chose qui
    // dise ce qui s'est passé.
    $echec = collect(lignes([
        'type' => 'actionner_levier',
        'jet' => ['issue' => 'echec', 'succes' => 0, 'difficulte' => 2],
        'portes_ouvertes' => [],
    ], 'Krogar'))->pluck('texte')->join(' | ');

    $reussite = collect(lignes([
        'type' => 'actionner_levier',
        'jet' => ['issue' => 'reussite', 'succes' => 2, 'difficulte' => 2],
        'portes_ouvertes' => [['x' => 36, 'y' => 30]],
    ], 'Krogar'))->pluck('texte')->join(' | ');

    // ⚠ L'échec doit dire qu'on peut RECOMMENCER : le forçage est retentable
    // sans limite, et sans cette mention le groupe lit un cul-de-sac.
    expect($echec)->toContain('sans succès')
        ->and($echec)->toContain('réessayer')
        ->and($reussite)->toContain("porte s'ouvre")
        ->and($reussite)->not->toContain('réessayer');
});
