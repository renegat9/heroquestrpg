<script setup>
// Badge discret affiché quand la SPA tourne sur les données de démo
// (API injoignable ou session refusée — voir store/game.js `modeDemo`).
import { computed } from 'vue';
import { useGameStore } from '../../store/game';

const store = useGameStore();
const visible = computed(() => store.state.modeDemo);
</script>

<template>
    <div v-if="visible" class="demo-badge">démo</div>
</template>

<style>
.demo-badge { position: fixed; left: 10px; bottom: 10px; z-index: 999; pointer-events: none;
  font-family: var(--font-ui, sans-serif); font-size: 10px; font-weight: 700; letter-spacing: 0.14em;
  text-transform: uppercase; padding: 3px 9px; border-radius: 99px; opacity: 0.85;
  color: var(--torch, #e8a33d); border: 1px solid currentColor; background: oklch(0.18 0.01 255 / 0.85); }
</style>
