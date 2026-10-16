<script setup>
// Faveur ARCANE LIBRARY (Wizards of Morcar, Hopekins Rest — livret G1504 p. 23) : « After heroes
// have selected spells, you may take an additional set of spells that you are eligible to cast. »
// Même décision que Weapon Expert (René, 2026-10-15) : la faveur et son porteur sont tirés au
// hasard, le JOUEUR choisit son répertoire au hub, entre deux quêtes, et le choix est définitif.
//
// ⚠ Rien ici n'est décidé. `faveur.choix` (publié par `/moi`) est la LISTE BLANCHE du serveur —
// ce que ce lanceur PEUT prendre en plus de ses sorts (les règles des lanceurs : écoles pour le
// Magicien et l'Elfe, répertoires optionnels pour les cinq classes, jamais un répertoire déjà
// connu) — et n'est non nul que tant que le choix reste à faire ET permis (au hub). Ce composant
// n'est monté que dans ce cas, ne connaît ni la phase, ni les classes, ni le catalogue de sorts :
// il rend la liste reçue. Les noms français des répertoires sont de simples libellés d'affichage.
import { ref } from 'vue';
import MSym from '../ui/MSym.vue';
import { ELEMENT } from '../../compendium';

defineProps({
    // L'entrée `arcane_library` de `/moi` → faveurs : {cle, libelle, effet, repertoire: null, choix: [{element, sorts: [nom…]}]}.
    faveur: { type: Object, required: true },
    // Un choix est en cours (gèle le bouton).
    enCours: { type: Boolean, default: false },
});
const emit = defineEmits(['choisir']);

const choisi = ref(null);
</script>

<template>
    <div class="crf">
        <div class="sect-title"><MSym n="auto_stories" fill :size="16" /> {{ faveur.libelle }} : choisissez un répertoire de sorts</div>
        <p class="crf-note">
            {{ faveur.effet }} Le choix est définitif.
        </p>
        <div class="crf-liste" role="radiogroup">
            <button
                v-for="c in faveur.choix"
                :key="c.element"
                class="crf-rep"
                :class="{ on: choisi === c.element }"
                role="radio"
                :aria-checked="choisi === c.element"
                type="button"
                @click="choisi = c.element"
            >
                <span class="crf-nom"><MSym :n="ELEMENT[c.element]?.ic || 'auto_stories'" :size="15" /> {{ ELEMENT[c.element]?.l || c.element }}</span>
                <span class="crf-sorts">{{ c.sorts.join(' · ') }}</span>
            </button>
        </div>
        <button class="crf-btn" :disabled="enCours || choisi === null" @click="emit('choisir', choisi)">
            <MSym n="check" :size="16" /> Confirmer ce répertoire
        </button>
    </div>
</template>

<style scoped>
.crf { margin: 0 0 16px; padding: 12px; border: 1px solid var(--gold, #c9a24a); border-radius: 12px;
  background: rgba(201, 162, 90, 0.08); }
.crf-note { font-size: 13px; line-height: 1.4; color: var(--ink-300, #cfc3ad); margin: 4px 0 10px; }
.crf-liste { display: grid; gap: 8px; margin-bottom: 10px; }
.crf-rep { display: flex; flex-direction: column; align-items: flex-start; gap: 3px; padding: 9px 11px;
  border: 1px solid var(--line-soft, oklch(0.4 0.02 70 / 0.4)); border-radius: 9px; cursor: pointer;
  background: var(--panel-2, oklch(0.22 0.02 70 / 0.4)); color: inherit; text-align: left; }
.crf-rep.on { border-color: var(--gold, #c9a24a); background: rgba(201, 162, 90, 0.2); }
.crf-nom { display: flex; align-items: center; gap: 6px; font-size: 14px; font-weight: 700; }
.crf-sorts { font-size: 12px; line-height: 1.35; color: var(--ink-500); }
.crf-btn { width: 100%; padding: 9px; border: 0; border-radius: 9px; display: flex; align-items: center;
  justify-content: center; gap: 6px; font-weight: 700; font-size: 14px; cursor: pointer;
  background: var(--gold, #c9a24a); color: #1a1204; }
.crf-btn:disabled { background: var(--panel-3, oklch(0.28 0.02 70 / 0.6)); color: var(--ink-500); cursor: default; }
</style>
