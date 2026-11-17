<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Classique\ValidationCampagne;
use App\Partie\RangementObjet;
use App\Partie\Sauvegarde;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Classique\DocumentExemple;

/*
 * L'ÉQUIPEMENT CONFISQUÉ (lot K5j, R4 — *Rise of the Dread Moon*, F6646 p. 11). Tout est pris au départ (décision de
 * René : comme la source) ; chaque héros reprend SES affaires en fouillant le trésor de la salle désignée ; rien ne se
 * perd, ni à la fin de la quête ni à une reprise. Plateau et quête de `DocumentExemple`, INVENTÉS : les affaires
 * attendent dans la `galerie`.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, ObjetSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

function campagneConfisquee(array $regle = ['salle' => 'galerie']): array
{
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['regles'] = ['confiscation' => $regle];

    return $campagne;
}

/** Une partie lancée : Albrecht part avec une dague et 30 pièces d'or. */
function partieConfisquee(object $test): array
{
    app(CampagnesConstruites::class)->enregistrer(campagneConfisquee());
    $alice = connecterJoueur('alice');
    $heros = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Albrecht', 'classe' => 'barbare', 'niveau' => 1, 'or' => 30,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    RangementObjet::ranger(Objet::where('nom', 'Dague')->firstOrFail(), (int) $heros->id, 1);
    $code = $test->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $heros->id])
        ->assertCreated()->json('groupe.identifiant');
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();
    $test->postJson("/api/groupes/{$code}/quetes")->assertCreated();
    $quete = Quete::findOrFail($groupe->refresh()->quete_courante_id);

    return compact('code', 'groupe', 'quete', 'heros');
}

it('valide la règle : une salle ouverte, qu\'on peut fouiller', function (array $regles, ?string $chemin, ?string $code) {
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['regles'] = $regles;
    $refus = app(ValidationCampagne::class)->valider($campagne);
    if ($code === null) {
        expect($refus)->toBe([]);

        return;
    }
    expect(collect($refus)->contains(fn (array $r) => $r['chemin'] === $chemin && $r['code'] === $code))->toBeTrue(json_encode($refus, JSON_UNESCAPED_UNICODE));
})->with([
    'salle ouverte' => [['confiscation' => ['salle' => 'galerie']], null, null],
    'salle fermée dans cette quête' => [['confiscation' => ['salle' => 'crypte']], 'quetes.0.regles.confiscation.salle', ValidationCampagne::SALLE_INCONNUE],
    'salle qu\'on ne fouille pas' => [['confiscation' => ['salle' => 'galerie'], 'sans_fouille' => ['galerie']], 'quetes.0.regles.confiscation.salle', ValidationCampagne::VALEUR_INVALIDE],
    'clé inconnue' => [['confiscation' => ['salle' => 'galerie', 'or' => false]], 'quetes.0.regles.confiscation.or', ValidationCampagne::CLE_INCONNUE],
]);

it('EN JEU : tout est pris au départ, et le héros reprend SES affaires en fouillant la salle désignée', function () {
    ['code' => $code, 'groupe' => $groupe, 'quete' => $quete, 'heros' => $heros] = partieConfisquee($this);

    expect($heros->inventaire()->count())->toBe(0)
        ->and((int) $heros->fresh()->or)->toBe(0)
        ->and($quete->refresh()->consignes[(string) $heros->id]['or'])->toBe(30)
        ->and($quete->consignes[(string) $heros->id]['objets'])->toHaveCount(1);
    $annonce = \App\Models\Evenement::where('groupe_id', $groupe->id)->get()->first(fn ($e) => ($e->payload['type'] ?? null) === 'confiscation');
    expect(app(\App\Partie\JournalCombat::class)->depuisResultat($annonce->payload, 'Albrecht')[0]['texte'])
        ->toBe('Albrecht doit tout laisser : 1 objet et 30 pièces d\'or — tout attend quelque part dans le donjon');

    // Dans la galerie, vidée de monstres : la fouille du trésor rend la consigne, et ne tire aucune carte.
    InstanceMonstre::where('quete_id', $quete->id)->update(['etat' => 'vaincu']);
    $quete->etatsPersonnages()->update(['position_x' => 8, 'position_y' => 3]);
    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros));
    $this->getJson("/api/groupes/{$code}/menu")->assertOk();
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'fouiller_tresor'])->assertAccepted()
        ->assertJsonPath('resultat.issue', 'consigne')
        ->assertJsonPath('resultat.consigne.or', 30);

    expect($heros->inventaire()->with('objet')->get()->pluck('objet.nom')->all())->toBe(['Dague'])
        ->and((int) $heros->fresh()->or)->toBe(30)
        ->and($quete->refresh()->consignes[(string) $heros->id]['rendue'])->toBeTrue();
});

