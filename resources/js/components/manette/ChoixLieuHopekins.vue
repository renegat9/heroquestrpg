<script setup>
// HOPEKINS REST (Wizards of Morcar, livret G1504 p. 21 et 23) : « At the start of each quest, a hero
// may select one location to visit and receive its Boon of Heroism (if available). » René (2026-10-16) :
// chaque joueur choisit le lieu de SON héros, au hub, entre deux quêtes — ou n'en visite aucun.
//
// ⚠ Rien ici n'est décidé. `visite` (publié par `/moi` → `visite_hopekins`) porte TOUS les lieux, chacun
// avec `disponible` et, quand il ne l'est pas, le `motif` écrit par le serveur (« Tenue par Brynn. »).
// Un lieu grisé n'est pas cliquable : le client ne sait ni qui tient quoi, ni pourquoi. Ce composant n'est
// monté que lorsque la visite reste à faire (tour ouvert, héros pas encore décidé).
import { ref } from 'vue';
import MSym from '../ui/MSym.vue';

defineProps({
    // `visite_hopekins` de `/moi` : {quete_id, lieux: [{cle, libelle, effet, disponible, motif}]}.
    visite: { type: Object, required: true },
    // Un choix est en cours (gèle les boutons).
    enCours: { type: Boolean, default: false },
});
const emit = defineEmits(['choisir']);

const choisi = ref(null);
</script>

<template>
    <div class="clh">
        <div class="sect-title"><MSym n="signpost" fill :size="16" /> Hopekins Rest : choisissez un lieu</div>
        <p class="clh-note">
            Un lieu, une faveur, tenue jusqu'à ce que votre héros en reçoive une autre. Elle remplace celle
            qu'il tient. Vous pouvez aussi n'en visiter aucun.
        </p>
        <div class="clh-liste" role="radiogroup">
            <button
                v-for="l in visite.lieux"
                :key="l.cle"
                class="clh-lieu"
                :class="{ on: choisi === l.cle, off: !l.disponible }"
                role="radio"
                :aria-checked="choisi === l.cle"
                :aria-disabled="!l.disponible"
                :disabled="!l.disponible || enCours"
                type="button"
                @click="choisi = l.cle"
            >
                <span class="clh-nom">{{ l.libelle }}</span>
                <span class="clh-effet">{{ l.effet }}</span>
                <span v-if="!l.disponible && l.motif" class="clh-motif">{{ l.motif }}</span>
            </button>
        </div>
        <button class="clh-btn" :disabled="enCours || choisi === null" @click="emit('choisir', choisi)">
            <MSym n="check" :size="16" /> Visiter ce lieu
        </button>
        <button class="clh-renonce" :disabled="enCours" type="button" @click="emit('choisir', null)">
            N'en visiter aucun
        </button>
    </div>
</template>

<style scoped>
.clh { margin: 0 0 16px; padding: 12px; border: 1px solid var(--gold, #c9a24a); border-radius: 12px;
  background: rgba(201, 162, 90, 0.08); }
.clh-note { font-size: 13px; line-height: 1.4; color: var(--ink-300, #cfc3ad); margin: 4px 0 10px; }
.clh-liste { display: grid; gap: 8px; margin-bottom: 10px; }
.clh-lieu { display: flex; flex-direction: column; align-items: flex-start; gap: 3px; padding: 9px 11px;
  border: 1px solid var(--line-soft, oklch(0.4 0.02 70 / 0.4)); border-radius: 9px; cursor: pointer;
  background: var(--panel-2, oklch(0.22 0.02 70 / 0.4)); color: inherit; text-align: left; }
.clh-lieu.on { border-color: var(--gold, #c9a24a); background: rgba(201, 162, 90, 0.2); }
.clh-lieu.off { opacity: 0.55; cursor: default; }
.clh-nom { font-size: 14px; font-weight: 700; }
.clh-effet { font-size: 12px; line-height: 1.35; color: var(--ink-500); }
.clh-motif { font-size: 12px; font-style: italic; color: var(--danger, #e0735c); }
.clh-btn { width: 100%; padding: 9px; border: 0; border-radius: 9px; display: flex; align-items: center;
  justify-content: center; gap: 6px; font-weight: 700; font-size: 14px; cursor: pointer;
  background: var(--gold, #c9a24a); color: #1a1204; }
.clh-btn:disabled { background: var(--panel-3, oklch(0.28 0.02 70 / 0.6)); color: var(--ink-500); cursor: default; }
.clh-renonce { width: 100%; margin-top: 8px; padding: 8px; border: 1px solid var(--line-soft, oklch(0.4 0.02 70 / 0.4));
  border-radius: 9px; background: transparent; color: var(--ink-300, #cfc3ad); font-size: 13px; cursor: pointer; }
.clh-renonce:disabled { opacity: 0.5; cursor: default; }
</style>
