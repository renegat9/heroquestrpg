<script setup>
// Faveur WEAPON EXPERT (Wizards of Morcar, Hopekins Rest — livret G1504 p. 22-23) : « Select a
// type of weapon […] when you acquire this Boon ». Décision de René (2026-10-15) : le JOUEUR
// choisit son type d'arme AU MOMENT où il reçoit la faveur — au hub, entre deux quêtes. La
// faveur et son porteur sont tirés au hasard ; seul le type d'arme revient au joueur.
//
// ⚠ Rien ici n'est décidé. `faveur.choix` (publié par `/moi`) est la LISTE BLANCHE du serveur —
// lue dans le catalogue, filtrée sur ce que ce héros peut manier — et n'est non nul que tant que
// le choix reste à faire ET permis (au hub). Ce composant n'est monté que dans ce cas, ne
// connaît ni la phase, ni les armes d'armurerie, ni les classes : il rend la liste reçue.
import { ref } from 'vue';
import MSym from '../ui/MSym.vue';

defineProps({
    // L'entrée `weapon_expert` de `/moi` → faveurs : {cle, libelle, effet, specialite: null, choix: [{objet_id, nom, des_attaque}]}.
    faveur: { type: Object, required: true },
    // Un choix est en cours (gèle le bouton).
    enCours: { type: Boolean, default: false },
});
const emit = defineEmits(['choisir']);

const choisi = ref(null);
</script>

<template>
    <div class="caf">
        <div class="sect-title"><MSym n="workspace_premium" fill :size="16" /> {{ faveur.libelle }} : choisissez votre type d'arme</div>
        <p class="caf-note">
            {{ faveur.effet }} Le choix est définitif.
        </p>
        <div class="caf-liste" role="radiogroup">
            <button
                v-for="c in faveur.choix"
                :key="c.objet_id"
                class="caf-arme"
                :class="{ on: choisi === c.objet_id }"
                role="radio"
                :aria-checked="choisi === c.objet_id"
                type="button"
                @click="choisi = c.objet_id"
            >
                <span class="caf-nom">{{ c.nom }}</span>
                <span class="caf-des"><MSym n="swords" :size="13" /> {{ c.des_attaque }}</span>
            </button>
        </div>
        <button class="caf-btn" :disabled="enCours || choisi === null" @click="emit('choisir', choisi)">
            <MSym n="check" :size="16" /> Confirmer ce type d'arme
        </button>
    </div>
</template>

<style scoped>
.caf { margin: 0 0 16px; padding: 12px; border: 1px solid var(--gold, #c9a24a); border-radius: 12px;
  background: rgba(201, 162, 90, 0.08); }
.caf-note { font-size: 13px; line-height: 1.4; color: var(--ink-300, #cfc3ad); margin: 4px 0 10px; }
.caf-liste { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 8px; margin-bottom: 10px; }
.caf-arme { display: flex; align-items: center; justify-content: space-between; gap: 6px; padding: 9px 10px;
  border: 1px solid var(--line-soft, oklch(0.4 0.02 70 / 0.4)); border-radius: 9px; cursor: pointer;
  background: var(--panel-2, oklch(0.22 0.02 70 / 0.4)); color: inherit; font-size: 13.5px; text-align: left; }
.caf-arme.on { border-color: var(--gold, #c9a24a); background: rgba(201, 162, 90, 0.2); font-weight: 700; }
.caf-nom { min-width: 0; }
.caf-des { display: flex; align-items: center; gap: 3px; flex: none; font-size: 12px; color: var(--ink-500); }
.caf-btn { width: 100%; padding: 9px; border: 0; border-radius: 9px; display: flex; align-items: center;
  justify-content: center; gap: 6px; font-weight: 700; font-size: 14px; cursor: pointer;
  background: var(--gold, #c9a24a); color: #1a1204; }
.caf-btn:disabled { background: var(--panel-3, oklch(0.28 0.02 70 / 0.6)); color: var(--ink-500); cursor: default; }
</style>
