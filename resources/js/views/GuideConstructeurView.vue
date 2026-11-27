<script setup>
// GUIDE DU CONSTRUCTEUR DE CAMPAGNES (René, 2026-11-20 : « il faudrait faire un guide pour le constructeur de map sur la
// page d'accueil »). Page publique et STATIQUE : elle décrit l'écran `/constructeur` et le serveur MCP du constructeur.
// Elle ne publie aucune règle : les listes de choix (verrous, effets de notes, catalogues) restent servies par
// `GET /api/constructeur/catalogues`, et la validation du serveur fait foi — ce texte dit où cliquer, pas ce qui est permis.
import MSym from '../components/ui/MSym.vue';

const SOMMAIRE = [
    ['principe', 'Le principe'],
    ['campagne', '1. La campagne'],
    ['plateau', '2. Le plateau'],
    ['quetes', '3. Les quêtes'],
    ['notes', '4. Les notes « quand → alors »'],
    ['livrets', '5. Les règles des livrets'],
    ['enregistrer', '6. Valider, enregistrer, jouer'],
    ['mcp', '7. Le serveur MCP (une IA au clavier)'],
    ['limites', 'Ce que l\'écran ne fait pas'],
];

const OUTILS = [
    ['meeting_room', 'Ouvrir / fermer une salle', 'Une salle ouverte fait partie du donjon de cette quête ; une salle fermée est de la roche.'],
    ['door_front', 'Porte', 'Cliquer le BORD d\'une case. Normale ou secrète ; une porte normale peut porter un verrou (note, clé, pierre, condition) ou être ouverte au départ, une secrète être déjà découverte — ou cachée : seule une note la révèle, jamais la fouille. Une porte ordinaire peut aussi être une porte où l\'on frappe (un dé, trois succès, puis la bonne porte s\'ouvre et pose un drapeau) ou une porte assiégée (ses PV et sa défense ; les monstres à son contact la frappent).'],
    ['block', 'Bloc de couloir', 'Une case de couloir bouchée.'],
    ['chair', 'Meuble', 'Choisir le meuble et sa rotation, cliquer sa case en haut à gauche. Un meuble se pose dans une salle ou, en entier, dans un couloir.'],
    ['skull', 'Monstre', 'Une figure du bestiaire. Son numéro (0, 1, 2…) sert aux notes et à la fin de quête.'],
    ['warning', 'Piège', 'Avec les options « introuvable », « indésarmable » et, pour une fosse, « gouffre sans fond » (qui y tombe est perdu à jamais). « Déclenché par une note » : aucune case foulée ne le fait partir, seule une note. Une lame balançoire reçoit sa zone (trois cases, debout ou couchée).'],
    ['water', 'Terrain', 'Glace, gouffre, pont… « posée au premier pas » la cache jusqu\'à ce qu\'un héros y marche. Une glissière de glace reçoit sa case d\'arrivée.'],
    ['my_location', 'Cible de fin', 'Pour une fin « vaincre » : désigne les monstres à abattre.'],
    ['login', 'Entrée', 'Un escalier 2×2 (case du coin haut-gauche) ou une porte (bord cliqué).'],
    ['logout', 'Sortie', 'Pareil ; « Sortie = entrée » remet la sortie sur l\'escalier. Une porte de sortie peut être verrouillée comme une porte barrée, et toute sortie rester cachée jusqu\'à un drapeau.'],
    ['block', 'Fausse porte de sortie', 'Une sortie dessinée qui ne mène nulle part.'],
    ['ink_eraser', 'Effacer', 'Retire ce qui est sur la case cliquée (monstre, piège, terrain, bloc, meuble).'],
];

