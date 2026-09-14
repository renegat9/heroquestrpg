<script setup>
/**
 * Bandeau de narration du maître du jeu, au bas de l'écran de table.
 *
 * ⚠ REPLIABLE depuis le 2026-09-14 (René : « peut-on avoir un bouton pour
 * cacher la boîte en bas de page du maître du jeu, elle prend parfois beaucoup
 * de place »). Un récit de salle fait couramment quatre à cinq lignes et mange
 * le tiers bas de l'écran, carte comprise.
 *
 * ⚠ Et il REVIENT TOUT SEUL au texte suivant. Un repli qui survivrait à la
 * narration suivante ferait taire le maître du jeu sans que personne ne s'en
 * souvienne — c'est la même famille de défaut que la carte d'ouverture invisible
 * ou que le brouillard refermé par une clé de cache expirée : un état qui
 * persiste au-delà de ce qu'il devait couvrir. Le repli vaut donc pour CE
 * texte-ci, pas pour l'écran.
 */
import { ref, watch } from 'vue';
import MSym from '../ui/MSym.vue';

const props = defineProps({
    text: { type: String, required: true },
    /** Affiche l'égaliseur TTS animé. */
    speaking: { type: Boolean, default: true },
});

const replie = ref(false);

// Nouveau texte = nouveau message : on redéploie, quoi qu'ait fait le narrateur
// sur le précédent.
watch(() => props.text, () => { replie.value = false; });
</script>

<template>
    <div class="narr" :class="{ 'narr-replie': replie }">
        <div class="av"><MSym n="menu_book" fill /></div>

        <div v-if="!replie" class="nbody">
            <div class="who">LE MAÎTRE DE JEU</div>
            <p>{{ text }}</p>
        </div>
        <div v-else class="nbody narr-resume">
            <div class="who">LE MAÎTRE DE JEU</div>
            <p>{{ text }}</p>
        </div>

        <div v-if="speaking && !replie" class="tts"><i /><i /><i /><i /><i /></div>

        <button
            class="narr-plier"
            type="button"
            :title="replie ? 'Déplier la narration' : 'Replier la narration'"
            :aria-expanded="!replie"
            @click="replie = !replie"
        >
            <MSym :n="replie ? 'unfold_more' : 'unfold_less'" :size="20" />
        </button>
    </div>
</template>

<style>
/* ⚠ Classes préfixées `narr-` : les blocs <style> des SFC sont GLOBAUX ici, une
   classe générique fuit d'une vue à l'autre. */
.narr-plier {
    flex: none; align-self: flex-start;
    width: 34px; height: 34px; border-radius: 9px;
    display: grid; place-items: center; cursor: pointer;
    background: transparent; border: var(--line); color: var(--ink-500);
    transition: color .15s, border-color .15s, background .15s;
}
.narr-plier:hover { color: var(--parch-100); border-color: var(--gold); background: var(--stone-850); }
.narr-plier:focus-visible { outline: 2px solid var(--torch); outline-offset: 2px; }

/* Replié : une seule ligne tronquée, et un sceau réduit — on garde de quoi
   reconnaître le texte sans lui laisser le tiers de l'écran.
   ⚠ Sélecteurs à la MÊME spécificité que `.table-screen .narr` (0,2,0) :
   écrits en `.narr-replie` seul (0,1,0) ils perdaient, et la bande gardait
   exactement la même hauteur — le repli ne repliait rien. C'est la collision
   que les règles du front signalent : ici les blocs <style> sont globaux. */
.table-screen .narr.narr-replie { padding: 7px 22px; gap: 12px; }
.table-screen .narr.narr-replie .av { width: 30px; height: 30px; }
.table-screen .narr.narr-replie .av .msym { font-size: 17px; }
.table-screen .narr.narr-replie .who { display: none; }
.table-screen .narr.narr-replie p {
    margin: 0; font-size: 15px; opacity: .6;
    display: -webkit-box; -webkit-line-clamp: 1; line-clamp: 1;
    -webkit-box-orient: vertical; overflow: hidden;
}
</style>
