<script setup>
// File d'initiative compacte de la manette (composant Init de manette-app.jsx).
import { computed } from 'vue';
import MSym from '../ui/MSym.vue';
import { INIT_ORDER_MINI } from '../../data/demo';

const props = defineProps({
    /** Jeton courant : 'MAGE' | 'BARB' | 'DWARF' | 'ELF' | 'orc' | … */
    cur: { type: String, required: true },
});

const norm = (s) => {
    const u = s.toUpperCase();
    return u === 'MAGE' ? 'MAG' : u === 'BARB' ? 'BAR' : u === 'DWARF' ? 'NAI' : u === 'ELF' ? 'ELF' : u;
};
const c = computed(() => norm(props.cur));

const order = INIT_ORDER_MINI;
</script>

<template>
    <div class="init-mini">
        <template v-for="(o, i) in order" :key="i">
            <div class="tok" :class="{ cur: o.k === c, foe: o.foe }">{{ o.k }}</div>
            <MSym v-if="i < order.length - 1" n="chevron_right" class="arrow" />
        </template>
    </div>
</template>

<style>
.init-mini { display: flex; gap: 6px; align-items: center; margin-bottom: 16px; overflow-x: auto; padding-bottom: 4px; }
.init-mini .tok { flex: none; width: 42px; height: 42px; border-radius: 50%; display: grid; place-items: center;
  font-weight: 800; font-size: 12px; border: 2px solid var(--stone-600); background: var(--stone-800); color: var(--ink-300); }
.init-mini .tok.foe { border-color: var(--body); color: var(--body-bright); }
.init-mini .tok.cur { border-color: var(--torch); background: var(--torch); color: var(--stone-950);
  box-shadow: var(--glow-torch); transform: scale(1.1); }
.init-mini .arrow { color: var(--ink-700); }
</style>
