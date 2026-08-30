<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * Vignette de remplacement, en SVG, quand aucune image n'a pu être générée.
 *
 * Le jeu tourne sans clé d'IA — c'est une règle du projet, pas un mode dégradé
 * — et depuis que les crédits Gemini peuvent s'épuiser en pleine campagne, une
 * quête sur deux se retrouve sans illustration. Jusqu'ici l'écran affichait
 * simplement du vide : ni image, ni indication, un trou dans la mise en page
 * qu'on lisait comme un bug (demande de René, 2026-08-21).
 *
 * ⚠ Ce n'est PAS une fausse illustration : on ne cherche pas à faire croire
 * qu'une image a été générée. C'est un emblème sobre, assumé comme tel, qui
 * remplit le cadre proprement.
 *
 * SVG et non PNG, pour trois raisons : aucun fichier écrit sur le disque (donc
 * rien à purger ni à régénérer), une netteté parfaite quelle que soit la taille
 * — la même vignette sert à 56 px dans le bandeau et à 900 px sur la carte
 * d'ouverture — et un coût de génération nul.
 *
 * La GRAINE rend la vignette stable ET distincte : deux quêtes d'une même
 * campagne n'ont pas la même teinte, mais une quête donnée garde toujours la
 * sienne. Sans ça, toutes les salles se ressembleraient et l'écran donnerait
 * l'impression de ne pas se rafraîchir.
 */
class PlaceholderController extends Controller
{
    /** Emblème par type de sujet — un trait simple, lisible à 56 px comme à 900. */
    private const EMBLEMES = [
        // Une arche de donjon : deux montants et un linteau cintré.
        'quete' => 'M300 620 V330 a150 150 0 0 1 300 0 V620',
        // Un foyer : une flamme centrale encadrée de deux plus basses, posées
        // sur une bûche. Sans la bûche, les trois formes se lisaient comme des
        // pétales — le geste ne suffisait pas à dire « feu ».
        'hub' => 'M450 520 c-70 -55 -30 -130 0 -170 c30 40 70 115 0 170 Z '
            .'M378 520 c-46 -34 -20 -84 0 -106 c20 22 46 72 0 106 Z '
            .'M522 520 c-46 -34 -20 -84 0 -106 c20 22 46 72 0 106 Z '
            .'M320 546 h260',
        // Une silhouette cornue. ⚠ Les cornes PARTENT des épaules du crâne
        // vers le haut et l'extérieur : tracées vers l'intérieur, elles le
        // traversaient et l'ensemble se lisait comme un insecte.
        'monstre' => 'M365 365 L300 240 M535 365 L600 240 M450 330 '
            .'a120 120 0 0 1 120 120 v170 h-240 V450 a120 120 0 0 1 120 -120 Z',
        // Un buste : tête et épaules. ⚠ Les épaules DESCENDENT jusqu'au bord du
        // cadre : arrêtées en l'air, elles se lisaient comme un trait coupé
        // plutôt que comme un portrait recadré.
        'heros' => 'M450 232 a74 74 0 1 1 0 148 a74 74 0 1 1 0 -148 '
            .'M296 675 v-96 a154 154 0 0 1 308 0 v96',
        // Une bourse fermée par son lien — l'objet le plus neutre du sac.
        'objet' => 'M362 405 h176 l34 175 h-244 Z M406 405 v-42 a44 44 0 0 1 88 0 v42',
        // Trois pointes sortant du sol : un piège se voit à ce qu'il dépasse.
        // Élargies et rehaussées — au premier jet elles se perdaient dans le bas
        // du cadre.
        'piege' => 'M288 590 h324 M330 590 L370 428 L410 590 M410 590 L450 392 L490 590 '
            .'M490 590 L530 428 L570 590',
        // Un sceau arcanique : deux triangles entrelacés, lisible même à 40 px.
        'sort' => 'M450 282 L562 472 L338 472 Z M450 562 L338 372 L562 372 Z',
        // Une dalle gravée et la main qui s'y pose : on se MESURE à une
        // épreuve, on ne la subit pas. ⚠ Le trait ne reprend NI les pointes du
        // piège NI la bourse de l'objet : les confondre à 40 px coûterait une
        // action au joueur qui croit désamorcer ce qu'il faut tenter.
        // Un meuble : plateau posé sur deux pieds, plus un tiroir suggéré. La
        // silhouette la plus neutre des huit pièces — un coffre ou un trône
        // dessinés ici auraient nommé UNE pièce là où l'emblème les remplace
        // toutes.
        'mobilier' => 'M282 330 h336 v52 h-336 Z M318 382 v192 M582 382 v192 '
            .'M348 432 h204 M348 484 h204',
        // Un levier : platine murale, bras oblique, poignée.
        'levier' => 'M366 300 h168 v300 h-168 Z M450 450 L570 366 M570 366 m-26 0 a26 26 0 1 0 52 0 a26 26 0 1 0 -52 0',
        // Une porte : chambranle cintré, battant, poignée.
        'porte' => 'M318 620 V392 a132 132 0 0 1 264 0 V620 Z M522 506 m-16 0 a16 16 0 1 0 32 0 a16 16 0 1 0 -32 0',
        'epreuve' => 'M330 288 h240 v264 h-240 Z M378 348 h144 M378 408 h144 M378 468 h96 '
            .'M450 600 v72 M408 672 h84',
    ];

