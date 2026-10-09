<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Engine\MotsClesEquipement;
use App\Jobs\GenererMenu;
use App\Models\Carte;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\GroupeMercenaire;
use App\Models\InstanceMonstre;
use App\Models\Joueur;
use App\Models\Inventaire;
use App\Models\Mercenaire;
use App\Models\Mobilier;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Piege;
use App\Models\Quete;
use App\Partie\AlliesInvoques;
use App\Partie\Equipement;
use App\Partie\Fouille\DeckFouille;
use App\Partie\Marche\PhaseMarche;
use App\Partie\MoteurPotions;
use App\Partie\MoteurSorts;
use App\Partie\ResolveurTour;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MercenaireSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/**
 * JUNGLES OF DELTHRAK — LE BUTIN (chantier A, 2026-10-09) : les six artefacts et
 * les deux trésors-valeurs du livret p. 50, les trois potions de l'Alchimiste du
 * livret p. 2. Chaque clé de vocabulaire neuve est prouvée EN JEU.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MercenaireSeeder::class,
        MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, MobilierSeeder::class,
    ]);
});

/** Range un objet au sac du héros et rend sa ligne. */
function ranger(Personnage $heros, string $nom, string $emplacement = 'sac'): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::where('nom', $nom)->value('id'),
        'emplacement' => $emplacement,
        'quantite' => 1,
    ]);
}

/**
 * Scène minimale : un couloir 9×3 de sol, un héros en (1,1), une Table en (3,1).
 *
 * @return array{groupe: Groupe, quete: Quete, heros: Personnage, etat: EtatPersonnageQuete}
 */
function sceneDelthrak(array $herosAttrs = [], array $mobilier = [], array $pieges = [], string $theme = 'jungles_delthrak', bool $etroit = false): array
{
    $groupe = creerGroupe('table-delthrak-'.uniqid());
    $groupe->update(['theme_bestiaire' => $theme]);
    $gabarit = GabaritQuete::query()->firstOrFail();

    $quete = Quete::create([
        'groupe_id' => $groupe->id, 'gabarit_id' => $gabarit->id, 'titre' => 'Quête de test — butin de Delthrak',
        'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours', 'or_initial' => 0,
    ]);

    $cases = array_fill(0, 3, array_fill(0, 9, 's'));

    // Couloir ÉTROIT : une seule rangée de sol — aucun détour possible, donc le
    // trajet traverse vraiment ce qui s'y trouve.
    if ($etroit) {
        $cases[0] = array_fill(0, 9, 'm');
        $cases[2] = array_fill(0, 9, 'm');
    }

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 9, 'hauteur' => 3,
        'grille' => [
            'largeur' => 9, 'hauteur' => 3, 'cases' => $cases,
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => 9, 'hauteur' => 3, 'theme' => 'generique', 'mediane_x' => 4, 'mediane_y' => 1]],
            'portes' => [], 'leviers' => [], 'pieges' => $pieges, 'mobilier' => $mobilier, 'epreuves' => [],
            'terrain' => [], 'glace' => [], 'spawn_heros' => [['x' => 1, 'y' => 1]], 'spawn_monstres' => [], 'aretes' => [],
        ],
    ]);

    $groupe->update(['quete_courante_id' => $quete->id, 'phase' => 'quete']);

    $joueur = connecterJoueur('delthrak-'.uniqid());
    $heros = creerHeros($joueur, $groupe, 'Testeur', 1, $herosAttrs);

    $etat = EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $heros->id,
        'position_x' => 1, 'position_y' => 1, 'tombe' => false, 'a_joue' => false,
    ]);

    return compact('groupe', 'quete', 'heros', 'etat');
}

function tableEn(int $x, int $y): array
{
    return ['mobilier_id' => Mobilier::where('nom', 'Table')->value('id'), 'x' => $x, 'y' => $y, 'l' => 1, 'h' => 1];
}

function deplacerVers(array $scene, int $x, int $y): array
{
    return app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros'], ['id' => 'se_deplacer', 'libelle' => 'Se déplacer', 'type' => 'deplacement'], ['x' => $x, 'y' => $y],
    );
}

// =====================================================================
// 1. REGISTRE — dans les deux sens (cartes ↔ catalogue)
// =====================================================================

