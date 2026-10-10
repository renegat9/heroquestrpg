<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Models\Monstre;
use App\Partie\MoteurDegats;
use App\Partie\MoteurDread;
use App\Partie\ResolveurTour;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * MONSTRE À PHASES (chantier transverse 2026-10-04, lot C d'Against the Ogre
 * Horde p. 6) : « Some powerful foes adopt new statistics as the heroes
 * battle them […] still considered the same monster for game effects such as
 * spells. » Ces tests exercent l'UNIQUE point de passage de la mort d'un
 * monstre — `MoteurDegats::infligerAMonstre()` — et vérifient qu'il décide
 * dans le bon ORDRE : défense réactive à usage unique (Résilience/Demon
 * Wings) → increvable une fois (Sir Ragnar) → changement de phase (Gruzbella,
 * Spawn of the Pit, Gretzl) → mort réelle (avec reddition pour Gruzbella).
 *
 * Douze chemins de dégâts traversaient autrefois leur propre écriture de
 * `pv_body`/`etat` : ce fichier n'en rejoue qu'une poignée (le reste est
 * couvert par la suite Pest existante — DreadTest, SortsDreadCartesTest,
 * CombatTest, JournalCombatTest, tous verts après le passage par ce point de
 * passage unique), et ajoute UN test de bout en bout via `ResolveurTour::
 * frapper()` pour prouver que le chemin RÉEL (un héros qui frappe) l'emprunte
 * bien aussi.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class,
        SortSeeder::class, SortDreadSeeder::class, ConditionSeeder::class, MobilierSeeder::class]);
});

it('fait adopter à Gruzbella sa forme suivante à 0 Body, sans jamais la tuer avant la dernière phase', function () {
    $ctx = demarrerQueteAvecMonstre('Gruzbella Hammerhand');
    $instance = $ctx['instance'];
    $degats = app(MoteurDegats::class);

    expect($instance->monstre->nom_base)->toBe('Gruzbella Hammerhand')
        ->and((int) $instance->pv_body)->toBe(5);

    // Premier coup fatal : la RÉSILIENCE (ignore_degats_attaque) joue d'abord
    // — à usage unique pour toute la rencontre, jamais gaspillée avant qu'un
    // coup ne menace vraiment la phase courante.
    $r1 = $degats->infligerAMonstre($instance, 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);
    expect($r1['reaction'])->toBe('ignore_degats_attaque')
        ->and($r1['degats'])->toBe(0)
        ->and($r1['vaincu'])->toBeFalse()
        ->and($r1['changement_phase'])->toBeNull()
        ->and((int) $instance->fresh()->pv_body)->toBe(5)
        ->and($instance->monstre->nom_base)->toBe('Gruzbella Hammerhand');

    // Deuxième coup fatal : la Résilience est épuisée, elle adopte sa phase
    // suivante — NOUVEAU monstre_id, PV PLEINS de la nouvelle phase, toujours
    // la MÊME instance (position, usages déjà dépensés inchangés).
    $r2 = $degats->infligerAMonstre($instance->fresh()->load('monstre'), 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);
    expect($r2['vaincu'])->toBeFalse()
        ->and($r2['reaction'])->toBeNull()
        ->and($r2['changement_phase'])->toMatchArray(['avant' => 'Gruzbella Hammerhand', 'apres' => 'Gruzbella Déterminée'])
        ->and($r2['pv_body'])->toBe(5);

    $instance->refresh()->load('monstre');
    expect($instance->monstre->nom_base)->toBe('Gruzbella Déterminée')
        ->and((int) $instance->pv_body)->toBe(5)
        ->and($instance->attaqueEffective())->toBe(5); // la NOUVELLE phase, relue sans drapeau

    // Troisième coup fatal : sa dernière phase.
    $r3 = $degats->infligerAMonstre($instance, 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);
    expect($r3['changement_phase'])->toMatchArray(['avant' => 'Gruzbella Déterminée', 'apres' => 'Gruzbella Imprudente'])
        ->and($r3['vaincu'])->toBeFalse();

    $instance->refresh()->load('monstre');
    expect($instance->monstre->nom_base)->toBe('Gruzbella Imprudente');

    // Quatrième coup fatal, sur la DERNIÈRE phase : plus de phase suivante,
    // plus de Résilience disponible → reddition (jamais une vraie mort pour
    // elle, « elle n'est pas maléfique »), 1000 po créditées au groupe.
    $orAvant = (int) $ctx['groupe']->fresh()->or;
    $r4 = $degats->infligerAMonstre($instance, 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);

    expect($r4['vaincu'])->toBeTrue()
        ->and($r4['changement_phase'])->toBeNull()
        ->and($r4['reddition'])->toBeTrue()
        ->and($r4['or_gagne'])->toBe(1000);

    expect((int) $ctx['groupe']->fresh()->or)->toBe($orAvant + 1000);
    expect($instance->fresh()->etat)->toBe('vaincu');
});

