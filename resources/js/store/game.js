/* =========================================================================
   Store minimaliste (reactive) partagé entre les écrans.
   Volontairement simple : c'est le réceptacle des événements Echo / des
   réponses API quand ils seront branchés (un seul endroit à mettre à jour).
   ========================================================================= */

import { reactive, readonly } from 'vue';

const state = reactive({
    /** Code du groupe courant (param de route). */
    groupe: null,
    /** Texte de narration courant du MJ. */
    narration: "Une lueur d'ambre danse sur les murs suintants. Trois ombres trapues se redressent en grognant…",
    /** « Le MJ réfléchit… » (job IA en cours, indicateur non bloquant). */
    mjReflechit: false,
    /** État de connexion temps réel : 'ok' | 'warn'. */
    connexion: 'ok',
});

export function useGameStore() {
    return {
        state: readonly(state),

        setGroupe(groupe) {
            state.groupe = groupe;
        },
        setNarration(texte) {
            state.narration = texte;
        },
        setMjReflechit(actif) {
            state.mjReflechit = actif;
        },
        setConnexion(statut) {
            state.connexion = statut;
        },
    };
}
