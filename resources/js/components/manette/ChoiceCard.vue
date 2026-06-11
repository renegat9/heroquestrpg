<script setup>
// Carte de choix tactile (composant Choice de manette-app.jsx).
import MSym from '../ui/MSym.vue';

defineProps({
    icon: { type: String, required: true },
    title: { type: String, required: true },
    meta: { type: String, default: '' },
    /** Petit badge inline après le titre (ex. type de sort du contrat). */
    badge: { type: String, default: '' },
    sel: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    danger: { type: Boolean, default: false },
    elClass: { type: String, default: '' },
    chev: { type: Boolean, default: true },
});

const emit = defineEmits(['click']);
</script>

<template>
    <button
        class="choice"
        :class="[{ sel, disabled, danger }, elClass]"
        @click="!disabled && emit('click')"
    >
        <span class="ic"><MSym :n="icon" /></span>
        <span style="flex: 1">
            <span class="ttl">{{ title }}<span v-if="badge" class="badge">{{ badge }}</span></span>
            <span v-if="meta" class="meta">{{ meta }}</span>
        </span>
        <MSym v-if="chev" n="chevron_right" />
    </button>
</template>
