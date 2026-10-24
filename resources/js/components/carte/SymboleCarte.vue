<script setup>
// SYMBOLE D'UNE CASE — le PICTOGRAMME officiel du livret (`symbole_url`, publié par le serveur), ou, à défaut, l'icône
// Material Symbols que `symboles.js` déclare pour la famille. Un seul composant pour la carte (table et manette), la légende
// et l'aperçu de salle : une seconde règle « prendre l'image quand elle est là » écrite à trois endroits finirait par diverger.
//
// ⚠ Le client ne compose AUCUNE URL : il dessine ce que le serveur publie. `null` = pas de symbole, l'icône d'aujourd'hui.
// ⚠ Une image qui casse (fichier retiré après publication) retombe sur l'icône, comme `Vignette`.
// ⚠ Un pictogramme n'est PAS une illustration : il se pose sur la carte, l'illustration reste dans la légende.
import { ref, watch } from 'vue';
import MSym from '../ui/MSym.vue';

const props = defineProps({
    /** `symbole_url` publié par le serveur : une URL, ou `null` (pas de symbole : l'icône). */
    url: { type: String, default: null },
    /** L'icône Material Symbols de repli, celle que `symboles.js` déclare pour la famille. */
    icone: { type: String, required: true },
    /** Le nom de l'élément, pour l'attribut `alt`. */
    nom: { type: String, default: '' },
});

const erreur = ref(false);
watch(() => props.url, () => { erreur.value = false; });
</script>

<template>
    <img
        v-if="url && !erreur"
        class="sym-livret"
        :src="url"
        :alt="nom"
        draggable="false"
        @error="erreur = true"
    />
    <MSym v-else :n="icone" fill />
</template>

<style scoped>
/* Le pictogramme occupe ~80 % de la silhouette qui le porte (une case, une emprise, un octogone) et garde son rapport
   d'aspect : `contain`, jamais `cover` — un meuble 3 × 1 ne doit pas être rogné. Aucun pointeur : le toucher de la
   manette tombe sur la case, jamais sur le dessin. Le dessin du livret est déjà recoloré en clair avec un liseré sombre ;
   le léger ombrage ci-dessous fait le même travail que celui des icônes. */
.sym-livret {
    display: block;
    width: 80%;
    height: 80%;
    object-fit: contain;
    pointer-events: none;
    user-select: none;
    -webkit-user-drag: none;
    filter: drop-shadow(0 1px 2px oklch(0 0 0 / 0.6));
}
</style>
