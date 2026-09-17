<script setup>
// Feuille de DÉPLACEMENT (manette) : montre l'allonce du tour (dé déjà lancé
// côté serveur : base + 1d6) et une mini-carte tappable. Le TERRAIN (cases /
// portes / pièges) est rendu par le socle PARTAGÉ DungeonGrid — le MÊME que
// l'écran table — pour un rendu identique ; cette feuille n'ajoute que la
// surbrillance des cases accessibles (BFS) et le tap de destination.
import { computed, nextTick, onMounted, ref } from 'vue';
import { useApi } from '../../composables/useApi';
import DungeonGrid from '../carte/DungeonGrid.vue';
import LegendeCarte from '../carte/LegendeCarte.vue';
import MSym from '../ui/MSym.vue';

const props = defineProps({
    carte: { type: Object, required: true },   // { largeur, hauteur, cases, portes }
    entites: { type: Array, default: () => [] }, // [{type, id, x, y, ...}]
    depart: { type: Object, required: true },    // { x, y } du héros
    portee: { type: Number, required: true },
    de: { type: [Number, null], default: null },
    base: { type: Number, default: 0 },
    // MOBILITÉ DE COMBAT (Rogue) / Voile de Brume : publié par `EtatGroupe`
    // (`entites[].franchit_figures`, calculé par
    // `MoteurSorts::mobiliteCombatDisponible()`) — la DÉCISION serveur, pas
    // un talent/buff que ce composant pourrait deviner lui-même. René,
    // 2026-09-11 : « la mobilité de combat du Rogue ne permet pas de se
    // déplacer à travers les ennemis » — le moteur l'autorisait déjà,
    // c'était CE miroir qui traitait tout monstre comme un mur pour tout le
    // monde.
    franchitFigures: { type: Boolean, default: false },
    /** Code du groupe — sert UNIQUEMENT à demander l'aperçu de trajet au
     *  serveur (`POST deplacement/apercu`). */
    groupe: { type: String, default: '' },
});
const emit = defineEmits(['deplacer', 'close']);

const grilleRef = ref(null);
const cle = (x, y) => `${x},${y}`;

// Portes = CLOISONS (arêtes) : indexées par arête canonique pour bloquer le pas
// à travers une porte FERMÉE (le rendu du battant est géré par DungeonGrid).
const cleArete = (x1, y1, x2, y2) => {
    const a = cle(x1, y1); const b = cle(x2, y2);
    return a <= b ? `${a}|${b}` : `${b}|${a}`;
};
const casesPorte = (p) => (p.cote === 's'
    ? [{ x: p.x, y: p.y }, { x: p.x, y: p.y + 1 }]
    : [{ x: p.x, y: p.y }, { x: p.x + 1, y: p.y }]);
const portesParArete = computed(() => {
    const m = new Map();
    for (const p of props.carte.portes ?? []) {
        const [a, b] = casesPorte(p);
        m.set(cleArete(a.x, a.y, b.x, b.y), p);
    }
    return m;
});
const porteFermeeEntre = (x1, y1, x2, y2) => {
    const p = portesParArete.value.get(cleArete(x1, y1, x2, y2));
    return !!p && p.etat !== 'ouverte'; // fermee / verrouillee / secrete
};

// Case d'EMBRASURE d'une porte NON ouverte (René, 2026-09-11 : « la porte
// doit être centrale à sa case, bloquant l'entrée dans sa case tant qu'elle
// n'est pas ouverte ») — MIROIR de `Grille::estTraversable()`/`ligneDeVue()`
// côté serveur, en PLUS de l'arête ci-dessus (`porteFermeeEntre`), pas à sa
// place : avant ce miroir, le BFS client ne gardait que le pas venu du
// couloir bloqué, jamais celui venu de l'INTÉRIEUR de la salle — il aurait
// donc continué à surbrillancer une case que le serveur refuse désormais des
// deux côtés, un refus sans explication que « le menu ne propose jamais ce
// que le résolveur refusera » interdit. `p.embrasure` est publiée toute
// faite par `EtatGroupe::portes()` (`Grille::caseEmbrasure()`) : la dériver
// une seconde fois ici serait une deuxième copie de cette règle géométrique.
const embrasuresFermees = computed(() => {
    const s = new Set();
    for (const p of props.carte.portes ?? []) {
        if (p.etat !== 'ouverte' && p.embrasure) s.add(cle(p.embrasure.x, p.embrasure.y));
    }
    return s;
});

