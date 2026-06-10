<script setup>
// Feuille de vote de groupe (kick, TPK, abandon…) — port de VoteSheet (manette-app.jsx).
import { computed } from 'vue';
import MSym from '../ui/MSym.vue';

const props = defineProps({
    /** { q, opts: [{ k, l, c }], mine, missing } */
    vote: { type: Object, required: true },
});

const emit = defineEmits(['cast', 'close']);

const total = computed(() => props.vote.opts.reduce((s, o) => s + o.c, 0) || 1);
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
                :class="{ choose: vote.mine == null }"
                @click="vote.mine == null && emit('cast', o.k)"
            >
                <div class="vote-bar">
                    <div class="fillb" :style="{ width: (o.c / total) * 100 + '%' }" />
                    <span class="vtxt">{{ o.l }}{{ vote.mine === o.k ? ' · toi' : '' }}</span>
                </div>
                <span class="ct">{{ o.c }}</span>
            </div>
            <div v-if="vote.missing > 0" class="waiting">
                <MSym n="hourglass_top" :size="16" /> En attente de {{ vote.missing }} joueur{{ vote.missing > 1 ? 's' : '' }}…
            </div>
            <button v-else class="btn btn-torch btn-block" style="margin-top: 10px" @click="emit('close')">
                <MSym n="check" /> Décision prise — Recharger
            </button>
            <p v-if="vote.mine == null" style="font-size: 11.5px; color: var(--ink-700); text-align: center; margin-top: 10px">
                Touche une option pour voter
            </p>
        </div>
    </div>
</template>
