<script setup>
import MSym from '../ui/MSym.vue';

defineProps({
    /** [{ l, cur?, foe?, id, type }] */
    order: { type: Array, required: true },
});

// Cliquer un jeton d'initiative ouvre la fiche de stats de la figure (C3).
const emit = defineEmits(['inspecter']);
</script>

<template>
    <div class="init">
        <span class="ttl">Initiative</span>
        <template v-for="(o, i) in order" :key="i">
            <button
                type="button"
                class="tok"
                :class="{ cur: o.cur, foe: o.foe }"
                :title="`Voir les stats de ${o.l}`"
                @click="emit('inspecter', o)"
            >{{ o.l }}</button>
            <MSym v-if="i < order.length - 1" n="chevron_right" class="arrow" />
        </template>
    </div>
</template>

<style scoped>
/* Le jeton devient un bouton : neutraliser le style natif, garder l'apparence
   fournie par les règles globales `.table-screen .init .tok`. */
.init .tok {
    font: inherit;
    cursor: pointer;
    appearance: none;
    -webkit-appearance: none;
}
.init .tok:hover {
    filter: brightness(1.15);
}
</style>
