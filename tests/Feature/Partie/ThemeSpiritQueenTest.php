<?php

declare(strict_types=1);

use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\Monstre;
use App\Partie\BestiaireGroupe;
use App\Partie\DemarreurQuete;
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
 * SPIRIT QUEEN'S TORMENT — thème ALLUMÉ (chantier EXT-SPIRIT, 2026-10-23 ; René : « Oui »).
 *
 * La boîte entre dans la rotation des thèmes, À LA FIN (son index suit `kellars_keep`). Rencontre finale : Nelath, la
 * Reine des Esprits (boss) ; sous-boss : Kavra. Aucune créature de tier `base` : la masse commune et les forts ne
 * changent pas, seul le choix des deux sorciers nommés est porté par le thème.
 *
 * Ce fichier prouve, par tirage réel de `acheterMonstres()` (réflexion, comme les autres `Theme*Test`) :
 *  1. la boîte est rangée à la fin de la rotation, avec son libellé, et les campagnes figées ne bougent pas ;
 *  2. sous son thème, la rencontre finale tire Nelath et le sous-boss tire Kavra ;
 *  3. les autres thèmes ne tirent JAMAIS une créature de cette boîte quand ils ont leur propre candidat : une
 *     préférence, jamais un filtre. Le repli sur le pool entier, pour un thème sans candidat, est celui qui existait.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class,
    ]);
});

/**
 * Achats d'une rencontre pour un thème donné, par le GABARIT nommé (réflexion sur la méthode privée).
 *
 * @return list<Monstre>
 */
function achatsSpiritQueenDuGabarit(string $theme, string $gabarit, int $graine): array
{
    $demarreur = app(DemarreurQuete::class);
    $methode = new ReflectionMethod($demarreur, 'acheterMonstres');
    $methode->setAccessible(true);
    $structure = GabaritQuete::where('nom', $gabarit)->firstOrFail()->structure;

    return $methode->invoke(
        $demarreur, $structure, 40, 6, 1, $graine, BestiaireGroupe::auto($theme),
    );
}

it('la boîte est ALLUMÉE : rangée à la FIN de la rotation, derrière kellars_keep, avec son libellé', function () {
    $themes = DemarreurQuete::BOITES_THEMATIQUES;
    $position = array_search('spirit_queen', $themes, true);

    expect($position)->not->toBeFalse()
        ->and($themes[$position - 1])->toBe('kellars_keep', 'une boîte ajoutée après coup se met À LA FIN : l\'ordre EST la rotation')
        ->and($position)->toBe(count($themes) - 1, 'elle est la dernière entrée')
        ->and(array_unique($themes))->toHaveCount(count($themes), 'aucun thème en double')
        ->and(DemarreurQuete::LIBELLES_BOITES['spirit_queen'])->toBe("Spirit Queen's Torment")
        ->and(array_key_exists('spirit_queen', DemarreurQuete::BOITES_INCOMPLETES))->toBeFalse('une boîte allumée n\'est pas désactivée');
});

it('une campagne FIGÉE sur un autre thème ne change pas quand la rotation s\'allonge (Spirit Queen = index suivant)', function () {
    $demarreur = app(DemarreurQuete::class);
    $position = array_search('spirit_queen', DemarreurQuete::BOITES_THEMATIQUES, true);

    // Un identifiant qui donnerait AUJOURD'HUI la Spirit Queen (modulo = nombre de thèmes), figé sur une boîte antérieure.
    $id = $position + count(DemarreurQuete::BOITES_THEMATIQUES);
    $groupe = new Groupe(['theme_bestiaire' => 'dread_moon']);
    $groupe->id = $id;

    expect($demarreur->themeBestiaire($id))->toBe('spirit_queen')
        ->and($demarreur->themeBestiaireDuGroupe($groupe))->toBe('dread_moon');
});

it('Kavra et Nelath portent la boîte spirit_queen, et AUCUNE créature de tier base ne la porte', function () {
    expect(Monstre::where('boite', 'spirit_queen')->orderBy('nom_base')->pluck('nom_base')->all())
        ->toBe(['Kavra', 'Nelath, la Reine des Esprits']);

    // Une créature base de cette boîte serait tirée dans TOUTES les campagnes (masse commune, `autorise()` en auto).
    expect(Monstre::where('boite', 'spirit_queen')->where('tier', 'base')->exists())->toBeFalse();
});

