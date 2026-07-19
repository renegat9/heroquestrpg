<script setup>
// Feuille de DÉPLACEMENT (manette) : montre l'allonce du tour (dé déjà lancé
// côté serveur : base + 1d6) et une mini-carte tappable. Le joueur touche une
// case accessible (atteignable orthogonalement, ≤ portée, sans mur ni figurine)
// → on émet la destination. Le moteur revalide à la résolution.
import { computed, onMounted, ref } from 'vue';
import MSym from '../ui/MSym.vue';

const props = defineProps({
    carte: { type: Object, required: true },   // { largeur, hauteur, cases }
    entites: { type: Array, default: () => [] }, // [{type, id, x, y, ...}]
    depart: { type: Object, required: true },    // { x, y } du héros
    portee: { type: Number, required: true },
    de: { type: [Number, null], default: null },
    base: { type: Number, default: 0 },
});
const emit = defineEmits(['deplacer', 'close']);

const grilleRef = ref(null);
const cle = (x, y) => `${x},${y}`;

// Cases occupées par une AUTRE figurine (le héros sur sa case de départ ne bloque pas).
const occupees = computed(() => {
    const s = new Set();
    for (const e of props.entites) {
        if (e.x === props.depart.x && e.y === props.depart.y) continue;
        s.add(cle(e.x, e.y));
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
            const c = cases?.[ny]?.[nx];
            if (c !== 's' && c !== 'p') continue;
            if (occupees.value.has(k)) continue;
            dist[k] = d + 1;
            out.add(k);
            file.push({ x: nx, y: ny });
        }
    }
    return out;
});

function classeCase(x, y) {
    const c = props.carte.cases?.[y]?.[x];
    if (x === props.depart.x && y === props.depart.y) return 'depart';
    if (occupees.value.has(cle(x, y))) {
        const ent = props.entites.find((e) => e.x === x && e.y === y);
        return ent?.type === 'monstre' ? 'monstre' : 'allie';
    }
    if (c !== 's' && c !== 'p') return 'mur';
    return accessibles.value.has(cle(x, y)) ? 'accessible' : 'sol';
}

function toucher(x, y) {
    if (accessibles.value.has(cle(x, y))) emit('deplacer', { x, y });
}

const lignes = computed(() => Array.from({ length: props.carte.hauteur }, (_, y) => y));
const colonnes = computed(() => Array.from({ length: props.carte.largeur }, (_, x) => x));

onMounted(() => {
    // Centre la vue sur le héros.
    const el = grilleRef.value?.querySelector('.dep-cell.depart');
    el?.scrollIntoView({ block: 'center', inline: 'center', behavior: 'instant' });
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
                <button class="dep-close" type="button" @click="$emit('close')"><MSym n="close" /></button>
            </header>

            <p v-if="accessibles.size" class="dep-hint"><MSym n="touch_app" :size="14" /> Touche une case éclairée pour t'y déplacer</p>
            <p v-else class="dep-hint dep-hint-bloque"><MSym n="block" :size="14" /> Aucune case accessible — tu es bloqué. Ferme et termine ton tour.</p>

            <div ref="grilleRef" class="dep-scroll">
                <div class="dep-grid" :style="{ gridTemplateColumns: `repeat(${carte.largeur}, 22px)` }">
                    <template v-for="y in lignes" :key="y">
                        <div
                            v-for="x in colonnes"
                            :key="`${x}-${y}`"
                            class="dep-cell"
                            :class="classeCase(x, y)"
                            @click="toucher(x, y)"
                        >
                            <MSym v-if="classeCase(x, y) === 'depart'" n="person" :size="14" fill />
                            <MSym v-else-if="classeCase(x, y) === 'monstre'" n="pets" :size="13" fill />
                        </div>
                    </template>
                </div>
            </div>

            <!-- Fermeture toujours atteignable au bas de la feuille (le bouton du
                 header peut être hors de vue sur petit écran / longue grille). -->
            <button class="dep-fermer" type="button" @click="$emit('close')">
                <MSym n="close" :size="16" /> Fermer
            </button>
        </div>
    </div>
</template>

<style>
.dep-ov { position: fixed; inset: 0; z-index: 70; display: grid; place-items: end center;
  background: oklch(0.12 0.02 60 / 0.6); backdrop-filter: blur(3px); }
.dep-sheet { width: 100%; max-width: 520px; max-height: 82vh; display: flex; flex-direction: column;
  background: var(--stone-900); border-top-left-radius: 18px; border-top-right-radius: 18px;
  border: var(--line); border-bottom: none; padding: 14px 14px 20px; box-shadow: var(--sh-3); }

.dep-head { display: flex; align-items: center; gap: 12px; }
.dep-roll { display: inline-flex; align-items: baseline; gap: 6px; color: var(--torch); font-weight: 800; }
.dep-roll .msym { font-size: 26px; align-self: center; }
.dep-portee { font-size: 26px; font-family: var(--font-display); }
.dep-portee-lbl { font-size: 12px; color: var(--ink-400); font-weight: 700; }
.dep-detail { font-size: 13px; color: var(--ink-400); font-weight: 700; }
.dep-detail span { color: var(--ink-600); }
.dep-close { margin-left: auto; display: grid; place-items: center; width: 34px; height: 34px;
  border-radius: 999px; border: var(--line); background: var(--stone-850); color: var(--ink-300); cursor: pointer; }

.dep-hint { font-size: 12.5px; color: var(--ink-400); display: flex; align-items: center; gap: 6px; margin: 8px 0 10px; }
.dep-hint .msym { color: var(--torch); }
.dep-hint-bloque { color: var(--danger, #e66); }
.dep-hint-bloque .msym { color: var(--danger, #e66); }
.dep-fermer { margin-top: 12px; flex: none; width: 100%; padding: 11px; border-radius: 11px; border: var(--line);
  background: var(--stone-850); color: var(--ink-200, #e7dcc6); font-weight: 700; font-size: 14px; cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: 6px; }

/* `safe center` : la grille est CENTRÉE quand elle tient dans la vue, mais
   revient au bord (start) quand elle DÉPASSE — sinon `margin: 0 auto` rendait la
   partie droite du donjon inatteignable au scroll en portrait (correctif B1). */
.dep-scroll { overflow: auto; flex: 1; border-radius: var(--r-md); background: var(--stone-950); padding: 8px;
  display: flex; justify-content: safe center; align-items: safe center; }
.dep-grid { display: grid; gap: 2px; width: max-content; margin: 0; flex: none; }
.dep-cell { width: 22px; height: 22px; border-radius: 3px; display: grid; place-items: center; }
.dep-cell.mur { background: transparent; }
.dep-cell.sol { background: var(--stone-800); }
.dep-cell.allie { background: oklch(0.55 0.14 260 / 0.5); }
.dep-cell.monstre { background: oklch(0.55 0.16 25 / 0.45); color: var(--danger, #e66); }
.dep-cell.depart { background: linear-gradient(150deg, var(--ember), var(--ember-deep)); color: var(--parch-100); }
.dep-cell.accessible { background: oklch(0.6 0.15 145 / 0.32); cursor: pointer; outline: 1px solid oklch(0.6 0.15 145 / 0.5); }
.dep-cell.accessible:hover { background: oklch(0.6 0.15 145 / 0.55); }
</style>
