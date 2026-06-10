<script setup>
import MSym from '../ui/MSym.vue';

defineProps({
    /** [{ l: 'ELF', cur?: true, foe?: true }, …] */
    order: { type: Array, required: true },
});
</script>

<template>
    <div class="init">
        <span class="ttl">Initiative</span>
        <template v-for="(o, i) in order" :key="i">
            <div class="tok" :class="{ cur: o.cur, foe: o.foe }">{{ o.l }}</div>
            <MSym v-if="i < order.length - 1" n="chevron_right" class="arrow" />
        </template>
    </div>
</template>