it('fait adopter à Spawn of the Pit sa forme déchaînée, sans capacité réactive (le livret n\'en donne aucune)', function () {
    $ctx = demarrerQueteAvecMonstre('Spawn of the Pit');
    $instance = $ctx['instance'];
    $degats = app(MoteurDegats::class);

    $r1 = $degats->infligerAMonstre($instance, 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);
    expect($r1['reaction'])->toBeNull() // aucune réaction sourcée pour ce monstre
        ->and($r1['changement_phase'])->toMatchArray(['avant' => 'Spawn of the Pit', 'apres' => 'Spawn of the Pit déchaîné'])
        ->and($r1['vaincu'])->toBeFalse()
        ->and($r1['pv_body'])->toBe(6); // Body de sa forme Enraged

    $instance->refresh()->load('monstre');
    expect($instance->monstre->nom_base)->toBe('Spawn of the Pit déchaîné');

    // Sa dernière phase meurt NORMALEMENT (aucune reddition déclarée pour ce
    // monstre — seule Gruzbella en porte une).
    $r2 = $degats->infligerAMonstre($instance, 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);
    expect($r2['vaincu'])->toBeTrue()
        ->and($r2['reddition'])->toBeFalse()
        ->and($r2['or_gagne'])->toBe(0);
});

it('garde le même nom affiché (habillage) à travers un changement de phase — « toujours le même monstre »', function () {
    $ctx = demarrerQueteAvecMonstre('Gretzl la Porte-Fléau');
    $instance = $ctx['instance'];

    // L'IA (ou, ici, le test) habille l'instance d'un nom propre AVANT le
    // combat — c'est ce qui doit rester stable : le joueur voit « Gretzl »,
    // jamais le nom de catalogue de sa forme suivante (secret de Zargon,
    // Ogre Horde p. 6).
    app(\App\Partie\MoteurSorts::class); // s'assure du binding, sans effet ici
    $instance->update(['habillage' => ['nom' => 'Gretzl']]);

    $avant = $instance->nomAffiche();
    expect($avant)->toBe('Gretzl');

    $degats = app(MoteurDegats::class);
    // Premier coup fatal : Demon Wings (ignore_degats_attaque) joue d'abord
    // — même ordre que pour Gruzbella, elle la porte aussi.
    $r1 = $degats->infligerAMonstre($instance, 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);
    expect($r1['reaction'])->toBe('ignore_degats_attaque')
        ->and($instance->fresh()->nomAffiche())->toBe('Gretzl');

    // Deuxième coup fatal : Demon Wings épuisée, elle change de forme — le
    // nom AFFICHÉ (habillage) reste « Gretzl », seul le catalogue change.
    $degats->infligerAMonstre($instance->fresh()->load('monstre'), 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);

    expect($instance->fresh()->nomAffiche())->toBe('Gretzl')
        ->and($instance->fresh()->load('monstre')->monstre->nom_base)->toBe('Demonspider');
});

it('laisse Sir Ragnar survivre à 1 PV une seule fois, sans jamais déclarer de phase', function () {
    $ctx = demarrerQueteAvecMonstre('Sir Ragnar');
    $instance = $ctx['instance'];
    $degats = app(MoteurDegats::class);

    expect(Monstre::where('nom_base', 'Sir Ragnar')->firstOrFail()->phase_suivante)->toBeNull();

    $r1 = $degats->infligerAMonstre($instance, 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);
    expect($r1['survie_increvable'])->toBeTrue()
        ->and($r1['vaincu'])->toBeFalse()
        ->and($r1['changement_phase'])->toBeNull()
        ->and($r1['pv_body'])->toBe(1);

    expect((int) $instance->fresh()->pv_body)->toBe(1);

    // La seconde fois, plus de plancher : il meurt pour de vrai.
    $r2 = $degats->infligerAMonstre($instance->fresh(), 1, MoteurDegats::SOURCE_ATTAQUE_HEROS);
    expect($r2['vaincu'])->toBeTrue()
        ->and($r2['survie_increvable'])->toBeFalse();
});

