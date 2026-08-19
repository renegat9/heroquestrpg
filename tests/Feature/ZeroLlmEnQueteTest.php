<?php

declare(strict_types=1);

use App\Agent\ClientLLM;
use App\Events\NarrationDiffusee;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Piege;
use App\Models\Quete;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * LE garde-fou de la bascule 2026-08-18 (« l'IA fabrique la quête, elle ne la
 * joue plus », LOT C) : une quête entière — déplacement, piège, fouille,
 * porte, découverte de salle, attaque qui achève un monstre — se joue sans
 * qu'un SEUL appel LLM ne parte, du moment que `quetes.recits` est déjà
 * peuplé (le cas nominal après que le job de pré-génération a rendu).
 *
 * Le projet teste les PROPRIÉTÉS, pas les déclarations (cf. CartesSourcesTest,
 * ArmurerieConformeTest) : plutôt que de vérifier que tel job n'est plus
 * dispatché (ce que les autres tests de ce lot font déjà, fichier par
 * fichier), on lie `App\Agent\ClientLLM` — le SEUL point d'entrée vers
 * Anthropic/Gemini dans tout le runtime — à un faux qui LÈVE sur le moindre
 * appel, puis on joue. Un appel non intercepté par un try/catch (la plupart
 * des chemins moteur n'en ont pas) ferait exploser le test avec une pile
 * d'appel explicite ; un appel avalé par un try/catch (HabillerMonstres,
 * best-effort) reste détecté par le compteur vérifié à la fin.
 *
 * `Http::fake()` sans argument reste posé en filet — si jamais un appel
 * HTTP réel partait malgré le faux ClientLLM (un job qui irait chercher un
 * AUTRE client par exemple), il échouerait aussi plutôt que de taper un vrai
 * fournisseur.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

/**
 * Remplace le déplacement/créneau du héros pour enchaîner une NOUVELLE action
 * sans passer par un tour complet (le test ne porte pas sur le cycle de
 * round, seulement sur l'absence d'appel LLM). `deplacement_tour`/`_restant`
 * remis à `null` : sinon l'allocation figée au premier déplacement du test
 * (MenuMoteur ne la re-tire QUE si elle est nulle) s'épuiserait au fil des
 * six étapes au lieu d'être re-tirée comme à chaque round réel.
 */
function reouvrirCreneauxZeroLlm(EtatPersonnageQuete $etat): void
{
    $etat->update([
        'a_joue' => false, 'a_deplace' => false, 'a_agi' => false,
        'deplacement_tour' => null, 'deplacement_restant' => null,
    ]);
}

/** Pose UN piège caché (remplace ceux de l'assembleur, comme PiegesTest::poserPieges). */
function poserPiegeCacheZeroLlm(Quete $quete, int $x, int $y, string $nom): void
{
    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['pieges'] = [['x' => $x, 'y' => $y, 'piege_id' => Piege::where('nom', $nom)->value('id'), 'etat' => 'cache']];
    $carte->update(['grille' => $grille]);
    $quete->load('carte');
}

/**
 * Pose UNE porte fermée (sans verrou) sur l'arête reliant (x,y) à la case
 * libre adjacente trouvée dans $salle0 — remplace les portes de l'assembleur.
 *
 * @return array{x: int, y: int, cote: string} la porte posée
 */
function poserPorteAdjacenteZeroLlm(Quete $quete, array $salle0, int $x, int $y): array
{
    $dansSalle0 = fn (int $cx, int $cy): bool => $cx >= $salle0['x'] && $cx < $salle0['x'] + $salle0['largeur']
        && $cy >= $salle0['y'] && $cy < $salle0['y'] + $salle0['hauteur'];

    foreach ([[1, 0, 'e', 0, 0], [-1, 0, 'e', -1, 0], [0, 1, 's', 0, 0], [0, -1, 's', 0, -1]] as [$dx, $dy, $cote, $ox, $oy]) {
        if (! caseQueteLibre($quete, $x + $dx, $y + $dy) || ! $dansSalle0($x + $dx, $y + $dy)) {
            continue;
        }

        $porte = ['x' => $x + $ox, 'y' => $y + $oy, 'cote' => $cote, 'etat' => 'fermee'];

        $carte = $quete->carte;
        $grille = $carte->grille;
        $grille['portes'] = [$porte];
        $carte->update(['grille' => $grille]);
        $quete->load('carte');

        return $porte;
    }

    throw new RuntimeException('Aucune arête libre autour du héros pour poser une porte — scénario de test invalide.');
}

