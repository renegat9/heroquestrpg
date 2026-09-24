<script setup>
// Carte de la TABLE (narrateur) : le TERRAIN (cases / portes / pièges) est rendu
// par le socle partagé DungeonGrid (identique à la manette) ; cette vue y ajoute
// la CAMÉRA (fenêtre qui se recentre sur le héros actif, + un glissé manuel à la
// main — René 2026-09-24), et la couche des FIGURINES animées (glissement
// case-par-case, fondu à la mort, et désormais une fiche au tap : voir
// TableView.vue, MÊME popup que celle ouverte depuis la barre d'initiative).
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import DungeonGrid from '../carte/DungeonGrid.vue';
import MSym from '../ui/MSym.vue';
import Vignette from '../ui/Vignette.vue';

/** Densité de référence (cases visibles à l'écran, quelle que soit la taille
 *  réelle de la carte) — garde le même « zoom » perçu. */
const COLS_VUE = 14;
const LIGNES_VUE = 9;

const props = defineProps({
    /** Carte du contrat : { largeur, hauteur, cases, portes }. */
    carte: { type: Object, required: true },
    /** Figurines : [{ x, y, k, l, ic, hp?, cur?, tgt?, cond?, ew?, eh? }]. */
    entities: { type: Array, required: true },
    /** Pièges visibles : [{ x, y, etat, nom, titre }] — voir piegesVersMarqueurs(). */
    traps: { type: Array, default: () => [] },
    /** Mobilier visible : [{ x, y, l, h, nom, bloque_mouvement, bloque_vue, ic, titre }] — voir mobilierVersDecor(). */
    furniture: { type: Array, default: () => [] },
    /** Case (x, y) du héros actif — la caméra s'y recentre. `null` = immobile. */
    activeX: { type: Number, default: null },
    activeY: { type: Number, default: null },
});
// Émis au TAP (pas au glissé) sur une figurine — {id, type}, le même couple
// que celui déjà consommé par `statsFigure()` (voir store/game.js) pour la
// fiche ouverte depuis la barre d'initiative. Une seule fiche, une seule
// popup : TableView branche cet événement sur SA fonction `inspecter`
// existante plutôt que d'en recréer une.
const emit = defineEmits(['inspecter']);

// Doit rester égal au `padding` de `.table-screen .map` (TableView.vue).
const PADDING_PX = 14;
const viewportEl = ref(null);
const cellPx = ref(40);
let observateur = null;

onMounted(() => {
    const mesurer = () => {
        if (viewportEl.value) cellPx.value = (viewportEl.value.clientWidth - 2 * PADDING_PX) / COLS_VUE;
        // Un redimensionnement peut rendre un ancien décalage manuel invalide
        // (la fenêtre a grandi, la carte tient maintenant tout entière) — on le
        // reborne avec la MÊME règle que le glissé, jamais une nouvelle.
        reborner();
    };
    mesurer();
    observateur = new ResizeObserver(mesurer);
    if (viewportEl.value) observateur.observe(viewportEl.value);
});
onBeforeUnmount(() => observateur?.disconnect());

/** Dimensions courantes (px) — fenêtre visible ET carte réelle, sur les deux
 *  axes. Partagées par le rendu de la caméra ET par le bornage du glissé
 *  ci-dessous, pour qu'aucun des deux ne puisse dériver de l'autre. */
const dims = computed(() => {
    const c = Math.max(1, props.carte.largeur ?? COLS_VUE);
    const r = Math.max(1, props.carte.hauteur ?? LIGNES_VUE);
    const px = cellPx.value;
    return {
        c, r, px,
        largeurVue: COLS_VUE * px, hauteurVue: LIGNES_VUE * px,
        largeurCarte: c * px, hauteurCarte: r * px,
    };
});

/** Point (px) que la caméra AUTOMATIQUE vise — le centre de la carte tant
 *  qu'aucun héros actif n'est publié. */
const cibleXPx = computed(() => ((props.activeX ?? (dims.value.c - 1) / 2) + 0.5) * dims.value.px);
const cibleYPx = computed(() => ((props.activeY ?? (dims.value.r - 1) / 2) + 0.5) * dims.value.px);

/** Décalage (px) qui centre `cible` dans la fenêtre, borné aux bords de la
 *  carte. SEUL point de bornage de la caméra (une règle, un point de passage,
 *  CLAUDE.md) : le glissé manuel le RÉUTILISE ci-dessous plutôt que d'écrire
 *  une seconde borne qui finirait, un jour, par diverger de celle-ci. */
