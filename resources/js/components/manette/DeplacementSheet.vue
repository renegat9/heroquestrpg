<script setup>
// Feuille de DÉPLACEMENT (manette) : montre l'allonce du tour (dé déjà lancé
// côté serveur : base + 1d6) et une mini-carte tappable. Le TERRAIN (cases /
// portes / pièges) est rendu par le socle PARTAGÉ DungeonGrid — le MÊME que
// l'écran table — pour un rendu identique ; cette feuille n'ajoute que la
// surbrillance des cases accessibles (BFS) et le tap de destination.
import { computed, onMounted, ref } from 'vue';
import DungeonGrid from '../carte/DungeonGrid.vue';
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
            if (cases?.[ny]?.[nx] !== 's') continue;      // mur / brouillard 'b' = infranchissable
            if (porteFermeeEntre(x, y, nx, ny)) continue;  // on ne traverse pas une porte fermée
            if (occupees.value.has(k)) continue;
            dist[k] = d + 1;
            out.add(k);
            file.push({ x: nx, y: ny });
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

const gridStyle = computed(() => ({
    gap: '2px',
    width: 'max-content',
    gridTemplateColumns: `repeat(${props.carte.largeur}, 22px)`,
    gridTemplateRows: `repeat(${props.carte.hauteur}, 22px)`,
}));

onMounted(() => {
    // Centre la vue sur le héros.
    grilleRef.value?.querySelector('.dg-cell.depart')
        ?.scrollIntoView({ block: 'center', inline: 'center', behavior: 'instant' });
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
                <DungeonGrid :carte="carte" :traps="carte.pieges ?? []" :cell-class="surcouche" :grid-style="gridStyle" @cell="toucher">
                    <template #cell="{ x, y }">
                        <MSym v-if="surcouche(x, y) === 'depart'" n="person" :size="14" fill />
                        <MSym v-else-if="surcouche(x, y) === 'monstre'" n="pets" :size="13" fill />
                    </template>
                </DungeonGrid>
            </div>

            <!-- Fermeture toujours atteignable au bas de la feuille. -->
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
   revient au bord quand elle DÉPASSE (scroll jusqu'à la salle la plus à droite). */
.dep-scroll { overflow: auto; flex: 1; border-radius: var(--r-md); background: var(--stone-950); padding: 8px;
  display: flex; justify-content: safe center; align-items: safe center; }
/* Départ/occupants : centrer l'icône dans la case (DungeonGrid gère le reste). */
.dep-scroll .dg-cell { display: grid; place-items: center; }
</style>