it('joue une quête complète — déplacement, piège, fouille, porte, découverte de salle, attaque fatale — sans un SEUL appel LLM', function () {
    // Faux client LLM qui lève sur le moindre appel, ET compte les appels
    // (filet double : la pile explicite pour un chemin sans try/catch, le
    // compteur pour un chemin best-effort qui avalerait l'exception).
    $faux = new class implements ClientLLM
    {
        public int $appels = 0;

        public function genererStructure(string $system, array $messages, array $outil, ?string $model = null, ?int $maxTokens = null): array
        {
            $this->appels++;

            throw new RuntimeException("ClientLLM::genererStructure appelé pendant le runtime (outil « {$outil['name']} ») — LOT C violé.");
        }

        public function genererTexte(string $system, array $messages, ?string $model = null): string
        {
            $this->appels++;

            throw new RuntimeException('ClientLLM::genererTexte appelé pendant le runtime — LOT C violé.');
        }

        public function modeleParDefaut(): string
        {
            return 'faux-modele-de-test';
        }
    };

    // Démarrage de quête AVANT de lier le faux client : le job narrateur-side
    // encore dispatché ici (HabillerMonstres) appelle légitimement l'IA — ce
    // n'est PAS le périmètre de ce lot (« l'IA fabrique la quête », pas « l'IA
    // joue la quête ») — et son échec est déjà couvert par le repli standard
    // de toute la suite (`services.anthropic.api_key` / `.gemini.api_key` à
    // null, posé au beforeEach) : catalogue conservé, aucun blocage.
    // QUEUE_CONNECTION=sync (phpunit.xml) fait tourner ce job EN LIGNE ici,
    // donc AVANT que le faux client n'existe.
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();

    $salles = $quete->carte->grille['salles'];
    expect(count($salles))->toBeGreaterThan(1); // plancher de 5 salles (CLAUDE.md)

    // Pack « déjà peuplé à la main » — simule le job de pré-génération déjà
    // rendu : c'est le chemin PRINCIPAL qu'on veut exercer (pas le repli
    // config/narration.php, qui a son propre test dans NarrationVoixTest).
    $quete->update(['recits' => [
        'temps_forts' => [
            'piege_declenche' => ['ambiance' => 'tension', 'variantes' => ['{heros} déclenche un piège écrit d\'avance — zéro appel réseau.']],
            'progression' => ['ambiance' => 'tension', 'variantes' => ['{heros} agit ; la scène vient du pack pré-généré, pas d\'un modèle.']],
            'attaque_mort' => ['ambiance' => 'tension', 'variantes' => ['Le coup de {heros} achève {monstre} — récit piochE dans le pack.']],
        ],
        'salles' => [
            1 => ['texte' => 'La salle 1, décrite mot pour mot par le pack — aucun LLM consulté.', 'ambiance' => 'mystere'],
        ],
    ]]);

    // À PARTIR D'ICI, le tour se joue : plus un SEUL appel LLM n'est permis —
    // c'est le périmètre exact du Lot C (runtime de partie).
    app()->instance(ClientLLM::class, $faux);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    Event::fake([NarrationDiffusee::class]);

    // 1) DÉPLACEMENT — trivial, jamais narré (ChoixController).
    $dest = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'se_deplacer', 'parametres' => $dest])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'deplacement');
    Event::assertNotDispatched(NarrationDiffusee::class);

    // 2) PIÈGE — un déplacement sur une case piégée déclenche sa propre
    // narration SYNCHRONE (MoteurPieges), indépendamment de la trivialité
    // du déplacement qui le porte.
    reouvrirCreneauxZeroLlm($etat->refresh());
    $cible = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    poserPiegeCacheZeroLlm($quete, $cible['x'], $cible['y'], 'Piège à lances');
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'se_deplacer', 'parametres' => $cible])
        ->assertStatus(202)
        ->assertJsonPath('resultat.pieges_declenches.0.piege.nom', 'Piège à lances');
    // Placeholder {heros} substitué par le nom du héros — jamais laissé tel
    // quel (le texte brut du pack, lui, contient encore « {heros} »).
    Event::assertDispatched(NarrationDiffusee::class, fn (NarrationDiffusee $e) => str_starts_with($e->texte, 'Albrecht déclenche un piège écrit d\'avance'));

    // 3) FOUILLE — TRÉSOR : carte pipée sur « tresor », résolue sans dé
    // (empilerCarteFouille), narrée depuis le pack (« progression »).
    reouvrirCreneauxZeroLlm($etat->refresh());
    empilerCarteFouille($quete, ['issue' => 'tresor', 'or' => 20]);
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    Event::fake([NarrationDiffusee::class]); // on isole les assertions par étape
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_tresor'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.issue', 'tresor');
    Event::assertDispatched(NarrationDiffusee::class);

    // 4) OUVERTURE DE PORTE — porte simple posée sur une arête de la salle de
    // départ, ouverte à la main (E2 : ne consomme pas le tour).
    reouvrirCreneauxZeroLlm($etat->refresh());
    $salle0 = $salles[0];
    $porte = poserPorteAdjacenteZeroLlm($quete, $salle0, (int) $etat->position_x, (int) $etat->position_y);
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    Event::fake([NarrationDiffusee::class]);
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => "ouvrir_porte_{$porte['x']}_{$porte['y']}_{$porte['cote']}",
    ])->assertStatus(202)->assertJsonPath('resultat.type', 'ouvrir_porte');
    // (narration possible ou non selon ce qu'il y a derrière — seul compte
    // ici qu'aucun appel LLM ne soit parti, vérifié en fin de test)

    // 5) DÉCOUVERTE DE SALLE — le héros est amené dans la salle 1, encore non
    // découverte : sa description vient du pack (`recits.salles.1`), jamais
    // du repli « salle_decouverte » générique.
    reouvrirCreneauxZeroLlm($etat->refresh());
    $s1 = $salles[1];
    $centre = ['x' => (int) $s1['x'] + intdiv((int) $s1['largeur'], 2), 'y' => (int) $s1['y'] + intdiv((int) $s1['hauteur'], 2)];
    $etat->update(['position_x' => $centre['x'], 'position_y' => $centre['y']]);
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    Event::fake([NarrationDiffusee::class]);
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);
    Event::assertDispatched(NarrationDiffusee::class, fn (NarrationDiffusee $e) => str_starts_with($e->texte, 'La salle 1, décrite mot pour mot par le pack'));
    expect($quete->fresh()->sallesDecouvertes())->toContain(1);

    // 6) ATTAQUE FATALE — dernier monstre actif+révélé achevé : le combat se
    // termine PENDANT cette action (ChoixController redevient narratif juste
    // après le coup qui l'achève), narré depuis le pack (« attaque_mort »).
    reouvrirCreneauxZeroLlm($etat->refresh());
    $proie = $quete->instancesMonstres()->with('monstre')->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($proie->id)->update(['etat' => 'vaincu']);
    $contact = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    $proie->update(['position_x' => $contact['x'], 'position_y' => $contact['y'], 'pv_body' => 1, 'revele' => true]);
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    desFiges([1, 4, 4, ...array_fill(0, (int) $proie->monstre->defense, 4)]);
    Event::fake([NarrationDiffusee::class]);
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attaquer', 'parametres' => ['cible_id' => $proie->id]])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'attaque')
        ->assertJsonPath('resultat.cible_vaincue', true);
    Event::assertDispatched(NarrationDiffusee::class, fn (NarrationDiffusee $e) => str_starts_with($e->texte, 'Le coup de Albrecht achève'));

    // Le verdict final : ni la pile (aucune exception remontée jusqu'ici — un
    // appel non protégé aurait fait échouer le test bien avant cette ligne)
    // ni le compteur (un appel avalé par un try/catch best-effort) ne
    // montrent le moindre appel au LLM sur toute la partie jouée.
    expect($faux->appels)->toBe(0);
});