it('registre : les 7 cartes de la p. 50 et les 3 potions de la p. 2 sont portées, boîtées et sourcées', function () {
    $cartes = collect((array) config('cartes.artefacts.cartes'))->where('paquet', 'Jungles of Delthrak');
    $potions = collect((array) config('cartes.potions.cartes'))->where('paquet', 'Jungles of Delthrak');

    expect($cartes)->toHaveCount(7)->and($potions)->toHaveCount(3);

    foreach ($cartes->concat($potions) as $carte) {
        $objet = Objet::where('nom', $carte['objet'])->first();
        expect($objet)->not->toBeNull("« {$carte['objet']} » doit être semé")
            ->and($objet->boite)->toBe('jungles_delthrak');
    }

    // Les six artefacts sont uniques (jamais à l'étal, jamais revendus), les
    // trésors-valeurs ne le sont PAS : un artefact ne se revend pas, un trésor si.
    foreach (['Diadème de braise forgée', 'Brassards du Sauvage', 'Brassard du Garde-Crocs', 'Le Crâne de Saphir', 'Ceinture de Puissance'] as $nom) {
        expect(Objet::where('nom', $nom)->value('rarete'))->toBe('unique');
    }
    foreach (['Cœur d\'émeraude de Delthrak', 'Relique naine ancienne'] as $nom) {
        $o = Objet::where('nom', $nom)->first();
        expect($o->categorie)->toBe('tresor')->and($o->rarete)->not->toBe('unique');
    }

    // Prix de la boutique de l'Alchimiste (livret p. 2) ; la Potion de guérison
    // (500 po) est la carte du paquet officiel, sans doublon.
    expect(Objet::where('nom', 'Potion de sang de serpent')->value('prix_base'))->toBe(50)
        ->and(Objet::where('nom', 'Potion de sagesse ancienne')->value('prix_base'))->toBe(400)
        ->and(Objet::where('nom', 'Élixir de pas d\'araignée')->value('prix_base'))->toBe(100)
        ->and(Objet::where('nom', 'Potion de guérison')->value('prix_base'))->toBe(500);

    // Le registre a un lecteur pour chaque clé neuve (aucune clé décorative).
    foreach (['franchit_mobilier', 'ignore_terrain_entravant', 'franchit_fosses_revelees', 'bonus_deplacement_inconditionnel',
        'des_attaque_au_contact', 'appelle_allie', 'valeur_marchande', 'recupere_sort_ou_competence', 'une_par_quete'] as $cle) {
        expect(MotsClesEquipement::estActive($cle))->toBeTrue($cle);
    }
});

// =====================================================================
// 2. LES SIX ARTEFACTS, EN JEU
// =====================================================================

it('Diadème : +1 dé de défense et +1 Body max, et il prend la place du casque', function () {
    $s = sceneDelthrak();
    $heros = $s['heros'];
    $equip = app(Equipement::class);
    $defenseAvant = (int) $heros->des_defense;
    $bodyAvant = (int) $heros->pv_body_max;

    $equip->equiper($heros, ranger($heros, 'Diadème de braise forgée'));
    $heros->refresh();

    expect((int) $heros->des_defense)->toBe($defenseAvant + 1)
        ->and((int) $heros->pv_body_max)->toBe($bodyAvant + 1);

    // « May not be combined with the helmet » : même slot, l'un renvoie l'autre au sac.
    $casque = ranger($heros, 'Casque');
    $equip->equiper($heros, $casque);
    $heros->refresh();

    expect($casque->fresh()->emplacement)->toBe('casque')
        ->and(Inventaire::where('personnage_id', $heros->id)->where('emplacement', 'sac')->count())->toBe(1)
        ->and((int) $heros->des_defense)->toBe($defenseAvant + 1) // casque +1 remplace diadème +1
        ->and((int) $heros->pv_body_max)->toBe($bodyAvant);
});

it('Brassards du Sauvage : +1 dé, +2 cases partout, et ils se cumulent avec casque et bouclier', function () {
    $s = sceneDelthrak();
    $heros = $s['heros'];
    $equip = app(Equipement::class);
    $avant = (int) $heros->des_defense;

    $equip->equiper($heros, ranger($heros, 'Brassards du Sauvage'));
    $equip->equiper($heros, ranger($heros, 'Casque'));
    $heros->refresh();

    expect((int) $heros->des_defense)->toBe($avant + 2);

    // +2 cases sans condition de boîte (les Raquettes, elles, exigent la glace).
    expect($equip->bonusDeplacementActif($heros, $s['quete']))->toBe(2);

    // …et le déplacement du tour les compte réellement.
    expect(app(Equipement::class)->valeurEffetPorte($heros, 'bonus_deplacement_inconditionnel'))->toBe(2);
});