// Cases occupées par une AUTRE figurine BLOQUANTE — MÊME règle que le moteur
// (FabriqueGrille) pour ne jamais bloquer une case que le serveur laisse libre :
//  - le héros sur sa propre case de départ ne se bloque pas ;
//  - un héros TOMBÉ s'enjambe (ne bloque pas) ;
//  - un monstre non-actif (vaincu) a déjà quitté le plateau — filtre défensif.
// ⚠ DEUX ensembles, et il en faut deux — c'est la règle du plateau : « on peut
// traverser la case d'un autre héros (pas s'y arrêter), on ne peut jamais
// partager une case » (LR p. 12, doc 16 §5). Le serveur la tient depuis le
// 2026-09-04 avec son quatrième jeu de cases (`Grille::$alliees`, opt-in
// `franchitAllies`) ; ce miroir, lui, fondait tout dans `occupees` et
// traitait un compagnon comme un mur. Résultat signalé par René en jouant :
// deux héros dans un couloir se bloquaient encore À L'ÉCRAN alors que le
// moteur, lui, les laissait passer depuis une semaine.
//
// ⚠ C'est la TROISIÈME fois qu'un miroir client dérive d'une règle serveur
// (après le coût de déplacement pondéré et le calcul des cases atteignables).
// Tout miroir est une seconde copie de la règle : il ne dérive pas le jour où
// on l'écrit, il dérive le jour où la règle bouge sans lui.
//
// ⚠ QUATRIÈME dérive (René, 2026-09-11 : « la mobilité de combat du Rogue ne
// permet pas de se déplacer à travers les ennemis »). Un monstre était
// bloquant SANS CONDITION, alors que le résolveur lève cette barrière pour un
// héros qui porte le talent `franchit_figures` (Rogue) ou le buff Voile de
// Brume (`ResolveurTour::resoudreDeplacer()`,
// `MoteurSorts::mobiliteCombatDisponible()`). Le talent existait côté moteur
// et restait injouable côté écran : le joueur ne pouvait même pas TAPER la
// case au-delà d'un monstre. `props.franchitFigures` porte la DÉCISION
// publiée par `EtatGroupe` — ce composant ne peut pas deviner tout seul si CE
// héros porte le talent ou le buff.
const BLOQUANTE = (e) => e.type === 'monstre' && ! props.franchitFigures;

/** Cases où l'on ne peut ni passer ni s'arrêter : les MONSTRES — sauf pour un
 *  héros qui les franchit ce tour-ci (`franchitFigures`), auquel cas ils
 *  rejoignent `alliees` ci-dessous : traversables, jamais une destination. */
const occupees = computed(() => {
    const s = new Set();
    for (const e of props.entites) {
        if (e.x === props.depart.x && e.y === props.depart.y) continue;
        if (! BLOQUANTE(e)) continue;
        if ((e.etat ?? 'actif') !== 'actif' || (e.pv_body ?? 1) <= 0) continue;
        s.add(cle(e.x, e.y));
    }
    return s;
});

/** Cases TRAVERSABLES mais où l'on ne peut pas S'ARRÊTER : héros, alliés,
 *  mercenaires — tout ce qui n'est pas un monstre BLOQUANT (voir `BLOQUANTE`
 *  ci-dessus : un monstre y tombe aussi quand `franchitFigures` est vrai).
 *  Un héros à terre ne compte pas : il n'occupe plus sa case comme obstacle. */
const alliees = computed(() => {
    const s = new Set();
    for (const e of props.entites) {
        if (e.x === props.depart.x && e.y === props.depart.y) continue;
        if (BLOQUANTE(e)) continue;
        if (e.type === 'heros' && e.tombe) continue;
        if (e.type === 'monstre' && ((e.etat ?? 'actif') !== 'actif' || (e.pv_body ?? 1) <= 0)) continue;
        s.add(cle(e.x, e.y));
    }
    return s;
});

// Mobilier bloquant le MOUVEMENT (doc 17) : même occupation que côté serveur
// (FabriqueGrille::pour(), seule source de vérité — ceci n'en est qu'un
// MIROIR côté client, le serveur revalide toujours le déplacement choisi).
// Cases distinctes de `occupees` (pas une figurine) : DungeonGrid dessine déjà
// le meuble lui-même, cette liste ne sert qu'à couper le BFS d'accessibilité.
// `bloque_vue` (une bibliothèque coupe la vue mais une table non) n'entre PAS
// dans ce calcul : la ligne de vue n'est pas ce que le BFS de déplacement mesure.
// ⚠ TROIS sources pour UN seul jeu de cases, exactement comme `$obstacles`
// côté serveur (`FabriqueGrille::pour()`) : le mobilier bloquant, le terrain
// bloquant, et les MURS DE GLACE posés en cours de quête par le sort du boss
// (`carte.glace`, doc 18 §4). Le mur de glace manquait ici — et n'était dessiné
// nulle part — alors qu'il barre bel et bien la case côté moteur : la manette
// proposait une destination derrière un mur invisible, que le serveur refusait
// ensuite (René, 2026-09-17). Le terrain bloquant est ajouté par prévention :
// aucun terrain du catalogue ne bloque à ce jour, mais le drapeau est publié et
// le moteur le lit — le miroir ne doit pas attendre le premier qui bloquera.
const mobilierOccupe = computed(() => {
    const s = new Set();
    for (const m of props.carte.mobilier ?? []) {
        if (m.bloque_mouvement === false) continue;
        for (let dy = 0; dy < Math.max(1, m.h ?? 1); dy++) {
            for (let dx = 0; dx < Math.max(1, m.l ?? 1); dx++) {
                s.add(cle(m.x + dx, m.y + dy));
            }
        }
    }
    for (const t of props.carte.terrain ?? []) {
        if (t.bloque_mouvement) s.add(cle(t.x, t.y));
    }
    for (const g of props.carte.glace ?? []) {
        s.add(cle(g.x, g.y));
    }
    return s;
});

