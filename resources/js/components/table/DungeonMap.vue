<script setup>
// Carte de la TABLE (narrateur) : le TERRAIN (cases / portes / pièges) est rendu
// par le socle partagé DungeonGrid (identique à la manette) ; cette vue y ajoute
// la CAMÉRA (fenêtre qui se recentre sur le héros actif) et la couche des
// FIGURINES animées (glissement case-par-case, fondu à la mort).
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
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
    /** Case (x, y) du héros actif — la caméra s'y recentre. `null` = immobile. */
    activeX: { type: Number, default: null },
    activeY: { type: Number, default: null },
});

// Doit rester égal au `padding` de `.table-screen .map` (TableView.vue).
const PADDING_PX = 14;
const viewportEl = ref(null);
const cellPx = ref(40);
let observateur = null;

onMounted(() => {
    const mesurer = () => {
        if (viewportEl.value) cellPx.value = (viewportEl.value.clientWidth - 2 * PADDING_PX) / COLS_VUE;
    };
    mesurer();
    observateur = new ResizeObserver(mesurer);
    if (viewportEl.value) observateur.observe(viewportEl.value);
});
onBeforeUnmount(() => observateur?.disconnect());

const gridStyle = computed(() => {
    const c = Math.max(1, props.carte.largeur ?? COLS_VUE);
    const r = Math.max(1, props.carte.hauteur ?? LIGNES_VUE);
    const px = cellPx.value;
    const largeurVue = COLS_VUE * px;
    const hauteurVue = LIGNES_VUE * px;
    const largeurCarte = c * px;
    const hauteurCarte = r * px;

    const cibleX = (props.activeX ?? (c - 1) / 2) + 0.5;
    const cibleY = (props.activeY ?? (r - 1) / 2) + 0.5;

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
        transform: `translate(${centrer(largeurVue, largeurCarte, cibleX * px)}px, ${centrer(hauteurVue, hauteurCarte, cibleY * px)}px)`,
    };
});

/** Décalage (px) qui centre `cible` dans la fenêtre, borné aux bords de la carte. */
function centrer(dimVue, dimCarte, cible) {
    if (dimCarte <= dimVue) return (dimVue - dimCarte) / 2;
    return Math.min(0, Math.max(dimVue - dimCarte, dimVue / 2 - cible));
}
</script>

<template>
    <div ref="viewportEl" class="map">
        <DungeonGrid :carte="carte" :traps="traps" :grid-style="gridStyle" animate>
            <!-- Figurines (héros / monstres / alliés) — enfants directs de la
                 grille : FLIP de glissement case-par-case, fondu à la mort. -->
            <div
                v-for="e in entities"
                :key="`ent-${e.k}-${e.id}`"
                class="ent-holder"
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
    </div>
</template>
