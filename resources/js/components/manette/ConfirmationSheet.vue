<script setup>
// Feuille de CONFIRMATION (manette) : une question à deux réponses, posée AVANT un geste
// que le serveur annonce comme coûteux (`perd_attaque_supplementaire` sur une option de
// mouvement — décision de René, 2026-10-22). Même patron que la confirmation « tir ami »
// de CibleSheet : un bandeau d'avertissement, un bouton de confirmation (danger) et un
// bouton d'annulation.
//
// Rien n'est envoyé ici. « Oui » rend la main au parent, qui reprend le geste ; « Non »
// (ou taper le fond) le laisse tomber, et le menu reste tel quel. Les libellés sont de
// la présentation ; la DÉCISION (le geste fera perdre une frappe) vient du serveur.
import MSym from '../ui/MSym.vue';

defineProps({
    /** { titre, texte, oui, non } — la question, ses deux libellés. */
    question: { type: Object, required: true },
});

const emit = defineEmits(['oui', 'non']);

function onOverlayClick(e) {
    if (e.target.classList.contains('overlay')) emit('non');
}
</script>

<template>
    <div class="overlay" @click="onOverlayClick">
        <div class="sheet">
            <div class="grip" />
            <h3><MSym n="warning" fill :size="20" style="color: var(--danger); vertical-align: -3px" /> {{ question.titre }}</h3>
            <div class="confirm-sheet-warn">
                <MSym n="warning" fill :size="22" />
                <span>{{ question.texte }}</span>
            </div>
            <button class="btn btn-danger btn-block confirm-sheet-oui" style="margin-top: 14px" @click="emit('oui')">
                {{ question.oui }}
            </button>
            <button class="btn btn-ghost btn-block confirm-sheet-non" style="margin-top: 8px" @click="emit('non')">
                {{ question.non }}
            </button>
        </div>
    </div>
</template>

<style>
/* Non scopé, comme les autres feuilles de la manette : classes préfixées `confirm-sheet-`
   pour ne rien heurter (le bandeau de CibleSheet, `.ami-warn`, est une autre classe). */
.sheet .confirm-sheet-warn { display: flex; align-items: center; gap: 10px; padding: 12px 14px; border-radius: var(--r-md);
  font-size: 13px; color: var(--parch-100); background: oklch(0.6 0.2 25 / 0.14); border: 1px solid oklch(0.6 0.2 25 / 0.5); }
.sheet .confirm-sheet-warn .msym { color: var(--danger); flex: none; }
</style>
