<script setup>
import MSym from '../ui/MSym.vue';

defineProps({
    /** { C, R, cells: [{ x, y, t, range }] } — voir buildTableMap(). */
    map: { type: Object, required: true },
    /** Figurines : [{ x, y, k: 'hero'|'foe'|'chest', l, ic, hp?, cur?, tgt? }] */
    entities: { type: Array, required: true },
    /** Pièges visibles : [{ x, y, etat: 'detecte'|'desarme'|'declenche', nom, titre }]
     *  — voir piegesVersMarqueurs() (les cachés n'arrivent jamais au client). */
    traps: { type: Array, default: () => [] },
});
</script>

<template>
    <div class="map">
        <div
            v-for="cell in map.cells"
            :key="`${cell.x}-${cell.y}`"
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
        <div
            v-for="(e, i) in entities"
            :key="`ent-${i}`"
            class="ent-holder"
            :style="{ gridColumn: e.x + 1, gridRow: e.y + 1 }"
        >
            <div class="fig" :class="[e.k, { cur: e.cur, tgt: e.tgt }]">
                <MSym v-if="e.ic" :n="e.ic" fill />
                <template v-else>{{ e.l }}</template>
                <div v-if="e.hp" class="hp">
                    <i v-for="p in e.hp" :key="p" />
                </div>
            </div>
        </div>
    </div>
</template>
