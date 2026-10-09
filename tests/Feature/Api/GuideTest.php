<?php

declare(strict_types=1);

use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;

/**
 * Guide / compendium PUBLIC (GET /api/guide) : données de référence en lecture
 * seule, servies SANS authentification (la page /guide s'ouvre depuis l'accueil
 * sans compte). Renvoie les catalogues seedés + les descriptions de talents.
 */
beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class,
        MonstreSeeder::class, ObjetSeeder::class, SortSeeder::class, PiegeSeeder::class,
        MobilierSeeder::class,
    ]);
});

it('sert le compendium complet sans authentification', function () {
    $data = $this->getJson('/api/guide')->assertOk()->json();

    // Toutes les rubriques présentes et non vides.
    foreach (['classes', 'competences', 'monstres', 'objets', 'sorts', 'pieges'] as $cle) {
        expect($data[$cle] ?? [])->not->toBeEmpty("Rubrique {$cle} vide.");
    }

    // Les 12 classes, chacune avec ses stats de base : 4 historiques + les 8
    // d'extension sourcées sur carte (2026-08-12).
    expect(collect($data['classes'])->pluck('nom')->sort()->values()->all())
        ->toBe(['barbare', 'barde', 'berserker', 'chevalier', 'druide', 'elfe', 'explorateur', 'magicien', 'moine', 'nain', 'rogue', 'warlock']);
    expect($data['classes'][0])->toHaveKeys(['nom', 'pv_body', 'pv_mind', 'des_attaque', 'des_defense', 'deplacement_base']);

    // Les talents portent leur description (correctif précédent).
    expect(collect($data['competences'])->every(fn ($t) => ! empty($t['description'])))->toBeTrue();

    // Un monstre expose ses stats + capacités (tableau).
    expect($data['monstres'][0])->toHaveKeys(['nom_base', 'deplacement', 'attaque', 'defense', 'pv_body', 'pv_mind', 'tier', 'cout', 'capacites']);

    // Un objet expose catégorie / rareté / prix / effet.
    expect($data['objets'][0])->toHaveKeys(['nom', 'categorie', 'rarete', 'prix_base', 'emplacement', 'effet']);

    // Un sort expose élément / type / difficulté / effet.
    expect($data['sorts'][0])->toHaveKeys(['element', 'nom', 'type', 'difficulte_parchemin', 'effet']);
});