it('Brassards du Sauvage : le mobilier se traverse, on ne s\'y arrête pas — et le menu et l\'aperçu le disent', function () {
    $s = sceneDelthrak(mobilier: [tableEn(3, 1)]);
    $heros = $s['heros'];
    desFiges(array_fill(0, 40, 3));

    // Sans les Brassards : la Table barre le couloir central ; la case derrière
    // reste atteignable par la ligne du haut, mais le chemin direct est refusé.
    $sans = app(ResolveurTour::class)->grilleDeplacement($s['quete'], $heros);
    expect($sans->estTraversable(3, 1))->toBeFalse();

    app(Equipement::class)->equiper($heros, ranger($heros, 'Brassards du Sauvage'));
    $heros->refresh();

    expect(app(MoteurSorts::class)->mobilierFranchi($heros))->toBeTrue();

    $avec = app(ResolveurTour::class)->grilleDeplacement($s['quete'], $heros);
    expect($avec->estTraversable(3, 1))->toBeTrue('on passe sur le meuble')
        ->and($avec->arretInterditParTerrain(3, 1))->toBeTrue('mais on ne s\'y arrête pas')
        ->and(array_key_exists('3,1', $avec->casesAtteignables(1, 1, 6)))->toBeFalse()
        ->and(array_key_exists('4,1', $avec->casesAtteignables(1, 1, 6)))->toBeTrue();

    // L'aperçu refuse la case du meuble avec ses propres mots.
    $etat = $s['etat']->fresh();
    $apercu = app(ResolveurTour::class)->apercuDeplacement($s['quete']->fresh(), $heros, $etat, 3, 1);
    expect($apercu['atteignable'])->toBeFalse()->and($apercu['raison'])->toContain('meuble');

    // Le résolveur refuse aussi de FINIR dessus…
    expect(fn () => deplacerVers($s, 3, 1))->toThrow(Illuminate\Validation\ValidationException::class);

    // …et laisse passer derrière, en annonçant ce qui a été franchi.
    $resultat = deplacerVers($s, 4, 1);
    expect((int) $s['etat']->fresh()->position_x)->toBe(4)
        ->and($resultat['franchit'] ?? [])->toContain('mobilier');
});

it('un mur de glace posé par un sort reste un mur pour les Brassards (seul le MEUBLE s\'efface)', function () {
    $s = sceneDelthrak(mobilier: [tableEn(3, 1)]);
    $heros = $s['heros'];
    app(Equipement::class)->equiper($heros, ranger($heros, 'Brassards du Sauvage'));

    $carte = $s['quete']->carte;
    $grille = (array) $carte->grille;
    $grille['glace'] = [['x' => 5, 'y' => 1, 'source_instance_id' => 0, 'cranes' => 3]];
    $carte->update(['grille' => $grille]);

    $g = app(ResolveurTour::class)->grilleDeplacement($s['quete']->fresh(), $heros->fresh());
    expect($g->estTraversable(3, 1))->toBeTrue()->and($g->estTraversable(5, 1))->toBeFalse();
});

it('Crâne de Saphir : 2 dés d\'attaque, arme à distance (la ligne de vue décide, pas l\'adjacence)', function () {
    $s = sceneDelthrak();
    $heros = $s['heros'];
    $ligne = ranger($heros, 'Le Crâne de Saphir');
    app(Equipement::class)->equiper($heros, $ligne);
    $heros->refresh();

    $objet = Objet::where('nom', 'Le Crâne de Saphir')->first();
    expect((int) $heros->des_attaque)->toBe(2)
        ->and($objet->effet['portee'])->toBe('distance')
        ->and(isset($objet->effet['inutilisable_adjacent']))->toBeFalse()
        ->and(app(Equipement::class)->armeADistance($ligne->fresh()->load('objet')))->toBeTrue();
});