// Coût de déplacement du TERRAIN (doc 18 §4 — Rivière gelée : 2 points pour
// ENTRER dans la case, au lieu de 1) — MIROIR de `Terrain::cout_deplacement`,
// déjà publié par `EtatGroupe::terrain()` mais jusqu'ici jamais lu ici : le
// BFS d'accessibilité comptait chaque case pour 1, quel que soit son coût
// réel, et pouvait donc surbrillancer une case que le serveur refusait
// ensuite — exactement l'« effet que rien n'annonce » que CLAUDE.md proscrit.
const coutParCase = computed(() => {
    const m = {};
    for (const t of props.carte.terrain ?? []) {
        m[cle(t.x, t.y)] = Math.max(1, t.cout_deplacement ?? 1);
    }
    return m;
});
const coutDe = (x, y) => coutParCase.value[cle(x, y)] ?? 1;

// Cases accessibles dans le budget de points `portee` — parcours PONDÉRÉ
// (Dijkstra), MIROIR de `Grille::casesAtteignables()` (doc 18 §4, plan glace
// §2) : chaque pas coûte `coutDe()` de la case d'ARRIVÉE, pas 1 uniformément.
// ⚠ Sert UNIQUEMENT à choisir une DESTINATION (surbrillance + tap) — le
// serveur revalide de toute façon chaque déplacement, coût compris.
const accessibles = computed(() => {
    const { largeur: w, hauteur: h, cases } = props.carte;
    const dist = { [cle(props.depart.x, props.depart.y)]: 0 };
    const out = new Set();
    // File de priorité par scan linéaire : la zone qu'un déplacement de héros
    // peut explorer tient en quelques dizaines de cases (bornée par `portee`
    // ET par le brouillard) — pas besoin d'un tas pour rester instantané.
    let frontiere = [{ x: props.depart.x, y: props.depart.y, d: 0 }];
    while (frontiere.length) {
        let iMin = 0;
        for (let i = 1; i < frontiere.length; i++) {
            if (frontiere[i].d < frontiere[iMin].d) iMin = i;
        }
        const { x, y, d } = frontiere.splice(iMin, 1)[0];
        if (d > (dist[cle(x, y)] ?? Infinity)) continue; // entrée dépassée (suppression paresseuse)
        for (const [dx, dy] of [[0, 1], [0, -1], [1, 0], [-1, 0]]) {
            const nx = x + dx; const ny = y + dy;
            if (nx < 0 || ny < 0 || nx >= w || ny >= h) continue;
            const k = cle(nx, ny);
            if (porteFermeeEntre(x, y, nx, ny)) continue;  // on ne traverse pas une porte fermée
            const porteOuverteIci = portesParArete.value.get(cleArete(x, y, nx, ny))?.etat === 'ouverte';
            const caseConnue = cases?.[ny]?.[nx] === 's';
            // Sol déjà connu, OU porte OUVERTE sur l'arête franchie : le brouillard
            // masque l'intérieur d'une salle tant qu'on n'y est pas entré, mais une
            // porte ouverte GARANTIT du sol juste derrière (une porte ne sépare
            // jamais que deux cases de sol) — on peut donc continuer son
            // mouvement à travers une porte qu'on vient d'ouvrir, comme le
            // permet le moteur serveur (docs/contrat-api.md : « on l'ouvre et on
            // poursuit son mouvement s'il reste des points »).
            // Filet de sécurité (§2.16) : une case VOISINE IMMÉDIATE du héros
            // reste proposée même si la carte connue est incomplète. Sans lui,
            // une carte partielle rendait `accessibles` VIDE et le héros ne
            // pouvait plus bouger du tout — constaté en partie réelle, tout le
            // groupe figé sur place avec un message parlant d'un blocage
            // tactique. La cause serveur est corrigée par ailleurs, mais le
            // client ne doit pas être un point de défaillance unique : le
            // moteur revalide de toute façon chaque déplacement.
            const voisinImmediat = d === 0 && (cases?.[ny]?.[nx] ?? 'b') !== 'm';
            if (!caseConnue && !porteOuverteIci && !voisinImmediat) continue;
            if (occupees.value.has(k) || mobilierOccupe.value.has(k)) continue;
            if (embrasuresFermees.value.has(k)) continue; // case d'embrasure close : inoccupable

            const nd = d + coutDe(nx, ny);
            if (nd > props.portee) continue; // hors budget : jamais une destination possible
            if (nd < (dist[k] ?? Infinity)) {
                dist[k] = nd;
                // ⚠ Une case d'ALLIÉ se TRAVERSE mais n'est jamais une
                // DESTINATION (LR p. 12 : « pas s'y arrêter », et « on ne peut
                // jamais partager une case »). On l'ajoute donc à la frontière
                // — sinon tout ce qui est derrière un compagnon reste
                // inatteignable à l'écran — mais PAS à `out`, sinon le joueur
                // taperait une case que le serveur refusera.
                if (! alliees.value.has(k)) out.add(k);
                // Ne PAS étendre au-delà d'une case encore dans le brouillard :
                // on ignore ce qu'il y a plus loin tant que le serveur n'a pas
                // révélé la salle (prochain état, après ce déplacement).
                if (caseConnue) frontiere.push({ x: nx, y: ny, d: nd });
            }
        }
    }
    return out;
});