function centrer(dimVue, dimCarte, cible) {
    if (dimCarte <= dimVue) return (dimVue - dimCarte) / 2;
    return Math.min(0, Math.max(dimVue - dimCarte, dimVue / 2 - cible));
}

/* ---- glissé manuel de la caméra (René, 2026-09-24) --------------------
 * Le focus automatique reste LA RÈGLE : ce décalage ne fait que s'AJOUTER au
 * centrage ci-dessus (cible effective = cible - décalage, réinjectée dans
 * `centrer()`), et il est jeté dès que le héros actif change de case — voir
 * le watcher plus bas. Sans cette remise à zéro, la table finirait plantée
 * sur une vue d'il y a dix tours pendant que l'action continue hors cadre. */
const decalageX = ref(0);
const decalageY = ref(0);
const aDecale = computed(() => decalageX.value !== 0 || decalageY.value !== 0);
/* Coupe la transition PENDANT le glissé actif : la vue doit suivre le doigt
 * sans délai. Hors glissé (recentrage automatique au changement de tour, ou
 * clic sur « Recentrer »), une transition douce est réappliquée. */
const enTrainDeGlisser = ref(false);

/** Borne un décalage manuel CANDIDAT aux mêmes bords que `centrer()`, en
 *  dérivant le résultat de `centrer()` lui-même (algèbre inverse) — jamais en
 *  rejouant son `Math.min/Math.max` dans une seconde fonction. */
function bornerDecalage(dimVue, dimCarte, ciblePx, decalageCandidat) {
    const translateBorne = centrer(dimVue, dimCarte, ciblePx - decalageCandidat);
    return ciblePx - (dimVue / 2 - translateBorne);
}

function reborner() {
    decalageX.value = bornerDecalage(dims.value.largeurVue, dims.value.largeurCarte, cibleXPx.value, decalageX.value);
    decalageY.value = bornerDecalage(dims.value.hauteurVue, dims.value.hauteurCarte, cibleYPx.value, decalageY.value);
}

const gridStyle = computed(() => {
    const { largeurVue, hauteurVue, largeurCarte, hauteurCarte, c, r } = dims.value;

    return {
        // Positionnée dans la fenêtre `.map` (padding 14px), clippée par son
        // overflow ; la caméra est le translate ci-dessous.
        position: 'absolute',
        top: `${PADDING_PX}px`,
        left: `${PADDING_PX}px`,
        gap: '3px',
        width: `${largeurCarte}px`,
        height: `${hauteurCarte}px`,
        gridTemplateColumns: `repeat(${c}, 1fr)`,
        gridTemplateRows: `repeat(${r}, 1fr)`,
        transform: `translate(${centrer(largeurVue, largeurCarte, cibleXPx.value - decalageX.value)}px, ${centrer(hauteurVue, hauteurCarte, cibleYPx.value - decalageY.value)}px)`,
        // Pendant le glissé : suit le doigt SANS délai. Sinon : transition douce
        // (recentrage automatique au changement de tour, ou bouton Recentrer).
        transition: enTrainDeGlisser.value ? 'none' : 'transform .25s ease',
    };
});

// Le focus automatique reprend la main dès que le héros actif change de case
// — nouveau tour, ou un pas de plus dans son déplacement. Le glissé n'est
// qu'une consultation ponctuelle : on ne le quitte jamais à la main.
watch([() => props.activeX, () => props.activeY], () => {
    decalageX.value = 0;
    decalageY.value = 0;
});

function recentrer() {
    decalageX.value = 0;
    decalageY.value = 0;
}

/* ---- glissé / tap (Pointer Events : souris ET tactile — l'écran de table est
 * posé sur un PC, une télé ou une tablette, jamais un seul type de pointeur).
 * ⚠ Sous le seuil, c'est un TAP (ouvre la fiche si on vise une figurine) ; au
 * delà, un GLISSÉ (n'ouvre jamais rien) — sinon chaque recadrage de la vue
 * ouvrirait une popup au passage. */
const SEUIL_GLISSE_PX = 8;
let geste = null; // { id, x0, y0, decalageX0, decalageY0, aBouge, figure }

