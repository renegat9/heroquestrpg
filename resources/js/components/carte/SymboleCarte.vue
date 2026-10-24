<script setup>
// SYMBOLE D'UNE CASE — le PICTOGRAMME officiel du livret (`symbole_url`, publié par le serveur), ou, à défaut, l'icône
// Material Symbols que `symboles.js` déclare pour la famille. Un seul composant pour la carte (table et manette), la légende
// et l'aperçu de salle : une seconde règle « prendre l'image quand elle est là » écrite à trois endroits finirait par diverger.
//
// ⚠ Le client ne compose AUCUNE URL : il dessine ce que le serveur publie. `null` = pas de symbole, l'icône d'aujourd'hui.
// ⚠ Une image qui casse (fichier retiré après publication) retombe sur l'icône, comme `Vignette`.
// ⚠ Un pictogramme n'est PAS une illustration : il se pose sur la carte, l'illustration reste dans la légende.
// ⚠ Une IMAGE NE FAIT JAMAIS LA TAILLE D'UNE CASE NI D'UNE RANGÉE (correctifs symboles, 2026-10-24). Elle est posée en
// absolu dans le conteneur de sa famille, qui doit être positionné et `overflow: hidden`. Dans le flux, sa taille intrinsèque
// poussait la piste de grille qui la portait : une rangée de la table passait de 73 à 119 px sous une plaque de Sable.
// ⚠ La ROTATION n'est pas décidée ici : `tourne` est le `symbole_rotation` publié par le serveur. Le composant l'applique,
// avec les dimensions que l'emprise posée (`l` × `h`) impose au dessin.
import { computed, ref, watch } from 'vue';
import MSym from '../ui/MSym.vue';

const props = defineProps({
    /** `symbole_url` publié par le serveur : une URL, ou `null` (pas de symbole : l'icône). */
    url: { type: String, default: null },
    /** L'icône Material Symbols de repli, celle que `symboles.js` déclare pour la famille. */
    icone: { type: String, required: true },
    /** Le nom de l'élément, pour l'attribut `alt`. */
    nom: { type: String, default: '' },
    /** `symbole_rotation` publié par le serveur : `90` quand le meuble est posé couché alors que son dessin est debout. */
    tourne: { type: Number, default: 0 },
    /** L'emprise posée, en cases : le dessin tourné se dimensionne sur elle (en pourcentages du conteneur). */
    l: { type: Number, default: 1 },
    h: { type: Number, default: 1 },
});

const erreur = ref(false);
watch(() => props.url, () => { erreur.value = false; });

// Dessin tourné de 90° : avant rotation, il mesure en LARGEUR 80 % de la HAUTEUR de l'emprise et en HAUTEUR 80 % de sa
// LARGEUR. Une fois tourné, il occupe 80 % de chaque côté, au bon rapport : un dessin 1×3 tourné remplit une emprise 3×1.
// Les pourcentages se simplifient (la taille d'une case disparaît), donc le résultat ne dépend pas de la taille de la carte.
const styleTourne = computed(() => {
    if (props.tourne !== 90) return null;

    const l = Math.max(1, props.l);
    const h = Math.max(1, props.h);

    return {
        width: `${(80 * h) / l}%`,
        height: `${(80 * l) / h}%`,
        transform: 'translate(-50%, -50%) rotate(90deg)',
    };
});
</script>

<template>
    <img
        v-if="url && !erreur"
        class="sym-livret"
        :style="styleTourne"
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
   le léger ombrage ci-dessous fait le même travail que celui des icônes.

   ⚠ Posée EN ABSOLU, centrée (`left`/`top` à 50 %, `translate`) : elle ne compte dans AUCUNE piste de grille, et
   `overflow: hidden` de son conteneur coupe ce qui déborderait. Pas de `max-width` : un dessin tourné a, avant rotation,
   une boîte qui dépasse celle de sa silhouette dans un axe — c'est le calcul de `styleTourne` qui le remet à sa place. */
.sym-livret {
    position: absolute;
    left: 50%;
    top: 50%;
    width: 80%;
    height: 80%;
    min-width: 0;
    min-height: 0;
    transform: translate(-50%, -50%);
    object-fit: contain;
    pointer-events: none;
    user-select: none;
    -webkit-user-drag: none;
    filter: drop-shadow(0 1px 2px oklch(0 0 0 / 0.6));
}
</style>