/** Rendu d'une figure présente sur une case : 'monstre' (icône dédiée) sauf
 *  pour un allié — hérité, mercenaire, ou monstre enrôlé par la Baguette d'Os
 *  (`controle_par`), qui n'est plus un ennemi ce tour-ci et se peint comme sur
 *  la table — ou pour un monstre devenu traversable (`franchitFigures`) : il
 *  reste un MONSTRE à l'écran, seule sa capacité à bloquer a changé. */
function silhouetteDe(x, y) {
    const ent = occupantDe(x, y);
    return (ent?.type === 'monstre' && ! ent?.controle_par) ? 'monstre' : 'allie';
}

// Surcouche par case (au-dessus du terrain rendu par DungeonGrid) : départ,
// occupant (monstre/allié) ou case accessible ; null = terrain nu.
function surcouche(x, y) {
    if (x === props.depart.x && y === props.depart.y) return 'depart';
    const k = cle(x, y);
    if (occupees.value.has(k)) return silhouetteDe(x, y);
    // ⚠ Les ALLIÉS ont leur propre ensemble depuis qu'on peut les traverser
    // (2026-09-11) — et un monstre qu'un Rogue/Voile de Brume franchit y
    // tombe aussi désormais (`BLOQUANTE`, plus haut) : ne tester que
    // `occupees` les rendait INVISIBLES sur la carte (ni couleur, ni glyphe,
    // une figure devenue sol nu), et un test qui rendait tout `alliees`
    // comme 'allie' sans condition aurait peint un monstre traversable en
    // pastille de compagnon — silhouette FAUSSE pour une figure qui reste un
    // ennemi. Ils ne sont pas non plus dans `accessibles` — on les traverse,
    // on ne s'y arrête pas — donc sans ce test ils ne retombent sur rien.
    if (alliees.value.has(k)) return silhouetteDe(x, y);
    // Trajet prévu : la case VISÉE d'abord (elle est aussi dans le chemin), puis
    // les cases traversées — elles restent accessibles, on ne fait que dire
    // « le héros passera par là ».
    if (viseeSur(x, y)) return 'visee';
    if (casesTrajet.value.has(k)) return 'trajet';
    return accessibles.value.has(k) ? 'accessible' : null;
}

/** L'entité debout sur cette case, s'il y en a une. */
function occupantDe(x, y) {
    return props.entites.find((e) => e.x === x && e.y === y
        && ! (e.type === 'heros' && e.tombe)
        && ! (e.type === 'monstre' && ((e.etat ?? 'actif') !== 'actif' || (e.pv_body ?? 1) <= 0)));
}

/** TROIS PREMIÈRES LETTRES du compagnon (René, 2026-09-11 : « peux-tu mettre
 *  les 3 premières lettres des joueurs pour les identifier » — une initiale
 *  seule ne se rattachait pas assez vite à un nom autour de la table).
 *
 *  ⚠ Pas de portrait : à 38 px une illustration devient une tache. C'est la
 *  leçon des images de carte, qui vivent dans la LÉGENDE et jamais sur la
 *  grille, parce que la silhouette est ce qu'on lit d'un coup d'œil.
 *
 *  ⚠ Trois lettres ne tiennent pas dans un disque : la pastille est un
 *  RECTANGLE arrondi, et la graisse compense la petite taille. */
function initialeDe(x, y) {
    const nom = (occupantDe(x, y)?.nom ?? '').trim();
    return nom ? nom.slice(0, 3).toUpperCase() : '';
}

/** ⚠ Deux héros peuvent porter le MÊME nom (constaté en partie : deux
 *  « Thrakor »), donc l'initiale seule ne suffit pas à les distinguer. La
 *  teinte se dérive de l'`id`, stable d'un tour à l'autre — deux compagnons
 *  homonymes restent alors deux figurines différentes à l'œil. */
function teinteDe(x, y) {
    const id = occupantDe(x, y)?.id;
    return id == null ? null : { '--dep-allie-h': `${(Number(id) * 47) % 360}` };
}

