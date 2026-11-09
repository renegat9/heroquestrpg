<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Partie\Arene\PuissanceArene;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;

/**
 * MODE ARÈNE, lot T3 — la PUISSANCE D'ÉQUIPE (F9528 p. 15-16) : un héros vaut 1 + les dés de sa meilleure attaque, sort
 * compris ; un monstre vaut ses dés d'attaque ; le Defender vaut au plus le Challenger.
 */
beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class, SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class, MobilierSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
    $this->joueur = connecterJoueur();
});

function idMonstreArene(string $nom): int
{
    return (int) Monstre::where('nom_base', $nom)->value('id');
}

it('compte comme le livret : 1 + l\'arme, ou 1 + le sort quand il frappe plus fort (le Génie vaut 6)', function () {
    $puissance = app(PuissanceArene::class);

    $barbare = Personnage::findOrFail($this->postJson('/api/personnages', ['nom' => 'Brakka', 'classe' => 'barbare', 'mode' => 'arene'])->json('personnage.id'));
    $mage = Personnage::findOrFail($this->postJson('/api/personnages', ['nom' => 'Ilyra', 'classe' => 'magicien', 'mode' => 'arene', 'elements' => ['air', 'feu', 'eau']])->json('personnage.id'));

    expect($puissance->duHeros($barbare))->toBe(1 + (int) $barbare->des_attaque)
        // « 6 — Genie spell » (p. 15) : le Génie frappe à 5 dés, plus fort que la dague du magicien.
        ->and($mage->sorts()->where('nom', 'Génie')->exists())->toBeTrue()
        ->and($puissance->duHeros($mage))->toBe(6)
        // Un monstre vaut ses dés d'attaque (roster p. 16, colonne Total) : gobelin 2, orque 3.
        ->and($puissance->duMonstre(Monstre::where('nom_base', 'Gobelin')->first()))->toBe(2)
        ->and($puissance->duMonstre(Monstre::where('nom_base', 'Orque')->first()))->toBe(3);
});

it('le salon publie les puissances et ce qui reste abordable ; le Defender ne dépasse jamais le Challenger', function () {
    $barbare = Personnage::findOrFail($this->postJson('/api/personnages', ['nom' => 'Brakka', 'classe' => 'barbare', 'mode' => 'arene'])->json('personnage.id'));
    $code = $this->postJson('/api/arene', ['personnage_id' => $barbare->id])->json('groupe.identifiant');
    $challenger = 1 + (int) $barbare->des_attaque;

    $salon = $this->getJson("/api/groupes/{$code}/etat")->json('groupe.arene');
    expect($salon['puissance_challenger'])->toBe($challenger)
        ->and($salon['puissance_defender'])->toBe(0)
        ->and($salon['combattants'][0]['puissance'])->toBe($challenger);

    // On ajoute des gobelins (2) tant qu'ils restent abordables, puis le serveur refuse le suivant.
    $ajoutes = 0;
    while (($challenger - 2 * $ajoutes) >= 2) {
        $this->postJson("/api/arene/{$code}/defender", ['monstre_id' => idMonstreArene('Gobelin')])->assertOk();
        $ajoutes++;
    }
    $salon = $this->getJson("/api/groupes/{$code}/etat")->json('groupe.arene');
    $gobelin = collect($salon['monstres'])->firstWhere('nom_base', 'Gobelin');
    expect($salon['puissance_defender'])->toBe(2 * $ajoutes)
        ->and($gobelin['abordable'])->toBe($challenger - 2 * $ajoutes >= 2);

    $this->postJson("/api/arene/{$code}/defender", ['monstre_id' => idMonstreArene('Gargouille')])
        ->assertUnprocessable()->assertJsonValidationErrors(['monstre_ids' => 'ne peut pas dépasser']);

    // Retirer une figure la rend de nouveau abordable.
    $salon = $this->deleteJson("/api/arene/{$code}/defender/0")->assertOk()->json('groupe.arene');
    expect($salon['puissance_defender'])->toBe(2 * ($ajoutes - 1))
        ->and(collect($salon['monstres'])->firstWhere('nom_base', 'Gobelin')['abordable'])->toBeTrue();
});

it('le lancement revalide : un Defender devenu trop fort ne part pas', function () {
    $barbare = Personnage::findOrFail($this->postJson('/api/personnages', ['nom' => 'Brakka', 'classe' => 'barbare', 'mode' => 'arene'])->json('personnage.id'));
    $code = $this->postJson('/api/arene', ['personnage_id' => $barbare->id])->json('groupe.identifiant');
    $this->postJson("/api/arene/{$code}/pret", ['pret' => true])->assertOk();

    // Un Defender posé en base au-delà de la puissance (un combattant parti, une copie qui vaut moins…).
    Groupe::where('identifiant', $code)->update(['arene_defender' => json_encode(array_fill(0, 5, idMonstreArene('Gargouille')))]);

    $salon = $this->getJson("/api/groupes/{$code}/etat")->json('groupe.arene');
    expect($salon['lancement_possible'])->toBeFalse()
        ->and($salon['lancement_motif'])->toContain('dépasse la puissance du Challenger');
    $this->postJson("/api/arene/{$code}/lancer")->assertUnprocessable();
});
