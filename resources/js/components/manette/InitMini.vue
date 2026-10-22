<script setup>
// File d'initiative compacte de la manette (composant Init de manette-app.jsx).
// `order` : initiative réelle ([{k, foe}], voir initiativeVersMini).
import { computed } from 'vue';
import MSym from '../ui/MSym.vue';
import Vignette from '../ui/Vignette.vue';

const props = defineProps({
    /** Jeton courant : label court réel (voir labelCourt), ou '···' si inconnu. */
    cur: { type: String, required: true },
    /** File réelle [{k, nom, img, foe, ally}]. */
    order: { type: Array, default: () => [] },
});

const norm = (s) => s.toUpperCase();
const c = computed(() => norm(props.cur));

const ordre = computed(() => props.order ?? []);
</script>

<template>
    <div class="init-mini">
        <template v-for="(o, i) in ordre" :key="i">
            <!-- Portrait + nom dessous (René, 2026-10-01), même rendu que la
                 barre de la table ; l'initiale reste le repli sans image. -->
            <div class="unite" :class="{ cur: o.k === c, foe: o.foe, ally: o.ally }">
                <div class="tok">
                    <Vignette v-if="o.img" :src="o.img" :icon="o.foe ? 'skull' : 'person'" :alt="o.nom" />
                    <template v-else>{{ o.k }}</template>
                </div>
                <span class="nom">{{ o.nom }}</span>
            </div>
            <MSym v-if="i < ordre.length - 1" n="chevron_right" class="arrow" />
        </template>
    </div>
</template>

<style>
/* Un peu de marge : le jeton courant est AGRANDI, et le conteneur qui défile
   (`overflow-x`) rogne tout ce qui déborde. */
.init-mini { display: flex; gap: 6px; align-items: center; margin-bottom: 16px; overflow-x: auto; padding: 6px 4px 4px; }
.init-mini .tok { flex: none; width: 42px; height: 42px; border-radius: 50%; display: grid; place-items: center;
  font-weight: 800; font-size: 12px; border: 2px solid var(--stone-600); background: var(--stone-800); color: var(--ink-300); }
.init-mini .unite { flex: none; display: flex; flex-direction: column; align-items: center; gap: 3px; width: 60px; }
.init-mini .tok { overflow: hidden; }
.init-mini .tok .vignette-img { width: 100%; height: 100%; object-fit: cover; }
/* Le nom passe à la LIGNE plutôt que d'être coupé (« Raptor a… », 2026-10-22) : deux lignes au plus,
   centrées — « Raptor / apprivoisé » tient ; un mot plus long que la colonne se coupe en dernier recours. */
.init-mini .nom { max-width: 60px; font-size: 10px; font-weight: 600; line-height: 1.1; color: var(--ink-400);
  text-align: center; overflow-wrap: anywhere;
  display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.init-mini .unite.foe .tok { border-color: var(--body); color: var(--body-bright); }
.init-mini .unite.foe .nom { color: var(--body-bright); }
.init-mini .unite.ally .tok { border-color: oklch(0.7 0.13 155); color: oklch(0.82 0.13 152); }
.init-mini .unite.ally .nom { color: oklch(0.82 0.13 152); }
.init-mini .unite.cur .nom { color: var(--torch); font-weight: 800; }
.init-mini .unite.cur .tok { border-color: var(--torch); background: var(--torch); color: var(--stone-950);
  /* Anneau NET plutôt que le halo flou de la table (`--glow-torch`, 18 px) :
     dans ce conteneur qui défile, le halo était rogné en un carré sombre. */
  box-shadow: 0 0 0 2px oklch(0.76 0.155 65 / 0.45); transform: scale(1.08); }
.init-mini .arrow { color: var(--ink-700); }
/* Un nom sur deux lignes allonge sa colonne : sans cet ancrage en HAUT, son portrait remontait au-dessus
   des autres. Les portraits s'alignent donc par le haut, et la flèche se centre sur eux (42 px de jeton). */
.init-mini .unite, .init-mini .arrow { align-self: flex-start; }
.init-mini .arrow { margin-top: calc((42px - 1em) / 2); }
</style>
