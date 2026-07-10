<script setup>
import MSym from '../ui/MSym.vue';

defineProps({
    text: { type: String, required: true },
    /** Affiche l'égaliseur TTS animé. */
    speaking: { type: Boolean, default: true },
});
</script>

<template>
    <div class="narr">
        <div class="av"><MSym n="menu_book" fill /></div>
        <div class="nbody">
            <div class="who">LE MAÎTRE DE JEU</div>
            <p>{{ text }}</p>
        </div>
        <div v-if="speaking" class="tts"><i /><i /><i /><i /><i /></div>
    </div>
</template>
