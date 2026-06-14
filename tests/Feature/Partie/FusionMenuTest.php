<?php

declare(strict_types=1);

use App\Agent\Memoire\ContexteAssembleur;
use App\Agent\Skills\MenuChoix;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Quete;
use App\Partie\MenuMoteur;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Garantie de jouabilité (doc 08 §2) : même quand le menu IA omet les options
 * mécaniques (déplacement, attaque), GenererMenu les réinjecte depuis le moteur
 * — un héros n'est jamais sans moyen d'agir. Régression d'un bug trouvé en test
 * de partie end-to-end (menu IA sans « se_deplacer » → softlock à l'approche).
 */
beforeEach(function () {
    $this->seed([ClasseHerosSeeder::class, MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
    config()->set('services.anthropic.api_key', 'cle-test');
});

/** Un seul monstre actif, placé adjacent au héros (ciblage déterministe). */
function queteUnMonstreAdjacent(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Grimnar', 1, ['classe' => 'barbare']);
    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    $instance = $quete->instancesMonstres()->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($instance->id)->update(['etat' => 'vaincu']);

    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heros->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    $instance->update(['etat' => 'actif', 'position_x' => $contact['x'], 'position_y' => $contact['y']]);

    return [$alice, $groupe, $heros, $quete, $instance->fresh()];
}

/** Fake unique : réponse menu IA fournie par $menuInput, le reste en repli. */
function fakeMenuIa(array $menuInput): void
{
    Http::fake(function ($req) use ($menuInput) {
        if (str_contains($req->url(), 'anthropic')) {
            if (($req->data()['tool_choice']['name'] ?? null) === 'proposer_menu_choix') {
                return Http::response(['stop_reason' => 'tool_use', 'content' => [[
                    'type' => 'tool_use', 'name' => 'proposer_menu_choix', 'input' => $menuInput,
                ]]]);
            }

            return Http::response([], 500); // autres skills → repli
        }

        return Http::response([], 200); // Qdrant, etc.
    });
}

function genererMenuPour($groupe, $alice, $heros): array
{
    (new GenererMenu($groupe->id, (int) $alice->id, (int) $heros->id))
        ->handle(app(MenuChoix::class), app(ContexteAssembleur::class), app(MenuMoteur::class));

    return Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu'];
}

it('réinjecte déplacement et attaque (cible_id) quand le menu IA les omet', function () {
    fakeMenuIa(['situation' => 'Une ombre se dresse.', 'options' => [
        ['id' => 'parler', 'libelle' => 'Tenter d\'intimider la créature', 'type' => 'dialogue'],
        ['id' => 'ecouter', 'libelle' => 'Tendre l\'oreille — jet de Mind', 'type' => 'jet', 'jet' => ['attribut' => 'mind', 'difficulte' => 2]],
    ]]);
    [$alice, $groupe, $heros, , $instance] = queteUnMonstreAdjacent();

    $menu = genererMenuPour($groupe, $alice, $heros);
    $types = collect($menu['options'])->pluck('type');

    expect($types)->toContain('deplacement')   // réinjecté par le moteur
        ->and($types)->toContain('attaque')     // monstre adjacent → attaque possible
        ->and($types)->toContain('dialogue');   // couleur IA conservée

    $attaque = collect($menu['options'])->firstWhere('type', 'attaque');
    expect((int) $attaque['cible_id'])->toBe((int) $instance->id); // ancrage mécanique correct
});

it('emprunte le libellé IA pour habiller une option mécanique du moteur', function () {
    // Closure unique : l'IA propose une attaque sur le monstre actif (cible_id
    // lu dans la requête — un seul monstre actif), avec un libellé dressé.
    Http::fake(function ($req) {
        if (str_contains($req->url(), 'anthropic')) {
            if (($req->data()['tool_choice']['name'] ?? null) === 'proposer_menu_choix') {
                $contenu = $req->data()['messages'][0]['content'] ?? '';
                preg_match('/"instance_id":\s*(\d+)/', is_string($contenu) ? $contenu : json_encode($contenu), $m);

                return Http::response(['stop_reason' => 'tool_use', 'content' => [[
                    'type' => 'tool_use', 'name' => 'proposer_menu_choix', 'input' => ['options' => [
                        ['id' => 'charger', 'libelle' => 'Charger la Sentinelle dans un cri de guerre',
                            'type' => 'attaque', 'cible_id' => (int) ($m[1] ?? 0)],
                        ['id' => 'jauger', 'libelle' => 'Jauger l\'adversaire du regard', 'type' => 'dialogue'],
                    ]]],
                ]]);
            }

            return Http::response([], 500);
        }

        return Http::response([], 200);
    });

    [$alice, $groupe, $heros, , $instance] = queteUnMonstreAdjacent();

    $menu = genererMenuPour($groupe, $alice, $heros);
    $attaque = collect($menu['options'])->firstWhere('type', 'attaque');

    expect($attaque['libelle'])->toBe('Charger la Sentinelle dans un cri de guerre') // habillage IA
        ->and((int) $attaque['cible_id'])->toBe((int) $instance->id);                  // binding moteur
});
