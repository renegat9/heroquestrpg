<script setup>
// Une TABLE de cases indexée par un dé (lots K5n/K5o du constructeur) : `destinations_d6` d'un effet `teleporter` (faces
// 1 à 6) et `regles.portes_teleportation` (sommes 2 à 12). Chaque face a sa case, saisie ou pointée sur la carte. La table
// est créée PLEINE (le serveur exige toutes les faces : `cles()`), à partir de la case d'origine.
import MSym from '../ui/MSym.vue';

const props = defineProps({
    table: { type: Object, required: true },
    faces: { type: Array, required: true },
    libelle: { type: String, default: 'face' },
    pointer: { type: Function, required: true },
});
</script>

<template>
    <div class="tdc">
        <div v-for="f in faces" :key="f" class="tdc-ligne">
            <span class="tdc-face">{{ libelle }} {{ f }}</span>
            <input v-model.number="props.table[f].x" type="number" class="cst-input cst-num" title="x" />
            <input v-model.number="props.table[f].y" type="number" class="cst-input cst-num" title="y" />
            <button type="button" class="cst-icone" title="Choisir sur la carte" @click="pointer(props.table[f], `${libelle} ${f}`)"><MSym n="pin_drop" :size="16" /></button>
        </div>
    </div>
</template>

<style scoped>
.tdc { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 4px 12px; }
.tdc-ligne { display: flex; align-items: center; gap: 6px; }
.tdc-face { font-size: 12px; color: var(--ink-300); min-width: 62px; }
</style>
