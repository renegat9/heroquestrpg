<?php

declare(strict_types=1);

use App\Partie\Grille;

/**
 * `Grille::caseEmbrasure()` sur une géométrie RÉELLE (couloir + salle, `salles`
 * fourni) — pas le repli utilisé par les grilles synthétiques de
 * `PortesGrilleTest`. Reproduit le défaut signalé en partie réelle le
 * 2026-09-10 : « aujourd'hui l'arête 29|30 est bloquée mais pas 30|31, donc un
 * héros venant du couloir peut se tenir dans une embrasure close ».
 *
 * Grille à une ligne : colonnes 0,1,2 = couloir ; 3 = embrasure (mur de la
 * salle, percé par la porte) ; 4,5,6 = intérieur de la salle. La salle
 * publiée (mur compris) couvre x=[3..6] — sa colonne x=3 est son bord GAUCHE,
 * donc c'est elle, et PAS la case de couloir (2,0), que `caseEmbrasure()`
 * doit désigner.
 */
function grilleCouloirSalle(string $etat): Grille
{
    $grille = new Grille(array_map(str_split(...), ['sssssss']));
    $grille->definirPortes(
        [['x' => 2, 'y' => 0, 'cote' => 'e', 'etat' => $etat]],
        [['x' => 3, 'y' => 0, 'largeur' => 4, 'hauteur' => 1]],
    );

    return $grille;
}

it('désigne la case de la SALLE comme embrasure, jamais la case de couloir', function () {
    $porte = ['x' => 2, 'y' => 0, 'cote' => 'e'];
    $salles = [['x' => 3, 'y' => 0, 'largeur' => 4, 'hauteur' => 1]];

    expect(Grille::caseEmbrasure($porte, $salles))->toBe(['x' => 3, 'y' => 0]);
});

it('bloque l\'embrasure des DEUX côtés — le bug corrigé (2026-09-10)', function () {
    $fermee = grilleCouloirSalle('fermee');

    // Avant ce correctif, seule l'arête (2,0)|(3,0) — le pas VENU DU COULOIR —
    // était bloquée : un héros déjà dans la salle (via un autre accès) pouvait
    // librement ENTRER dans (3,0) par l'intérieur (arête (3,0)|(4,0), jamais
    // déclarée) et s'y tenir, dans une embrasure fermée. Les DEUX pas sont
    // désormais coupés : la case elle-même est inoccupable.
    expect($fermee->estTraversable(3, 0))->toBeFalse()
        ->and($fermee->chemin(2, 0, 3, 0))->toBeNull()   // depuis le couloir
        ->and($fermee->chemin(4, 0, 3, 0))->toBeNull()   // depuis l'intérieur de la salle
        // Les deux zones restent chacune praticables en interne.
        ->and($fermee->chemin(0, 0, 2, 0))->not->toBeNull()
        ->and($fermee->chemin(4, 0, 6, 0))->not->toBeNull()
        // Mais aucune des deux ne rejoint l'autre tant que la porte est close.
        ->and($fermee->chemin(0, 0, 6, 0))->toBeNull()
        ->and($fermee->ligneDeVue(0, 0, 6, 0))->toBeFalse();
});

it('laisse voir et passer à travers l\'embrasure, des deux côtés, une fois ouverte', function () {
    $ouverte = grilleCouloirSalle('ouverte');

    expect($ouverte->estTraversable(3, 0))->toBeTrue()
        ->and($ouverte->chemin(2, 0, 3, 0))->not->toBeNull()
        ->and($ouverte->chemin(4, 0, 3, 0))->not->toBeNull()
        ->and($ouverte->chemin(0, 0, 6, 0))->not->toBeNull()
        ->and($ouverte->ligneDeVue(0, 0, 6, 0))->toBeTrue();

    // Aucun coût de déplacement introduit par l'embrasure : 6 pas, 6 points.
    $chemin = $ouverte->chemin(0, 0, 6, 0);
    expect($chemin)->toHaveCount(6)
        ->and($ouverte->coutChemin($chemin))->toBe(6);
});

it('la salle mitoyenne (un seul mur partagé) désigne toujours la même case, quel que soit le côté déclaré', function () {
    // Jonction MITOYENNE (AssembleurCarte::accolerSallesMitoyennes()) : les deux
    // salles partagent EXACTEMENT une colonne de mur (x=3), percée par LA porte
    // au milieu (y=1) — qu'elle soit déclarée côté gauche (ancre = l'embrasure
    // elle-même, cote 'e' en x=3) ou côté droit (ancre = l'intérieur immédiat
    // de la salle droite, x=2 — cf. `creuserArete()` : seule `porte_parent`
    // survit à une jonction mitoyenne, et elle peut être n'importe lequel des
    // deux côtés) ne doit rien changer : les deux salles se TOUCHENT sur x=3,
    // qui est sur l'anneau de mur des DEUX à la fois. Hauteur 3 (pas 1) : une
    // salle d'une seule rangée ferait dégénérer TOUT son intérieur en « bord »
    // (yMin == yMax), ce qu'aucune vraie salle (murs sur les 4 côtés) ne fait.
    $salles = [
        ['x' => 0, 'y' => 0, 'largeur' => 4, 'hauteur' => 3], // salle gauche, mur droit x=3
        ['x' => 3, 'y' => 0, 'largeur' => 4, 'hauteur' => 3], // salle droite, mur gauche x=3
    ];

    expect(Grille::caseEmbrasure(['x' => 3, 'y' => 1, 'cote' => 'e'], $salles))->toBe(['x' => 3, 'y' => 1])
        ->and(Grille::caseEmbrasure(['x' => 2, 'y' => 1, 'cote' => 'e'], $salles))->toBe(['x' => 3, 'y' => 1]);
});

it('retombe sur (x+1,y)/(x,y+1) sans salle correspondante — comportement historique', function () {
    expect(Grille::caseEmbrasure(['x' => 5, 'y' => 5, 'cote' => 'e'], []))->toBe(['x' => 6, 'y' => 5])
        ->and(Grille::caseEmbrasure(['x' => 5, 'y' => 5, 'cote' => 's'], []))->toBe(['x' => 5, 'y' => 6]);
});
