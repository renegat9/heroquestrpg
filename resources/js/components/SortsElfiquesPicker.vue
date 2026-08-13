<script setup>
/*
 * Sélecteur des 3 sorts du RÉPERTOIRE ELFIQUE (Mage of the Mirror).
 *
 * UN SEUL composant pour les deux écrans qui en ont besoin — la création de
 * personnage (`JoueurView`) et le rechoix au hub (`SpellsTab`) : les deux
 * posent exactement la même question, et deux listes divergentes finiraient par
 * proposer deux répertoires différents.
 *
 * Le catalogue vient de `GET /api/guide` (public), filtré sur l'élément
 * `elfique` — c'est la seule liste publique du répertoire, et elle porte les
 * identifiants que l'API attend.
 */
import { computed, onMounted, ref } from 'vue';
import MSym from './ui/MSym.vue';
import { useApi } from '../composables/useApi';
import { TYPES_SORT } from '../store/game';

const props = defineProps({
    /** Identifiants déjà choisis (v-model). */
    modelValue: { type: Array, default: () => [] },
    /** Combien en choisir — 3, comme les 3 sorts d'une école. */
    max: { type: Number, default: 3 },
});

const emit = defineEmits(['update:modelValue']);

const api = useApi();
const repertoire = ref([]);
const chargement = ref(true);
const erreur = ref('');

onMounted(async () => {
    try {
        const guide = await api.getGuide();
        repertoire.value = (guide?.sorts ?? []).filter((s) => s.element === 'elfique');
    } catch (e) {
        erreur.value = e.message;
    } finally {
        chargement.value = false;
    }
});

const complet = computed(() => props.modelValue.length >= props.max);

function basculer(id) {
    if (props.modelValue.includes(id)) {
        emit('update:modelValue', props.modelValue.filter((s) => s !== id));
    } else if (!complet.value) {
        emit('update:modelValue', [...props.modelValue, id]);
    }
}
</script>

<template>
    <!-- Préfixe `sef-` : beaucoup de blocs <style> du projet sont GLOBAUX, et un
         nom générique fuirait d'une vue à l'autre. -->
    <div class="sef-wrap">
        <p v-if="chargement" class="sef-note">Chargement du répertoire…</p>
        <p v-else-if="erreur" class="sef-note sef-err"><MSym n="error" :size="14" /> {{ erreur }}</p>

        <template v-else>
            <div class="sef-grid">
                <button
                    v-for="s in repertoire"
                    :key="s.id"
                    type="button"
                    class="sef-btn"
                    :class="{ on: modelValue.includes(s.id), off: !modelValue.includes(s.id) && complet }"
                    @click="basculer(s.id)"
                >
                    <span class="sef-nom">{{ s.nom }}</span>
                    <span class="sef-type">{{ TYPES_SORT[(s.type ?? '').toLowerCase()]?.l ?? s.type }}</span>
                </button>
            </div>
            <p class="sef-note">{{ modelValue.length }} / {{ max }} choisis</p>
        </template>
    </div>
</template>

<style scoped>
.sef-wrap { margin: 6px 0 10px; }
.sef-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px; }

.sef-btn {
    display: flex; flex-direction: column; align-items: flex-start; gap: 2px;
    padding: 8px 10px;
    border-radius: 10px;
    border: 1px solid rgba(201, 162, 74, 0.35);
    background: rgba(255, 255, 255, 0.04);
    color: var(--ink-100, #f3e9d6);
    font-size: 13px; text-align: left;
    cursor: pointer;
}
.sef-btn.on { background: rgba(201, 162, 74, 0.22); border-color: var(--gold, #c9a24a); }
.sef-btn.off { opacity: 0.45; }

.sef-nom { font-weight: 700; }
.sef-type { font-size: 11px; color: var(--ink-300, #b6a88a); }

.sef-note { margin: 6px 0 0; font-size: 12px; color: var(--ink-300, #b6a88a); }
.sef-err { color: #ff8f6b; }
</style>
