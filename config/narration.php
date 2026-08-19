<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Répliques scriptées du NARRATEUR (voix du MJ) + voix de génération
|--------------------------------------------------------------------------
| Deux usages :
|  - `lancement` : réplique de CÉRÉMONIE jouée tout de suite quand la quête
|    démarre (tous prêts + narrateur actif), AVANT la narration d'ambiance de
|    l'IA — toujours disponible, avec variantes (tirage aléatoire) ;
|  - `repli` : narration NEUTRE par temps fort, utilisée quand le LLM est
|    absent (le jeu reste jouable sans clé), avec variantes.
|
| Le TEXTE est la source. L'audio (vraie voix de narrateur) est pré-généré
| par `php artisan narration:generer` (voix Gemini ci-dessous) dans
| public/audio/narration/{cle}/{i}.wav. Sans asset : lecture par le navigateur
| (Web Speech). Les descriptions de salles pré-générées par quête sont, elles,
| synthétisées par `GenererVoixQuete` dans public/audio/narration/dyn/{hash}.wav.
|
| ⚠ Ces répliques ne sont plus un simple filet : depuis le 2026-08-18 elles
| jouent pendant les premières secondes de CHAQUE quête (le pack de récits est
| écrit par un job asynchrone) et pendant TOUTE une partie menée sans clé
| d'API. Les 24 clés doivent rester couvertes — un test l'exige.
*/

