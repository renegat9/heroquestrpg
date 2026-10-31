<?php

/*
|--------------------------------------------------------------------------
| Illustrations du jeu (Gemini image) — style + gabarits de prompt
|--------------------------------------------------------------------------
| Sert à la pré-génération hors-ligne du catalogue (php artisan images:generer)
| et aux jobs dynamiques (boss/scène/hub/portrait). Un préfixe de STYLE commun
| assure la cohérence artistique ; chaque gabarit interpole les champs de
| l'entité ({nom}, {detail}, {categorie}, {tier}, {description}, {intro}…).
| Sans clé/asset, le front retombe sur les icônes — ces prompts ne sont jamais
| nécessaires au jeu.
*/

return [

    // Préfixe de style ajouté à TOUS les prompts (cohérence visuelle).
    'style' => 'Illustration de jeu de plateau dark-fantasy heroic, peinture numérique '
        .'détaillée, éclairage de torche dramatique, fond sombre neutre, sujet centré, '
        .'sans texte, sans bordure, sans filigrane.',

    // Gabarits par type. {style} est remplacé par la valeur ci-dessus.
    'gabarits' => [
        'classe' => 'Portrait héroïque en buste d\'un {detail}, personnage de jeu de rôle fantasy. {style}',
        // MONSTRE (2026-10-31) : un monstre SANS apparence dans `config/apparences_monstres.php` garde son nom et
        // son tier ; la consigne « aucune inscription » est ajoutée, parce que Gemini recopiait des noms dans l'image.
        'monstre' => 'Figurine de monstre de donjon : {nom} (tier {tier}), créature menaçante en pied. Illustration seule : aucune inscription, aucun chiffre, aucune interface. {style}',
        // MONSTRE NOMMÉ (2026-10-31) : l'APPARENCE tirée du livret remplace le NOM. Un nom propre (Ulag, Kessandria,
        // Archaloneus…) faisait dessiner un démon cornu à tous les méchants nommés. Lu par
        // `BibliothequeImages::promptMonstre()`, seul point de passage.
        'monstre_nomme' => 'Figurine de monstre de donjon, en pied : {apparence}. Illustration seule : aucune inscription, aucun chiffre, aucune interface. {style}',
        // ALLIÉ (2026-10-01, René : « des illustrations pour les alliés quand
        // ils font des actions ») : mercenaire ou créature qui combat AUX CÔTÉS
        // des héros. ⚠ Le prompt porte la DESCRIPTION du catalogue : avec le
        // seul type, Gemini inventait l'équipement (l'Estafier « bretteur à
        // deux haches » sortait avec un marteau et un bouclier).
        // ⚠ La description mêle allure et RÈGLES (« la meilleure défense du
        // jeu, sur deux points de vie ») : Gemini en a fait une incrustation
        // « 2 HP » sur l'Estafier — d'où l'interdit explicite ci-dessous.
        'mercenaire' => 'Mercenaire allié des héros, en pied dans un donjon : {nom}. {description} '
            .'Illustration seule : aucune inscription, aucun chiffre, aucune interface. {style}',
        // ⚠ Gabarit À PART pour les animaux : « en pied » et « compagnon
        // d'aventure » faisaient du Croc-sabre — un fauve — un guerrier-tigre
        // debout, massue au poing.
        'mercenaire_animal' => 'Bête de combat apprivoisée, alliée des héros dans un donjon : {nom}. {description} '
            .'Un animal sauvage, sur ses pattes, sans arme ni vêtement. '
            .'Illustration seule : aucune inscription, aucun chiffre, aucune interface. {style}',
        'objet' => 'Icône d\'inventaire : {nom}, un objet de catégorie « {categorie} », objet seul présenté sur fond sombre. {style}',
        'piege' => 'Piège de donjon : {nom}, mécanisme dangereux dans un couloir de pierre. {style}',
        // ÉPREUVE (2026-08-24) : un élément de décor auquel on se MESURE, à
        // distinguer du piège (danger subi) comme du meuble (obstacle) — d'où
        // l'insistance sur l'énigme et l'usure plutôt que sur la menace.
        'epreuve' => 'Élément de donjon à examiner : {nom}. {description} Détail énigmatique et usé par le temps, sculpté dans la pierre. {style}',
        // MOBILIER (2026-08-27) : les huit pièces n'avaient AUCUNE illustration
        // — ni gabarit, ni génération, ni `image_url` —, alors que pièges et
        // épreuves en ont depuis leur arrivée. Une pièce se FOUILLE et se
        // FRACASSE : c'est un élément de jeu, pas un décor. Le prompt insiste
        // sur la vue de dessus et l'emprise, parce qu'un meuble se lit sur la
        // carte à plat, contrairement à un piège cadré en couloir.
        // ⚠ EMPRISE EN MOTS (2026-10-16) : `{forme}` vient de `BibliothequeImages::formeMobilier()`,
        // jamais des chiffres. « 3 × 2 » faisait peindre la table à 2,8 : 1 au lieu de 1,5 : 1 (la
        // consigne « occupe presque toute la longueur » l'étirait), et « 3 × 1 » ne disait pas quel
        // côté est le long. Le style à TORCHES est écarté pour la même raison : c'est lui qui a peint
        // un brasero dans la bibliothèque. `largeur` est l'axe horizontal de la carte, `hauteur` sa
        // profondeur, et l'orientation de l'image suit cette convention.
        // « Pièce » se lisait comme une SALLE : la bibliothèque (3 × 1) est sortie en pièce
        // entière, avec sol et torches. Le mot est donc « mobilier », jamais « pièce ».
        'mobilier' => 'Meuble de donjon vu STRICTEMENT DE DESSUS, comme un plan de jeu de société, '
            .'posé à plat sur le sol (jamais accroché à un mur) : {nom}. {description} '
            .'La caméra regarde le sol à la verticale : on ne voit ni façade, ni face, ni porte de face, '
            .'ni planche dressée, ni perspective, seulement le dessus du meuble. '
            .'Le meuble est seul au centre du cadre, sur un fond sombre uni, sans sol, sans mur, '
            .'sans torche, sans flamme, sans personnage, sans autre objet, sans aucune scène. '
            .'Ses proportions sont exactes : {forme}, avec des marges autour de lui. '
            .'Illustration de jeu de plateau dark-fantasy, peinture numérique nette, lumière uniforme '
            .'et douce, sans texte.',
        // TERRAIN (doc 18 §4, The Frozen Horror, phase 4a) : une case de sol
        // dangereuse ou magique (glace glissante, rivière gelée, tunnel de
        // téléportation…), pas un obstacle qu'on contourne comme le mobilier —
        // d'où l'insistance sur la texture de la case elle-même, vue de dessus
        // comme le mobilier (une case se lit à plat sur la carte).
        // ⚠ NEUTRE depuis le 2026-10-15 : le gabarit disait « texture de glace ou de givre » pour TOUT
        // terrain, y compris le sable, la mare et le brasier de la jungle — et un « Sables mouvants »
        // généré par ce gabarit aurait été givré. Le NOM porte la matière.
        'terrain' => 'Case de sol de donjon vue de dessus : {nom}, avec la texture caractéristique de cette matière. Case isolée sur fond sombre. {style}',
        // LEVIER et PORTE (2026-08-29) : les deux seuls éléments d'une salle
        // sans table de catalogue — un levier n'est qu'un id dans la grille, une
        // porte une arête. Ils sont donc nommés comme les CLASSES, par un
        // libellé fixe et non par `{id}-{slug}` : il n'y a pas de ligne à
        // numéroter. Un état de porte = une illustration, parce que c'est
        // l'état qui porte l'information (close, verrouillée, dérobée) et
        // qu'une image unique les rendrait indiscernables.
        'levier' => 'Mécanisme de donjon : un levier de fer scellé dans un mur de pierre, '
            .'longue poignée usée par les mains, engrenages apparents. {style}',
        'porte' => 'Porte de donjon en bois cerclé de fer, vue de face dans un mur de pierre : {detail}. {style}',
        'sort' => 'Illustration de sort de magie : {nom}, magie élémentaire ({element}), effet {type} spectaculaire, symbole arcanique lumineux. {style}',
        // SORT DE DREAD (2026-10-16) : la magie du MJ, carte par carte. Même famille
        // visuelle que les sorts de héros, mais une magie CORROMPUE : « magie de Zargon ».
        // Le sujet est `{effet}` (voir `sorts_dread_effets`), jamais la règle de la carte.
        'sort_dread' => 'Illustration de sort de magie noire de Zargon, le sorcier du Dread : {nom}. {effet} '
            .'Magie corrompue et sinistre, lueurs violettes et vert bilieux, symbole arcanique à l\'effigie '
            .'de Zargon, une seule scène centrée, sans texte. {style}',

        // Dynamiques (jobs) :
        'boss' => 'Boss de donjon imposant et terrifiant : {nom}. {description} {style}',
        'scene' => 'Illustration d\'ambiance, scène d\'ouverture d\'une quête de donjon fantasy : {intro} {style}',
        'hub' => 'Lieu de repos des aventuriers entre deux quêtes (campement / salle commune chaleureuse), ambiance de répit. {premisse} {style}',
        'portrait' => 'Portrait héroïque en buste de « {nom} », un {detail}, personnage unique de jeu de rôle fantasy. {style}',
    ],

    // Détail d'apparence par ÉTAT DE PORTE (enrichit {detail} pour `porte`).
    // ⚠ Les clés sont celles de `MoteurPortes::ETAT_*` — le contrat publie cet
    // état tel quel, et un libellé inventé ici ne trouverait jamais son image.
    'portes' => [
        'fermee' => 'battant clos et massif, lourds gonds de fer, aucune ouverture',
        'ouverte' => 'battant grand ouvert sur les ténèbres de la salle au-delà',
        'verrouillee' => 'battant clos barré de chaînes et d\'un gros cadenas de fer rouillé',
        'secrete' => 'passage dérobé entrouvert dans la maçonnerie, pierre pivotante révélée',
    ],

    // Sujet d'une carte de Dread (`{effet}` du gabarit `sort_dread`) : son TYPE d'effet,
    // jamais sa règle — `effet` porte les dés et les crânes, que Gemini peindrait en
    // chiffres sur la carte. ⚠ Les clés sont celles de `MotsClesSortDread::TYPES`, et un
    // test les confronte DANS LES DEUX SENS : un type ajouté au moteur sans phrase ici
    // tomberait en silence sur un prompt vide.
    'sorts_dread_effets' => [
        'degats' => 'Une décharge de magie destructrice jaillit en direction du héros.',
        'controle' => 'Un sort qui entrave la volonté et les membres de sa victime.',
        'invocation' => 'Des créatures surgissent de la terre et des ténèbres pour combattre aux côtés du sorcier.',
        'soin' => 'Une lueur sombre qui referme les blessures du sorcier et de ses créatures.',
        'fuite' => 'Une fumée d\'ombre qui permet à celui qui la lance de disparaître.',
        'destruction' => 'Une magie qui ronge, rouille et brise l\'équipement de ses victimes.',
        'mind' => 'Une attaque qui vrille l\'esprit, visible comme une onde de souffrance.',
        'terrain' => 'Le sol se transforme : glace, pierre ou terre qui se soulève.',
        'deplacement' => 'Une force invisible arrache sa victime et la déplace de force.',
        'mur_magique' => 'Un mur de magie se dresse et barre le passage.',
        'repoussement' => 'Une vague de magie repousse brutalement les adversaires.',
        'oubli' => 'Un sort qui efface un savoir de la mémoire de sa victime.',
        'reaction' => 'Une contre-magie prête à riposter, un bouclier de ténèbres.',
        'renfort' => 'Une protection magique qui renforce les alliés du sorcier.',
        'amelioration' => 'Une magie qui rend une arme ou une armure plus redoutable.',
        'drain' => 'Une magie qui aspire la vie de sa victime pour nourrir le sorcier.',
        'domination' => 'Un regard qui asservit la volonté d\'un héros.',
    ],

    // Détail d'apparence par classe (enrichit {detail} pour classe/portrait).
    'classes' => [
        'barbare' => 'barbare musclé en fourrures avec une grande hache',
        'nain' => 'nain robuste à la barbe tressée, en armure, avec un marteau de guerre',
        'elfe' => 'elfe agile aux traits fins, avec un arc',
        'magicien' => 'magicien en longue robe, tenant un bâton, entouré d\'une aura arcanique',
        // Les 8 classes d'extension. Les descriptions suivent l'illustration
        // de leur carte officielle (numérisation du 2026-08-11).
        'barde' => 'barde orc à la peau verte, chapeau à plume violet, luth dans le dos et rapière à la ceinture',
        'druide' => 'druide, femme aux cheveux tressés et couronne de fleurs, bâton de bois noueux, vêtue de cuir et de feuilles',
        'warlock' => 'sorcière halfeline frêle, flamme verte spectrale flottant à sa main, longue robe en lambeaux',
        'rogue' => 'voleur elfe blond en manteau à col de fourrure, dague et épée courte, silhouette agile',
        'moine' => 'moine en tunique de lin, mains nues en garde, sans armure, posture de combat',
        'chevalier' => 'chevalier en armure de plates bleue et rouge, grand bouclier au lion doré, cape',
        'berserker' => 'berserker torse nu sous des fourrures, hache à deux mains levée, hurlant',
        'explorateur' => 'exploratrice encapuchonnée de jaune, sac de voyage, piolet à la main, lampe à la ceinture',
    ],
];
