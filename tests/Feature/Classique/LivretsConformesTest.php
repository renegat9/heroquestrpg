<?php

declare(strict_types=1);

use App\Engine\Des\LanceurDes;
use App\Engine\Des\LanceurDeterministe;
use App\Engine\MotsClesQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Classique\CarteConstruite;
use App\Partie\Classique\CatalogueConstructeur;
use App\Partie\Classique\NotesDeQuete;
use App\Partie\Classique\ValidationCampagne;
use App\Partie\MoteurDormants;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Classique\DocumentExemple;

/*
 * « LES LIVRETS SONT MAÎTRES EN MODE CLASSIQUE » — ce que la relecture des trois campagnes a demandé (René, 2026-11-20) :
 * 2. une porte de SORTIE verrouillée comme une porte barrée (*Kellar's Keep* q. 9 et 10) ;
 * 3. un meuble dans un COULOIR (*Witch Lord* q. 2, *Kellar's Keep* q. 4) ;
 * 4. une figure INACTIVE qu'une note éveille (*Witch Lord* q. 7, la Légion oubliée) ;
 * 5. `vulnerable_uniquement_a` qui accepte des SORTS (*Witch Lord* q. 10).
 * Plateau inventé (`DocumentExemple`) ; sortie par une porte de couloir contre le bord est, en (19, 7).
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class, ObjetSeeder::class, SortSeeder::class]);
});

function campagneLivret(?array $verrou = null): array
{
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['fin'] = ['type' => 'sortir', 'libelle' => 'Trouver la porte de sortie.'];
    $campagne['quetes'][0]['sortie'] = ['type' => 'porte', 'x' => 19, 'y' => 7, 'cote' => 'e'] + ($verrou === null ? [] : ['verrou' => $verrou]);

    return $campagne;
}

function codesLivret(array $campagne): array
{
    return array_column(app(ValidationCampagne::class)->valider($campagne), 'code');
}

function partieLivret(object $test, array $campagne, int $mind = 2): array
{
    app(CampagnesConstruites::class)->enregistrer($campagne);
    $alice = connecterJoueur('alice');
    $heros = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Albrecht', 'classe' => 'barbare', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => $mind, 'pv_mind' => $mind,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $code = $test->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $heros->id])
        ->assertCreated()->json('groupe.identifiant');
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();
    $test->postJson("/api/groupes/{$code}/quetes")->assertCreated();
    $quete = Quete::findOrFail($groupe->refresh()->quete_courante_id);

    return compact('code', 'groupe', 'quete', 'heros');
}

function optionSortie(object $test, Groupe $groupe, string $code, Personnage $heros): ?array
{
    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros));

    return collect($test->getJson("/api/groupes/{$code}/menu")->assertOk()->json('menu.options'))->firstWhere('id', 'sortir_donjon');
}

// ─── 2. La porte de sortie verrouillée ───────────────────────────────────────────────────────────────────────────

it('valide un verrou sur une PORTE de sortie, jamais sur un escalier ; « ouvrir_porte » vise la sortie verrouillée', function () {
    expect(codesLivret(campagneLivret(['type' => 'note'])))->toBe([])
        ->and(codesLivret(campagneLivret(['type' => 'condition', 'condition' => 'porte_de_l_est'])))->toBe([]);

    $escalier = campagneLivret();
    $escalier['quetes'][0]['sortie'] = ['type' => 'escalier', 'x' => 9, 'y' => 4, 'verrou' => ['type' => 'note']];
    expect(codesLivret($escalier))->toContain(ValidationCampagne::VALEUR_INVALIDE);

    $note = ['repere' => 'D', 'quand' => ['declencheur' => 'mort', 'cible' => ['monstre' => 0]], 'alors' => [['effet' => 'ouvrir_porte', 'porte' => 'sortie']]];
    $scellee = campagneLivret(['type' => 'note']);
    $scellee['quetes'][0]['notes'] = [$note];
    expect(codesLivret($scellee))->toBe([]);

    $libre = campagneLivret();
    $libre['quetes'][0]['notes'] = [$note];
    expect(codesLivret($libre))->toContain(ValidationCampagne::VALEUR_INVALIDE);
});

it('EN JEU : scellée, la sortie ne s\'offre pas ; la note qui l\'ouvre (la mort du gardien) la rend au groupe', function () {
    $campagne = campagneLivret(['type' => 'note']);
    $campagne['quetes'][0]['notes'] = [['repere' => 'D', 'quand' => ['declencheur' => 'mort', 'cible' => ['monstre' => 0]],
        'alors' => [['effet' => 'ouvrir_porte', 'porte' => 'sortie']]]];
    ['code' => $code, 'groupe' => $groupe, 'quete' => $quete, 'heros' => $heros] = partieLivret($this, $campagne);
    $quete->etatsPersonnages()->where('personnage_id', $heros->id)->update(['position_x' => 19, 'position_y' => 7]);

    expect(optionSortie($this, $groupe, $code, $heros))->toBeNull();
    // Le couloir de la sortie vu depuis la case du héros : la table dit que la porte est verrouillée.
    app(\App\Partie\Classique\CouloirsVus::class)->mettreAJour($quete->fresh());
    $acces = collect($this->getJson("/api/groupes/{$code}/etat")->json('carte.acces'))->firstWhere('role', 'sortie');
    expect($acces['libelle'] ?? null)->toBe('Porte de sortie — verrouillée');

    app(NotesDeQuete::class)->signaler($quete->fresh(), MotsClesQuete::MORT, ['monstre' => 0], $heros);

    expect(optionSortie($this, $groupe, $code, $heros)['libelle'])->toBe('Quitter le donjon par la porte de sortie');
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'sortir_donjon'])->assertAccepted()
        ->assertJsonPath('resultat.type', 'sortie_en_personne');
});

it('EN JEU : la porte à condition se tente à chaque passage — raté, le tour s\'achève ; réussi, le héros sort', function () {
    $campagne = campagneLivret(['type' => 'condition', 'condition' => 'porte_de_l_est']);
    ['code' => $code, 'groupe' => $groupe, 'quete' => $quete, 'heros' => $heros] = partieLivret($this, $campagne, mind: 4);
    $quete->etatsPersonnages()->where('personnage_id', $heros->id)->update(['position_x' => 19, 'position_y' => 7]);

    $option = optionSortie($this, $groupe, $code, $heros);
    expect($option['libelle'])->toBe('Quitter le donjon par la porte de sortie (2 dés rouges, total au plus égal à ton Esprit actuel (4))');

    app()->instance(LanceurDes::class, new LanceurDeterministe([6, 6, ...array_fill(0, 60, 3)]));
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'sortir_donjon'])->assertAccepted()
        ->assertJsonPath('resultat.type', 'sortie_verrouillee')
        ->assertJsonPath('resultat.total', 12);
    expect($quete->etatsPersonnages()->where('personnage_id', $heros->id)->exists())->toBeTrue();

    // Un nouveau tour : 1 + 2 = 3 ≤ 4, il passe.
    $quete->etatsPersonnages()->where('personnage_id', $heros->id)->update(['a_agi' => false, 'a_deplace' => false, 'a_joue' => false]);
    app()->instance(LanceurDes::class, new LanceurDeterministe([1, 2, ...array_fill(0, 60, 3)]));
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'sortir_donjon'])->assertAccepted()
        ->assertJsonPath('resultat.type', 'sortie_en_personne')
        ->assertJsonPath('resultat.jet.total', 3);
});

// ─── 3. Un meuble dans un couloir ────────────────────────────────────────────────────────────────────────────────

it('pose un meuble dans un COULOIR ; refuse une emprise à cheval sur une salle', function () {
    $campagne = campagneLivret();
    // Couloir y 6-8 entre `entree` (y 2-5) et `crypte` (y 9-11).
    $campagne['quetes'][0]['mobilier'][] = ['slug' => 'coffre', 'x' => 12, 'y' => 7, 'rotation' => 0];
    expect(codesLivret($campagne))->toBe([]);

    $carte = app(CarteConstruite::class)->produire($campagne, $campagne['quetes'][0])['carte'];
    expect(collect($carte['mobilier'])->firstWhere('x', 12)['salle'])->toBeNull();

    $campagne['quetes'][0]['mobilier'][] = ['slug' => 'bibliotheque', 'x' => 4, 'y' => 7, 'rotation' => 90]; // 1×3 : y 7-9, la dernière dans la crypte
    expect(codesLivret($campagne))->toContain(ValidationCampagne::MOBILIER_HORS_SALLE);
});

// ─── 4. Une figure inactive, éveillée par une note ───────────────────────────────────────────────────────────────

it('EN JEU : la figure « inactif » dort et ne peut être blessée ; l\'ouverture de la porte l\'éveille (« eveiller »)', function () {
    $campagne = campagneLivret();
    $campagne['quetes'][0]['monstres'][0]['inactif'] = true;
    $campagne['quetes'][0]['notes'] = [['repere' => 'B', 'quand' => ['declencheur' => 'ouverture_porte', 'cible' => ['porte' => 0]],
        'alors' => [['effet' => 'eveiller', 'monstres' => [0]]]]];
    expect(codesLivret($campagne))->toBe([]);

    $sansInactif = $campagne;
    unset($sansInactif['quetes'][0]['monstres'][0]['inactif']);
    expect(codesLivret($sansInactif))->toContain(ValidationCampagne::VALEUR_INVALIDE);

    ['quete' => $quete, 'heros' => $heros] = partieLivret($this, $campagne);
    $gobelin = InstanceMonstre::where('quete_id', $quete->id)->orderBy('id')->firstOrFail();
    $dormants = app(MoteurDormants::class);
    expect($dormants->estDormant($gobelin))->toBeTrue()->and($dormants->estIntouchable($gobelin))->toBeTrue();

    app(NotesDeQuete::class)->signaler($quete->fresh(), MotsClesQuete::OUVERTURE_PORTE, ['porte' => 0], $heros);

    $gobelin->refresh();
    expect($dormants->estDormant($gobelin))->toBeFalse()->and($dormants->estIntouchable($gobelin))->toBeFalse();
});

// ─── 5. Vulnérable seulement à des armes ET des sorts ────────────────────────────────────────────────────────────

it('« vulnerable_uniquement_a » accepte un sort du catalogue, et l\'habillage le nomme', function () {
    $campagne = campagneLivret();
    $campagne['quetes'][0]['monstres'][0]['vulnerable_uniquement_a'] = ['boule-de-feu', 'trait-de-feu'];
    expect(codesLivret($campagne))->toBe([]);

    $habillage = InstanceMonstre::habillageDeQuete($campagne['quetes'][0]['monstres'][0], app(CatalogueConstructeur::class));
    expect($habillage['profil_quete']['vulnerable_uniquement_a'])->toBe(['Boule de Feu', 'Trait de Feu']);

    $campagne['quetes'][0]['monstres'][0]['vulnerable_uniquement_a'] = ['sort-imaginaire'];
    expect(codesLivret($campagne))->toContain(ValidationCampagne::CATALOGUE_INCONNU);
});

// ─── Le Château du Mystère : essayer d'ouvrir une porte téléporte ────────────────────────────────────────────────

it('EN JEU, portes à téléportation : la porte peut donner sur la roche ; l\'essayer téléporte, elle reste close, une par tour', function () {
    $campagne = campagneLivret();
    $quete = &$campagne['quetes'][0];
    $quete['salles_ouvertes'] = ['entree'];          // la galerie est de la roche : la porte (6,3) e y donne
    $quete['portes'] = [['x' => 6, 'y' => 3, 'cote' => 'e', 'type' => 'normale']];
    $quete['monstres'] = [];
    $quete['mobilier'] = [];
    $quete['pieges'] = [];
    $quete['terrain'] = [];
    $quete['fin'] = ['type' => 'sortir', 'libelle' => 'Sortir.'];
    $quete['regles'] = ['portes_teleportation' => array_fill_keys(array_map('strval', range(2, 12)), ['x' => 3, 'y' => 2])];
    unset($quete);
    expect(codesLivret($campagne))->toBe([]);

    // Sans la règle, la même porte est refusée : elle donnerait dans la roche.
    $sansRegle = $campagne;
    unset($sansRegle['quetes'][0]['regles']);
    expect(codesLivret($sansRegle))->toContain(ValidationCampagne::PORTE_DANS_LA_ROCHE);

    ['code' => $code, 'groupe' => $groupe, 'quete' => $q, 'heros' => $heros] = partieLivret($this, $campagne);
    $q->etatsPersonnages()->where('personnage_id', $heros->id)->update(['position_x' => 6, 'position_y' => 3]);
    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros));
    $porte = collect($this->getJson("/api/groupes/{$code}/menu")->assertOk()->json('menu.options'))->firstWhere('type', 'ouvrir_porte');
    expect($porte)->not->toBeNull();

    app()->instance(LanceurDes::class, new LanceurDeterministe([3, 4, ...array_fill(0, 60, 3)]));
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => $porte['id']])->assertAccepted()
        ->assertJsonPath('resultat.type', 'porte_teleporte')
        ->assertJsonPath('resultat.total', 7);

    $etat = $q->etatsPersonnages()->where('personnage_id', $heros->id)->first();
    expect([(int) $etat->position_x, (int) $etat->position_y])->toBe([3, 2])
        ->and(data_get($q->fresh()->carte->grille, 'portes.0.etat'))->toBe(\App\Partie\MoteurPortes::ETAT_FERMEE);

    // Revenu devant la porte le même tour : elle n'est plus offerte (une porte par tour).
    $etat->update(['position_x' => 6, 'position_y' => 3]);
    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros));
    expect(collect($this->getJson("/api/groupes/{$code}/menu")->json('menu.options'))->firstWhere('type', 'ouvrir_porte'))->toBeNull();
});

// ─── 6. Le captif à escorter (Sir Ragnar, livret de base q. 2) ───────────────────────────────────────────────────

function campagneRagnar(): array
{
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['captif'] = ['mercenaire' => 'sir-ragnar-livret-de-base', 'x' => 9, 'y' => 3];
    $campagne['quetes'][0]['fin'] = ['type' => 'ramener', 'libelle' => 'Ramener Sir Ragnar vivant à l\'escalier.'];
    $campagne['quetes'][0]['terrain'] = [];

    return $campagne;
}

it('valide un captif du catalogue ; refuse un mercenaire qui n\'en est pas un ; la fin « ramener » l\'accepte au lieu d\'une charge', function () {
    $this->seed(\Database\Seeders\MercenaireSeeder::class);
    expect(codesLivret(campagneRagnar()))->toBe([]);

    $mauvais = campagneRagnar();
    $mauvais['quetes'][0]['captif']['mercenaire'] = \Illuminate\Support\Str::slug(\App\Models\Mercenaire::where('captif', false)->value('nom'));
    expect(codesLivret($mauvais))->toContain(ValidationCampagne::VALEUR_INVALIDE);

    $sans = campagneRagnar();
    unset($sans['quetes'][0]['captif']);
    expect(codesLivret($sans))->toContain(ValidationCampagne::CLE_MANQUANTE);
});

it('EN JEU : libéré au contact, il suit son héros à un dé rouge, n\'attaque pas ; ramené à l\'escalier, la récompense est versée', function () {
    $this->seed(\Database\Seeders\MercenaireSeeder::class);
    ['code' => $code, 'groupe' => $groupe, 'quete' => $quete, 'heros' => $heros] = partieLivret($this, campagneRagnar());
    $captif = \App\Models\GroupeMercenaire::where('groupe_id', $groupe->id)->firstOrFail();
    expect($captif->etat)->toBe('captif')->and([(int) $captif->position_x, (int) $captif->position_y])->toBe([9, 3]);

    $quete->etatsPersonnages()->where('personnage_id', $heros->id)->update(['position_x' => 9, 'position_y' => 4]);
    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros));
    $liberer = collect($this->getJson("/api/groupes/{$code}/menu")->json('menu.options'))->firstWhere('type', 'liberer_captif');
    expect($liberer)->not->toBeNull();
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => $liberer['id']])->assertAccepted()
        ->assertJsonPath('resultat.type', 'captif_libere');
    $captif->refresh();
    expect($captif->etat)->toBe('actif')->and((int) $captif->recruteur_personnage_id)->toBe($heros->id);

    // Son tour : un dé rouge pour bouger (gardé en colonne), et aucune attaque, même un gobelin au contact.
    $captif->update(['a_joue' => false, 'a_deplace' => false, 'a_agi' => false]);
    InstanceMonstre::where('quete_id', $quete->id)->orderBy('id')->first()->update(['position_x' => 10, 'position_y' => 3, 'revele' => true]);
    app()->instance(LanceurDes::class, new LanceurDeterministe([4, ...array_fill(0, 30, 3)]));
    $menu = app(\App\Partie\MenuMoteur::class)->genererMenuAllie($groupe->fresh(), $quete->fresh(), $captif->fresh());
    $options = collect($menu['options'] ?? $menu);
    expect($options->firstWhere('id', 'attaquer_allie'))->toBeNull()
        ->and($options->firstWhere('id', 'se_deplacer_case_allie')['parametres']['portee'])->toBe(4)
        ->and((int) $captif->fresh()->deplacement_tire)->toBe(4);

    // Ramené sur l'escalier (2-3 × 4-5) ; le héros sort par l'autre case des marches : la récompense (10 po) est versée.
    $captif->update(['position_x' => 2, 'position_y' => 4]);
    $quete->etatsPersonnages()->where('personnage_id', $heros->id)->update(['position_x' => 3, 'position_y' => 5, 'a_agi' => false, 'a_joue' => false]);
    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros));
    expect(collect($this->getJson("/api/groupes/{$code}/menu")->json('menu.options'))->pluck('id'))->toContain('sortir_donjon');
    $r = $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'sortir_donjon']);
    expect($r->status())->toBe(202, $r->getContent());
    $r
        ->assertJsonPath('resultat.type', 'sortie_en_personne');
    expect($quete->refresh()->etat)->toBe('terminee')->and((int) $heros->fresh()->or)->toBe(10);
});

it('EN JEU : le captif n\'est pas ramené — la quête est gagnée, mais la récompense n\'est pas versée, et c\'est annoncé', function () {
    $this->seed(\Database\Seeders\MercenaireSeeder::class);
    ['code' => $code, 'groupe' => $groupe, 'quete' => $quete, 'heros' => $heros] = partieLivret($this, campagneRagnar());
    $quete->etatsPersonnages()->where('personnage_id', $heros->id)->update(['position_x' => 3, 'position_y' => 5]);
    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros));
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'sortir_donjon'])->assertAccepted();

    expect($quete->refresh()->etat)->toBe('terminee')
        ->and((int) $heros->fresh()->or)->toBe(0)
        ->and(\App\Models\Evenement::where('groupe_id', $groupe->id)->get()->contains(fn ($e) => data_get($e->contenu, 'type') === 'recompense_perdue'
            || str_contains(json_encode($e->toArray()), 'recompense_perdue')))->toBeTrue();
});