it('Ceinture de Puissance : +1 dé avec une arme de contact, pas avec une arme à distance ni au lancer', function () {
    $s = sceneDelthrak();
    $heros = $s['heros'];
    $equip = app(Equipement::class);

    $epee = ranger($heros, 'Épée longue');
    $equip->equiper($heros, $epee);
    $sansCeinture = (int) $heros->fresh()->des_attaque;
    expect($sansCeinture)->toBe(3);

    $equip->equiper($heros->fresh(), ranger($heros, 'Ceinture de Puissance'));
    $heros->refresh();

    expect((int) $heros->des_attaque)->toBe(4, 'arme de contact : +1');

    // Arme à distance en main droite : la ceinture ne donne rien.
    $arbalete = ranger($heros, 'Arbalète');
    $equip->equiper($heros->fresh(), $arbalete);
    expect((int) $heros->fresh()->des_attaque)->toBe(3);

    // Les mains nues ne sont pas « une attaque d'arme ».
    expect($equip->bonusAuContact($heros->fresh(), null))->toBe(0);

    // Une arme jetée (dague, `jetable`) est une attaque à distance.
    $dague = ranger($heros, 'Dague');
    expect($equip->bonusAuContact($heros->fresh(), $dague->load('objet')))->toBe(1)
        ->and(Objet::where('nom', 'Dague')->first()->effet['jetable'] ?? false)->toBeTrue();
});