it('à deux héros, chacun garde SA consigne (vu en partie réelle : la clé du premier devenait 0)', function () {
    ['code' => $code, 'groupe' => $groupe] = (function () {
        app(CampagnesConstruites::class)->enregistrer(campagneConfisquee());
        $alice = connecterJoueur('alice');
        $bruno = connecterJoueur('bruno');
        $creer = fn ($joueur, $nom, $or) => Personnage::create([
            'joueur_id' => $joueur->id, 'nom' => $nom, 'classe' => 'barbare', 'niveau' => 1, 'or' => $or,
            'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
            'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
        ]);
        $a = $creer($alice, 'Albrecht', 30);
        $b = $creer($bruno, 'Bertrand', 7);
        $this->actingAs($alice, 'joueur');
        $code = $this->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $a->id])
            ->assertCreated()->json('groupe.identifiant');
        $this->actingAs($bruno, 'joueur')->postJson("/api/groupes/{$code}/joueurs", ['personnage_id' => $b->id])->assertSuccessful();
        $this->postJson("/api/groupes/{$code}/quetes")->assertCreated();

        return ['code' => $code, 'groupe' => Groupe::where('identifiant', $code)->firstOrFail()];
    })();
    $quete = Quete::findOrFail($groupe->quete_courante_id);
    $ids = $groupe->personnages()->pluck('personnages.id')->map(fn ($id) => (string) $id)->sort()->values()->all();

    expect(collect(array_keys($quete->consignes))->map(fn ($k) => (string) $k)->sort()->values()->all())->toBe($ids);

    $quete->update(['etat' => 'terminee']);
    expect(Personnage::whereIn('id', $ids)->orderBy('id')->pluck('or')->all())->toBe([30, 7]);
});

it('rien ne se perd : la quête finie sans que le héros soit passé par la salle, tout lui est rendu', function () {
    ['quete' => $quete, 'heros' => $heros] = partieConfisquee($this);
    expect($heros->inventaire()->count())->toBe(0);

    $quete->update(['etat' => 'echouee']);

    expect($heros->inventaire()->count())->toBe(1)
        ->and((int) $heros->fresh()->or)->toBe(30)
        ->and($quete->refresh()->consignes[(string) $heros->id]['rendue'])->toBeTrue();
});

it('la reprise de l\'instantané de départ ne perd ni ne double rien', function () {
    ['code' => $code, 'groupe' => $groupe, 'quete' => $quete, 'heros' => $heros] = partieConfisquee($this);

    // Il reprend ses affaires…
    InstanceMonstre::where('quete_id', $quete->id)->update(['etat' => 'vaincu']);
    $quete->etatsPersonnages()->update(['position_x' => 8, 'position_y' => 3]);
    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros));
    $this->getJson("/api/groupes/{$code}/menu")->assertOk();
    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'fouiller_tresor'])->assertAccepted();
    expect($heros->inventaire()->count())->toBe(1);

    // …puis le groupe reprend l'instantané de DÉPART : mains vides, consigne en attente — pas une dague de plus.
    // Le premier instantané du groupe : celui du départ de la quête (l'étiquette vit dans son état sérialisé).
    $depart = \App\Models\Snapshot::where('groupe_id', $groupe->id)->orderBy('id')->firstOrFail();
    app(Sauvegarde::class)->restaurer($groupe->refresh(), $depart);

    expect($heros->inventaire()->count())->toBe(0)
        ->and((int) $heros->fresh()->or)->toBe(0)
        ->and($quete->refresh()->consignes[(string) $heros->id]['rendue'])->toBeFalse();
});
