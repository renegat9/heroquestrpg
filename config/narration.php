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
                '{heros} racle la poussière d\'un renfoncement et en sort {or} pièces d\'or.',
                'Un coffret minuscule, oublié là : {heros} y trouve {or} pièces d\'or.',
                '{heros} tire sur une brique descellée — {or} pièces d\'or tombent à ses pieds.',
            ],
        ],
        'fouille_potion' => [
            'ambiance' => 'calme',
            'variantes' => [
                'Calée dans une niche, une fiole intacte : {heros} empoche {objet}.',
                '{heros} déniche {objet}, miraculeusement préservée sous les gravats.',
                'Le renfoncement gardait son secret : {objet}, que {heros} range précieusement.',
                '{heros} referme la main sur {objet}, intacte malgré les siècles.',
                'Une fiole roule hors d\'une fissure : {objet}, pour {heros}.',
                '{heros} dégage la poussière et met la main sur {objet}.',
            ],
        ],
        'fouille_errant' => [
            'ambiance' => 'tension',
            'variantes' => [
                'Le bruit de la fouille a porté trop loin : {monstre} surgit de l\'ombre, droit sur {heros} !',
                '{heros} n\'était pas seul : {monstre} rôdait, et vient de trouver sa proie.',
                'Un grognement dans le noir — {monstre} répond au vacarme et fond sur {heros}.',
                'Quelque chose répond au raclement : {monstre} sort de l\'ombre vers {heros}.',
                '{heros} a réveillé ce qui dormait là — {monstre} se dresse.',
                'Un souffle rauque derrière {heros}, et {monstre} est déjà sur lui.',
            ],
        ],
        'fouille_piege' => [
            'ambiance' => 'tension',
            'variantes' => [
                'Les doigts de {heros} rencontrent un fil tendu — trop tard, le mécanisme est déjà parti.',
                'Ce n\'était pas une cachette mais un appât : {heros} déclenche le piège de plein fouet.',
                '{heros} tire sur la mauvaise pierre, et le donjon riposte.',
                '{heros} déplace la mauvaise pierre, et le mécanisme répond.',
                'Un cliquetis sous les doigts de {heros} — trop tard pour retirer la main.',
                'La cachette était piégée, et {heros} l\'apprend à ses dépens.',
            ],
        ],
        'fouille_rien' => [
            'ambiance' => 'calme',
            'variantes' => [
                '{heros} retourne la poussière un long moment. Rien. D\'autres sont passés avant.',
                'Rien que de la pierre froide sous les mains de {heros}.',
                '{heros} fouille avec méthode, et ne remonte que des gravats.',
                '{heros} sonde chaque interstice et n\'en tire que de la poussière.',
                'Rien ici. {heros} se relève, les mains vides.',
                '{heros} cherche longtemps, pour rien.',
            ],
        ],
        'mobilier_objet' => [
            'ambiance' => 'victoire',
            'variantes' => [
                'Le meuble rend ce qu\'il gardait : {heros} en retire {objet}.',
                '{heros} force le bois vermoulu et met la main sur {objet}.',
                'Sous un double fond, {objet} — {heros} ne se le fait pas dire deux fois.',
                '{heros} fait sauter le fermoir : {objet} attendait là.',
                'Au fond d\'un tiroir gonflé d\'humidité, {heros} trouve {objet}.',
                '{heros} déplace ce qui traîne et met la main sur {objet}.',
            ],
        ],
        'mobilier_piege' => [
            'ambiance' => 'tension',
            'variantes' => [
                'Le meuble était protégé : quelque chose mord la main de {heros}.',
                'Un déclic sec dans le bois, et {heros} retire sa main trop tard.',
                'Ce que gardait ce meuble, c\'était surtout une mauvaise surprise pour {heros}.',
                'Le meuble se défend : quelque chose claque sous les doigts de {heros}.',
                '{heros} force la serrure, et la serrure rend coup pour coup.',
                'Une aiguille, une lame, un ressort — {heros} n\'a pas le temps de voir.',
            ],
        ],
        'mobilier_rien' => [
            'ambiance' => 'calme',
            'variantes' => [
                '{heros} ouvre, sonde, retourne — le meuble est vide depuis longtemps.',
                'Rien dans ce meuble que de la poussière et le travail du temps.',
                '{heros} referme sur du vide.',
                '{heros} vide le meuble de sa poussière, et c\'est tout.',
                'Des chiffons, du bois pourri, rien pour {heros}.',
                '{heros} referme le battant sur un vide parfait.',
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
        // ── Clés à FORTE fréquence ──────────────────────────────────────
        //
        // Elles tombent à chaque jet de dés et à chaque coup porté, et n'avaient
        // que DEUX variantes chacune — quand les clés rares en avaient trois.
        // Héritage du temps où ces répliques n'étaient qu'un filet derrière une
        // narration écrite par l'IA : la bascule du 2026-08-18 les a promues au
        // rang de cas nominal sans que personne ne réévalue leur nombre.
        // Résultat mesuré sur une campagne réelle : 293 narrations pour 94
        // textes distincts, le plus fréquent lu DIX-HUIT FOIS.
        //
        // Les étoffer ne coûte RIEN — ces répliques ne sont jamais synthétisées
        // en audio (leur texte n'existe qu'une fois substitué, le hash ne tombe
        // jamais) et vivent en config, donc zéro token et zéro quota TTS.
        'attaque_mort' => [
            'ambiance' => 'tension',
            'variantes' => [
                'Le coup porte et l\'adversaire accuse le choc avant de s\'effondrer.',
                'Un dernier râle, et l\'ennemi s\'écroule sous la puissance du coup.',
                'La créature chancelle, cherche appui sur le vide, et tombe.',
                'Un craquement sec — la chose s\'affaisse et ne bouge plus.',
                'Le coup traverse la garde et fauche l\'adversaire net.',
                'L\'ennemi recule d\'un pas, s\'effondre sur les genoux, puis sur la pierre.',
                'Plus de souffle, plus de garde : la créature s\'abat lourdement.',
                'Un dernier soubresaut, et le silence reprend sa place.',
                'La riposte n\'arrive jamais : l\'adversaire est déjà à terre.',
                'Le corps glisse le long du mur et s\'immobilise dans la poussière.',
            ],
        ],
        'attaque_touche' => [
            'ambiance' => 'tension',
            'variantes' => [
                'Le coup porte et l\'adversaire accuse le choc, mais reste menaçant.',
                'La lame mord la chair : l\'ennemi vacille, sans tomber.',
                'Touché — la créature grogne et resserre sa garde.',
                'Le coup entame, sans abattre. L\'adversaire tient encore.',
                'Un cri rauque : la blessure est réelle, la menace aussi.',
                'La frappe ouvre une plaie ; l\'ennemi recule d\'un pas et revient.',
                'Le choc le déséquilibre un instant, pas davantage.',
                'Le sang coule, mais les yeux ne cillent pas.',
                'Bien placé — pas encore assez pour en finir.',
                'La créature encaisse et redresse la tête, plus hargneuse.',
            ],
        ],
        'attaque_pare' => [
            'ambiance' => 'tension',
            'variantes' => [
                'L\'attaque est parée de justesse : l\'adversaire tient bon et le combat continue.',
                'Le coup ricoche sur la défense ennemie ; rien n\'est encore joué.',
                'La garde se referme au dernier instant — le coup se perd.',
                'Un choc mat, et rien de plus : l\'attaque n\'a pas trouvé la faille.',
                'L\'adversaire dévie la frappe d\'un mouvement d\'épaule.',
                'La lame glisse sans mordre. Il faudra recommencer.',
                'Esquivé de peu — la créature était déjà ailleurs.',
                'Le fer rencontre le fer, et le combat reprend son souffle.',
                'Rien ne passe : la défense tient, obstinée.',
                'Le coup fend l\'air à un doigt de sa cible.',
            ],
        ],
        // Mort d'un BOSS ou d'un SOUS-BOSS — la seule chose qui perce le
        // silence du combat, et la seule réplique de combat qui NOMME sa cible.
        //
        // ⚠ Les noms de créatures portent DÉJÀ leur article (« Le Noyé de
        // Gorrim », « La Vase Putrescente ») : ne jamais faire précéder
        // {monstre} d'un déterminant ni d'un « de ». Constaté en conditions
        // réelles — « Le râle de Le Noyé de Gorrim ». Placer {monstre} en
        // sujet, ou après un verbe, jamais après une préposition.
        // En campagne réelle, le boss final est tombé entre deux lignes de log,
        // sans un mot, après quatre quêtes de préparation.
        'boss_vaincu' => [
            'ambiance' => 'victoire',
            'variantes' => [
                '{monstre} vacille, immense encore, puis s\'effondre dans un fracas qui court le long des murs.',
                'Le dernier coup atteint {monstre} : la créature tombe, et quelque chose se dénoue dans le donjon.',
                'Un long silence, puis {monstre} s\'abat de tout son poids sur la pierre. C\'est fini.',
                '{monstre} tend une dernière fois vers le groupe une main qui n\'atteindra personne, et s\'écroule.',
                'La chose que redoutait tout ce donjon n\'est plus qu\'une forme immobile : {monstre} est vaincu.',
                '{monstre} pousse un dernier râle qui se perd sous les voûtes, et ne revient pas.',
            ],
        ],
        // Chute et relèvement d'un héros. Aucune clé ne les couvrait : une
        // héroïne est restée à terre vingt-deux minutes sans un mot.
        'heros_tombe' => [
            'ambiance' => 'defaite',
            'variantes' => [
                '{heros} s\'effondre, l\'arme lui échappe des mains et résonne sur la pierre.',
                'Un genou, puis l\'autre : {heros} s\'affaisse et ne se relève pas.',
                'Le coup était de trop. {heros} tombe, immobile.',
                '{heros} vacille, cherche un appui qui n\'est pas là, et s\'écroule.',
                'Le souffle coupé, {heros} glisse au sol au milieu du fracas.',
                'La garde de {heros} cède d\'un coup — le corps suit.',
                'Plus rien ne tient {heros} debout. Le groupe perd une lame.',
                'Un cri bref, et {heros} disparaît sous la mêlée.',
            ],
        ],
        'heros_releve' => [
            'ambiance' => 'tension',
            'variantes' => [
                '{heros} reprend son souffle, se redresse en titubant, et ramasse son arme.',
                'Une main tendue, un effort — {heros} tient de nouveau debout.',
                '{heros} rouvre les yeux, la mâchoire serrée, et se remet en garde.',
                'Le sol lâche prise : {heros} se relève, blême mais vivant.',
                'Porté par les siens, {heros} retrouve ses appuis.',
                '{heros} crache la poussière et revient dans le combat.',
            ],
        ],
        'reussite' => [
            'ambiance' => 'tension',
            'variantes' => [
                'Le geste est sûr et la tentative réussit : le groupe reprend l\'avantage.',
                'Bien joué — l\'action aboutit et la voie se dégage.',
                'Le calcul était bon : ça passe.',
                'Une main assurée, et l\'affaire est réglée.',
                'La manœuvre fonctionne du premier coup.',
                'Rien ne résiste : la tentative aboutit proprement.',
                'Le donjon cède un peu de terrain.',
                'Voilà qui est fait, et bien fait.',
                'L\'obstacle tombe sans bruit.',
                'Le groupe gagne ce point-là.',
            ],
        ],
        'reussite_mixte' => [
            'ambiance' => 'tension',
            'variantes' => [
                'La tentative aboutit, mais quelque chose accroche au passage — le groupe progresse, sur ses gardes.',
                'Réussi, de justesse : un détail cloche, restez vigilants.',
                'Ça passe, mais pas proprement. Quelque chose a été dérangé.',
                'Le résultat est là ; le bruit qu\'il a fait, aussi.',
                'Un succès qui laisse un arrière-goût.',
                'L\'affaire est réglée, à un détail près — et les détails comptent, ici.',
                'Réussi. Mais quelque part, quelque chose a bougé.',
                'Le groupe obtient ce qu\'il voulait, et un peu plus.',
            ],
        ],
        'echec' => [
            'ambiance' => 'tension',
            'variantes' => [
                'La tentative échoue. Un silence pesant s\'installe, et le groupe doit envisager une autre approche.',
                'Raté. Le doute s\'immisce ; il faudra trouver une autre voie.',
                'Rien ne cède. Le donjon garde ce qu\'il tient.',
                'Le geste manque son but, et le temps file.',
                'Un échec net, sans excuse à chercher ailleurs.',
                'La pierre reste sourde à l\'effort.',
                'Ça ne prend pas. Autre chose, alors.',
                'L\'obstacle demeure, et le groupe avec lui.',
                'Peine perdue — pour cette fois.',
                'La tentative se perd sans laisser de trace.',
            ],
        ],
        'progression' => [
            'ambiance' => 'tension',
            'variantes' => [
                'L\'aventure suit son cours : le groupe progresse prudemment dans la pénombre, attentif au moindre bruit.',
                'Le donjon garde ses secrets. Les héros avancent, tous les sens en éveil.',
                'Un pas de plus dans les profondeurs, et rien qui rassure.',
                'La pierre suinte, les torches faiblissent, le groupe continue.',
                'L\'air se fait plus lourd à mesure qu\'ils s\'enfoncent.',
                'Rien ne bouge dans l\'ombre — pour l\'instant.',
                'Le silence du donjon n\'a rien de paisible.',
                'Les héros progressent, l\'oreille tendue vers ce qui ne vient pas encore.',
                'Quelque part devant, quelque chose attend son heure.',
                'Le couloir s\'étire, et la lumière ne porte pas bien loin.',
            ],
        ],
    ],
];
