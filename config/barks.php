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
        // --- Familles des EXTENSIONS (2026-10-20) --------------------------------
        // Une voix par FAMILLE, pas par monstre : chaque profil coûte 12 requêtes
        // TTS (4 événements × 3), et le quota est de 100/jour — 63 profils en
        // auraient coûté ~756. Le registre `archetypes` ci-dessous est testé dans
        // les deux sens (`BarksArchetypesTest`) : aucun monstre du bestiaire sans
        // voix déclarée, aucune entrée qui ne soit un monstre.
        'ogre' => [
            'voix' => 'Algenib',
            'style' => 'une voix énorme, rocailleuse et lente d\'ogre affamé',
        ],
        'ogresse' => [
            'voix' => 'Kore',
            'style' => 'une voix féminine rauque, puissante et goguenarde de cheffe de guerre ogre',
        ],
        'bete' => [
            'voix' => 'Enceladus',
            // ⚠ « sans parole articulée » contredisait le prompt (« Dis ceci… ») : trois
            // grognements sur douze revenaient SANS AUDIO (2026-10-20). On décrit le
            // timbre, pas l'absence de parole.
            'style' => 'la voix rauque et gutturale d\'une bête sauvage qui gronde et rugit',
        ],
        'sorcier' => [
            'voix' => 'Gacrux',
            'style' => 'une voix ancienne, sifflante et cruelle de sorcier du Dread qui psalmodie',
        ],
        'sorciere' => [
            'voix' => 'Despina',
            'style' => 'une voix féminine glacée, ensorcelante et méprisante de sorcière',
        ],
        'fleau' => [
            'voix' => 'Umbriel',
            'style' => 'un murmure humide, grouillant et chuintant de végétation pourrie et d\'insecte',
        ],
        'spectre' => [
            'voix' => 'Enceladus',
            'style' => 'un chuchotement spectral, lointain et soufflé, comme venu d\'outre-tombe',
        ],
        'elfe' => [
            'voix' => 'Schedar',
            'style' => 'une voix claire, hautaine et froide d\'elfe corrompu',
        ],
        'glace' => [
            'voix' => 'Achernar',
            'style' => 'une voix glaciale, lente et cristalline, comme un vent de banquise',
        ],
        'dragon' => [
            'voix' => 'Alnilam',
            'style' => 'une voix immense, grondante et souveraine de dragon ancien, chargée de feu',
        ],
        'construct' => [
            'voix' => 'Rasalgethi',
            'style' => 'une voix mécanique, monocorde et grinçante de pierre et de métal animés',
        ],
        'tueur' => [
            'voix' => 'Achird',
            'style' => 'une voix basse, sèche et moqueuse de tueur à gages',
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
        'Abomination' => 'demon',
        // Variantes À DISTANCE (Against the Ogre Horde p. 8, `monstres.variante_distance_de`) :
        // même créature, même voix que leur monstre de base.
        'Gobelin archer' => 'gobelin',
        'Orque archer' => 'brute',
        'Archer squelette' => 'mort_vivant',
        // Gabarits génériques (sans boîte).
        'Troll' => 'ogre',
        'Chamane Gobelin' => 'gobelin',
        'Liche' => 'mort_vivant',
        'Sorcier des Tempêtes' => 'sorcier',
        // Kellar's Keep (2026-10-23) : le squelette-roi, la gargouille-statue et la gardienne, le sorcier Borokk, Gragor ;
        // Witch Lord : Bellthor (une gargouille). Le Métamorphe : la voix de sa forme de départ, l'Abomination.
        'Squelette-roi nain' => 'mort_vivant',
        'Métamorphe' => 'demon',
        'Gargouille-statue' => 'demon',
        'Gargouille gardienne' => 'construct',
        'Borokk' => 'sorcier',
        'Gragor' => 'sorcier',
        'Bellthor' => 'demon',
        'Sorcier des Tempêtes' => 'sorcier',
        // Rise of the Dread Moon
        'Cultiste du Dread' => 'sorcier',
        'Spectre' => 'spectre',
        'Assassin' => 'tueur',
        'Garde-mage' => 'sorcier',
        'Ombre du Dread' => 'spectre',
        'Sir Ragnar' => 'champion',
        // The Mage of the Mirror
        'Ogre' => 'ogre',
        'Archer elfe' => 'elfe',
        'Guerrier elfe' => 'elfe',
        'Loup géant' => 'bete',
        'Archimage elfe' => 'elfe',
        // The Frozen Horror
        'Ours polaire de guerre' => 'bete',
        'Gremlin des glaces' => 'gobelin',
        'Yéti' => 'bete',
        'Horreur des Glaces' => 'glace',
        // Against the Ogre Horde
        'Ogre guerrier' => 'ogre',
        'Ogre champion' => 'ogre',
        'Ogre commandant' => 'ogre',
        'Seigneur ogre' => 'ogre',
        'Doralf' => 'ogre',
        'Gruzbella Hammerhand' => 'ogresse',
        'Gruzbella Déterminée' => 'ogresse',
        'Gruzbella Imprudente' => 'ogresse',
        'Spawn of the Pit' => 'demon',
        'Spawn of the Pit déchaîné' => 'demon',
        'Nexrael' => 'sorcier',
        'Xenloth' => 'sorcier',
        'Festral' => 'sorcier',
        'Effigie gardienne' => 'construct',
        // Jungles of Delthrak
        'Rejeton putride' => 'fleau',
        'Tisseur putride' => 'fleau',
        'Crâne putride' => 'fleau',
        'Raptor' => 'bete',
        'Rampant putride' => 'fleau',
        'Serpent géant' => 'bete',
        'Singe géant' => 'bete',
        'Gretzl la Porte-Fléau' => 'sorciere',
        'Demonspider' => 'fleau',
        'Demonape' => 'demon',
        'Gruulob, Sorcier Gobelin Corrompu' => 'gobelin',
        'Gruulob, Forme Démoniaque' => 'demon',
        'Deathspinner la Reine du Fléau' => 'sorciere',
        'Golem Cœur-de-Forge' => 'construct',
        'Scion Fleur-Pourrie' => 'fleau',
        'Vrille' => 'fleau',
        'Bakabri le Cruel' => 'sorcier',
        // First Light
        // Qwindrak : une voix de sorcier (son répertoire est celui d'un boss sorcier, `archetype_lanceur` qwindrak).
        // Ses formes génériques (Gobelin, Orque…) gardent la leur — la voix est celle de la figure qui crie.
        'Qwindrak the Warlock' => 'sorcier',
        'Dragon' => 'dragon',
        // Prophecy of Telor
        'Sorcier du Dread' => 'sorcier',
        // Fellmarak, le Roi Sorcier : la voix de sorcier du Dread, comme le sorcier générique de la boîte.
        'Fellmarak le Roi Sorcier' => 'sorcier',
        // Wizards of Morcar
        'Maître des orages' => 'sorcier',
        'Haut mage' => 'sorcier',
        'Nécromancien' => 'sorcier',
        'Mage de guerre orque' => 'sorcier',
        'Artificière' => 'sorciere',
        'Golem' => 'construct',
        'Dreadshifter' => 'spectre',
        'Minotaure' => 'bete',
        // Return of the Witch Lord (F4193) : la voix suit la figure de la fiche — un mort-vivant, un Dread, un
        // chef de légion, une reine sorcière, et le Seigneur final, voix de boss.
        'Cavalier-esprit' => 'mort_vivant',
        'Garde du Dread' => 'demon',
        'Skulmar' => 'champion',
        'Kessandria' => 'sorcier',
        'Seigneur Sorcier' => 'boss',
        // Spirit Queen's Torment (thème allumé le 2026-10-23) : deux sorcières, la voix féminine de l'Artificière de Morcar.
        'Kavra' => 'sorciere',
        'Nelath, la Reine des Esprits' => 'sorciere',
        // The Crypt of Perpetual Darkness (G1798, chantier EXT-CRYPTE, 2026-10-23) : la voix suit la figure du
        // livret — un crâne qui tire, une sorcière des marais, une momie, une reine gobeline, le chevalier de la
        // mort (voix de sorcier du Dread), et le dragon final (voix du Dragon de First Light).
        'Crâne du Dread' => 'spectre',
        'Sorcière des Marais' => 'sorciere',
        'Squelette de minotaure' => 'mort_vivant',
        'Buubhealxea, reine gobeline' => 'gobelin',
        'Roi Archaloneus' => 'mort_vivant',
        'Momie-naine' => 'mort_vivant',
        'Kedrick Gilbane' => 'sorcier',
        'Venim le Dragon' => 'dragon',
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
        'ogre' => [
            'attaque' => ['Ogre a faim !', 'Je vais t\'écraser comme un cafard !', 'Viens dans la marmite !'],
            'touche' => ['Ça chatouille !', 'GRAOUH ! Petit insolent !', 'Tu m\'as énervé !'],
            'rate' => ['Hoho ! Trop petit !', 'Même pas senti !', 'C\'est une piqûre de moustique ?'],
            'mort' => ['Ogre… plus faim…', 'Grrouh… tombe…', 'Pas… juste…'],
        ],
        'ogresse' => [
            'attaque' => ['Place à Gruzbella !', 'Mon marteau a soif !', 'Allez, viens danser !'],
            'touche' => ['Ha ! Enfin un adversaire !', 'Tu cognes fort, pour un nabot !', 'Ça, tu vas le payer !'],
            'rate' => ['Raté, mon mignon !', 'C\'est tout ce que t\'as ?', 'Je m\'ennuie !'],
            'mort' => ['Bien joué… nabot…', 'La horde… chantera mon nom…', 'Pas… encore…'],
        ],
        'bete' => [
            'attaque' => ['GRRRAAAOH !', 'Rrrraaah !', 'Hhhhhaaarrr !'],
            // ⚠ Gemini TTS rend parfois une réponse SANS AUDIO sur un grognement sans
            // voyelle terminé par « … » (3 échecs sur 6, 0 sur les 6 en « ! ») : voyelle
            // et point d'exclamation.
            'touche' => ['Kaïïï !', 'GRAOU !', 'Rraaah, rraaah !'],
            'rate' => ['Grrraou !', 'Hhhfff !', 'Rrrrrrr…'],
            // ⚠ « Aouuuuh… » puis « Aouuuuuh ! » : deux délais de 60 s dépassés d'affilée
            // (2026-10-21) — une voyelle tenue que le modèle semble ne jamais finir.
            // Un râle COURT, sur le modèle des « Aaargh… » qui passent ailleurs.
            'mort' => ['Raaargh… hhh…', 'Hhhnnn…', 'Rrrr… rrr…'],
        ],
        'sorcier' => [
            'attaque' => ['Que le Dread vous consume !', 'Brûlez !', 'Sentez la colère de Zargon !'],
            'touche' => ['Ma magie me protège…', 'Vous ne savez pas à qui vous avez affaire !', 'Impudents !'],
            'rate' => ['Vos armes sont aveugles !', 'Ha ! Prévisible.', 'Les ténèbres m\'ont averti.'],
            'mort' => ['Le Dread… m\'abandonne…', 'Zargon… vengez-moi…', 'Mon savoir… perdu…'],
        ],
        'sorciere' => [
            'attaque' => ['Approchez, mes petits…', 'Je vais vous défaire, fil à fil.', 'Votre fin est déjà tissée.'],
            'touche' => ['Tu oses abîmer mon œuvre ?', 'Insolent !', 'Je m\'en souviendrai.'],
            'rate' => ['Si maladroit…', 'Tu me fais pitié.', 'Encore manqué, mon chou.'],
            'mort' => ['Non… pas maintenant…', 'Mon œuvre… inachevée…', 'Vous… paierez…'],
        ],
        'fleau' => [
            'attaque' => ['Sssss… pourrissez…', 'Le Fléau vous réclame…', 'Grandir… dévorer…'],
            'touche' => ['Ssskkkk !', 'Ça repousse… toujours…', 'Kssshhh…'],
            'rate' => ['Ssss… lent…', 'Le Fléau ne craint rien…', 'Kkhhh…'],
            'mort' => ['Ssss… flétrir…', 'Retourner… à l\'humus…', 'Khhhh…'],
        ],
        'spectre' => [
            'attaque' => ['Votre souffle… m\'appartient…', 'Froid… si froid…', 'Venez… dans l\'ombre…'],
            'touche' => ['Vous ne pouvez saisir… le vent…', 'Ahhh…', 'Je me dissipe… et reviens…'],
            'rate' => ['Vous frappez… le vide…', 'Je ne suis… plus ici…', 'Rien… vous ne touchez rien…'],
            'mort' => ['Enfin… libre…', 'Le voile… se déchire…', 'Ahhhh…'],
        ],
        'elfe' => [
            'attaque' => ['Tu n\'aurais pas dû venir ici.', 'Pas un pas de plus.', 'Ma lame est plus vieille que ta lignée.'],
            'touche' => ['Une chance, rien de plus.', 'Tu vas le regretter.', 'Hmph.'],
            'rate' => ['Lourd et lent, comme tous les tiens.', 'Prévisible.', 'Pitoyable.'],
            'mort' => ['Le miroir… se brise…', 'Si loin… de chez moi…', 'Non…'],
        ],
        'glace' => [
            'attaque' => ['Que l\'hiver vous prenne.', 'Gelez.', 'Le froid ne pardonne pas.'],
            'touche' => ['La glace se reforme.', 'Vous ne faites que me réveiller.', 'Craquement… rien de plus.'],
            'rate' => ['Vos lames gèlent.', 'Trop froid pour vous.', 'L\'hiver est patient.'],
            'mort' => ['Le dégel… déjà…', 'Fondre…', 'L\'hiver… reviendra…'],
        ],
        'dragon' => [
            'attaque' => ['BRÛLEZ, MORTELS !', 'Vous serez cendres !', 'Mon feu est éternel !'],
            'touche' => ['Une égratignure !', 'VOUS OSEZ ?!', 'Mes écailles en ont vu d\'autres !'],
            'rate' => ['Des moucherons !', 'Ridicule !', 'Je ne sens rien !'],
            'mort' => ['Mille ans… pour finir ainsi…', 'Mon trésor…', 'GRRAAAAH…'],
        ],
        'construct' => [
            'attaque' => ['Intrus. Éliminer.', 'Ordre reçu.', 'Avancer. Frapper.'],
            'touche' => ['Dommage… mineur.', 'Fissure… détectée.', 'Fonction… maintenue.'],
            'rate' => ['Aucun effet.', 'Structure intacte.', 'Inefficace.'],
            'mort' => ['Arrêt…', 'Fonction… terminée…', 'Effondrement…'],
        ],
        'tueur' => [
            'attaque' => ['Rien de personnel.', 'Tu ne m\'as pas vu venir.', 'Ton nom était sur ma liste.'],
            'touche' => ['Pas mal.', 'Tu viens de doubler mon prix.', 'Tch.'],
            'rate' => ['Trop lent.', 'Par ici.', 'Raté.'],
            'mort' => ['Le contrat… était trop bas…', 'Bien joué…', 'Ombre… à l\'ombre…'],
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
