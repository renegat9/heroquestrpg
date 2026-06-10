<script setup>
// Onglet Sorts (grimoire par élément) — port de SpellsTab (manette-app.jsx).
import { computed } from 'vue';
import MSym from '../ui/MSym.vue';
import ChoiceCard from './ChoiceCard.vue';
import { SPELLS, EL_LABEL, EL_CLASS } from '../../data/demo';

const props = defineProps({
    hero: { type: Object, required: true },
    mind: { type: Object, required: true },
});

const emit = defineEmits(['cast']);

const byEl = computed(() => {
    const groups = {};
    SPELLS.forEach((s) => { (groups[s.el] = groups[s.el] || []).push(s); });
    return groups;
});
</script>

<template>
    <div v-if="!hero.hasSpells" class="empty-note">
        <MSym n="auto_awesome" :size="36" style="display: block; margin: 0 auto 12px; color: var(--ink-700)" />
        Le {{ hero.cls }} ne manie pas la magie. Sa puissance est dans l'acier.
    </div>
    <div v-else>
        <div class="turn-banner mine" style="justify-content: space-between">
            <span><MSym n="psychology" fill /> Grimoire</span>
            <span style="font-size: 12px">Mind {{ mind.cur }}/{{ mind.max }}</span>
        </div>
        <div v-for="(spells, el) in byEl" :key="el">
            <div class="sect-title">
                <span :style="{ width: '9px', height: '9px', borderRadius: '50%', background: `var(--elem-${el})` }" />
                {{ EL_LABEL[el] }}
            </div>
            <div class="choices" style="margin-bottom: 16px">
                <ChoiceCard
                    v-for="sp in spells"
                    :key="sp.id"
                    :icon="sp.icon"
                    :title="sp.name"
                    :meta="sp.desc"
                    :el-class="EL_CLASS[el]"
                    :disabled="mind.cur < 1"
                    @click="emit('cast', sp)"
                />
            </div>
        </div>
    </div>
</template>
