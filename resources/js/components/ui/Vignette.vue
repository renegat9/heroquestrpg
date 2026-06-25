<script setup>
// Vignette — affiche une IMAGE si `src` est fournie, sinon l'icône `icon`
// (Material Symbols). Repli systématique sur l'icône quand l'asset n'a pas
// été généré (jeu jouable sans images). L'image remplit son conteneur
// (médaillon/jeton) en `object-fit: cover` et hérite de son border-radius.
import { ref, watch } from 'vue';
import MSym from './MSym.vue';

const props = defineProps({
    src: { type: String, default: null },
    icon: { type: String, default: 'help' },
    size: { type: Number, default: 24 },
    fill: { type: Boolean, default: false },
    alt: { type: String, default: '' },
});

// Si l'image casse (404, asset purgé), on retombe sur l'icône.
const erreur = ref(false);
watch(() => props.src, () => { erreur.value = false; });
</script>

<template>
    <img
        v-if="src && !erreur"
        :src="src"
        :alt="alt"
        class="vignette-img"
        loading="lazy"
        @error="erreur = true"
    />
    <MSym v-else :n="icon" :size="size" :fill="fill" />
</template>

<style>
.vignette-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    border-radius: inherit;
}
</style>
