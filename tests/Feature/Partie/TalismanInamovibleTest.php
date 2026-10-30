<?php

declare(strict_types=1);

use App\Engine\MotsClesEquipement;
use App\Jobs\GenererMenu;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Partie\DonObjet;
use App\Partie\Equipement;
use App\Partie\MoteurPotions;
use App\Partie\MoteurSables;
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
use Illuminate\Validation\ValidationException;

/*
 * Talisman du Savoir maudit — RETRAIT IMPOSSIBLE (chantier RESTES-TALISMAN, 2026-10-29).
 *
 * Source : Prophecy of Telor p. 10, « Removing the Talisman » : « The bearer cannot remove the talisman. If they
 * try, read the following to them: You feel a great heat emanating from the talisman. Lose 1 Body Point. »
 * Écart assumé (plan Telor Q2, retenu par René) : le menu n'OFFRE pas le retrait, donc la pénalité d'1 PV devient
 * inatteignable. Un seul prédicat, `Equipement::retirable()`, lu par le menu et par chaque résolveur.
 *
 * Ces tests utilisent un objet de TEST (la malédiction seule) : la ligne catalogue n'est semée qu'au dernier
 * mécanisme, une fois ses trois lecteurs prouvés en jeu.
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

/** Un talisman de TEST : maudit (`inamovible`) ou ordinaire. Même gain de Mind max que le vrai. */
function inamovibleTalisman(string $nom = 'Talisman maudit d\'essai', bool $maudit = true): Objet
{
    return Objet::create([
        'nom' => $nom,
        'categorie' => 'armure',
        'rarete' => 'unique',
        'prix_base' => 800,
        'emplacement' => 'talisman',
        'effet' => $maudit
            ? ['bonus_pv_mind_max' => 1, MotsClesEquipement::INAMOVIBLE => true]
            : ['bonus_pv_mind_max' => 1],
    ]);
}

/** Une ligne d'inventaire au sac, pour un objet donné. */
function inamovibleAuSac(App\Models\Personnage $heros, Objet $objet): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => $objet->id,
        'emplacement' => 'sac',
        'quantite' => 1,
    ]);
}

/** Le refus d'un résolveur : le texte de ses erreurs de validation, à plat. */
function inamovibleRefus(Closure $geste): string
{
    try {
        $geste();
    } catch (ValidationException $e) {
        return collect($e->errors())->flatten()->implode(' ');
    }

    return '';
}

it('dans le sac, la pièce maudite reste un objet ordinaire : `retirable()` est vrai', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    $ligne = inamovibleAuSac($heros, inamovibleTalisman());

    expect((new Equipement)->retirable($ligne))->toBeTrue();
});

it('une fois portée, la pièce maudite ne se range plus : refus, et elle reste au talisman', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    $equipement = new Equipement;
    $ligne = inamovibleAuSac($heros, inamovibleTalisman());
    $equipement->equiper($heros->refresh(), $ligne);

    $refus = inamovibleRefus(fn () => $equipement->desequiper($heros->refresh(), $ligne->refresh()));

    expect($refus)->toContain('porté pour toujours')
        ->and($ligne->fresh()->emplacement)->toBe('talisman')
        ->and($equipement->retirable($ligne->fresh()))->toBeFalse();
});

it('un autre talisman ne prend pas l\'emplacement tenu par la pièce maudite', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    $equipement = new Equipement;
    $maudit = inamovibleAuSac($heros, inamovibleTalisman());
    $equipement->equiper($heros->refresh(), $maudit);
    $ordinaire = inamovibleAuSac($heros, inamovibleTalisman('Talisman ordinaire d\'essai', maudit: false));

    $refus = inamovibleRefus(fn () => $equipement->equiper($heros->refresh(), $ordinaire->refresh()));

    expect($refus)->toContain('pour toujours')
        ->and($maudit->fresh()->emplacement)->toBe('talisman')
        ->and($ordinaire->fresh()->emplacement)->toBe('sac');
});

it('régression : une pièce ordinaire portée se range toujours — le verrou ne touche que la malédiction', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    $equipement = new Equipement;
    $ligne = inamovibleAuSac($heros, inamovibleTalisman('Talisman ordinaire d\'essai', maudit: false));
    $equipement->equiper($heros->refresh(), $ligne);

    $equipement->desequiper($heros->refresh(), $ligne->refresh());

    expect($ligne->fresh()->emplacement)->toBe('sac');
});

