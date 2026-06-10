<script setup>
// Résolution de combat sur l'écran de table (port de Table.html).
// Démo locale : les jets sont simulés ; à terme le résultat exact arrive
// du moteur via le canal `groupe.{id}` (useEcho) et est seulement animé ici.
import { onUnmounted, reactive } from 'vue';
import MSym from '../ui/MSym.vue';

const emit = defineEmits(['narrate']);

const state = reactive({
    show: false,
    rolling: false,
    atk: [],
    def: [],
    verdict: '',
});

let timers = [];
const later = (fn, ms) => timers.push(setTimeout(fn, ms));
const clear = () => { timers.forEach(clearTimeout); timers = []; };
onUnmounted(clear);

function faceIcon(face) {
    return face === 'skull' ? 'skull' : face === 'shield' ? 'shield' : 'remove';
}

function play() {
    clear();
    state.show = true;
    state.rolling = true;
    state.verdict = '';
    const nAtk = 2;
    const nDef = 1;
    state.atk = Array.from({ length: nAtk }, () => null);
    state.def = Array.from({ length: nDef }, () => null);

    later(() => {
        state.rolling = false;
        state.atk = Array.from({ length: nAtk }, () => (Math.random() < 0.62 ? 'skull' : 'blank'));
        state.def = Array.from({ length: nDef }, () => (Math.random() < 0.34 ? 'shield' : 'blank'));
        const sk = state.atk.filter((d) => d === 'skull').length;
        const sh = state.def.filter((d) => d === 'shield').length;
        const dmg = Math.max(0, sk - sh);

        later(() => {
            state.verdict = dmg > 0
                ? `Touché — ${dmg} blessure${dmg > 1 ? 's' : ''} au Gobelin`
                : 'Le Gobelin pare le coup';
            emit('narrate', dmg > 0
                ? "La flèche de Sylanwë siffle et se fiche dans l'épaule du gobelin, qui titube en hurlant."
                : "Le gobelin lève son écu rouillé juste à temps ; la flèche ricoche dans l'ombre.");
        }, 420);
        later(() => { state.show = false; }, 3200);
    }, 900);
}

defineExpose({ play });
</script>

<template>
    <div class="combat" :class="{ show: state.show }">
        <div class="panel">
            <div class="ctitle"><b>Sylanwë</b> attaque le <span class="foe">Gobelin</span></div>
            <div class="dice-stage">
                <div class="dgrp">
                    <div class="gl">Attaque · crânes</div>
                    <div class="dice-row">
                        <div
                            v-for="(d, i) in state.atk"
                            :key="`a${i}`"
                            class="die"
                            :class="state.rolling ? 'rolling' : [d, 'reveal']"
                        >
                            <MSym :n="state.rolling ? 'casino' : faceIcon(d)" :fill="!state.rolling" />
                        </div>
                    </div>
                </div>
                <div class="vs">vs</div>
                <div class="dgrp">
                    <div class="gl">Défense · boucliers</div>
                    <div class="dice-row">
                        <div
                            v-for="(d, i) in state.def"
                            :key="`d${i}`"
                            class="die"
                            :class="state.rolling ? 'rolling' : [d, 'reveal']"
                        >
                            <MSym :n="state.rolling ? 'casino' : faceIcon(d)" :fill="!state.rolling" />
                        </div>
                    </div>
                </div>
            </div>
            <div class="verdict" :class="{ show: state.verdict }">{{ state.verdict }}</div>
        </div>
    </div>
</template>