it('ne touche à rien pour un monstre ORDINAIRE — comportement inchangé', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $instance = $ctx['instance'];

    $r = app(MoteurDegats::class)->infligerAMonstre($instance, 1, MoteurDegats::SOURCE_ATTAQUE_HEROS);

    expect($r['vaincu'])->toBeTrue()
        ->and($r['changement_phase'])->toBeNull()
        ->and($r['reaction'])->toBeNull()
        ->and($r['reddition'])->toBeFalse();
    expect($instance->fresh()->etat)->toBe('vaincu');
});

it('garde le ratio d\'adaptation au groupe (pvAdapte) à travers un changement de phase', function () {
    // `DemarreurQuete::pvAdapte()` ajuste le Body d'un boss/sous-boss à la
    // taille du groupe UNE SEULE fois, au placement. Un changement de phase
    // ne doit pas le défaire : un boss adouci pour un duo (ratio 0,6 ici)
    // reste adouci dans sa forme suivante, jamais reporté au catalogue PLEIN.
    $ctx = demarrerQueteAvecMonstre('Spawn of the Pit');
    $instance = $ctx['instance'];

    // Simule un groupe réduit : Body catalogue 4, adapté à 2 (ratio 0,5).
    $instance->update(['pv_body' => 2, 'pv_body_max' => 2]);

    $resultat = app(MoteurDegats::class)->infligerAMonstre($instance, 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);

    // Enraged : catalogue 6 × ratio 0,5 = 3 (pas 6, le Body catalogue plein).
    expect($resultat['pv_body'])->toBe(3)
        ->and($resultat['pv_body_max'])->toBe(3);
});

it('passe par le même point de passage depuis le sort de ZONE du Dread (MoteurDread::blesserMonstre)', function () {
    // Un sort de zone du MJ (Tempête de feu, Nuée d'Effroi…) peut blesser un
    // monstre allié resté dans la zone — un second chemin de dégâts, distinct
    // de l'attaque d'un héros, qui doit traverser le MÊME point de passage.
    $ctx = demarrerQueteAvecMonstre('Spawn of the Pit');
    $instance = $ctx['instance'];

    $blesser = new ReflectionMethod(MoteurDread::class, 'blesserMonstre');
    $resultat = $blesser->invoke(app(MoteurDread::class), $instance, 99, null);

    expect($resultat['changement_phase'])->toMatchArray(['avant' => 'Spawn of the Pit', 'apres' => 'Spawn of the Pit déchaîné'])
        ->and($resultat['vaincu'])->toBeFalse();
});

it('fait traverser le VRAI chemin de combat (ResolveurTour::frapper) par le point de passage unique', function () {
    // Bout en bout : un héros qui frappe RÉELLEMENT une Gruzbella déjà rendue
    // à 1 PV (sa Résilience déjà consommée) la fait passer à sa phase
    // suivante — preuve que le câblage des douze chemins de dégâts (et pas
    // seulement l'appel direct au moteur) emprunte bien ce point de passage.
    $ctx = demarrerQueteAvecMonstre('Gruzbella Hammerhand');
    $instance = $ctx['instance'];
    $instance->update(['pv_body' => 1, 'capacites_reactives_utilisees' => ['ignore_degats_attaque']]);

    // Tous les dés à « 1 » (crâne) : chaque volée touche ou ne pare rien
    // (un monstre ne pare que sur bouclier NOIR), quel que soit le nombre
    // exact de dés lancés de chaque côté.
    desFiges(array_fill(0, 20, 1));

    $payload = app(ResolveurTour::class)->frapper(
        $ctx['groupe']->fresh(), $ctx['quete']->fresh()->load('carte'),
        $ctx['etatHeros']->fresh(), $ctx['heros']->fresh(), $instance->fresh()->load('monstre'),
        acteur: ['type' => 'personnage', 'id' => $ctx['heros']->id, 'nom' => $ctx['heros']->nom],
    );

    expect($payload['changement_phase'])->toMatchArray(['avant' => 'Gruzbella Hammerhand', 'apres' => 'Gruzbella Déterminée'])
        ->and($payload['cible_vaincue'])->toBeFalse();

    $instance->refresh()->load('monstre');
    expect($instance->monstre->nom_base)->toBe('Gruzbella Déterminée')
        ->and((int) $instance->pv_body)->toBe(5);
});