it('les blocs de stats suivent le Dread Warrior de la CARTE (M7 A4 D4 B3 Mi3) : D+1 pour Kavra, 6 Body pour Nelath', function () {
    $kavra = Monstre::where('nom_base', 'Kavra')->firstOrFail();
    $nelath = Monstre::where('nom_base', 'Nelath, la Reine des Esprits')->firstOrFail();

    // Guerrier du Chaos = Dread Warrior de la carte (reference/20_cartes_monstres.md, p03 : 7/4/4/3/3). Le paquet de fan
    // (6/3/4/1/3) de l'ancien plan est périmé : la carte gagne, partout (René, 2026-10-05).
    $carte = Monstre::where('nom_base', 'Guerrier du Chaos')->firstOrFail();
    expect([$carte->deplacement, $carte->attaque, $carte->defense, $carte->pv_body, $carte->pv_mind])->toBe([7, 4, 4, 3, 3]);

    // Kavra : « stats of a Dread Warrior, with 1 additional Defend die » (G0053 p. 11).
    expect([$kavra->deplacement, $kavra->attaque, $kavra->defense, $kavra->pv_body, $kavra->pv_mind])->toBe([7, 4, 5, 3, 3]);

    // Nelath : « a Dread Warrior with 6 Body Points » (G0053 p. 33).
    expect([$nelath->deplacement, $nelath->attaque, $nelath->defense, $nelath->pv_body, $nelath->pv_mind])->toBe([7, 4, 4, 6, 3]);
});

it('le coût de Kavra et de Nelath suit la méthode mesurée : attaques pour l\'abattre, lues sur la droite de leur palier', function () {
    $kavra = Monstre::where('nom_base', 'Kavra')->firstOrFail();
    $nelath = Monstre::where('nom_base', 'Nelath, la Reine des Esprits')->firstOrFail();

    // Kavra, sous-boss : 3 / (1,5 − 5/6) = 4,5 attaques ; droite des sous-boss (Ogre champion 7,2 → 11 ; Doralf 10,5 → 13,
    // pente 2 / 3,3 ≈ 0,61) : 11 + 0,61 × (4,5 − 7,2) ≈ 9,4 ⇒ 9.
    $attaquesKavra = $kavra->pv_body / (1.5 - $kavra->defense / 6);
    expect((int) round(11 + (2 / 3.3) * ($attaquesKavra - 7.2)))->toBe((int) $kavra->cout)
        ->and((int) $kavra->cout)->toBe(9);

    // Nelath, boss : 6 / (1,5 − 4/6) = 7,2 attaques ; droite des boss (Seigneur 15 → 20, pente 0,5) : 16 — comme Fellmarak.
    $attaquesNelath = $nelath->pv_body / (1.5 - $nelath->defense / 6);
    expect((int) round(20 + 0.5 * ($attaquesNelath - 15)))->toBe((int) $nelath->cout)
        ->and((int) $nelath->cout)->toBe(16);
});

it('sous le thème spirit_queen, la rencontre finale tire NELATH et le sous-boss tire KAVRA (graines 1 à 30)', function () {
    foreach (range(1, 30) as $graine) {
        $boss = collect(achatsSpiritQueenDuGabarit('spirit_queen', 'Confrontation finale', $graine))
            ->where('tier', 'boss')->pluck('nom_base')->values()->all();
        $sousBoss = collect(achatsSpiritQueenDuGabarit('spirit_queen', 'Antre du sous-boss', $graine))
            ->where('tier', 'sous_boss')->pluck('nom_base')->values()->all();

        expect($boss)->toBe(['Nelath, la Reine des Esprits'], "graine {$graine} : boss")
            ->and($sousBoss)->toBe(['Kavra'], "graine {$graine} : sous-boss");
    }
});

it('PRÉFÉRENCE, PAS FILTRE : un thème qui a son candidat ne tire jamais la boîte d\'à côté (graines 1 à 25)', function () {
    // Le repli de `acheterMonstres()` (pool entier, quand le thème n'a aucun candidat du jalon) est voulu : une boîte
    // pauvre en sous-boss reste jouable. Ce qui serait une fuite, c'est un thème qui A son candidat et tire quand même
    // une créature d'une autre boîte du même palier.
    $fuites = [];

    foreach (['Confrontation finale', 'Antre du sous-boss'] as $gabarit) {
        $structure = GabaritQuete::where('nom', $gabarit)->firstOrFail()->structure;
        $tier = (string) data_get($structure, 'rencontre_finale.tier');
        $pool = (array) data_get($structure, 'rencontre_finale.archetypes', []);
        $creatures = (array) data_get($structure, 'rencontre_finale.creatures', []);

        $candidats = Monstre::query()
            ->where('tier', $tier)
            ->where(fn ($q) => $q->whereIn('archetype_lanceur', $pool)->orWhereIn('nom_base', $creatures))
            ->whereNotIn('nom_base', Monstre::nomsDeFormeSuivante())
            ->get();

        foreach (DemarreurQuete::BOITES_THEMATIQUES as $theme) {
            $aSonCandidat = $candidats->contains(fn (Monstre $m) => in_array($theme, $m->boites(), true));

            if (! $aSonCandidat) {
                continue; // repli écrit : pool entier, rien à vérifier ici.
            }

            foreach (range(1, 25) as $graine) {
                foreach (achatsSpiritQueenDuGabarit($theme, $gabarit, $graine) as $achat) {
                    if ($achat->tier === $tier && ! in_array($theme, $achat->boites(), true)) {
                        $fuites[] = "{$gabarit} / {$theme} / graine {$graine} → {$achat->nom_base}";
                    }
                }
            }
        }
    }

    expect($fuites)->toBe([]);
});