const OUTILS_MCP = [
    ['catalogues', 'Les slugs posables (meubles, monstres, pièges, terrains), les classes, objets, sorts, mercenaires, captifs et tous les vocabulaires fermés.'],
    ['campagnes', 'La liste des campagnes enregistrées.'],
    ['campagne_lire', 'Le sommaire d\'une campagne (plateau, liste des quêtes).'],
    ['quete_lire', 'Le document complet d\'UNE quête.'],
    ['apercu', 'La carte d\'une quête rendue en texte, ses notes comprises — pour relire après une écriture.'],
    ['valider', 'Contrôle un document de campagne SANS rien écrire.'],
    ['campagne_ecrire', 'Écrit une campagne entière (crée, ou remplace celle du même slug).'],
    ['quete_ecrire', 'Écrit une seule quête dans une campagne.'],
    ['quete_modifier', 'Modifie une quête par opérations ciblées (ajouter, retirer, remplacer).'],
    ['exporter', 'Le fichier de la campagne, avec son empreinte.'],
    ['importer', 'Recharge un fichier exporté.'],
];

// Les captures (`browser-shots/guide-constructeur-captures.mjs`), faites sur une campagne d'exemple inventée.
const IMG = (nom) => `/images/guide-constructeur/${nom}.webp`;

const COMMANDE_MCP = 'claude mcp add constructeur -- docker compose -f /chemin/vers/heroquestrpg/docker-compose.yml exec -T app php -d display_errors=stderr artisan mcp:start constructeur';
</script>

