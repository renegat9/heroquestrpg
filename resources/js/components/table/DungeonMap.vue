<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import MSym from '../ui/MSym.vue';
import Vignette from '../ui/Vignette.vue';

/** Densité de référence (cases visibles à l'écran, quelle que soit la
 *  taille réelle de la carte) — inchangée par rapport à l'ancien rendu
 *  toujours-tout-visible, pour garder le même « zoom » perçu. */
const COLS_VUE = 14;
const LIGNES_VUE = 9;

const props = defineProps({
    /** { C, R, cells: [{ x, y, t, range }] } — voir buildTableMap(). */
    map: { type: Object, required: true },
    /** Figurines : [{ x, y, k: 'hero'|'foe'|'chest', l, ic, hp?, cur?, tgt?, cond? }]
     *  — cond ({t, ic, titre}) : pictogramme d'état discret sur le jeton
     *  (endormi, frayeur, commandé… — voir conditionDeJeton()). */
    entities: { type: Array, required: true },
    /** Pièges visibles : [{ x, y, etat: 'detecte'|'desarme'|'declenche', nom, titre }]
     *  — voir piegesVersMarqueurs() (les cachés n'arrivent jamais au client). */
    traps: { type: Array, default: () => [] },
    /** Portes connues : [{ x, y, etat, cadenas, titre }] — voir portesVersMarqueurs()
     *  (les secrètes non révélées n'arrivent jamais au client). */
    doors: { type: Array, default: () => [] },
    /** Case (x, y) du héros actif — la caméra s'y recentre au début de son
     *  tour. `null` = pas de héros courant (tour des monstres, hub…) : la
     *  caméra ne bouge pas. */
    activeX: { type: Number, default: null },
    activeY: { type: Number, default: null },
});

/* ---- caméra : la carte réelle peut être bien plus grande (ou étroite)
   que la densité de référence 14×9 (ex. 22×6 mesuré en jeu) — on affiche
   une FENÊTRE de taille fixe (mêmes proportions/zoom qu'avant) qui se
   recentre sur le héros actif, plutôt que d'écraser toute la carte dans
   une grille figée à 14×9 (désalignait les cases au-delà de la 14e). ---- */
// Doit rester égal au `padding` de `.table-screen .map` (TableView.vue) :
// clientWidth inclut ce padding, qu'il faut retirer pour ne mesurer que
// la zone réellement disponible pour la grille.
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
    const c = Math.max(1, props.map.C ?? COLS_VUE);
    const r = Math.max(1, props.map.R ?? LIGNES_VUE);
    const px = cellPx.value;
    const largeurVue = COLS_VUE * px;
    const hauteurVue = LIGNES_VUE * px;
    const largeurCarte = c * px;
    const hauteurCarte = r * px;

    // Cible : centre du héros actif, ou centre de la carte à défaut.
    const cibleX = (props.activeX ?? (c - 1) / 2) + 0.5;
    const cibleY = (props.activeY ?? (r - 1) / 2) + 0.5;

    return {
        width: `${largeurCarte}px`,
        height: `${hauteurCarte}px`,
        gridTemplateColumns: `repeat(${c}, 1fr)`,
        gridTemplateRows: `repeat(${r}, 1fr)`,
        transform: `translate(${centrer(largeurVue, largeurCarte, cibleX * px)}px, ${centrer(hauteurVue, hauteurCarte, cibleY * px)}px)`,
    };
});

/** Décalage (px) qui centre `cible` dans la fenêtre, borné aux limites de
 *  la carte (jamais de vide affiché au-delà des bords) ; une carte plus
 *  petite que la fenêtre est simplement centrée, immobile. */
function centrer(dimVue, dimCarte, cible) {
    if (dimCarte <= dimVue) return (dimVue - dimCarte) / 2;
    return Math.min(0, Math.max(dimVue - dimCarte, dimVue / 2 - cible));
}
</script>

<template>
    <div ref="viewportEl" class="map">
        <!-- TransitionGroup : anime le DÉPLACEMENT des figurines (FLIP sur le
             changement de case, héros ET monstres) au lieu de les téléporter,
             et fait FONDRE les jetons retirés (monstre vaincu). -->
        <TransitionGroup tag="div" name="figmv" class="map-grid" :style="gridStyle">
            <div
                v-for="cell in map.cells"
                :key="`cell-${cell.x}-${cell.y}`"
                class="cell"
                :class="[cell.t, { range: cell.range }]"
                :style="{ gridColumn: cell.x + 1, gridRow: cell.y + 1 }"
            />
            <!-- couche pièges : sous les figurines, au-dessus des cases -->
            <div
                v-for="(t, i) in traps"
                :key="`trap-${i}`"
                class="trap-holder"
                :style="{ gridColumn: t.x + 1, gridRow: t.y + 1 }"
            >
                <div class="trap" :class="t.etat" :title="t.titre ?? t.nom">
                    <MSym v-if="t.etat !== 'declenche'" n="warning" fill />
                </div>
            </div>
            <!-- couche portes : cadenas sur les portes verrouillées -->
            <div
                v-for="(d, i) in doors"
                :key="`door-${i}`"
                class="door-holder"
                :style="{ gridColumn: d.x + 1, gridRow: d.y + 1 }"
            >
                <div v-if="d.cadenas" class="door-lock" :class="d.etat" :title="d.titre">
                    <MSym n="lock" fill />
                </div>
            </div>
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
        </TransitionGroup>
    </div>
</template>

<style scoped>
/* Animation de déplacement des figurines (E4) : FLIP sur le changement de case
   — la figurine glisse vers sa nouvelle case au lieu de se téléporter (héros ET
   monstres). Un monstre vaincu (retiré de la liste) FOND au lieu de disparaître.
   Les cases/pièges/portes ne bougent jamais → aucune transition sur elles. */
.figmv-move {
    transition: transform 0.32s cubic-bezier(0.22, 1, 0.36, 1);
}
.figmv-leave-active {
    /* la figurine garde sa case (placement de grille explicite → les autres ne
       se décalent pas) et se contente de fondre sur place. */
    transition: opacity 0.28s ease, transform 0.28s ease;
}
.figmv-leave-to {
    opacity: 0;
    transform: scale(0.5);
}
.figmv-enter-active {
    transition: opacity 0.3s ease;
}
.figmv-enter-from {
    opacity: 0;
}
</style>

<style scoped>
/* Couche portes : cadenas discret centré sur une porte verrouillée. */
.door-holder {
    display: grid;
    place-items: center;
    pointer-events: none;
    z-index: 2;
}
.door-lock {
    display: grid;
    place-items: center;
    color: #e8c87a;
    filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.8));
    font-size: 0.8em;
}
</style>