it('le menu n\'offre « Ranger » que pour les pièces qu\'on peut ranger, en quête', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $equipement = new Equipement;
    $maudit = inamovibleAuSac($heros, inamovibleTalisman());
    $equipement->equiper($heros->refresh(), $maudit);
    $epee = inamovibleAuSac($heros->refresh(), Objet::where('nom', 'Épée large')->firstOrFail());
    $equipement->equiper($heros->refresh(), $epee);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $heros->id);

    $options = collect(test()->actingAs($ctx['alice'], 'joueur')
        ->getJson('/api/groupes/table-1/menu')->json('menu.options'));
    $ranger = $options->firstWhere('id', 'ranger');

    // La pièce maudite n'apparaît dans AUCUNE option du menu : ni rangée, ni jetée, ni échangée.
    $idsDansLeMenu = collect(\Illuminate\Support\Arr::dot($options->all()))
        ->filter(fn ($valeur, $cle) => str_ends_with((string) $cle, 'inventaire_id'))
        ->values()->all();

    expect($ranger)->not->toBeNull()
        ->and(collect($ranger['parametres']['pieces'])->pluck('inventaire_id')->all())->toBe([$epee->id])
        ->and($idsDansLeMenu)->not->toContain($maudit->id);
});

it('les sables mouvants ne proposent pas d\'abandonner la pièce maudite portée', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros']->refresh();
    $equipement = new Equipement;
    $equipement->equiper($heros, inamovibleAuSac($heros, inamovibleTalisman()));
    $epee = inamovibleAuSac($heros->refresh(), Objet::where('nom', 'Épée large')->firstOrFail());
    $equipement->equiper($heros->refresh(), $epee);

    $noms = collect(app(MoteurSables::class)->objetsDefaussables($heros->refresh()))->pluck('nom')->all();

    expect($noms)->not->toContain('Talisman maudit d\'essai')
        ->and($noms)->toContain('Épée large');
});

it('la potion d\'alchimie ne transmute jamais la pièce maudite : elle prend la pièce ordinaire', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros']->refresh();
    $equipement = new Equipement;
    // La maudite d'abord : c'est elle que la potion choisirait sans la garde (plus petit id, équipée).
    $maudit = inamovibleAuSac($heros, inamovibleTalisman());
    $equipement->equiper($heros->refresh(), $maudit);
    $epee = inamovibleAuSac($heros->refresh(), Objet::where('nom', 'Épée large')->firstOrFail());
    $equipement->equiper($heros->refresh(), $epee);
    $potion = inamovibleAuSac($heros->refresh(), Objet::where('nom', 'Potion d\'alchimie')->firstOrFail());

    app(MoteurPotions::class)->boire($heros->refresh(), $potion->refresh());

    expect(Inventaire::whereKey($epee->id)->exists())->toBeFalse()
        ->and($maudit->fresh()->emplacement)->toBe('talisman');
});

it('le don refuse la pièce maudite portée : elle ne se donne pas', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    $autre = creerHeros($alice, $groupe, 'Berthe', 2);
    $equipement = new Equipement;
    $maudit = inamovibleAuSac($heros, inamovibleTalisman());
    $equipement->equiper($heros->refresh(), $maudit);

    $refus = inamovibleRefus(fn () => app(DonObjet::class)->donner(
        $heros->refresh(), $maudit->refresh(), $autre->refresh(),
    ));

    expect($refus)->toContain('ne se donne pas')
        ->and($maudit->fresh()->emplacement)->toBe('talisman');
});

it('la carte dit la malédiction AVANT l\'équipement : le libellé figure dans les avantages', function () {
    $phrases = implode(' | ', MotsClesEquipement::avantages(['bonus_pv_mind_max' => 1, MotsClesEquipement::INAMOVIBLE => true]));

    expect($phrases)->toContain('ne peut plus être ni rangée')
        ->and($phrases)->toContain('jetée');
});

it('/moi publie `retirable: false` pour la pièce maudite portée (la manette ne recalcule pas)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    (new Equipement)->equiper($heros->refresh(), inamovibleAuSac($heros, inamovibleTalisman()));

    $fiche = collect(test()->actingAs($alice, 'joueur')->getJson('/api/moi')->json('joueur.personnages'))
        ->firstWhere('id', $heros->id);

    expect($fiche['equipement']['talisman']['retirable'])->toBeFalse();
});
