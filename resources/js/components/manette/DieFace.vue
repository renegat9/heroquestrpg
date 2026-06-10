<script setup>
// Dé de combat (crâne / bouclier / vide) — port du composant Die (manette-app.jsx).
import { computed } from 'vue';
import MSym from '../ui/MSym.vue';

const props = defineProps({
    /** 'skull' | 'shield' | 'blank' */
    face: { type: String, default: 'blank' },
    rolling: { type: Boolean, default: false },
    reveal: { type: Boolean, default: false },
});

const cls = computed(() => (props.face === 'skull' ? 'skull' : props.face === 'shield' ? 'shield' : 'blank'));
const icon = computed(() => (props.face === 'skull' ? 'skull' : props.face === 'shield' ? 'shield' : 'remove'));
</script>

<template>
    <div class="die" :class="[cls, { rolling, reveal }]">
        <MSym :n="rolling ? 'casino' : icon" :fill="!rolling" />
    </div>
</template>