it('expose les maîtrises d\'équipement des deux côtés (classe et objet)', function () {
    $data = $this->getJson('/api/guide')->assertOk()->json();

    // Sans ces deux champs, la restriction n'apparaissait NULLE PART dans le
    // guide : le joueur ne l'apprenait qu'au refus, en essayant d'équiper.
    $classes = collect($data['classes'])->keyBy('nom');
    expect($classes['magicien']['tags_equipement'])->toBeArray()
        ->and($classes['magicien']['tags_equipement'])->not->toContain('armure_legere')
        ->and($classes['barbare']['tags_equipement'])->toContain('arme_deux_mains')
        ->and($classes['nain']['tags_equipement'])->toContain('armure_lourde');

    // Chaque arme/armure porte la maîtrise qu'elle EXIGE — sauf celles dont la
    // carte n'énonce AUCUNE restriction de classe, qui n'ont légitimement pas de
    // tag (`verifierAccesEquipement` les laisse passer). Elles sont nommées ici
    // pour qu'un tag oublié ne se cache pas derrière la même absence.
    // ⚠ Les BRASSARDS ont rejoint la liste le 2026-08-15 : leur carte
    // officielle ne les réserve à personne (« May be combined with the helmet
    // and/or shield », rien de plus), là où nous en faisions une pièce du
    // magicien. Le tag a sauté avec la restriction.
    // ⚠ « Anneau de Vigueur » rejoint la liste le 2026-09-03 : sa carte
    // (*Ring of Fortitude*) ne pose AUCUNE restriction — « raises a hero's Body
    // Points by 1 », rien de plus. Un talisman sans maîtrise, comme les autres
    // anneaux de cette liste.
    $sansMaitrise = ['Talisman du Savoir', 'Anneau de Sort', 'Anneau de Feu', 'Brassards',
        'Anneau de Vigueur',
        // ⚠ Cape des Ombres et Sceptre de Télékinésie : leurs cartes ne posent
        // AUCUNE restriction de classe — ni « may not be used by the wizard »,
        // ni réservation à un héros. Leur inventer un tag serait une invention.
        'Cape des Ombres', 'Sceptre de Télékinésie',
        // Élixir de Vie et Bracelet de Guérison : leurs cartes ne posent aucune
        // restriction de classe non plus.
        'Élixir de Vie', 'Bracelet de Guérison', 'Cendres du Phénix',
        // ⚠ 2026-09-04. Les *Rabbit Boots* se chaussent par n'importe qui, la
        // *Bone Wand* « enables ANY hero », l'*Anneau du Retour* ne nomme
        // personne. Leur inventer un tag serait exactement l'invention que
        // §2.1bis interdit — et pour les bottes, ce serait pire : leur seule
        // vraie limite est « une fois par tour », qui vit ailleurs.
        'Bottes de Lièvre', "Baguette d'Os", 'Anneau du Retour',
        // ⚠ 2026-09-10 : Orbe Céleste, Anneau de Chaleur et Raquettes de
        // Vitesse ne posent non plus aucune restriction de classe sur leur
        // carte — mêmes raisons que ci-dessus.
        'Orbe Céleste', 'Anneau de Chaleur', 'Raquettes de Vitesse',
        // ⚠ 2026-10-08 (Wizards of Morcar) : la carte n'interdit la Cuirasse
        // qu'au MAGICIEN. Un tag de poids (`armure_legere`) l'aurait aussi
        // fermée au Druide, au Rogue… — d'où `classe_interdite`, sans tag.
        'Cuirasse de Peau de Dragon',
        // ⚠ 2026-10-08 (vague 2B) : Urdyn n'a aucun tag — « When using this
        // hammer », la carte ne restreint aucune classe.
        'Urdyn le Défaiseur'];

    $portables = collect($data['objets'])
        ->whereIn('categorie', ['arme', 'armure'])
        ->reject(fn ($o) => in_array($o['nom'], $sansMaitrise, true));

    expect($portables)->not->toBeEmpty()
        ->and($portables->every(fn ($o) => ! empty($o['tag_equipement'])))->toBeTrue();

    // …et les nœuds de déblocage restent lisibles pour croiser les deux.
    $deblocages = collect($data['competences'])
        ->filter(fn ($c) => ($c['effet']['mecanique'] ?? null) === 'acces_equipement');
    expect($deblocages)->not->toBeEmpty()
        ->and($deblocages->every(fn ($c) => is_array($c['effet']['tags'] ?? null)))->toBeTrue();
});

it('documente TOUS les objets : chacun porte un effet non vide', function () {
    $objets = $this->getJson('/api/guide')->assertOk()->json('objets');

    // Un objet sans effet est une pièce que le guide ne peut pas décrire — et,
    // le plus souvent, une pièce que le moteur n'applique pas non plus.
    $muets = collect($objets)->filter(fn ($o) => empty($o['effet']))->pluck('nom')->all();

    expect($muets)->toBe([], 'Objets sans effet : '.implode(', ', $muets));
});

it('trie le bestiaire par palier puis coût', function () {
    $monstres = $this->getJson('/api/guide')->assertOk()->json('monstres');

    $rang = ['base' => 0, 'sous_boss' => 1, 'boss' => 2];
    $precedent = -1;
    foreach ($monstres as $m) {
        expect($rang[$m['tier']])->toBeGreaterThanOrEqual($precedent);
        $precedent = $rang[$m['tier']];
    }
});