// APERÇU DU TRAJET (René, 2026-09-17 : « que la figure utilise le vrai
// chemin »). Un tap ne part plus tout droit : il demande au SERVEUR la route
// exacte, la dessine, et c'est le second tap (ou le bouton) qui l'engage.
//
// ⚠ Le chemin vient du résolveur, il n'est PAS refait ici. Ce composant sait
// déjà calculer des cases atteignables — et c'est précisément le piège : deux
// routes de même coût n'exposent pas aux mêmes pièges (`controlerChemin()`
// contrôle CASE PAR CASE), donc un chemin re-dérivé en JS pourrait annoncer un
// trajet que le héros ne prendra pas. Le serveur publie la DÉCISION.
const api = useApi();
const apercu = ref(null);        // { x, y, chemin, cout, restant_apres, pieges, atteignable, raison, indisponible }
const apercuEnCours = ref(false);

/** Cases du trajet prévu, pour la surcouche (O(1) par case). */
const casesTrajet = computed(() => {
    const s = new Set();
    for (const c of apercu.value?.chemin ?? []) s.add(cle(c.x, c.y));
    return s;
});

// ⚠ L'état arrive en VOCABULAIRE MOTEUR (`detecte`/`desarme`/`declenche`) : le
// dire tel quel à l'écran (« Fosse (detecte) ») met un identifiant de code sous
// les yeux du joueur. Même table que celle de la légende, en plus court — une
// ligne d'aperçu n'a pas la place d'une phrase.
const ETATS_PIEGE = { detecte: 'détecté', desarme: 'désamorcé', declenche: 'déjà déclenché' };
const trajetPieges = computed(() => (apercu.value?.pieges ?? []).map((p) => ({
    ...p,
    libelle: ETATS_PIEGE[p.etat] ?? p.etat,
})));

function viseeSur(x, y) {
    return apercu.value !== null && apercu.value.x === x && apercu.value.y === y;
}

async function toucher(x, y) {
    if (! accessibles.value.has(cle(x, y))) return;

    // Second tap sur la MÊME case : c'est la confirmation.
    if (viseeSur(x, y)) {
        emit('deplacer', { x, y });
        return;
    }

    apercu.value = { x, y, chemin: [], pieges: [], atteignable: true };
    apercuEnCours.value = true;

    try {
        const rep = await api.apercuDeplacement(props.groupe, x, y);
        // Une réponse tardive ne doit pas écraser un tap plus récent.
        if (viseeSur(x, y)) apercu.value = { x, y, ...rep };
    } catch {
        // ⚠ L'aperçu ne doit JAMAIS empêcher de jouer : réseau coupé, serveur
        // qui répond 422, peu importe — on garde la visée et le second tap
        // part comme avant. Le moteur revalide de toute façon.
        if (viseeSur(x, y)) apercu.value = { x, y, chemin: [], pieges: [], atteignable: true, indisponible: true };
    } finally {
        apercuEnCours.value = false;
    }
}

/** Bouton « Y aller » : même chemin que le second tap. */
function confirmer() {
    if (apercu.value) emit('deplacer', { x: apercu.value.x, y: apercu.value.y });
}

// ZOOM (René, 2026-08-28). Les cases étaient à 22 px : sous la cible tactile
// recommandée, on visait la voisine — et les marqueurs (piège, épreuve, meuble)
// s'y réduisaient à une tache. 38 px rend le tap fiable ET les symboles lisibles,
// au prix d'une carte qui ne tient plus à l'écran : d'où la croix de direction
// ci-dessous, qui est la contrepartie du zoom et non un ajout séparé.
const CASE = 38;
const ECART = 2;
// Un appui = trois cases. Une seule serait fastidieuse sur un donjon de 40 de
// large ; un écran entier ferait perdre le fil du chemin qu'on suit des yeux.
const PAS = 3 * (CASE + ECART);

const gridStyle = computed(() => ({
    gap: `${ECART}px`,
    width: 'max-content',
    gridTemplateColumns: `repeat(${props.carte.largeur}, ${CASE}px)`,
    gridTemplateRows: `repeat(${props.carte.hauteur}, ${CASE}px)`,
    // Les glyphes des marqueurs se dimensionnent là-dessus : sans cette
    // variable, agrandir la case laissait les symboles à ~10 px (voir
    // DungeonGrid.vue).
    '--dg-icone': `${Math.round(CASE * 0.46)}px`,
    // Taille RÉELLE de la case en px (ici une constante, la manette ne zoome
    // pas) — même rôle que `--dg-icone` juste au-dessus, pour le CONTOUR de
    // salle cette fois (DungeonGrid.vue, `.dg-room-outline`) : un trait
    // calculé en `%` se serait résolu sur la police héritée, en `vw` sur la
    // largeur d'écran — jamais sur la case, dans les deux cas.
}));

// Bornes de défilement, relues à chaque scroll : une flèche qui ne peut plus
// rien faire est GRISÉE plutôt que morte au toucher — sinon le joueur appuie
// trois fois en croyant que la carte est figée.
const bornes = ref({ gauche: false, droite: false, haut: false, bas: false });

function mesurer() {
    const el = grilleRef.value;
    if (! el) { return; }

    // Marge d'un pixel : les navigateurs rendent parfois un scrollLeft
    // fractionnaire, et une comparaison stricte laissait une flèche active à
    // l'arrivée en butée.
    bornes.value = {
        gauche: el.scrollLeft > 1,
        droite: el.scrollLeft < el.scrollWidth - el.clientWidth - 1,
        haut: el.scrollTop > 1,
        bas: el.scrollTop < el.scrollHeight - el.clientHeight - 1,
    };
}