<template>
    <div class="gc-screen tex-vignette">
        <header class="gc-top">
            <RouterLink to="/" class="gc-back"><MSym n="arrow_back" :size="16" /> Accueil</RouterLink>
            <div class="gc-titrewrap">
                <div class="gc-crest"><MSym n="architecture" fill /></div>
                <div>
                    <h1 class="gc-titre">Guide du constructeur</h1>
                    <p class="gc-sub">Dessiner un plateau, poser des quêtes et leurs notes, et les jouer en mode classique — à l'écran ou par une IA.</p>
                </div>
            </div>
            <RouterLink to="/constructeur" class="gc-ouvrir"><MSym n="open_in_new" :size="16" /> Ouvrir le constructeur</RouterLink>
        </header>

        <div class="gc-corps">
            <nav class="gc-sommaire" aria-label="Sommaire">
                <a v-for="[id, lib] in SOMMAIRE" :key="id" :href="`#${id}`">{{ lib }}</a>
            </nav>

            <main class="gc-texte">
                <section id="principe">
                    <h2>Le principe</h2>
                    <p>Une <b>campagne construite</b>, c'est un plateau (les salles) et une suite de quêtes posées dessus. Le constructeur l'écrit ; le mode <b>classique</b> la joue, quête après quête, sans que l'IA n'invente la carte.</p>
                    <ul>
                        <li><b>Le serveur valide tout.</b> À chaque modification, l'écran envoie la campagne au serveur, qui renvoie la liste des refus (en haut à droite : « Valide » ou « N refus »). Chaque refus dit où est le problème.</li>
                        <li><b>Rien n'est écrit sans « Enregistrer ».</b> On peut tout essayer ; recharger la page annule.</li>
                        <li><b>Les campagnes enregistrées sont de vraies données.</b> Enregistrer une campagne sous un slug existant la remplace. Exportez avant une grosse modification.</li>
                        <li>Un groupe déjà en quête garde la carte qu'il joue : une correction s'applique à sa quête suivante.</li>
                    </ul>
                </section>

                <section id="campagne">
                    <h2>1. La campagne</h2>
                    <p>Dans la colonne de gauche : choisir une campagne, ou <b>Nouvelle campagne</b>. L'onglet <b>Campagne</b> porte :</p>
                    <ul>
                        <li><b>Titre</b>, <b>slug</b> (l'identifiant, en minuscules et tirets), <b>auteur</b> et <b>mention</b> (crédit, droits) ;</li>
                        <li><b>Classes exigées</b> : les héros sans lesquels la campagne ne peut pas partir ;</li>
                        <li><b>Mercenaires</b> : ceux qu'on peut recruter entre les quêtes, et leur régime.</li>
                        <li><b>Cartes de trésor ajoutées au paquet</b> : celles de Jungles of Delthrak et de Wizards of Morcar ;</li>
                    </ul>
                    <figure class="gc-fig">
                        <a :href="IMG('campagne')" target="_blank" rel="noopener"><img :src="IMG('campagne')" alt="L'onglet Campagne : la liste à gauche, l'état de validation et « Enregistrer » en haut à droite." loading="lazy" /></a>
                        <figcaption>L'onglet Campagne : la liste à gauche, l'état de validation et « Enregistrer » en haut à droite.</figcaption>
                    </figure>
                </section>

                <section id="plateau">
                    <h2>2. Le plateau</h2>
                    <p>L'onglet <b>Plateau</b> fixe la grille (26 × 19 pour le plateau officiel) et ses salles. <b>Glisser</b> sur la grille trace une salle ; cliquer une salle la sélectionne ; la liste de droite règle son nom, sa position et sa taille. Tout ce qui n'est pas une salle est du couloir.</p>
                    <p>Le plateau est commun à toutes les quêtes de la campagne : chaque quête choisit ensuite quelles salles sont <b>ouvertes</b>.</p>
                    <figure class="gc-fig">
                        <a :href="IMG('plateau')" target="_blank" rel="noopener"><img :src="IMG('plateau')" alt="L'onglet Plateau : les 22 salles du plateau officiel, et leurs coordonnées à droite." loading="lazy" /></a>
                        <figcaption>L'onglet Plateau : les 22 salles du plateau officiel, et leurs coordonnées à droite.</figcaption>
                    </figure>
                    <p class="gc-attention"><MSym n="info" :size="16" /><span>Une salle en L (le « se-1 » du plateau officiel) a des <b>parties</b> : l'écran les dessine et les conserve, mais ne sait pas les tracer. Elles se saisissent dans le fichier de la campagne ou par le serveur MCP.</span></p>
                </section>

                <section id="quetes">
                    <h2>3. Les quêtes</h2>
                    <p>L'onglet <b>Quêtes</b> liste les quêtes dans l'ordre où elles se jouent (flèches pour réordonner, <b>+ Quête</b> pour en ajouter). Au-dessus de la carte, la barre d'outils : choisir un outil, puis cliquer la carte.</p>
                    <figure class="gc-fig">
                        <a :href="IMG('quete-carte')" target="_blank" rel="noopener"><img :src="IMG('quete-carte')" alt="Une quête : la liste des quêtes, la barre d'outils et la carte (salles ouvertes en gris, portes en jaune, escalier d'entrée en vert, monstres en rouge, meubles en brun)." loading="lazy" /></a>
                        <figcaption>Une quête : la liste des quêtes, la barre d'outils et la carte (salles ouvertes en gris, portes en jaune, escalier d'entrée en vert, monstres en rouge, meubles en brun).</figcaption>
                    </figure>
                    <table class="gc-table">
                        <tbody>
                            <tr v-for="[ic, nom, aide] in OUTILS" :key="nom">
                                <td class="gc-ic"><MSym :n="ic" :size="18" /></td>
                                <td><b>{{ nom }}</b></td>
                                <td>{{ aide }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <figure class="gc-fig">
                        <a :href="IMG('outil-sortie')" target="_blank" rel="noopener"><img :src="IMG('outil-sortie')" alt="L'outil Sortie, en mode porte : la porte de sortie peut recevoir un verrou, comme une porte barrée." loading="lazy" /></a>
                        <figcaption>L'outil Sortie, en mode porte : la porte de sortie peut recevoir un verrou, comme une porte barrée.</figcaption>
                    </figure>
                    <p>Sous la carte, la fiche de la quête :</p>
                    <ul>
                        <li><b>Titre</b>, <b>slug</b> et <b>texte d'ouverture</b> (lu à la table au départ) ;</li>
                        <li><b>Monstre errant</b> (avec son nombre de figures — « 2 Squelettes » — et un profil d'auteur par le bouton <MSym n="tune" :size="14" />), <b>quête solo</b>, <b>marché fermé</b> avant la quête, <b>salles qui ne se fouillent pas</b>, <b>équipement confisqué</b> ;</li>
                        <li><b>Récompense</b> : de l'or, partagé au groupe ou par héros, des objets (au premier héros ou à chacun), et au besoin une condition (« si le drapeau ») ;</li>
                        <li><b>Fin</b> : vaincre des monstres désignés, poser un drapeau (par une note), sortir du donjon, ou ramener une charge ou un captif — avec la <b>consigne</b> dite aux joueurs ;</li>
                        <li><b>Coffres piégés</b> : un par salle — la fouille des pièges le trouve, le Nain (ou une trousse à outils) le désamorce ; fouiller le trésor avant le déclenche (dégâts du piège ou dégâts fixes, au fouilleur ou à toute la salle) ;</li>
                        <li><b>Charge à porter</b> : un objet encombrant, son porteur lance un dé de déplacement de moins ;</li>
                        <li><b>Captif à escorter</b> (sa mort peut faire perdre la quête ; il peut rester allié pour la suite ; une note peut le libérer) : une figure qui attend dans sa cellule. Un héros au contact la libère et elle le suit ; ramenée vivante sur la sortie avec le dernier héros, la récompense est versée — sinon la quête est gagnée sans récompense ;</li>
                        <li><b>Sans restauration</b> : la quête enchaîne sans remettre le Corps ni l'Esprit à neuf ;</li>
                        <li><b>Brouillard vivant</b> (The Frozen Horror) : dans ces salles, chaque attaque d'un héros commence par un dé — seul le bouclier blanc frappe le vrai monstre ;</li>
                        <li><b>Salles qui se lèvent au tour du MJ</b> (Against the Ogre Horde q. 10) : un dé rouge à chaque tour du MJ — 1 à 5 une salle, 6 deux salles — et une salle cachée, tirée parmi les trois dont le chemin depuis les héros est le plus court, se découvre, porte ouverte, ses monstres jouant aussitôt (seulement à 12 pas de chemin au plus d'un héros ; s'il en reste moins de trois, le tirage se fait parmi celles qui restent) ; aucune salle à portée, rien ne se lève ce tour ; une carte de monstre errant en lève une, ou reste un monstre errant ordinaire sans salle à portée ;</li>
                        <li><b>Chemin imposé au porteur</b> (Prophecy of Telor q. 6) : le héros qui porte l'objet choisi joue en premier ; au début de ses premiers tours, il est mené le long du chemin (les cases dans l'ordre) jusqu'à l'arrêt du tour — son déplacement est pris, il garde son action, une porte close sur le chemin s'ouvre. L'effet de note « avancer sur le chemin » le fait avancer du total des dés ;</li>
                        <li><b>Réceptacle</b> (Against the Ogre Horde, la Fosse de l'Effroi) : les cases où un héros jette l'objet qu'une note « objet jeté » attend (l'Anneau de Festral) — la note pose le drapeau ou ouvre la sortie ;</li>
                        <li><b>Marché en quête</b> (Rise of the Dread Moon, la boutique de Zerese) : dans cette salle, chaque héros ouvre l'étal du marchand, achète et vend pour lui, de sa bourse, sans action ;</li>
                        <li><b>Planque</b> (Rise of the Dread Moon) : une salle qui ne se fouille qu'une fois par quête, où les cartes de monstre errant et de danger ne jouent pas ; chaque héros peut y lancer une fois un dé rouge de soin, qu'il répartit entre son Corps et son Esprit ;</li>
                        <li><b>Dés d'attaque en plus</b> (Rise of the Dread Moon : 1) : tous les monstres de la quête lancent ces dés de plus, au contact et à distance — annoncé au départ, au bandeau ;</li>
                        <li><b>Or d'un coffre sans note</b> (The Mage of the Mirror : 200) : la première fouille du trésor d'une salle à coffre qu'aucune note ne nomme rapporte cet or, à la place de la carte ;</li>
                        <li><b>Rebord de crevasse</b> (The Frozen Horror) : un dé à la première case de la salle et au début de chaque tour qu'on y passe — deux boucliers noirs, et le héros est perdu à jamais ;</li>
                        <li><b>Suite</b> (campagne ramifiée) : selon le drapeau posé, la quête suivante change ;</li>
                        <li><b>Quête double</b> : deux moitiés reliées par un passage ;</li>
                        <li><b>Tournoi de World's End</b> (Against the Ogre Horde, quêtes 1 à 3) : la tuile des héros, celle du Defender (les monstres posés, qui jouent d'abord), les tuiles trophées et les vagues suivantes — les activations alternent, un héros tombé est hors jeu jusqu'à la fin de la quête (jamais tué), un coffre tire la table 2d6 du tournoi, et le Defender vaincu fait gagner la quête.</li>
                    </ul>
                    <figure class="gc-fig">
                        <a :href="IMG('fiche-quete')" target="_blank" rel="noopener"><img :src="IMG('fiche-quete')" alt="Le haut de la fiche : titre, texte d'ouverture, monstre errant, salles qui ne se fouillent pas, récompense." loading="lazy" /></a>
                        <figcaption>Le haut de la fiche : titre, texte d'ouverture, monstre errant, salles qui ne se fouillent pas, récompense.</figcaption>
                    </figure>
                    <figure class="gc-fig">
                        <a :href="IMG('fin-captif')" target="_blank" rel="noopener"><img :src="IMG('fin-captif')" alt="La fin de la quête : ici, ramener un captif — Sir Ragnar, posé dans sa cellule." loading="lazy" /></a>
                        <figcaption>La fin de la quête : ici, ramener un captif — Sir Ragnar, posé dans sa cellule.</figcaption>
                    </figure>
                </section>

                <section id="notes">
                    <h2>4. Les notes « quand → alors »</h2>
                    <p>Les lettres des livrets (« A : quand un héros entre ici, lisez… ») deviennent des <b>notes</b>. Chaque note a un repère, un <b>déclencheur</b> (entrer dans une salle ou sur une case, fouiller le trésor, ouvrir une porte, abattre un monstre ou toutes les figures désignées, vider une salle, y entrer tous, la quitter, y finir son tour, déclencher un piège, détruire un meuble, libérer un captif…) et une liste d'<b>effets</b> (lire un texte, donner de l'or ou un objet — ou un objet tiré au hasard —, lancer des dés dont l'issue choisit la suite, faire apparaître des monstres, donner de l'or (au héros qui déclenche, partagé entre les héros vivants, ou autant à chacun), ouvrir une porte, éveiller des figures, téléporter, poser un drapeau, arrêter la marche du héros, le voler, faire surgir les monstres errants de la quête…). Le piège à monstre errant d'un livret s'écrit ainsi : entrer sur la case → arrêter + monstres errants.</p>
                    <p><b>Le talisman de Prophecy of Telor</b> s'écrit avec cinq effets : <b>apprendre un sort de Dread</b> au héros (il le garde d'une quête à l'autre, et le lance une fois par quête), <b>gagner ou perdre des points d'Esprit</b> (le total du héros bouge, au-delà de son maximum de départ, jusqu'à la fin de la campagne), <b>changer un objet en un autre</b> chez qui le possède (le talisman ordinaire devient maudit à la quête 7 : fardeau et sorts du porteur), puis, dans l'arène de la quête 10, <b>faire oublier les sorts appris</b> et <b>ramener l'Esprit au total d'avant la campagne</b>. Les notes réservées au porteur portent la condition « le héros porte le Talisman du Savoir ». La quête 5 <b>change les héros en orques</b> (effet « changer les héros en monstres » : la figure, son plafond de Corps, « sauf le héros qui déclenche » pour épargner le porteur — ils se battent avec les dés de l'orque, sans sorts) puis leur <b>rend leur forme</b> avec tout leur Corps. L'<b>arène</b> de la quête 10 est une salle spéciale cochée « sans magie » (ni sort, ni artefact, ni équipement : à mains nues ; potions permises ; la magie qui affectait un héros se dissipe en entrant) ; ses portes au verrou « note » s'ouvrent par une note au déclencheur « chaque héros à une porte différente », qui ouvre les portes puis les fait <b>franchir</b> d'un pas. La <b>Flamme de Zargon</b> (quête 13) est une capacité du profil d'un monstre : touchée, la figure joue au dé contre deux chiffres — épargnée ou consumée, quels que soient ses PV —, et l'Eau de Guérison peut la viser (un seul chiffre pour la sauver). La <b>quête à étages</b> (quête 3, le Grand Escalier) : le premier étage est la quête elle-même ; on donne les cases de l'escalier, puis chaque étage suivant avec ses monstres, ses meubles, ses pièges et ses notes (en JSON, de la même forme que ceux de la quête). L'étage vidé, quand tous les héros sont remontés sur l'escalier, le suivant se pose et la table l'annonce (« Étage 4 sur 12 »).</p>
                    <ul>
                        <li><b>si le drapeau / sauf si le drapeau</b> : la note ne joue que si un drapeau est (ou n'est pas) posé ;</li>
                        <li><b>une seule fois</b> : décoché, la note rejoue à chaque déclenchement ;</li>
                        <li>le bouton <MSym n="pin_drop" :size="14" /> à côté d'une case la fait choisir sur la carte.</li>
                    </ul>
                    <p>Une note qui vise la <b>porte de sortie verrouillée</b> (« la sortie s'ouvre quand la gargouille meurt ») choisit « la porte de sortie » dans l'effet <i>ouvrir une porte</i>. L'effet <i>éveiller</i> coche les monstres posés « inactif ».</p>
                    <figure class="gc-fig">
                        <a :href="IMG('notes')" target="_blank" rel="noopener"><img :src="IMG('notes')" alt="Trois notes : A éveille les squelettes à l'ouverture d'une porte, B ouvre la sortie verrouillée à la mort du Gardien, C donne de l'or à la fouille du coffre." loading="lazy" /></a>
                        <figcaption>Trois notes : A éveille les squelettes à l'ouverture d'une porte, B ouvre la sortie verrouillée à la mort du Gardien, C donne de l'or à la fouille du coffre.</figcaption>
                    </figure>
                </section>

                <section id="livrets">
                    <h2>5. Les règles des livrets</h2>
                    <p>Le bloc dépliable <b>Règles des livrets</b> rassemble ce que les livrets officiels demandent en plus :</p>
                    <ul>
                        <li><b>Profil d'auteur d'un monstre</b> (cliquer son numéro) : nom propre, caractéristiques, « seuls ces armes ou ces sorts le blessent », arme qui se brise, fuite vers un refuge, <b>sorts de Dread</b> (ils remplacent ceux de la fiche, chacun une fois par quête), <b>capacités</b> (frappe en diagonale, deux sorts par tour ou au premier tour, ignore les héros déguisés, retirée au contact d'un déguisé), <b>se rend</b> quand on l'attaque au contact, et <b>inactif</b> — la figure ne bouge pas, n'attaque pas et ne peut pas être blessée tant qu'une note ne l'a pas éveillée ;</li>
                        <li><b>Salles spéciales</b>, <b>glyphes</b>, <b>obscurité magique</b>, <b>déguisement</b>, <b>ordre libre</b>, <b>dégâts en Esprit</b>, <b>primes</b> par monstre abattu, monstres <b>pacifiés</b> par un drapeau ;</li>
                        <li><b>Portes à téléportation</b> : tenter d'ouvrir une porte téléporte le héros sur une case tirée aux dés ; la porte reste fermée ;</li>
                        <li><b>Dangers</b> de couloir : rocher roulant, brume de la mort.</li>
                    </ul>
                    <figure class="gc-fig">
                        <a :href="IMG('regles-livrets')" target="_blank" rel="noopener"><img :src="IMG('regles-livrets')" alt="Les règles des livrets : le Gardien, que seuls Boule de Feu et Trait de Feu blessent, et un squelette inactif." loading="lazy" /></a>
                        <figcaption>Les règles des livrets : le Gardien, que seuls Boule de Feu et Trait de Feu blessent, et un squelette inactif.</figcaption>
                    </figure>
                </section>

                <section id="enregistrer">
                    <h2>6. Valider, enregistrer, jouer</h2>
                    <ul>
                        <li>L'état en haut à droite doit dire <b>Valide</b>. Sinon, la liste des refus nomme chaque problème et son emplacement.</li>
                        <li><b>Enregistrer</b> écrit la campagne. <b>Exporter</b> télécharge son fichier (gardez-en une copie).</li>
                        <li>Pour la jouer : page <b>Joueur</b>, ouvrir un groupe en mode <b>classique</b> et choisir la campagne. Seules les campagnes valides sont proposées, et les classes exigées doivent être présentes.</li>
                    </ul>
                    <figure class="gc-fig">
                        <a :href="IMG('etat-refus')" target="_blank" rel="noopener"><img :src="IMG('etat-refus')" alt="Un monstre posé dans une salle fermée : l'état passe à « 1 refus »…" loading="lazy" /></a>
                        <figcaption>Un monstre posé dans une salle fermée : l'état passe à « 1 refus »…</figcaption>
                    </figure>
                    <figure class="gc-fig">
                        <a :href="IMG('refus')" target="_blank" rel="noopener"><img :src="IMG('refus')" alt="… et sous la carte, le refus dit lequel (monstres.8) et pourquoi." loading="lazy" /></a>
                        <figcaption>… et sous la carte, le refus dit lequel (monstres.8) et pourquoi.</figcaption>
                    </figure>
                </section>

                <section id="mcp">
                    <h2>7. Le serveur MCP (une IA au clavier)</h2>
                    <p>Le constructeur a un second client : une IA (Claude Code, par exemple) peut lire et écrire les campagnes par le protocole <b>MCP</b>. Ses outils appellent la même validation et le même enregistrement que l'écran : l'IA ne peut rien écrire que l'écran refuserait.</p>
                    <h3>Le brancher</h3>
                    <p>Sur la machine qui fait tourner le jeu (la stack Docker démarrée), dans un terminal :</p>
                    <pre class="gc-code">{{ COMMANDE_MCP }}</pre>
                    <p>Remplacer <code>/chemin/vers/heroquestrpg</code> par le dossier du projet, puis relancer Claude Code. Le serveur passe par l'entrée et la sortie standard : ni port, ni réseau, ni mot de passe. L'option <code>display_errors=stderr</code> est nécessaire, sans elle un avertissement PHP casse le protocole.</p>
                    <h3>Ses outils</h3>
                    <table class="gc-table">
                        <tbody>
                            <tr v-for="[nom, aide] in OUTILS_MCP" :key="nom">
                                <td><code>{{ nom }}</code></td>
                                <td>{{ aide }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <h3>Bien s'en servir</h3>
                    <ul>
                        <li>Ordre conseillé : <code>catalogues</code> → <code>campagne_lire</code> → <code>quete_lire</code> → modifier → <code>valider</code> ou <code>apercu</code>.</li>
                        <li>Tout slug vient de <code>catalogues</code> : un slug inventé est refusé.</li>
                        <li>Un refus de validation est une réponse normale : chaque refus a un <code>chemin</code> à corriger.</li>
                        <li>Il n'y a <b>pas d'outil de suppression</b>, mais <code>campagne_ecrire</code> remplace une campagne du même slug : demandez à l'IA d'<code>exporter</code> d'abord.</li>
                        <li>Après une mise à jour du code du jeu, relancer la session MCP : elle garde l'ancien code tant qu'elle tourne.</li>
                    </ul>
                </section>

                <section id="limites">
                    <h2>Ce que l'écran ne fait pas</h2>
                    <ul>
                        <li>Tracer les <b>parties</b> d'une salle en L (fichier ou MCP).</li>
                        <li><b>Importer</b> un fichier de campagne (outil <code>importer</code> du MCP).</li>
                        <li>Supprimer une campagne.</li>
                    </ul>
                </section>
            </main>
        </div>
    </div>
</template>

<style scoped>
.gc-screen { min-height: 100vh; background: var(--stone-950); color: var(--ink-100); padding: 22px clamp(14px, 4vw, 40px) 60px; }
.gc-top { max-width: 1100px; margin: 0 auto 22px; display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px 24px; }
.gc-back { flex-basis: 100%; display: inline-flex; align-items: center; gap: 5px; color: var(--ink-400); text-decoration: none; font-size: 13px; font-weight: 700; padding: 6px 0; }
.gc-back:hover { color: var(--torch); }
.gc-titrewrap { display: flex; align-items: center; gap: 16px; margin-right: auto; }
.gc-crest { width: 60px; height: 60px; border-radius: 16px; display: grid; place-items: center; flex: none;
  background: linear-gradient(150deg, var(--ember), var(--ember-deep)); color: var(--parch-100); box-shadow: var(--sh-2); }
.gc-crest .msym { font-size: 34px; }
.gc-titre { font-family: var(--font-display); font-size: clamp(26px, 4vw, 38px); font-weight: 800; margin: 0; letter-spacing: 0.02em; color: var(--parch-100); }
.gc-sub { font-family: var(--font-narr); font-style: italic; color: var(--ink-300); font-size: 15px; margin: 2px 0 0; }
.gc-ouvrir { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: var(--r-md); background: var(--torch);
  color: var(--stone-950); font-weight: 800; font-size: 13px; text-decoration: none; }

.gc-corps { max-width: 1100px; margin: 0 auto; display: grid; grid-template-columns: 220px minmax(0, 1fr); gap: 32px; align-items: start; }
.gc-sommaire { position: sticky; top: 16px; display: flex; flex-direction: column; gap: 2px; padding: 12px; border: var(--line); border-radius: var(--r-md); background: var(--stone-900); }
.gc-sommaire a { color: var(--ink-300); text-decoration: none; font-size: 13px; padding: 5px 8px; border-radius: 6px; }
.gc-sommaire a:hover { color: var(--parch-100); background: var(--stone-850); }

.gc-texte { min-width: 0; line-height: 1.6; font-size: 15px; }
.gc-texte section { padding-bottom: 18px; margin-bottom: 18px; border-bottom: var(--line); scroll-margin-top: 16px; }
.gc-texte section:last-child { border-bottom: none; }
.gc-texte h2 { font-family: var(--font-display); color: var(--parch-100); font-size: 22px; margin: 0 0 8px; letter-spacing: 0.02em; }
.gc-texte h3 { color: var(--torch); font-size: 15px; margin: 16px 0 4px; }
.gc-texte p { margin: 0 0 10px; color: var(--ink-200, #e7dcc6); }
.gc-texte ul { margin: 0 0 10px; padding-left: 20px; color: var(--ink-200, #e7dcc6); }
.gc-texte li { margin-bottom: 4px; }
.gc-texte b { color: var(--parch-100); }
.gc-texte code { font-size: 13px; padding: 1px 5px; border-radius: 4px; background: var(--stone-850); color: var(--parch-100); }
.gc-code { white-space: pre-wrap; word-break: break-all; font-size: 13px; padding: 12px; border-radius: var(--r-md); background: var(--stone-900); border: var(--line); color: var(--parch-100); }
.gc-attention { display: flex; gap: 8px; align-items: flex-start; padding: 10px 12px; border-radius: var(--r-md); background: var(--stone-900); border-left: 3px solid var(--torch); }
.gc-attention .msym { color: var(--torch); flex: none; margin-top: 3px; }

.gc-fig { margin: 8px 0 16px; }
.gc-fig a { display: block; }
.gc-fig img { display: block; max-width: 100%; height: auto; border-radius: var(--r-md); border: var(--line); background: var(--stone-900); }
.gc-fig figcaption { margin-top: 6px; font-size: 13px; font-style: italic; color: var(--ink-400); }
.gc-table { width: 100%; border-collapse: collapse; margin: 6px 0 12px; font-size: 14px; }
.gc-table td { padding: 7px 8px; border-bottom: var(--line); vertical-align: top; color: var(--ink-200, #e7dcc6); }
.gc-table td:first-child { white-space: nowrap; }
.gc-ic { width: 28px; color: var(--torch); }

@media (max-width: 760px) {
  .gc-corps { grid-template-columns: 1fr; gap: 16px; }
  .gc-sommaire { position: static; }
}
</style>