it('expose la provenance des cartes, portées et non portées', function () {
    $data = $this->getJson('/api/guide')->assertOk()->json();

    // Trois paquets exposés : sans eux la page /guide affichait un catalogue
    // sans jamais dire d'où viennent ses prix et ses dés. Le paquet fan Sjeng a
    // cédé la place aux photos du matériel officiel, scindées en équipement et
    // potions comme les deux PDF de René.
    $paquets = collect($data['cartes'] ?? []);
    // ⚠ Un QUATRIÈME paquet depuis le 2026-09-03 : les parchemins ont leur
    // section, parce qu'ils dérivent d'un SORT et n'ont pas de ligne d'objet.
    // ⚠ Un CINQUIÈME depuis le 2026-09-04 : les sorts de Dread. Ils
    // n'appartiennent à aucun héros et ne s'achètent nulle part, mais ce sont
    // eux que la table subit — et /guide est la seule page qui dise d'où vient
    // ce qui vous tombe dessus.
    expect($paquets->pluck('cle')->all())->toBe(['equipement', 'potions', 'artefacts', 'parchemins', 'dread', 'sorts_heros']);

    $cartes = $paquets->flatMap(fn ($p) => $p['cartes']);
    // 20 + 15 + 38 + 19 + 29 + 9 — artefacts : +1 Cor des Hearthkin (First Light,
    // 2026-09-30) puis +2 armes en os (Against the Ogre Horde, lot B, 2026-10-02),
    // puis +5 cartes de Wizards of Morcar (vague 1b, 2026-10-08) ; les neuf sorts
    // de héros de Morcar forment le sixième paquet (2026-10-08). +18 cartes de
    // sorts de Dread (vague 2A : Storm Master, High Mage, Necromancer) et +12
    // (vague 2B : Orc Warcaster, Artificer) = 165.
    expect($cartes)->toHaveCount(165);

    // Chaque carte dit si elle est portée, et celles qui ne le sont pas
    // annoncent leur texte de plateau ET la mécanique qui leur manque.
    foreach ($cartes as $carte) {
        expect($carte['porte'])->toBeBool();

        if (! $carte['porte']) {
            expect($carte['texte'])->not->toBeEmpty("{$carte['carte']} : texte de carte manquant")
                ->and($carte['manque'])->not->toBeEmpty("{$carte['carte']} : mécanique manquante non dite");
        }
    }

    // …et une carte portée pointe bien un objet réel du catalogue.
    $noms = collect($data['objets'])->pluck('nom')->all();
    // ⚠ Le paquet des PARCHEMINS est exclu de ce contrôle : une carte de
    // parchemin portée pointe un SORT, pas un objet — elle n'a aucune ligne à
    // retrouver au catalogue d'objets. Le contrôle vaut pour les trois paquets
    // qui, eux, désignent des pièces.
    // ⚠ Le paquet des SORTS DE DREAD est exclu pour la même raison : une carte
    // de Dread portée pointe une ligne de `sorts_dread`, pas un objet.
    // ⚠ Le paquet des SORTS DE HÉROS de Morcar l'est aussi : un sort porté pointe
    // une ligne de `sorts`.
    $cartesObjet = $paquets->reject(fn ($p) => in_array($p['cle'], ['parchemins', 'dread', 'sorts_heros'], true))
        ->flatMap(fn ($p) => $p['cartes']);

    foreach ($cartesObjet->where('porte', true) as $carte) {
        expect(in_array($carte['nom'], $noms, true))
            ->toBeTrue("{$carte['carte']} → « {$carte['nom']} » absent du catalogue exposé.");
    }
});


it('publie ce que fait chaque objet, déjà traduit par le serveur', function () {
    $objets = collect($this->getJson('/api/guide')->assertOk()->json('objets'));

    // Le client ne retraduit plus la clé mécanique : `avantages` est la décision
    // du serveur, la même que le sac du téléphone et le livret.
    $sans = $objets->filter(fn ($o) => ! empty($o['effet']) && empty($o['avantages']))->pluck('nom')->all();
    expect($sans)->toBe([], 'Objets sans texte d\'effet publié : '.implode(', ', $sans));
});

it('publie le mobilier avec ses points de vie quand il est attaquable', function () {
    $mobiliers = collect($this->getJson('/api/guide')->assertOk()->json('mobiliers'))->keyBy('nom');

    expect($mobiliers)->not->toBeEmpty()
        ->and($mobiliers['Haut Autel']['attaquable'])->toBeTrue()
        ->and($mobiliers['Haut Autel']['pv_body'])->toBe(6)
        ->and($mobiliers['Mur de Pierre']['defense_dice'])->toBe(6)
        ->and($mobiliers['Table']['attaquable'])->toBeFalse();
});

it('publie les thèmes de campagne sous leur libellé, jamais un identifiant brut', function () {
    $data = $this->getJson('/api/guide')->assertOk()->json();

    $themes = collect($data['themes']);
    expect($themes->pluck('cle')->all())->toBe(\App\Partie\DemarreurQuete::BOITES_THEMATIQUES)
        ->and($themes->every(fn ($t) => $t['libelle'] !== $t['cle']))->toBeTrue()
        ->and($themes->pluck('libelle'))->toContain('Wizards of Morcar');

    // Chaque créature d'extension nomme sa boîte.
    $morcar = collect($data['monstres'])->firstWhere('nom_base', 'Artificière');
    expect($morcar['boite_libelle'])->toBe('Wizards of Morcar');
});
