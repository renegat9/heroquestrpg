<script setup>
// Feuille de résolution (choix de cible → jet de dés → résultat).
// Port de FlowSheet + ResultBlock (manette-app.jsx).
import { computed } from 'vue';
import MSym from '../ui/MSym.vue';
import ChoiceCard from './ChoiceCard.vue';
import DieFace from './DieFace.vue';
import { ALLIES, FOES } from '../../data/demo';

const props = defineProps({
    /** { kind: 'attack'|'spell', step: 'target'|'rolling'|'result', spell?, target?, dice?, heal? } */
    flow: { type: Object, required: true },
    hero: { type: Object, required: true },
});

const emit = defineEmits(['target', 'confirm', 'close']);

const isSpell = computed(() => props.flow.kind === 'spell');
const targets = computed(() => (isSpell.value && props.flow.spell.target === 'ally' ? ALLIES : FOES));

const skulls = computed(() => (props.flow.dice ? props.flow.dice.atk.filter((d) => d === 'skull').length : 0));
const shields = computed(() => (props.flow.dice ? props.flow.dice.def.filter((d) => d === 'shield').length : 0));
const dmg = computed(() => Math.max(0, skulls.value - shields.value));

function onOverlayClick(e) {
    if (e.target.classList.contains('overlay')) emit('close');
}
</script>

<template>
    <div class="overlay" @click="onOverlayClick">
        <div class="sheet">
            <div class="grip" />

            <!-- choix de cible -->
            <template v-if="flow.step === 'target'">
                <h3>{{ isSpell ? flow.spell.name : 'Attaquer' }}</h3>
                <p class="sh-sub">{{ isSpell ? `${flow.spell.desc} — choisis une cible` : `${hero.atk} dés de crâne — choisis un ennemi à portée` }}</p>
                <div class="choices">
                    <ChoiceCard
                        v-for="t in targets"
                        :key="t.id"
                        :icon="t.icon"
                        :title="t.name"
                        :meta="t.dist || 'allié'"
                        @click="emit('target', t)"
                    />
                </div>
            </template>

            <!-- les dés roulent -->
            <template v-else-if="flow.step === 'rolling'">
                <h3>Résolution…</h3>
                <p class="sh-sub">Les dés roulent</p>
                <div class="dice-arena">
                    <div class="dice-grp">
                        <div class="gl">Attaque</div>
                        <div class="dice-row"><DieFace v-for="(d, i) in flow.dice.atk" :key="i" rolling /></div>
                    </div>
                    <div v-if="flow.dice.def.length" class="dice-grp">
                        <div class="gl">Défense {{ flow.target?.name }}</div>
                        <div class="dice-row"><DieFace v-for="(d, i) in flow.dice.def" :key="i" rolling /></div>
                    </div>
                </div>
            </template>

            <!-- résultat : soin -->
            <template v-else-if="flow.heal">
                <h3>Source de vie</h3>
                <div class="dice-arena">
                    <div style="text-align: center"><MSym n="water_drop" fill :size="56" style="color: var(--elem-water)" /></div>
                    <div class="result-line">
                        <div class="big">+2 Body</div>
                        <div class="sub">{{ flow.target?.name }} retrouve des forces · coûte 1 Mind</div>
                    </div>
                </div>
                <button class="btn btn-torch btn-block" style="margin-top: 18px" @click="emit('confirm')">Continuer</button>
            </template>

            <!-- résultat : dés -->
            <template v-else>
                <h3>{{ isSpell ? flow.spell.name : 'Résultat' }}</h3>
                <div class="dice-arena">
                    <div class="dice-row" style="align-items: center">
                        <div class="dice-grp">
                            <div class="gl">Crânes</div>
                            <div class="dice-row"><DieFace v-for="(d, i) in flow.dice.atk" :key="i" :face="d" reveal /></div>
                        </div>
                        <template v-if="flow.dice.def.length">
                            <span class="vs">vs</span>
                            <div class="dice-grp">
                                <div class="gl">Boucliers</div>
                                <div class="dice-row"><DieFace v-for="(d, i) in flow.dice.def" :key="i" :face="d" reveal /></div>
                            </div>
                        </template>
                    </div>
                    <div class="result-line">
                        <div v-if="dmg > 0" class="big">Touché · <span class="dmg">{{ dmg }} blessure{{ dmg > 1 ? 's' : '' }}</span></div>
                        <div v-else class="big">Coup paré</div>
                        <div class="sub">
                            {{ skulls }} crâne{{ skulls > 1 ? 's' : '' }}
                            {{ flow.dice.def.length ? `− ${shields} bouclier${shields > 1 ? 's' : ''}` : '' }}{{ isSpell ? ' · coûte 1 Mind' : '' }}
                        </div>
                    </div>
                </div>
                <button class="btn btn-torch btn-block" style="margin-top: 18px" @click="emit('confirm')">Continuer</button>
            </template>
        </div>
    </div>
</template>