// Rien à faire défiler = pas de croix. Sur une petite carte entièrement visible,
// quatre flèches grisées ne seraient que du décor recouvrant des cases jouables ;
// et le bouton de recentrage n'a rien à recentrer.
const padUtile = computed(() => Object.values(bornes.value).some(Boolean));

function deplacerVue(dx, dy) {
    grilleRef.value?.scrollBy({ left: dx * PAS, top: dy * PAS, behavior: 'smooth' });
}

function centrerSurHeros() {
    // ⚠ `scrollIntoView` sur la case de départ, et non un calcul de coordonnées :
    // c'est le DOM qui connaît la taille réelle du cadre, laquelle dépend du
    // clavier, de la barre d'adresse et de l'orientation.
    grilleRef.value?.querySelector('.dg-cell.depart')
        ?.scrollIntoView({ block: 'center', inline: 'center', behavior: 'smooth' });
}

onMounted(async () => {
    grilleRef.value?.querySelector('.dg-cell.depart')
        ?.scrollIntoView({ block: 'center', inline: 'center', behavior: 'instant' });
    await nextTick();
    mesurer();
});
</script>

<template>
    <div class="dep-ov" @click.self="$emit('close')">
        <div class="dep-sheet">
            <header class="dep-head">
                <div class="dep-roll">
                    <MSym n="casino" fill />
                    <span class="dep-portee">{{ portee }}</span>
                    <span class="dep-portee-lbl">cases</span>
                </div>
                <div class="dep-detail" v-if="de != null">{{ base }} <span>+ dé {{ de }}</span></div>
                <LegendeCarte class="dep-legende" :carte="carte" />
                <button class="dep-close" type="button" @click="$emit('close')"><MSym n="close" /></button>
            </header>

            <p v-if="accessibles.size && ! apercu" class="dep-hint"><MSym n="touch_app" :size="14" /> Touche une case éclairée pour voir le trajet</p>

            <!-- APERÇU : le trajet EXACT rendu par le serveur, à confirmer. Les
                 pièges annoncés sont ceux que la carte montre DÉJÀ (détectés,
                 désamorcés, déclenchés) — l'aperçu ne révèle rien. -->
            <div v-else-if="apercu" class="dep-apercu">
                <p class="dep-hint">
                    <MSym n="route" :size="14" />
                    <span v-if="apercuEnCours">Calcul du trajet…</span>
                    <span v-else-if="apercu.indisponible">Trajet indisponible — touche encore pour y aller quand même</span>
                    <span v-else-if="apercu.atteignable === false">{{ apercu.raison }}</span>
                    <span v-else>{{ apercu.cout }} point{{ apercu.cout > 1 ? 's' : '' }} — il en restera {{ apercu.restant_apres }}</span>
                </p>
                <p v-for="p in trajetPieges" :key="`${p.x}-${p.y}`" class="dep-hint dep-hint-piege">
                    <MSym n="warning" :size="14" /> Le trajet passe sur : {{ p.nom }} ({{ p.libelle }})
                </p>
                <button
                    v-if="apercu.atteignable !== false"
                    class="dep-aller"
                    type="button"
                    @click="confirmer"
                ><MSym n="directions_walk" :size="16" fill /> Y aller</button>
            </div>
            <p v-else class="dep-hint dep-hint-bloque"><MSym n="block" :size="14" /> Aucune case accessible — tu es bloqué. Ferme et termine ton tour.</p>

            <div class="dep-carte">
                <div ref="grilleRef" class="dep-scroll" @scroll.passive="mesurer">
                <DungeonGrid :carte="carte" :traps="carte.pieges ?? []" :furniture="carte.mobilier ?? []" :trials="carte.epreuves ?? []" :levers="carte.leviers ?? []" :terrain="carte.terrain ?? []" :ice="carte.glace ?? []" :cell-class="surcouche" :grid-style="gridStyle" @cell="toucher">
                    <template #cell="{ x, y }">
                        <MSym v-if="surcouche(x, y) === 'depart'" n="person" :size="14" fill />
                        <MSym v-else-if="surcouche(x, y) === 'monstre'" n="pets" :size="13" fill />
                        <span
                            v-else-if="surcouche(x, y) === 'allie' && initialeDe(x, y)"
                            class="dep-initiale"
                            :style="teinteDe(x, y)"
                            :title="occupantDe(x, y)?.nom"
                        >{{ initialeDe(x, y) }}</span>
                    </template>
                    </DungeonGrid>
                </div>

                <!-- Croix de direction : le pendant du zoom. Le doigt sert à
                     CHOISIR une case ; le faire aussi servir à faire glisser la
                     carte rend les deux gestes ambigus (un glissement un peu
                     court se lit comme un tap, et on part se déplacer où on ne
                     voulait pas). Le défilement natif reste possible, ces
                     boutons ne font que le rendre explicite.
                     Posée en surimpression d'un COIN : hors de `.dep-scroll`,
                     qui défile — dedans, elle s'en irait avec le donjon. -->
                <div v-if="padUtile" class="dep-pad">
                    <button type="button" class="pad-h" :disabled="! bornes.haut" aria-label="Vers le haut" @click="deplacerVue(0, -1)"><MSym n="keyboard_arrow_up" /></button>
                    <button type="button" class="pad-g" :disabled="! bornes.gauche" aria-label="Vers la gauche" @click="deplacerVue(-1, 0)"><MSym n="keyboard_arrow_left" /></button>
                    <!-- Au centre : revenir à son héros. C'est le seul repère
                         qui ne se perd jamais, donc la sortie de secours quand
                         on s'est égaré à l'autre bout du donjon. -->
                    <button type="button" class="pad-c" aria-label="Centrer sur mon héros" @click="centrerSurHeros"><MSym n="my_location" fill /></button>
                    <button type="button" class="pad-d" :disabled="! bornes.droite" aria-label="Vers la droite" @click="deplacerVue(1, 0)"><MSym n="keyboard_arrow_right" /></button>
                    <button type="button" class="pad-b" :disabled="! bornes.bas" aria-label="Vers le bas" @click="deplacerVue(0, 1)"><MSym n="keyboard_arrow_down" /></button>
                </div>
            </div>

            <!-- Fermeture toujours atteignable au bas de la feuille. -->
            <button class="dep-fermer" type="button" @click="$emit('close')">
                <MSym n="close" :size="16" /> Fermer
            </button>
        </div>
    </div>
