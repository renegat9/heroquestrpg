<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Partie\CadenceNiveaux;
use App\Partie\MonteeNiveau;

/*
 * LA CADENCE DE PROGRESSION — troisième déclencheur de la doc 01 §5, porté le
 * 2026-09-04 sur l'arbitrage de René (« on a dû attendre 3 quêtes avant de
 * passer de niveau »).
 *
 * ⚠ Le document déclare TROIS déclencheurs et n'en avait que deux de câblés :
 * « certains objectifs de quête majeurs marqués par le gabarit » n'existait
 * que sur le papier. La cadence réelle tombait à 1/2/3/4/5 niveaux selon la
 * longueur, contre les « ~5 à 8 par campagne » que la même section vise — et
 * la grille de talents est dimensionnée sur cette fourchette (§6, « neuf cases
 * pour quatre à sept points »).
 *
 * Ce fichier verrouille la fourchette elle-même, pour toutes les longueurs.
 */

/** Un groupe nu, avec son plan de campagne — aucune quête n'est jouée ici. */
function groupeDArc(int $nbQuetes, array $jalons): Groupe
{
    return new Groupe([
        'nb_quetes_total' => $nbQuetes,
        'plan_campagne' => ['jalons' => $jalons],
    ]);
}

/** Jalons régulièrement espacés, comme les écrit `SqueletteCampagne`. */
function jalonsReguliers(int $nbQuetes, int $nbSousBoss): array
{
    $jalons = [];

    for ($i = 1; $i <= $nbSousBoss; $i++) {
        $jalons[] = ['position' => (int) round($i * $nbQuetes / ($nbSousBoss + 1)), 'type' => 'sous_boss'];
    }

    $jalons[] = ['position' => $nbQuetes, 'type' => 'boss_final'];

    return $jalons;
}

it('amène dans la fourchette 5-8 toute campagne qui a assez de quêtes pour ça', function () {
    $cadence = new CadenceNiveaux;

    // (quêtes, sous-boss) — les cinq longueurs de `GroupeController`, prises à
    // leurs deux bornes, avec la cadence de `SqueletteCampagne::nbSousBossAttendu()`.
    $arcs = [
        [5, 1],              // courte, borne haute — celle de René
        [7, 2], [10, 2],     // normale
        [12, 3], [15, 3],    // longue
        [17, 4], [20, 4],    // très longue
    ];

    foreach ($arcs as [$quetes, $sousBoss]) {
        $groupe = groupeDArc($quetes, jalonsReguliers($quetes, $sousBoss));
        $niveaux = ($sousBoss + 1) + count($cadence->positionsMajeures($groupe));

        expect($niveaux)->toBeGreaterThanOrEqual(5, "campagne de {$quetes} quêtes")
            ->and($niveaux)->toBeLessThanOrEqual(CadenceNiveaux::NIVEAUX_VISES, "campagne de {$quetes} quêtes");
    }
});

it('donne un niveau PAR QUÊTE en deçà de la fourchette, faute de pouvoir faire mieux', function () {
    // ⚠ Une campagne de 3 quêtes ne peut pas rendre 5 niveaux : la fourchette
    // de la doc présuppose un arc qui a la place. On énonce donc la borne
    // atteignable — chaque quête monte — plutôt que de tordre les nombres pour
    // que le tableau ait l'air plein.
    $cadence = new CadenceNiveaux;

    foreach ([[1, 0], [3, 1], [4, 1]] as [$quetes, $sousBoss]) {
        $groupe = groupeDArc($quetes, jalonsReguliers($quetes, $sousBoss));
        $niveaux = ($sousBoss + 1) + count($cadence->positionsMajeures($groupe));

        expect($niveaux)->toBe($quetes, "campagne de {$quetes} quêtes");
    }
});

it('ne dépasse JAMAIS le plafond, même sur la campagne la plus longue', function () {
    // Sans cadence, une très longue campagne offrirait un niveau à chacune de
    // ses quinze quêtes ordinaires — vingt niveaux pour une fourchette qui en
    // vise huit. C'est la moitié « arc » de la règle, celle que le drapeau de
    // gabarit seul ne peut pas porter.
    $cadence = new CadenceNiveaux;
    $groupe = groupeDArc(20, jalonsReguliers(20, 4));

    expect(count($cadence->positionsMajeures($groupe)))->toBe(3)
        ->and(5 + 3)->toBe(CadenceNiveaux::NIVEAUX_VISES);
});

it('ne pose jamais un objectif majeur SUR un jalon — une quête ne monte pas deux fois', function () {
    $cadence = new CadenceNiveaux;
    $groupe = groupeDArc(10, jalonsReguliers(10, 2));

    $jalons = collect($groupe->plan_campagne['jalons'])->pluck('position')->all();

    foreach ($cadence->positionsMajeures($groupe) as $position) {
        expect($jalons)->not->toContain($position);
    }
});

it('répartit les positions majeures au lieu de les tasser en début d\'arc', function () {
    // Les grouper au début donnerait une campagne qui progresse vite puis plus
    // du tout — le contraire d'une cadence.
    $cadence = new CadenceNiveaux;
    $groupe = groupeDArc(20, jalonsReguliers(20, 4));

    $positions = $cadence->positionsMajeures($groupe);
    sort($positions);

    expect($positions[0])->toBeLessThan(8)
        ->and(end($positions))->toBeGreaterThan(12);
});

it('est STABLE : c\'est un placement, jamais un tirage', function () {
    // Même raison que le boss de la rencontre finale et `salle_artefact` :
    // recommencer une quête ou recharger un instantané ne doit pas déplacer un
    // objectif majeur.
    $cadence = new CadenceNiveaux;
    $groupe = groupeDArc(12, jalonsReguliers(12, 3));

    expect($cadence->positionsMajeures($groupe))
        ->toBe($cadence->positionsMajeures($groupe));
});

it('retombe sur la dernière quête quand aucun plan de campagne n\'est écrit', function () {
    // ⚠ Même repli que `DemarreurQuete::typeJalon()`. Les deux DOIVENT
    // répondre pareil : sinon une position compterait comme jalon d'un côté et
    // comme quête ordinaire de l'autre, et la dernière quête donnerait deux
    // niveaux.
    $cadence = new CadenceNiveaux;
    $groupe = new Groupe(['nb_quetes_total' => 5, 'plan_campagne' => null]);

    expect($cadence->positionsMajeures($groupe))->not->toContain(5)
        ->and($cadence->estMajeure($groupe, 5))->toBeFalse();
});

it('ne donne rien à une campagne d\'une seule quête', function () {
    // Une « très courte » est une démo : sa seule quête est le boss final, il
    // n'existe aucune quête ordinaire où poser un objectif majeur. On le
    // constate plutôt que de le maquiller.
    $cadence = new CadenceNiveaux;
    $groupe = groupeDArc(1, [['position' => 1, 'type' => 'boss_final']]);

    expect($cadence->positionsMajeures($groupe))->toBe([]);
});

it('garde la constante des jalons comme source unique', function () {
    expect(MonteeNiveau::JALONS)->toBe(['sous_boss', 'boss_final']);
});
