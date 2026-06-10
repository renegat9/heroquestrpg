<script setup>
// Feuille de vote de groupe (kick, TPK, abandon…) — port de VoteSheet (manette-app.jsx).
// Mode connecté (voteVersFeuille) : `spectateur` = cible d'un retrait_joueur
// (lecture seule, « le groupe délibère ») ; `done` + `closeLabel` = résultat
// reçu (.vote.resultat), l'option gagnante est marquée.
import { computed } from 'vue';
import MSym from '../ui/MSym.vue';

const props = defineProps({
    /** { q, opts: [{ k, l, c, gagnant? }], mine, missing, spectateur?, done?, closeLabel? } */
    vote: { type: Object, required: true },
});

const emit = defineEmits(['cast', 'close']);

const total = computed(() => props.vote.opts.reduce((s, o) => s + o.c, 0) || 1);
const peutVoter = computed(() => props.vote.mine == null && !props.vote.spectateur && !props.vote.done);
</script>

<template>
    <div class="overlay">
        <div class="sheet">
            <div class="grip" />
            <h3><MSym n="how_to_vote" :size="20" style="color: var(--torch); margin-right: 6px" />Vote du groupe</h3>
            <p class="sh-sub">{{ vote.q }}</p>
            <div
                v-for="o in vote.opts"
                :key="o.k"
                class="vote-opt"
                :class="{ choose: peutVoter }"
                @click="peutVoter && emit('cast', o.k)"
            >
                <div class="vote-bar">
                    <div class="fillb" :style="{ width: (o.c / total) * 100 + '%' }" />
                    <span class="vtxt">{{ o.l }}{{ vote.mine === o.k ? ' · toi' : '' }}{{ o.gagnant ? ' · ✓' : '' }}</span>
                </div>
                <span class="ct">{{ o.c }}</span>
            </div>
            <div v-if="vote.spectateur && !vote.done" class="waiting">
                <MSym n="gavel" :size="16" /> Le groupe délibère — tu ne participes pas à ce vote.
            </div>
            <div v-else-if="vote.missing > 0 && !vote.done" class="waiting">
                <MSym n="hourglass_top" :size="16" /> En attente de {{ vote.missing }} joueur{{ vote.missing > 1 ? 's' : '' }}…
            </div>
            <button v-else class="btn btn-torch btn-block" style="margin-top: 10px" @click="emit('close')">
                <MSym n="check" /> {{ vote.closeLabel ?? 'Décision prise — Recharger' }}
            </button>
            <p v-if="peutVoter" style="font-size: 11.5px; color: var(--ink-700); text-align: center; margin-top: 10px">
                Touche une option pour voter
            </p>
        </div>
    </div>
</template>