it('Ceinture de Puissance : le Magicien ne peut pas la porter (classe_interdite)', function () {
    $s = sceneDelthrak(['classe' => 'magicien']);
    $objet = Objet::where('nom', 'Ceinture de Puissance')->first();

    expect($objet->classe_interdite)->toBe('magicien')
        ->and(app(Equipement::class)->estAccessible($s['heros'], $objet))->toBeFalse();

    $barbare = Personnage::create([
        'joueur_id' => $s['heros']->joueur_id, 'nom' => 'Brute', 'classe' => 'barbare', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2,
        'pv_mind' => 2, 'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    expect(app(Equipement::class)->estAccessible($barbare, $objet))->toBeTrue();
});

it('Ceinture de Puissance : un lancer d\'arme ne profite pas du dé (frapper() le retire)', function () {
    $s = sceneDelthrak();
    $heros = $s['heros'];
    $equip = app(Equipement::class);
    $equip->equiper($heros, ranger($heros, 'Dague'));
    $equip->equiper($heros->fresh(), ranger($heros, 'Ceinture de Puissance'));
    $heros->refresh();

    // Dague (1 dé de classe remplacé par l'arme) : au contact, la ceinture compte.
    $dague = $heros->inventaire()->where('emplacement', 'arme_principale')->with('objet')->first();
    $auContact = $equip->desAttaqueAvec($heros, $dague);
    expect($equip->bonusAuContact($heros, $dague))->toBe(1)
        ->and($auContact)->toBe((int) $heros->des_attaque);
});

// =====================================================================
// 3. LES TRÉSORS-VALEURS — marché et deck
// =====================================================================

/** Ouvre un marché au hub pour ce groupe et rend ses noms en rayon. */
function nomsEnRayon(Groupe $groupe): array
{
    $groupe->update(['phase' => 'hub', 'quete_courante_id' => null]);
    $etat = app(PhaseMarche::class)->ouvrir($groupe->fresh());

    return array_column($etat['inventaire'], 'nom');
}

it('boutique : le marchand vend TOUT partout — potions de Delthrak et de Morcar quel que soit le thème (René, 2026-10-09)', function () {
    foreach (['jungles_delthrak', 'horde_ogre'] as $theme) {
        $rayon = nomsEnRayon(sceneDelthrak(theme: $theme)['groupe']);

        expect($rayon)->toContain('Potion de sang de serpent')
            ->and($rayon)->toContain('Potion de sagesse ancienne')
            ->and($rayon)->toContain('Élixir de pas d\'araignée')
            ->and($rayon)->toContain('Potion de résistance au feu')
            ->and($rayon)->toContain('Potion de guérison')
            // jamais un trésor ni un artefact à l'étal
            ->and($rayon)->not->toContain('Cœur d\'émeraude de Delthrak')
            ->and($rayon)->not->toContain('Brassard du Garde-Crocs');
    }
});

it('un trésor-valeur se revend à sa valeur ENTIÈRE, un artefact jamais', function () {
    expect(PhaseMarche::reventePour(Objet::where('nom', 'Cœur d\'émeraude de Delthrak')->first(), 75))->toBe(75)
        ->and(PhaseMarche::reventePour(Objet::where('nom', 'Relique naine ancienne')->first(), 50))->toBe(50)
        // une pièce ordinaire : 50 % du prix
        ->and(PhaseMarche::reventePour(Objet::where('nom', 'Potion de guérison')->first(), 500))->toBe(250);

    $s = sceneDelthrak();
    $ligne = ranger($s['heros'], 'Cœur d\'émeraude de Delthrak');
    $s['groupe']->update(['phase' => 'hub', 'quete_courante_id' => null, 'or' => 10]);

    $phase = app(PhaseMarche::class);
    $phase->ouvrir($s['groupe']->fresh());
    $etat = $phase->majPanier($s['groupe']->fresh(), Joueur::find($s['heros']->joueur_id), [], [['inventaire_id' => $ligne->id]]);

    $vendable = collect($etat['paniers'][0]['inventaire'])->firstWhere('inventaire_id', $ligne->id);
    expect($vendable['revente'])->toBe(75)
        ->and($etat['total_projete'])->toBe(10 + 75);

    // Artefact : refusé.
    $brassard = ranger($s['heros'], 'Brassard du Garde-Crocs');
    expect(fn () => $phase->majPanier($s['groupe']->fresh(), Joueur::find($s['heros']->joueur_id), [], [['inventaire_id' => $brassard->id]]))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('deck de fouille : les deux trésors-valeurs de Delthrak rejoignent le deck, sous ce thème seulement', function () {
    $s = sceneDelthrak();
    $gabarit = GabaritQuete::query()->firstOrFail();
    $carte = (array) $s['quete']->carte->grille;
    $deckFouille = app(DeckFouille::class);

    $dans = $deckFouille->construire($gabarit, $carte, $s['groupe'], 1, \App\Partie\BestiaireGroupe::auto('jungles_delthrak'));
    $hors = $deckFouille->construire($gabarit, $carte, $s['groupe'], 1, \App\Partie\BestiaireGroupe::auto('horde_ogre'));

    $coeur = Objet::where('nom', 'Cœur d\'émeraude de Delthrak')->value('id');
    $relique = Objet::where('nom', 'Relique naine ancienne')->value('id');
    $ids = fn (array $r) => collect($r['deck'])->where('issue', 'objet')->pluck('objet_id')->all();

    expect($ids($dans))->toContain($coeur)->toContain($relique)
        ->and($ids($hors))->not->toContain($coeur);
});

it('fouiller le Cœur d\'émeraude le range au sac du héros, annoncé', function () {
    $s = sceneDelthrak();
    $coeur = Objet::where('nom', 'Cœur d\'émeraude de Delthrak')->first();

    $m = new ReflectionMethod(ResolveurTour::class, 'remettreButin');
    $m->setAccessible(true);
    $r = $m->invoke(app(ResolveurTour::class), ['objet_id' => $coeur->id], $s['heros'], 'objet');

    expect($r['objet']['nom'])->toBe('Cœur d\'émeraude de Delthrak')
        ->and(Inventaire::where('personnage_id', $s['heros']->id)->where('objet_id', $coeur->id)->exists())->toBeTrue();
});

// =====================================================================
// 4. POTIONS DE L'ALCHIMISTE
// =====================================================================

it('Potion de sang de serpent : retire la paralysie du venin (Envenimé)', function () {
    $s = sceneDelthrak();
    $heros = $s['heros'];
    $heros->conditions()->attach(Condition::where('nom', 'Envenimé')->value('id'), ['duree' => 1, 'source' => 'test']);

    $ligne = ranger($heros, 'Potion de sang de serpent', 'consommable');
    $r = app(MoteurPotions::class)->boire($heros, $ligne);

    expect($r['effets']['retire_condition'])->toBe('Envenimé')
        ->and($heros->conditions()->where('nom', 'Envenimé')->exists())->toBeFalse();
});

it('Potion de sagesse ancienne : rend UN sort épuisé, une seule par héros et par quête, jamais offerte sans objet', function () {
    $s = sceneDelthrak(['classe' => 'magicien']);
    $heros = $s['heros'];
    $potions = app(MoteurPotions::class);
    $objet = Objet::where('nom', 'Potion de sagesse ancienne')->first();

    // Rien à récupérer : non offerte, et refusée.
    expect($potions->offrable($heros, $objet, $s['etat']))->toBeFalse();
    $l = ranger($heros, 'Potion de sagesse ancienne', 'consommable');
    $l->update(['quantite' => 2]);
    expect(fn () => $potions->boire($heros, $l->fresh()))->toThrow(Illuminate\Validation\ValidationException::class);

    // Deux sorts épuisés : UN seul revient.
    $sorts = App\Models\Sort::query()->orderBy('id')->take(2)->pluck('id');
    foreach ($sorts as $id) {
        $heros->sorts()->attach($id, ['disponible' => false]);
    }

    expect($potions->offrable($heros, $objet, $s['etat']))->toBeTrue();
    $r = $potions->boire($heros, $l->fresh(), [], null);

    expect($r['effets']['recupere']['type'])->toBe('sort')
        ->and(DB::table('personnage_sorts')->where('personnage_id', $heros->id)->where('disponible', true)->count())->toBe(1);

    // La seconde potion de la pile : refusée (une par héros et par quête), et plus offerte.
    $etat = $s['etat']->fresh();
    expect($potions->offrable($heros, $objet, $etat))->toBeFalse()
        ->and(fn () => $potions->boire($heros, $l->fresh()))->toThrow(Illuminate\Validation\ValidationException::class);
    expect(DB::table('personnage_sorts')->where('personnage_id', $heros->id)->where('disponible', true)->count())->toBe(1);
});

it('Potion de sagesse ancienne : à défaut de sort, rend une compétence « une fois par quête » dépensée', function () {
    $s = sceneDelthrak();
    $heros = $s['heros'];
    $competence = $heros->competences()->first()
        ?? tap(App\Models\Competence::query()->firstOrFail(), fn ($c) => $heros->competences()->attach($c->id));

    app(App\Partie\Talents::class)->marquerUtilisee($s['etat'], $competence->nom);
    // Une fenêtre d'OBJET dans le même compteur n'est jamais « une compétence ».
    app(App\Partie\Talents::class)->marquerUtilisee($s['etat']->fresh(), 'objet:999');

    $l = ranger($heros, 'Potion de sagesse ancienne', 'consommable');
    $r = app(MoteurPotions::class)->boire($heros, $l);

    expect($r['effets']['recupere'])->toBe(['type' => 'competence', 'nom' => $competence->nom])
        ->and($s['etat']->fresh()->capacites_utilisees)->not->toContain($competence->nom)
        ->and($s['etat']->fresh()->capacites_utilisees)->toContain('objet:999');
});

it('Élixir de pas d\'araignée : mobilier, terrain, figures et fosses révélées — fini au premier dégât', function () {
    $fosse = Piege::where('nom', 'Fosse')->firstOrFail();
    $s = sceneDelthrak(
        mobilier: [tableEn(3, 1)],
        pieges: [['x' => 5, 'y' => 1, 'piege_id' => $fosse->id, 'etat' => 'detecte']],
        etroit: true,
    );
    $heros = $s['heros'];
    $quete = $s['quete'];

    $l = ranger($heros, 'Élixir de pas d\'araignée', 'consommable');
    $r = app(MoteurPotions::class)->boire($heros, $l);
    $heros->refresh();

    expect($r['effets']['buff'])->toBe("Pas d'araignée");

    $sorts = app(MoteurSorts::class);
    expect($sorts->mobilierFranchi($heros))->toBeTrue()
        ->and($sorts->terrainEntravantIgnore($heros))->toBeTrue()
        ->and($sorts->mobiliteCombatDisponible($heros))->toBeTrue()
        ->and($sorts->aBuff($heros, 'franchit_fosses_revelees'))->toBeTrue();

    // La fosse révélée en (5,1) ne fait pas tomber.
    desFiges(array_fill(0, 40, 3));
    $pv = (int) $heros->pv_body;
    $resultat = deplacerVers($s, 6, 1);

    expect((int) $s['etat']->fresh()->position_x)->toBe(6)
        ->and((int) $heros->fresh()->pv_body)->toBe($pv)
        ->and(json_encode($resultat['pieges_declenches']))->toContain('piege_ignore');

    // « Ends if you suffer any amount of damage » : le premier dégât éteint tout.
    app(App\Partie\MoteurDegats::class)->infligerAHeros($heros->fresh(), 1, App\Partie\MoteurDegats::SOURCE_PIEGE, []);

    expect($sorts->mobilierFranchi($heros->fresh()))->toBeFalse()
        ->and($sorts->aBuff($heros->fresh(), 'franchit_fosses_revelees'))->toBeFalse();
});

it('une fosse CACHÉE surprend même sous l\'Élixir (la carte ne parle que des fosses révélées)', function () {
    $fosse = Piege::where('nom', 'Fosse')->firstOrFail();
    $s = sceneDelthrak(pieges: [['x' => 4, 'y' => 1, 'piege_id' => $fosse->id, 'etat' => 'cache']]);
    $heros = $s['heros'];

    app(MoteurPotions::class)->boire($heros, ranger($heros, 'Élixir de pas d\'araignée', 'consommable'));
    desFiges(array_fill(0, 40, 3));
    $pv = (int) $heros->fresh()->pv_body;

    deplacerVers($s, 6, 1);

    expect((int) $heros->fresh()->pv_body)->toBeLessThan($pv);
});

// =====================================================================
// 5. LE BRASSARD DU GARDE-CROCS — Raptor allié, une fois par quête, dormance
// =====================================================================

/** Quête réelle à deux héros, brassard dans le sac d'Albrecht. */
function queteAvecBrassard(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['theme_bestiaire' => 'jungles_delthrak']);

    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $ligne = ranger($albrecht, 'Brassard du Garde-Crocs');

    return compact('alice', 'groupe', 'albrecht', 'quete', 'ligne');
}

it('Brassard du Garde-Crocs : appelle un Raptor allié près du héros, une seule fois par quête', function () {
    $q = queteAvecBrassard();
    $fiche = Mercenaire::where('nom', 'Raptor apprivoisé')->firstOrFail();

    GenererMenu::dispatchSync($q['groupe']->id, (int) $q['alice']->id, (int) $q['albrecht']->id);

    $objets = collect(app(App\Partie\MenuMoteur::class)->generer($q['groupe']->fresh(), $q['albrecht'])['options'] ?? [])
        ->firstWhere('id', 'utiliser_objet');
    expect($objets)->not->toBeNull('l\'option est offerte (action de héros éveillé)');
    expect(collect($objets['parametres']['objets'])->pluck('nom')->all())->toContain('Brassard du Garde-Crocs');

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet', 'parametres' => ['cle' => "objet:{$q['ligne']->id}"],
    ])->assertAccepted()->assertJsonPath('resultat.allie.nom', 'Raptor apprivoisé');

    $allie = GroupeMercenaire::where('groupe_id', $q['groupe']->id)->firstOrFail();
    $etat = EtatPersonnageQuete::where('quete_id', $q['quete']->id)->where('personnage_id', $q['albrecht']->id)->first();

    expect((int) $allie->mercenaire_id)->toBe((int) $fiche->id)
        ->and((int) $allie->recruteur_personnage_id)->toBe((int) $q['albrecht']->id)
        ->and((int) $allie->invoque_par_objet_id)->toBe((int) $q['ligne']->objet_id)
        ->and($allie->etat)->toBe('actif')
        ->and((int) $allie->pv_body)->toBe((int) $fiche->pv_body)
        ->and(abs((int) $allie->position_x - (int) $etat->position_x) + abs((int) $allie->position_y - (int) $etat->position_y))
        ->toBeLessThanOrEqual(4);

    // « Once per quest » : l'objet reste au sac (rien ne le détruit), mais la
    // fenêtre est fermée — le menu ne l'offre plus.
    expect(Inventaire::find($q['ligne']->id))->not->toBeNull()
        ->and(app(App\Partie\MoteurCharges::class)->utilisable($q['ligne']->fresh()->load('objet'), $etat->fresh()))->toBeFalse();
});

it('Raptor vaincu : le brassard s\'endort (annoncé), deux quêtes terminées le réveillent', function () {
    $q = queteAvecBrassard();
    $groupe = $q['groupe'];
    // Les dés AVANT de résoudre le résolveur : il capte le lanceur à sa construction.
    desFiges(array_fill(0, 60, 1));
    $resolveur = app(ResolveurTour::class);

    $allie = app(AlliesInvoques::class)->appeler(
        $groupe, $q['quete'], $q['albrecht']->fresh(), $q['ligne']->load('objet'), (array) $q['ligne']->objet->effet,
    );
    $allieRow = GroupeMercenaire::findOrFail($allie['allie']['id']);

    // Un monstre massacre le Raptor (dés : tout en crânes, la défense blanche manque).
    $monstre = $q['quete']->instancesMonstres()->with('monstre')->firstOrFail();

    $m = new ReflectionMethod(ResolveurTour::class, 'resoudreAttaqueMonstreSurAllie');
    $m->setAccessible(true);
    $payload = $m->invoke($resolveur, $groupe, $q['quete'], $monstre, $allieRow, 8, ['type' => 'monstre', 'id' => $monstre->id, 'nom' => 'M'], 'M');

    expect($payload['allie_vaincu'])->toBeTrue()
        ->and($payload['objet_dormant']['quetes'])->toBe(2)
        ->and($q['ligne']->fresh()->quetes_avant_reveil)->toBe(2);

    // Le fil de combat le DIT.
    $lignes = collect(app(App\Partie\JournalCombat::class)->depuisResultat($payload, 'M'));
    expect($lignes->pluck('texte')->implode(' | '))->toContain('s\'endort');

    // Dormant : plus offert, plus invocable, et le sac le dit.
    $effet = (array) $q['ligne']->objet->effet;
    expect(app(AlliesInvoques::class)->refus($q['quete']->fresh(), $q['albrecht']->fresh(), $q['ligne']->fresh()->load('objet'), $effet))
        ->toContain('dort encore');

    $moi = $this->getJson('/api/moi')->assertOk()->json();
    expect(json_encode($moi))->toContain('DORMANT');

    // Deux quêtes terminées : réveil (et UNE annonce de fin de quête).
    app(AlliesInvoques::class)->terminerQuete($groupe, $q['quete']);
    expect($q['ligne']->fresh()->quetes_avant_reveil)->toBe(1);

    $reveilles = app(AlliesInvoques::class)->terminerQuete($groupe, $q['quete']);
    expect($q['ligne']->fresh()->quetes_avant_reveil)->toBeNull()
        ->and($reveilles)->toBe([['objet' => 'Brassard du Garde-Crocs', 'personnage' => 'Albrecht']]);

    expect(app(AlliesInvoques::class)->refus($q['quete']->fresh(), $q['albrecht']->fresh(), $q['ligne']->fresh()->load('objet'), $effet))
        ->toBeNull();
});

it('un allié appelé ne survit pas à la quête, un allié recruté si', function () {
    $q = queteAvecBrassard();
    $groupe = $q['groupe'];

    app(AlliesInvoques::class)->appeler(
        $groupe, $q['quete'], $q['albrecht']->fresh(), $q['ligne']->load('objet'), (array) $q['ligne']->objet->effet,
    );
    $recrue = GroupeMercenaire::create([
        'groupe_id' => $groupe->id, 'mercenaire_id' => Mercenaire::where('nom', 'Éclaireur')->value('id'),
        'recruteur_personnage_id' => $q['albrecht']->id, 'pv_body' => 2, 'position_x' => 1, 'position_y' => 1, 'etat' => 'actif',
    ]);

    app(ResolveurTour::class)->terminerQuete($groupe->fresh(), $q['quete']->fresh());

    expect(GroupeMercenaire::where('groupe_id', $groupe->id)->whereNotNull('invoque_par_objet_id')->count())->toBe(0)
        ->and(GroupeMercenaire::find($recrue->id))->not->toBeNull();
});

it('témoin : SANS l\'Élixir, la même fosse révélée fait tomber', function () {
    $fosse = Piege::where('nom', 'Fosse')->firstOrFail();
    $s = sceneDelthrak(
        pieges: [['x' => 5, 'y' => 1, 'piege_id' => $fosse->id, 'etat' => 'detecte']],
        etroit: true,
    );
    desFiges(array_fill(0, 40, 3));
    $pv = (int) $s['heros']->pv_body;

    deplacerVers($s, 6, 1);

    expect((int) $s['heros']->fresh()->pv_body)->toBeLessThan($pv);
});
