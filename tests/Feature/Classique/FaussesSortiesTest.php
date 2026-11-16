<?php

declare(strict_types=1);

use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Classique\CarteConstruite;
use App\Partie\Classique\ValidationCampagne;
use App\Partie\JournalCombat;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Classique\DocumentExemple;

/*
 * LES FAUSSES PORTES DE SORTIE (René, 2026-11-16 : « une quête avait 2 portes de sortie mais l'une était une fausse »).
 * Règle sourcée : les fausses portes « cannot be opened at all » (livret de base, F2847).
 *
 *  1. L'auteur les pose comme une porte de sortie — sur le mur d'une salle ouverte — et la validation les contrôle
 *     comme telle.
 *  2. Tant qu'on ne l'a pas essayée, une fausse porte est INDISCERNABLE de la vraie : même repère publié, même option
 *     au menu, même libellé, et une consigne qui ne nomme pas le lieu.
 *  3. Le héros qui la pousse reste dans le donjon, son action est dépensée, la porte est révélée pour tout le groupe et
 *     n'est plus offerte. La vraie porte, elle, fait sortir.
 *
 * Plateau et quête : ceux de `DocumentExemple`, INVENTÉS. `galerie` = 7..10 × 2..5. Vraie sortie au sud de (10, 5) ;
 * fausse à l'est de (10, 2).
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

function campagneAFausseSortie(array $fausses = [['x' => 10, 'y' => 2, 'cote' => 'e']]): array
{
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['sortie'] = ['type' => 'porte', 'x' => 10, 'y' => 5, 'cote' => 's'];
    $campagne['quetes'][0]['fausses_sorties'] = $fausses;
    // Une porte d'accès — vraie ou fausse — mène hors du plateau : un BLOC derrière chacune, sinon elle donnerait sur
    // le couloir (`porte_sans_bord`).
    $campagne['quetes'][0]['blocs'][] = ['x' => 10, 'y' => 6];
    $campagne['quetes'][0]['blocs'][] = ['x' => 11, 'y' => 2];

    return $campagne;
}

it('accepte une fausse porte de sortie posée sur le mur d\'une salle ouverte, et la produit non révélée', function () {
    $campagne = campagneAFausseSortie();
    expect(app(ValidationCampagne::class)->valider($campagne))->toBe([]);

    $produit = app(CarteConstruite::class)->produire($campagne, $campagne['quetes'][0]);

    expect($produit['acces']['fausses_sorties'])->toBe([[
        'type' => 'porte', 'cases' => [['x' => 10, 'y' => 2]], 'porte' => ['x' => 10, 'y' => 2, 'cote' => 'e'], 'revelee' => false,
    ]])
        // Ce n'est pas une porte du donjon : son bord reste muré, et `carte.portes` n'en compte pas une de plus.
        ->and($produit['carte']['portes'])->toHaveCount(3)
        ->and(collect($produit['carte']['murs'])->contains(['x' => 10, 'y' => 2, 'cote' => 'e']))->toBeTrue();

    // L'aperçu, fait pour l'AUTEUR, la distingue.
    expect(app(CarteConstruite::class)->apercu($campagne, $campagne['quetes'][0]))
        ->toContain('x FAUSSE porte de sortie (ne s\'ouvre pas) : porte, intérieur (10, 2), côté e');
});

it('refuse une fausse porte de sortie mal posée, en nommant le chemin', function (array $fausse, string $code) {
    $refus = app(ValidationCampagne::class)->valider(campagneAFausseSortie([$fausse]));

    expect(collect($refus)->contains(fn (array $r) => $r['code'] === $code && str_starts_with($r['chemin'], 'quetes.0.fausses_sorties.0')))
        ->toBeTrue(json_encode($refus, JSON_UNESCAPED_UNICODE));
})->with([
    'sur le bord de la vraie sortie' => [['x' => 10, 'y' => 5, 'cote' => 's'], ValidationCampagne::CASE_PARTAGEE],
    'dont le côté reste dans la salle' => [['x' => 8, 'y' => 3, 'cote' => 'e'], ValidationCampagne::PORTE_SANS_BORD],
    'dans une salle fermée' => [['x' => 4, 'y' => 10, 'cote' => 's'], ValidationCampagne::DEPART_INVALIDE],
    'avec une clé de trop' => [['x' => 10, 'y' => 2, 'cote' => 'e', 'type' => 'porte'], ValidationCampagne::CLE_INCONNUE],
    'sans côté' => [['x' => 10, 'y' => 2], ValidationCampagne::CLE_MANQUANTE],
]);

it('EN JEU : indiscernable avant l\'essai ; la pousser révèle la fausse porte ; la vraie fait sortir', function () {
    app(CampagnesConstruites::class)->enregistrer(campagneAFausseSortie());
    $alice = connecterJoueur('alice');
    $heros = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Albrecht', 'classe' => 'barbare', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $code = $this->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $heros->id])
        ->assertCreated()->json('groupe.identifiant');
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();
    $this->postJson("/api/groupes/{$code}/quetes")->assertCreated();
    $quete = Quete::findOrFail($groupe->refresh()->quete_courante_id);
    $etat = fn () => $quete->etatsPersonnages()->where('personnage_id', $heros->id)->first();
    $menuEn = function (int $x, int $y) use ($groupe, $code, $heros, $etat) {
        $etat()->update(['position_x' => $x, 'position_y' => $y, 'a_joue' => false]);
        Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros));

        return collect($this->getJson("/api/groupes/{$code}/menu")->assertOk()->json('menu.options'));
    };
    $sorties = fn () => collect($this->getJson("/api/groupes/{$code}/etat")->assertOk()->json('carte.acces'))
        ->reject(fn (array $a) => $a['role'] === 'entree_sortie' || $a['role'] === 'entree')->values();

    // La galerie découverte, l'objectif accompli : les deux portes sont publiées À L'IDENTIQUE.
    $quete->update(['salles_decouvertes' => [0, 1]]);
    InstanceMonstre::where('quete_id', $quete->id)->update(['etat' => 'vaincu']);

    $publiees = $sorties();
    expect($publiees)->toHaveCount(2)
        ->and($publiees->pluck('role')->unique()->all())->toBe(['sortie'])
        ->and($publiees->pluck('libelle')->unique()->all())->toBe(['Porte de sortie'])
        ->and(array_keys($publiees[0]))->toBe(array_keys($publiees[1]))
        // La consigne ne nomme pas le lieu tant qu'une fausse porte reste inconnue.
        ->and($this->getJson("/api/groupes/{$code}/etat")->json('quete.sortie.consigne'))->toBe('Objectif accompli : quittez le donjon.');

    // Le MÊME choix, au même libellé, devant l'une et devant l'autre.
    $devantLaVraie = $menuEn(10, 5)->firstWhere('id', 'sortir_donjon');
    $devantLaFausse = $menuEn(10, 2)->firstWhere('id', 'sortir_donjon');
    expect($devantLaFausse)->toBe($devantLaVraie)
        ->and($devantLaFausse['libelle'])->toBe('Quitter le donjon par la porte de sortie');

    // La situation du menu dit la consigne de sortie en personne — jamais « Rejoignez la salle de départ », la consigne
    // du rassemblement d'une campagne, qu'elle disait encore en classique (vu à l'écran le 2026-11-16).
    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros));
    expect($this->getJson("/api/groupes/{$code}/menu")->json('menu.situation'))
        ->toContain('Objectif accompli : quittez le donjon.')
        ->not->toContain('Rejoignez la salle de départ');

    // Il pousse la fausse : il reste dans le donjon, son action est dépensée, la porte est révélée.
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'sortir_donjon'])->assertAccepted()
        ->assertJsonPath('resultat.type', 'fausse_sortie');

    expect($etat())->not->toBeNull()
        ->and((bool) $etat()->a_agi)->toBeTrue()   // l'essai a coûté son action
        ->and($quete->refresh()->etat)->toBe('en_cours')
        ->and($quete->faussesSorties()[0]['revelee'])->toBeTrue()
        ->and(collect($this->getJson("/api/groupes/{$code}/etat")->json('carte.acces'))->firstWhere('role', 'fausse_sortie'))
        ->toMatchArray(['type' => 'porte', 'libelle' => 'Fausse porte — elle ne s\'ouvre pas', 'x' => 10, 'y' => 2, 'cote' => 'e'])
        // Connue, elle n'est plus offerte ; la consigne nomme de nouveau la sortie.
        ->and($menuEn(10, 2)->pluck('id'))->not->toContain('sortir_donjon')
        ->and($this->getJson("/api/groupes/{$code}/etat")->json('quete.sortie.consigne'))->toBe('Objectif accompli : quittez le donjon par la porte de sortie.');

    // Le fil le dit.
    $annonce = Evenement::where('groupe_id', $groupe->id)->get()
        ->first(fn ($e) => $e->type === 'action' && ($e->payload['type'] ?? null) === 'fausse_sortie');
    expect(app(JournalCombat::class)->depuisResultat($annonce->payload, 'Albrecht')[0]['texte'])
        ->toBe('Albrecht pousse la porte de sortie : c\'est une fausse porte, peinte sur le mur — elle ne s\'ouvre pas');

    // Au tour suivant, la vraie porte fait sortir, et gagne la quête.
    $etat()->update(['a_agi' => false]);
    $menuEn(10, 5);
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'sortir_donjon'])->assertAccepted()
        ->assertJsonPath('resultat.type', 'sortie_en_personne');
    expect($quete->refresh()->etat)->toBe('terminee');
});
