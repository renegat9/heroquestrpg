<script setup>
// Feuille de DÉPLACEMENT (manette) : montre l'allonce du tour (dé déjà lancé
// côté serveur : base + 1d6) et une mini-carte tappable. Le TERRAIN (cases /
// portes / pièges) est rendu par le socle PARTAGÉ DungeonGrid — le MÊME que
// l'écran table — pour un rendu identique ; cette feuille n'ajoute que la
// surbrillance des cases accessibles (BFS) et le tap de destination.
import { computed, nextTick, onMounted, ref } from 'vue';
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

// Cases occupées par une AUTRE figurine BLOQUANTE — MÊME règle que le moteur
// (FabriqueGrille) pour ne jamais bloquer une case que le serveur laisse libre :
//  - le héros sur sa propre case de départ ne se bloque pas ;
//  - un héros TOMBÉ s'enjambe (ne bloque pas) ;
//  - un monstre non-actif (vaincu) a déjà quitté le plateau — filtre défensif.
const occupees = computed(() => {
    const s = new Set();
    for (const e of props.entites) {
        if (e.x === props.depart.x && e.y === props.depart.y) continue;
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
    return s;
});

// BFS des cases accessibles dans la portée.
const accessibles = computed(() => {
    const { largeur: w, hauteur: h, cases } = props.carte;
    const dist = { [cle(props.depart.x, props.depart.y)]: 0 };
    const file = [{ x: props.depart.x, y: props.depart.y }];
    const out = new Set();
    while (file.length) {
        const { x, y } = file.shift();
        const d = dist[cle(x, y)];
        if (d >= props.portee) continue;
        for (const [dx, dy] of [[0, 1], [0, -1], [1, 0], [-1, 0]]) {
            const nx = x + dx; const ny = y + dy;
            if (nx < 0 || ny < 0 || nx >= w || ny >= h) continue;
            const k = cle(nx, ny);
            if (k in dist) continue;
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
            dist[k] = d + 1;
            out.add(k);
            // Ne PAS étendre le BFS au-delà d'une case encore dans le brouillard :
            // on ignore ce qu'il y a plus loin tant que le serveur n'a pas révélé
            // la salle (prochain état, après ce déplacement).
            if (caseConnue) file.push({ x: nx, y: ny });
        }
    }
    return out;
});

// Surcouche par case (au-dessus du terrain rendu par DungeonGrid) : départ,
// occupant (monstre/allié) ou case accessible ; null = terrain nu.
function surcouche(x, y) {
    if (x === props.depart.x && y === props.depart.y) return 'depart';
    if (occupees.value.has(cle(x, y))) {
        const ent = props.entites.find((e) => e.x === x && e.y === y);
        return ent?.type === 'monstre' ? 'monstre' : 'allie';
    }
    return accessibles.value.has(cle(x, y)) ? 'accessible' : null;
}

function toucher(x, y) {
    if (accessibles.value.has(cle(x, y))) emit('deplacer', { x, y });
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

            <p v-if="accessibles.size" class="dep-hint"><MSym n="touch_app" :size="14" /> Touche une case éclairée pour t'y déplacer</p>
            <p v-else class="dep-hint dep-hint-bloque"><MSym n="block" :size="14" /> Aucune case accessible — tu es bloqué. Ferme et termine ton tour.</p>

            <div class="dep-carte">
                <div ref="grilleRef" class="dep-scroll" @scroll.passive="mesurer">
                <DungeonGrid :carte="carte" :traps="carte.pieges ?? []" :furniture="carte.mobilier ?? []" :trials="carte.epreuves ?? []" :levers="carte.leviers ?? []" :cell-class="surcouche" :grid-style="gridStyle" @cell="toucher">
                    <template #cell="{ x, y }">
                        <MSym v-if="surcouche(x, y) === 'depart'" n="person" :size="14" fill />
                        <MSym v-else-if="surcouche(x, y) === 'monstre'" n="pets" :size="13" fill />
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
</style>
