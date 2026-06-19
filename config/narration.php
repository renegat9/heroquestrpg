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
| (Web Speech). La narration DYNAMIQUE de l'IA est, elle, synthétisée au vol
| et mise en cache (public/audio/narration/dyn/{hash}.wav) par GenererNarration.
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
