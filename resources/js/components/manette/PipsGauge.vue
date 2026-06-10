<script setup>
// Jauge de pips Body / Mind (composant Pips de manette-app.jsx).
defineProps({
    cur: { type: Number, required: true },
    max: { type: Number, required: true },
    /** 'body' | 'mind' */
    kind: { type: String, required: true },
});
</script>

<template>
    <div class="pips">
        <div
            v-for="i in max"
            :key="i"
            class="pip"
            :class="i <= cur ? (kind === 'body' ? 'full-body' : 'full-mind') : ''"
        />
    </div>
</template>