function debutGeste(e) {
    if (geste) return; // un second doigt (pincement…) n'ouvre pas un second glissé
    // ⚠ Le bouton « Recentrer » vit DANS `.map` (pour se positionner par-dessus
    // la fenêtre) : sans cette sortie, `setPointerCapture()` ci-dessous
    // retarget le CLIC qui suit sur `.map` lui-même, et le bouton ne reçoit
    // jamais son `@click` — mesuré : le bouton restait affiché, la vue ne
    // revenait jamais. Un pointerdown sur un contrôle interactif n'ouvre donc
    // aucun glissé, il le laisse gérer son propre clic.
    if (e.target.closest?.('.map-recentrer')) return;
    const cibleFigure = e.target.closest?.('.ent-holder');
    geste = {
        id: e.pointerId,
        x0: e.clientX, y0: e.clientY,
        decalageX0: decalageX.value, decalageY0: decalageY.value,
        aBouge: false,
        // Ids numériques (clés primaires Eloquent, comme partout ailleurs dans
        // ce fichier) : le dataset les rend en texte, `statsFigure()` compare
        // par égalité stricte contre l'entier publié par l'API.
        figure: cibleFigure ? { id: Number(cibleFigure.dataset.figId), type: cibleFigure.dataset.figType } : null,
    };
    e.currentTarget.setPointerCapture?.(e.pointerId);
}

function bougerGeste(e) {
    if (!geste || e.pointerId !== geste.id) return;
    const dx = e.clientX - geste.x0;
    const dy = e.clientY - geste.y0;
    if (!geste.aBouge) {
        if (Math.hypot(dx, dy) < SEUIL_GLISSE_PX) return; // encore un tap possible
        geste.aBouge = true;
        enTrainDeGlisser.value = true;
    }
    const { largeurVue, largeurCarte, hauteurVue, hauteurCarte } = dims.value;
    decalageX.value = bornerDecalage(largeurVue, largeurCarte, cibleXPx.value, geste.decalageX0 + dx);
    decalageY.value = bornerDecalage(hauteurVue, hauteurCarte, cibleYPx.value, geste.decalageY0 + dy);
}

function finGeste(e, permettreTap) {
    if (!geste || e.pointerId !== geste.id) return;
    const { aBouge, figure } = geste;
    geste = null;
    enTrainDeGlisser.value = false;
    if (permettreTap && !aBouge && figure?.id) emit('inspecter', { id: figure.id, type: figure.type });
}
</script>

<template>
    <div
        ref="viewportEl"
        class="map"
        @pointerdown="debutGeste"
        @pointermove="bougerGeste"
        @pointerup="(e) => finGeste(e, true)"
        @pointercancel="(e) => finGeste(e, false)"
    >
        <DungeonGrid :carte="carte" :traps="traps" :furniture="furniture" :trials="carte.epreuves ?? []" :levers="carte.leviers ?? []" :terrain="carte.terrain ?? []" :ice="carte.glace ?? []" :grid-style="gridStyle" animate>
            <!-- Figurines (héros / monstres / alliés) — enfants directs de la
                 grille : FLIP de glissement case-par-case, fondu à la mort.
                 `data-fig-*` : seule façon pour `debutGeste()` de savoir, au
                 pointerdown, QUELLE figurine (le cas échéant) est visée. -->
            <div
                v-for="e in entities"
                :key="`ent-${e.k}-${e.id}`"
                class="ent-holder"
                :data-fig-id="e.id"
                :data-fig-type="e.type"
                :style="{
                    gridColumn: `${e.x + 1} / span ${e.ew ?? 1}`,
                    gridRow: `${e.y + 1} / span ${e.eh ?? 1}`,
                }"
            >
                <div class="fig" :class="[e.k, { cur: e.cur, tgt: e.tgt, elite: e.elite }]">
                    <Vignette v-if="e.img || e.ic" :src="e.img" :icon="e.ic" fill />
                    <template v-else>{{ e.l }}</template>
                    <div v-if="e.elite" class="elite-badge" title="Élite">
                        <MSym n="star" fill />
                    </div>
                    <div v-if="e.hp" class="hp">
                        <i v-for="p in e.hp" :key="p" />
                    </div>
                    <div v-if="e.cond" class="cond" :class="e.cond.t ? `b-${e.cond.t}` : null" :title="e.cond.titre">
                        <MSym :n="e.cond.ic" fill />
                    </div>
                </div>
            </div>
        </DungeonGrid>

        <!-- « Recentrer » : seulement quand la vue a été écartée à la main —
             en permanence, il concurrencerait le seul signal qui doit compter
             (« la vue a bougé »). Redonne la main au focus automatique. -->
        <button v-if="aDecale" type="button" class="map-recentrer" @click="recentrer">
            <MSym n="my_location" :size="16" /> Recentrer
        </button>

        <!-- Légende : en surimpression d'un COIN DE LA FENÊTRE, hors de la
             grille — celle-ci se déplace sous la caméra à chaque tour, un
             bouton posé dedans glisserait avec le donjon.
             `.table-screen .map` est déjà `position: relative`. -->    </div>
</template>
