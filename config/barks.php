<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Barks de monstres — répliques courtes jouées sur l'écran de table
|--------------------------------------------------------------------------
| Pur AMBIANCE : aucun effet mécanique. Le texte est la source ; l'audio
| est pré-généré (commande `barks:generer`, voix Gemini par profil) et mis
| en cache. Sans audio, l'écran de table lit le texte via Web Speech.
|
| Événements de combat :
|  - attaque : le monstre attaque un héros (son tour) ;
|  - touche  : le monstre encaisse des dégâts mais survit ;
|  - rate    : un héros l'attaque sans le blesser (il raille) ;
|  - mort    : le monstre est vaincu.
|
| Garder des barks COURTS (un grognement, une menace) et 3 variantes par
| événement pour éviter la répétition.
*/

return [

    // Profils de voix : `voix` = voix préconstruite Gemini ; `style` = consigne
    // de timbre injectée dans le prompt de génération (pilotage en langage naturel).
    'profils' => [
        'gobelin' => [
            'voix' => 'Puck',
            'style' => 'une voix aiguë, nasillarde et fourbe de gobelin ricanant',
        ],
        'brute' => [
            'voix' => 'Fenrir',
            'style' => 'une voix grave, gutturale et brutale de grosse brute orque',
        ],
        'mort_vivant' => [
            'voix' => 'Charon',
            'style' => 'une voix caverneuse, sèche et sépulcrale de mort-vivant',
        ],
        'demon' => [
            'voix' => 'Fenrir',
            'style' => 'une voix démoniaque, menaçante et résonnante, pleine de haine',
        ],
        'champion' => [
            'voix' => 'Orus',
            'style' => 'une voix puissante, froide et arrogante de champion du chaos',
        ],
        'boss' => [
            'voix' => 'Charon',
            'style' => 'une voix terrifiante, profonde et solennelle de seigneur du chaos',
        ],
        // Repli si un monstre n'a pas de profil dédié.
        'defaut' => [
            'voix' => 'Fenrir',
            'style' => 'une voix de monstre menaçant',
        ],
    ],

    // Archétype (Monstre.nom_base) → profil de voix.
    'archetypes' => [
        'Gobelin' => 'gobelin',
        'Orque' => 'brute',
        'Fimir' => 'brute',
        'Squelette' => 'mort_vivant',
        'Zombie' => 'mort_vivant',
        'Momie' => 'mort_vivant',
        'Guerrier du Chaos' => 'demon',
        'Gargouille' => 'demon',
        'Champion' => 'champion',
        'Seigneur' => 'boss',
    ],

    'evenements' => ['attaque', 'touche', 'rate', 'mort'],

    // Lignes par profil. Repli sur `defaut` si un profil n'a pas la clé.
    'lignes' => [
        'gobelin' => [
            'attaque' => ['Héhéhé, à moi !', 'Tu vas saigner !', 'Attrape ça !'],
            'touche' => ['Aïe ! Sale brute !', 'Gnii, ça pique !', 'Pas juste !'],
            'rate' => ['Manqué, hahaha !', 'Trop lent, l\'humain !', 'Même pas mal !'],
            'mort' => ['Aaargh… pas comme ça…', 'Gnnn…', 'La horde… me vengera…'],
        ],
        'brute' => [
            'attaque' => ['POUR LE CHAOS !', 'Écrase-les !', 'Du sang !'],
            'touche' => ['RAAAH !', 'Tu m\'as touché, vermine !', 'Ça ne suffira pas !'],
            'rate' => ['Faiblard !', 'C\'est tout ?', 'Ridicule !'],
            'mort' => ['Impossible…', 'RAAAAH…', 'Je… tombe…'],
        ],
        'mort_vivant' => [
            'attaque' => ['Rejoins-nous…', 'Ton heure est venue…', 'Chairs fraîches…'],
            'touche' => ['Tu ne peux pas me tuer…', 'Je suis déjà mort…', 'Vain…'],
            'rate' => ['En vain, mortel…', 'Tes coups sont creux…', 'Je ne sens rien…'],
            'mort' => ['Le repos… enfin…', 'Poussière…', 'De nouveau… le néant…'],
        ],
        'demon' => [
            'attaque' => ['Je vais te dévorer !', 'Crains-moi !', 'Ton âme est à moi !'],
            'touche' => ['Tu oses ?!', 'GRAAAH !', 'Tu le paieras !'],
            'rate' => ['Pathétique mortel !', 'Tu trembles déjà !', 'Insolent !'],
            'mort' => ['Maudit sois-tu…', 'Je reviendrai des ténèbres…', 'GRAAAAH…'],
        ],
        'champion' => [
            'attaque' => ['Agenouille-toi !', 'Tu n\'es rien.', 'Pour mon seigneur !'],
            'touche' => ['Belle tentative.', 'Tu m\'amuses.', 'Encore ?'],
            'rate' => ['Dérisoire.', 'Est-ce là ta force ?', 'Lamentable.'],
            'mort' => ['Mon seigneur… pardon…', 'Vaincu… moi…', 'Impossible…'],
        ],
        'boss' => [
            'attaque' => ['Vous périrez tous ici !', 'Aucun de vous ne sortira !', 'Inclinez-vous devant moi !'],
            'touche' => ['VOUS OSEZ ME DÉFIER ?!', 'Insolents mortels !', 'Cela ne change RIEN !'],
            'rate' => ['Vos lames sont des jouets !', 'Vous ne pouvez RIEN contre moi !', 'Pitoyable !'],
            'mort' => ['NON… c\'est… impossible…', 'Les ténèbres… me réclament…', 'Vous… n\'avez fait… que retarder…'],
        ],
        'defaut' => [
            'attaque' => ['Grrraah !', 'À l\'attaque !', 'Meurs !'],
            'touche' => ['Grrr !', 'Argh !', 'Tu vas le regretter !'],
            'rate' => ['Manqué !', 'Trop lent !', 'Ha !'],
            'mort' => ['Aaargh…', 'Nnnh…', 'C\'est… la fin…'],
        ],
    ],

    // Répliques NOMMÉES pour boss/sous-boss : le placeholder {nom} est rendu
    // avec le nom donné par l'IA (instance.habillage.nom) au démarrage de quête,
    // puis l'audio est généré par {@see \App\Jobs\GenererBarksBoss}. Repli sur
    // `lignes` du profil si non défini. Indexées par profil de voix.
    'lignes_boss' => [
        'champion' => [
            'attaque' => ['{nom} ne fait pas de quartier !', 'Vous affrontez {nom} — agenouillez-vous !', 'Au nom du Chaos !'],
            'touche' => ['On ne touche pas {nom} impunément !', 'Insolents !', 'Vous le regretterez.'],
            'rate' => ['{nom} ne tombera pas si bas.', 'Dérisoire face à {nom}.', 'Lamentable.'],
            'mort' => ['{nom}… vaincu ?! Impossible…', 'Mon seigneur… pardonnez {nom}…', 'Non…'],
        ],
        'boss' => [
            'attaque' => ['Je suis {nom}, et ce donjon sera votre tombeau !', 'Nul n\'échappe à {nom} !', 'Tremblez devant {nom} !'],
            'touche' => ['VOUS OSEZ BLESSER {nom} ?!', '{nom} ne connaît pas la douleur !', 'Cela ne fait que m\'enrager !'],
            'rate' => ['Vos lames ne peuvent rien contre {nom} !', '{nom} est éternel !', 'Pitoyables mortels !'],
            'mort' => ['{nom}… ne peut pas… mourir…', 'Les ténèbres… réclament {nom}…', 'Vous n\'avez fait… que retarder l\'inévitable…'],
        ],
    ],
];