</template>

<style>
/* La légende vit dans l'EN-TÊTE, pas en surimpression de la grille : celle-ci
   défile (`.dep-scroll`), un bouton flottant posé dessus s'en irait avec elle.
   `flex: none` pour la même raison que la croix, juste à côté — l'en-tête se
   compresse sur un écran étroit et éjecterait le bouton hors du viewport. */
.dep-legende { flex: none; margin-left: auto; }

/* ⚠ ANCRAGE DU PANNEAU DE LÉGENDE. Il se positionne sur son bouton (`right: 0`
   de `.lg-wrap`), or ce bouton n'est PAS au bord : la croix de fermeture le
   suit. Sur 360 px, le panneau débordait donc de 44 px à GAUCHE de l'écran,
   texte coupé. En rendant l'en-tête positionné et le bouton statique, le
   panneau s'aligne sur le bord de l'EN-TÊTE — c'est-à-dire sur le bord de la
   feuille, qui est le seul repère juste. */
.dep-head { position: relative; }
.dep-head .dep-legende { position: static; }

/* ⚠ `minmax(0, 1fr)` : sans lui la piste de grille se dimensionne sur le
   CONTENU de la feuille, et celle-ci atteignait son `max-width` de 520 px sur un
   écran de 412 — mesuré. La carte étant large de plusieurs milliers de pixels,
   tout ce qui suit sortait de l'écran : la croix de fermeture de l'en-tête et la
   moitié droite de la croix de direction, donc inatteignables au doigt (c'est le
   défaut de la §2.2, ressorti par une autre porte). Le `overflow: auto` de
   `.dep-scroll` le masquait tant qu'il était l'enfant DIRECT de la feuille : un
   conteneur de défilement a une taille minimale nulle, pas un `div` ordinaire. */
.dep-ov { position: fixed; inset: 0; z-index: 70; display: grid; place-items: end center;
  grid-template-columns: minmax(0, 1fr);
  background: oklch(0.12 0.02 60 / 0.6); backdrop-filter: blur(3px); }
.dep-sheet { width: 100%; max-width: 520px; max-height: 82vh; display: flex; flex-direction: column;
  background: var(--stone-900); border-top-left-radius: 18px; border-top-right-radius: 18px;
  border: var(--line); border-bottom: none; padding: 14px 14px 20px; box-shadow: var(--sh-3); }

/* `min-width: 0` + `flex: none` sur la croix : sans ça, le contenu de l'en-tête
   (portée + détail du dé) refusait de se compresser sur un écran étroit et
   poussait le bouton de fermeture HORS du viewport — mesuré à x≈471 px sur un
   écran de 420 px, donc totalement inatteignable (verdict §2.2). */
.dep-head { display: flex; align-items: center; gap: 12px; min-width: 0; }
.dep-head > * { min-width: 0; }
.dep-roll { display: inline-flex; align-items: baseline; gap: 6px; color: var(--torch); font-weight: 800; }
.dep-roll .msym { font-size: 26px; align-self: center; }
.dep-portee { font-size: 26px; font-family: var(--font-display); }
.dep-portee-lbl { font-size: 12px; color: var(--ink-400); font-weight: 700; }
.dep-detail { font-size: 13px; color: var(--ink-400); font-weight: 700; }
.dep-detail span { color: var(--ink-600); }
.dep-close { margin-left: auto; flex: none; display: grid; place-items: center; width: 34px; height: 34px;
  border-radius: 999px; border: var(--line); background: var(--stone-850); color: var(--ink-300); cursor: pointer; }