    /**
     * GET /api/placeholder/{type}/{graine}
     *
     * Public, comme `/api/guide` : c'est une ressource d'affichage, servie à
     * l'écran de table qui n'a pas de compte.
     */
    public function afficher(string $type, string $graine): Response
    {
        // `classe` et `heros` partagent le même buste : une classe EST le
        // portrait générique de ses héros, c'est d'ailleurs vers elle que
        // retombe déjà un portrait individuel manquant.
        $embleme = self::EMBLEMES[$type === 'classe' ? 'heros' : $type] ?? self::EMBLEMES['quete'];

        // Teinte dérivée de la graine : stable pour un sujet donné, différente
        // d'un sujet à l'autre. Bornée aux ambres et aux verts sourds — rester
        // dans la palette du jeu, jamais une couleur criarde qui trahirait
        // l'absence d'illustration.
        $teinte = (crc32($type.$graine) % 70) + 20; // 20°..90°, ambre → vert-de-gris

        $svg = <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 675" width="900" height="675" role="img"
             aria-label="Illustration non disponible">
          <defs>
            <linearGradient id="f" x1="0" y1="0" x2="0.4" y2="1">
              <stop offset="0" stop-color="hsl({$teinte} 18% 14%)"/>
              <stop offset="1" stop-color="hsl({$teinte} 22% 5%)"/>
            </linearGradient>
            <radialGradient id="h" cx="0.5" cy="0.42" r="0.62">
              <stop offset="0" stop-color="hsl({$teinte} 45% 32%)" stop-opacity="0.30"/>
              <stop offset="1" stop-color="hsl({$teinte} 45% 32%)" stop-opacity="0"/>
            </radialGradient>
          </defs>
          <rect width="900" height="675" fill="url(#f)"/>
          <rect width="900" height="675" fill="url(#h)"/>
          <path d="{$embleme}" fill="none" stroke="hsl({$teinte} 40% 55%)" stroke-opacity="0.42"
                stroke-width="14" stroke-linecap="round" stroke-linejoin="round"/>
          <rect x="12" y="12" width="876" height="651" fill="none" rx="8"
                stroke="hsl({$teinte} 35% 45%)" stroke-opacity="0.22" stroke-width="3"/>
        </svg>
        SVG;

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            // Immuable : la vignette ne dépend que de son type et de sa graine.
            // Une vraie illustration générée plus tard change l'URL, pas celle-ci.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
