<script setup>
import MSym from '../ui/MSym.vue';
import Vignette from '../ui/Vignette.vue';

defineProps({
    /** [{ l, nom, img, cur?, foe?, ally?, id, type }] */
    order: { type: Array, required: true },
});

// Cliquer un jeton d'initiative ouvre la fiche de stats de la figure (C3).
const emit = defineEmits(['inspecter']);
</script>

<template>
    <!-- Rien à afficher au hub : sans ce garde, le libellé « Initiative » restait
         seul à l'écran après un retour au hub (§2.20). -->
    <div v-if="order.length" class="init">
        <span class="ttl">Initiative</span>
        <template v-for="(o, i) in order" :key="i">
            <!-- Portrait + nom dessous (René, 2026-10-01). Le cercle garde la
                 couleur du camp (doré héros courant, rouge monstre, vert allié) ;
                 sans image, l'initiale de trois lettres reste en repli. -->
            <button
                type="button"
                class="init-unite"
                :class="{ cur: o.cur, foe: o.foe, ally: o.ally }"
                :title="`Voir les stats de ${o.nom}`"
                @click="emit('inspecter', o)"
            >
                <span class="tok">
                    <Vignette v-if="o.img" :src="o.img" :icon="o.foe ? 'skull' : 'person'" :alt="o.nom" />
                    <template v-else>{{ o.l }}</template>
                </span>
                <span class="init-nom">{{ o.nom }}</span>
            </button>
            <MSym v-if="i < order.length - 1" n="chevron_right" class="arrow" />
        </template>
    </div>
</template>

<style scoped>
/* Le jeton devient un bouton : neutraliser le style natif, garder l'apparence
   fournie par les règles globales `.table-screen .init .tok`. */
.init .init-unite {
    font: inherit;
    cursor: pointer;
    appearance: none;
    -webkit-appearance: none;
    background: none;
    border: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
}
.init .init-unite:hover .tok {
    filter: brightness(1.15);
}
</style>