.dep-hint { font-size: 12.5px; color: var(--ink-400); display: flex; align-items: center; gap: 6px; margin: 8px 0 10px; }
.dep-hint .msym { color: var(--torch); }
.dep-hint-bloque { color: var(--danger, #e66); }
.dep-hint-bloque .msym { color: var(--danger, #e66); }

/* Aperçu du trajet : bloc COMPACT (la manette n'a qu'un écran de téléphone, et
   la carte doit rester la plus grande chose dessus) — une ligne de coût, une
   ligne par piège connu traversé, un bouton pleine largeur pour engager.
   ⚠ Classes préfixées `dep-` : les styles de SFC sont globaux ici. */
.dep-apercu { display: flex; flex-direction: column; gap: 2px; }
.dep-apercu .dep-hint { margin: 8px 0 6px; }
.dep-hint-piege { color: var(--torch, #d9a441); margin: 0 0 6px; }
.dep-hint-piege .msym { color: var(--torch, #d9a441); }
.dep-aller { margin: 2px 0 10px; padding: 10px; border-radius: 11px; border: var(--line);
  background: linear-gradient(150deg, var(--ember, #8c3b1b), var(--ember-deep, #5e2410));
  color: var(--parch-100, #f3e7cf); font-weight: 700; font-size: 14px; cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: 6px; }
.dep-fermer { margin-top: 12px; flex: none; width: 100%; padding: 11px; border-radius: 11px; border: var(--line);
  background: var(--stone-850); color: var(--ink-200, #e7dcc6); font-weight: 700; font-size: 14px; cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: 6px; }

/* Cadre de la carte : c'est LUI qui porte la croix de direction, pas la zone
   défilante — un bouton posé dans `.dep-scroll` s'en irait avec le donjon. */
.dep-carte { position: relative; flex: 1; min-height: 0; min-width: 0; display: flex; }

/* Croix de direction, en bas à droite. Compacte et translucide : elle recouvre
   quelques cases, et c'est le compromis assumé — la carte peut toujours être
   décalée pour dégager la case visée, alors que la placer SOUS la carte
   coûterait de la hauteur sur un écran déjà à 82 vh. */
.dep-pad { position: absolute; right: 10px; bottom: 10px; z-index: 5;
  display: grid; grid-template-columns: repeat(3, 34px); grid-template-rows: repeat(3, 34px);
  gap: 2px; padding: 4px; border-radius: 14px;
  background: oklch(0.16 0.012 255 / 0.82); border: var(--line); backdrop-filter: blur(6px);
  box-shadow: var(--sh-2); }
.dep-pad button { display: grid; place-items: center; border-radius: 9px; cursor: pointer;
  border: none; background: var(--stone-800); color: var(--ink-200, #e7dcc6);
  -webkit-tap-highlight-color: transparent; }
.dep-pad button:active { transform: scale(0.94); }
.dep-pad button:disabled { opacity: 0.28; pointer-events: none; }
.dep-pad .msym { font-size: 21px; }
.dep-pad .pad-h { grid-area: 1 / 2; }
.dep-pad .pad-g { grid-area: 2 / 1; }
.dep-pad .pad-c { grid-area: 2 / 2; background: var(--stone-850); color: var(--torch); }
.dep-pad .pad-d { grid-area: 2 / 3; }
.dep-pad .pad-b { grid-area: 3 / 2; }

/* `safe center` : la grille est CENTRÉE quand elle tient dans la vue, mais
   revient au bord quand elle DÉPASSE (scroll jusqu'à la salle la plus à droite). */
.dep-scroll { overflow: auto; flex: 1; min-width: 0; border-radius: var(--r-md); background: var(--stone-950); padding: 8px;
  display: flex; justify-content: safe center; align-items: safe center; }
/* Départ/occupants : centrer l'icône dans la case (DungeonGrid gère le reste). */
.dep-scroll .dg-cell { display: grid; place-items: center; }

/* Initiale d'un compagnon (René, 2026-09-11). Pleine case, fond teinté par
   `--dep-allie-h` (dérivé de l'id) pour séparer deux homonymes, et un contour
   sombre pour que la lettre tienne sur n'importe quelle teinte. */
.dep-initiale {
  display: grid; place-items: center; width: 100%; height: 100%;
  /* Rectangle arrondi, pas un disque : trois lettres dans un cercle de 38 px
     obligeraient à descendre sous 8 px pour tenir dans la corde. */
  border-radius: 6px;
  font-weight: 800; font-size: 11px; line-height: 1; letter-spacing: -0.04em;
  color: oklch(0.98 0 0);
  background: oklch(0.52 0.15 var(--dep-allie-h, 260));
  box-shadow: inset 0 0 0 1.5px oklch(0.16 0.012 255 / 0.85);
  text-shadow: 0 1px 2px oklch(0.16 0.012 255 / 0.9);
}
</style>
