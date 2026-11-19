<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Classique\ValidationCampagne;
use App\Partie\MoteurDegats;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Classique\DocumentExemple;

/*
 * LES DÉGÂTS CONVERTIS EN MIND (lot K6 — *Spirit Queen's Torment* q. 8 : « all damage heroes take will affect their Mind
 * Points instead of their Body Points » ; q. 9 : les Orques seulement). René, 2026-11-19 : « tout dégât ». Quête de
 * `DocumentExemple`, INVENTÉE : un gobelin en (5, 2).
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

function partieEnMind(object $t, bool|array $regle): array
{
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['regles'] = ['degats_en_mind' => $regle];
    app(CampagnesConstruites::class)->enregistrer($campagne);
    $alice = connecterJoueur('alice');
    $heros = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Albrecht', 'classe' => 'barbare', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 4, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 4, 'pv_mind' => 4,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $code = $t->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $heros->id])
        ->assertCreated()->json('groupe.identifiant');
    $t->postJson("/api/groupes/{$code}/quetes")->assertCreated();
    $quete = Quete::findOrFail(Groupe::where('identifiant', $code)->firstOrFail()->quete_courante_id);

    return compact('code', 'heros', 'quete');
}

it('tout dégât subi entame l\'Esprit, jamais le Corps — sauf le prix que le héros s\'inflige lui-même', function () {
    ['code' => $code, 'heros' => $heros] = partieEnMind($this, true);
    $degats = app(MoteurDegats::class);

    expect($degats->infligerAHeros($heros, 2, MoteurDegats::SOURCE_PIEGE))->toBe(2);
    $heros->refresh();
    expect((int) $heros->pv_body)->toBe(8)->and((int) $heros->pv_mind)->toBe(2);

    $degats->infligerAHeros($heros, 1, MoteurDegats::SOURCE_SACRIFICE);
    expect((int) $heros->refresh()->pv_body)->toBe(7)->and((int) $heros->pv_mind)->toBe(2);

    // Annoncé et publié : le bandeau de la quête le dit.
    expect(collect($this->getJson("/api/groupes/{$code}/etat")->json('quete.effets_globaux'))->pluck('texte')->implode(' '))
        ->toContain('Esprits : tout dégât');
});

it('une liste de monstres : seuls LEURS coups passent en Esprit', function () {
    ['heros' => $heros, 'quete' => $quete] = partieEnMind($this, ['gobelin']);
    $gobelin = $quete->instancesMonstres()->firstOrFail();
    $degats = app(MoteurDegats::class);

    $degats->infligerAHeros($heros, 1, MoteurDegats::SOURCE_ATTAQUE_MONSTRE, ['monstre' => 'Gobelin', 'instance_id' => (int) $gobelin->id]);
    $degats->infligerAHeros($heros, 1, MoteurDegats::SOURCE_PIEGE);

    $heros->refresh();
    expect((int) $heros->pv_mind)->toBe(3)->and((int) $heros->pv_body)->toBe(7);
});

it('valide `degats_en_mind` : true ou une liste de monstres du catalogue', function () {
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['regles'] = ['degats_en_mind' => ['dragon-imaginaire']];
    $refus = collect(app(ValidationCampagne::class)->valider($campagne))->map(fn ($r) => $r['chemin'].' '.$r['code'])->all();
    expect($refus)->toContain('quetes.0.regles.degats_en_mind.0 '.ValidationCampagne::CATALOGUE_INCONNU);

    $campagne['quetes'][0]['regles'] = ['degats_en_mind' => 'oui'];
    expect(collect(app(ValidationCampagne::class)->valider($campagne))->pluck('code')->all())->toContain(ValidationCampagne::TYPE_INVALIDE);
});