return [

    // Profil de voix du narrateur (conteur/MJ) — distinct des barks de monstres.
    'voix' => [
        'voix' => 'Iapetus',
        'style' => 'une voix de conteur grave, posée et théâtrale de maître de jeu, qui pose l\'ambiance',
    ],

    // Synthèse au vol de la narration dynamique de l'IA (true si clé présente).
    'voix_dynamique' => true,

    // Cérémonie de lancement (jouée immédiatement au démarrage de quête).
    'lancement' => [
        'ambiance' => 'epique',
        'variantes' => [
            'Tout le monde est prêt. Que l\'aventure commence !',
            'Vos armes sont aiguisées, vos cœurs résolus. En avant, héros !',
            'Le groupe est au complet. Les portes du donjon s\'ouvrent… Commençons.',
            'L\'heure est venue. Que la légende s\'écrive, ici et maintenant !',
        ],
    ],

    // Narration de repli par temps fort (sans LLM).
    'repli' => [
        'quete_demarree' => [
            'ambiance' => 'mystere',
            'variantes' => [
                'Le groupe franchit le seuil du donjon. Les torches crépitent, et quelque part dans l\'obscurité, quelque chose attend.',
                'Un air froid monte des profondeurs. Les héros s\'enfoncent dans les ténèbres, sens en alerte.',
                'La lourde porte se referme derrière vous. Il n\'y a plus qu\'un chemin : en avant, vers l\'inconnu.',
            ],
        ],
        'salle_decouverte' => [
            'ambiance' => 'mystere',
            'variantes' => [
                'Une nouvelle salle se dévoile : l\'air y est plus lourd, et les ombres semblent vous observer.',
                'Vous pénétrez dans une pièce jusque-là scellée. La pierre suinte, et un silence étrange règne.',
                'La salle s\'ouvre devant vous, révélant ses recoins obscurs et ses secrets enfouis.',
            ],
        ],
        'piege_declenche' => [
            'ambiance' => 'tension',
            'variantes' => [
                'Un déclic sinistre ! Le piège se referme dans un fracas — trop tard pour reculer.',
                'La dalle cède sous le pied : un mécanisme caché s\'active dans un grincement métallique.',
                'Un sifflement, et le piège jaillit de la pierre — la douleur fuse.',
            ],
        ],
        // Découverte du coffre à artefact — le sommet d'une quête.
        //
        // ⚠ Le raisonnement de quota qui limitait ce bloc à UN seul beat ne
        // tient plus depuis la bascule du 2026-08-18 : les répliques à
        // placeholders ne sont JAMAIS synthétisées (leur texte n'existe
        // qu'une fois substitué, son hash ne tomberait jamais dans le cache),
        // la table les lit en Web Speech. Seules les descriptions de salle,
        // fixes, consomment du quota Gemini TTS. Ajouter des variantes ici est
        // donc gratuit — et souhaitable : c'est ce repli qui joue tant que le
        // pack pré-généré de la quête n'est pas écrit, et TOUTE la partie
        // quand aucune clé d'API n'est configurée.
        'fouille_artefact' => [
            'ambiance' => 'victoire',
            'variantes' => [
                'Le couvercle cède dans un grincement millénaire : sous la poussière, une arme repose, intacte, comme si elle vous attendait.',
                'La serrure rompt. Ce que le coffre gardait n\'a pas d\'égal dans ce donjon — et c\'est à vous, désormais.',
            ],
        ],
        // ── Issues de fouille et de mobilier ────────────────────────────
        //
        // Ces dix clés n'avaient AUCUN repli scripté : elles naissent avec les
        // récits pré-générés (2026-08-18), et sans elles une fouille resterait
        // muette à chaque fois que le pack de la quête manque — c'est-à-dire
        // pendant les premières secondes de CHAQUE quête (le pack est écrit
        // par un job asynchrone) et pendant TOUTE une partie jouée sans clé
        // d'API. Le jeu doit rester jouable sans IA : ce n'est pas un filet,
        // c'est un mode de jeu à part entière.
        //
        // Les placeholders suivent le contrat de RecitsTempsForts::
        // PLACEHOLDERS_PAR_CLE — n'en employer aucun autre : le moteur ne
        // fournit que ceux-là, et un « {monstre} » non substitué s'afficherait
        // tel quel à l'écran.
        'fouille_tresor' => [
            'ambiance' => 'victoire',
            'variantes' => [
                'Sous une dalle descellée, {heros} met la main sur une bourse oubliée : {or} pièces d\'or rejoignent le butin commun.',
                '{heros} déloge une pierre branlante — {or} pièces d\'or roulent dans la poussière.',
                'La fouille paie : {heros} exhume {or} pièces d\'or d\'une cache que personne n\'avait vue.',
            ],
        ],
        'fouille_potion' => [
            'ambiance' => 'calme',
            'variantes' => [
                'Calée dans une niche, une fiole intacte : {heros} empoche {objet}.',
                '{heros} déniche {objet}, miraculeusement préservée sous les gravats.',
                'Le renfoncement gardait son secret : {objet}, que {heros} range précieusement.',
            ],
        ],
        'fouille_errant' => [
            'ambiance' => 'tension',
            'variantes' => [
                'Le bruit de la fouille a porté trop loin : {monstre} surgit de l\'ombre, droit sur {heros} !',
                '{heros} n\'était pas seul : {monstre} rôdait, et vient de trouver sa proie.',
                'Un grognement dans le noir — {monstre} répond au vacarme et fond sur {heros}.',
            ],
        ],
        'fouille_piege' => [
            'ambiance' => 'tension',
            'variantes' => [
                'Les doigts de {heros} rencontrent un fil tendu — trop tard, le mécanisme est déjà parti.',
                'Ce n\'était pas une cachette mais un appât : {heros} déclenche le piège de plein fouet.',
                '{heros} tire sur la mauvaise pierre, et le donjon riposte.',
            ],
        ],
        'fouille_rien' => [
            'ambiance' => 'calme',
            'variantes' => [
                '{heros} retourne la poussière un long moment. Rien. D\'autres sont passés avant.',
                'Rien que de la pierre froide sous les mains de {heros}.',
                '{heros} fouille avec méthode, et ne remonte que des gravats.',
            ],
        ],
        'mobilier_objet' => [
            'ambiance' => 'victoire',
            'variantes' => [
                'Le meuble rend ce qu\'il gardait : {heros} en retire {objet}.',
                '{heros} force le bois vermoulu et met la main sur {objet}.',
                'Sous un double fond, {objet} — {heros} ne se le fait pas dire deux fois.',
            ],
        ],
        'mobilier_piege' => [
            'ambiance' => 'tension',
            'variantes' => [
                'Le meuble était protégé : quelque chose mord la main de {heros}.',
                'Un déclic sec dans le bois, et {heros} retire sa main trop tard.',
                'Ce que gardait ce meuble, c\'était surtout une mauvaise surprise pour {heros}.',
            ],
        ],
        'mobilier_rien' => [
            'ambiance' => 'calme',
            'variantes' => [
                '{heros} ouvre, sonde, retourne — le meuble est vide depuis longtemps.',
                'Rien dans ce meuble que de la poussière et le travail du temps.',
                '{heros} referme sur du vide.',
            ],
        ],
        'porte_ouverte' => [
            'ambiance' => 'mystere',
            'variantes' => [
                'La porte cède dans un long grincement, et l\'obscurité d\'au-delà s\'offre au regard.',
                'Le battant s\'ouvre : au-delà du seuil, l\'air est plus froid.',
                'Un raclement de pierre, et le passage est libre.',
            ],
        ],
        'levier_actionne' => [
            'ambiance' => 'mystere',
            'variantes' => [
                '{heros} abaisse le levier : quelque part dans le donjon, un mécanisme lui répond.',
                'Sous la poigne de {heros}, le levier bascule — et la pierre gronde au loin.',
                '{heros} actionne le levier ; un déclic profond court le long des murs.',
            ],
        ],

        'reprise' => [
            'ambiance' => 'mystere',
            'variantes' => [
                'Le fil du destin se rembobine : le groupe se retrouve là où tout pouvait encore basculer, armes en main. L\'aventure reprend.',
                'Comme surgis d\'un songe, les héros reprennent leur poste, le souffle court. Tout reste à jouer.',
            ],
        ],
        'deplacement' => [
            'ambiance' => 'tension',
            'variantes' => [
                'Vous progressez dans le donjon, pas à pas, attentifs au moindre bruit.',
                'Les pierres résonnent sous vos bottes tandis que le groupe avance dans la pénombre.',
                'Prudemment, la formation se déplace, chaque ombre scrutée avec méfiance.',
            ],
        ],
        'victoire_quete' => [
            'ambiance' => 'victoire',
            'variantes' => [
                'Le dernier adversaire s\'effondre : le donjon retombe dans le silence. Le groupe rassemble le butin, victorieux.',
                'Le combat est gagné. Un calme soudain s\'installe, et la voie vers le hub s\'ouvre enfin.',
                'Plus rien ne bouge dans l\'ombre. La quête est accomplie — savourez l\'instant, héros.',
            ],
        ],
        'attaque_mort' => [
            'ambiance' => 'tension',
            'variantes' => [
                'Le coup porte et l\'adversaire accuse le choc avant de s\'effondrer.',
                'Un dernier râle, et l\'ennemi s\'écroule sous la puissance du coup.',
            ],
        ],
        'attaque_touche' => [
            'ambiance' => 'tension',
            'variantes' => [
                'Le coup porte et l\'adversaire accuse le choc, mais reste menaçant.',
                'La lame mord la chair : l\'ennemi vacille, sans tomber.',
            ],
        ],
        'attaque_pare' => [
            'ambiance' => 'tension',
            'variantes' => [
                'L\'attaque est parée de justesse : l\'adversaire tient bon et le combat continue.',
                'Le coup ricoche sur la défense ennemie ; rien n\'est encore joué.',
            ],
        ],
        'reussite' => [
            'ambiance' => 'tension',
            'variantes' => [
                'Le geste est sûr et la tentative réussit : le groupe reprend l\'avantage.',
                'Bien joué — l\'action aboutit et la voie se dégage.',
            ],
        ],
        'reussite_mixte' => [
            'ambiance' => 'tension',
            'variantes' => [
                'La tentative aboutit, mais quelque chose accroche au passage — le groupe progresse, sur ses gardes.',
                'Réussi, de justesse : un détail cloche, restez vigilants.',
            ],
        ],
        'echec' => [
            'ambiance' => 'tension',
            'variantes' => [
                'La tentative échoue. Un silence pesant s\'installe, et le groupe doit envisager une autre approche.',
                'Raté. Le doute s\'immisce ; il faudra trouver une autre voie.',
            ],
        ],
        'progression' => [
            'ambiance' => 'tension',
            'variantes' => [
                'L\'aventure suit son cours : le groupe progresse prudemment dans la pénombre, attentif au moindre bruit.',
                'Le donjon garde ses secrets. Les héros avancent, tous les sens en éveil.',
            ],
        ],
    ],
];
