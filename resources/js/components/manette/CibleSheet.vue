<script setup>
// Feuille de ciblage des options de menu (overlay + sheet), branchée sur
// le contrat :
// - mode 'cible' : l'option (sort / parchemin / attaque ciblée) expose
//   parametres.cibles — les héros y figurent (tir ami S3) : taper un
//   allié demande une confirmation « ⚠ allié » avant l'envoi ;
// - mode 'concentration' : choisir LE sort épuisé à récupérer
//   (parametres: {sort_id}) — sacrifie le tour.
import { ref, watch } from 'vue';
import MSym from '../ui/MSym.vue';
import ChoiceCard from './ChoiceCard.vue';
import { elementInfo, TYPES_SORT } from '../../store/game';

const props = defineProps({
    /** { option, mode: 'cible'|'concentration', cibles?: [...], sorts?: [...] } */
    feuille: { type: Object, required: true },
});

const emit = defineEmits(['cible', 'sort', 'close']);

/* tir ami : la cible alliée tapée attend une confirmation explicite */
const allieAConfirmer = ref(null);
watch(() => props.feuille, () => { allieAConfirmer.value = null; });

function choisir(cible) {
    if (cible.ami) {
        allieAConfirmer.value = cible;
        return;
    }
    emit('cible', cible);
}

function carteSort(s) {
    const el = elementInfo(s.element);
    return {
        ic: el?.ic ?? 'auto_awesome',
        elClass: el ? `el-${el.cle}` : '',
        badge: TYPES_SORT[(s.type ?? '').toLowerCase()]?.l ?? '',
    };
}

function onOverlayClick(e) {
    if (e.target.classList.contains('overlay')) emit('close');
}
</script>

<template>
    <div class="overlay" @click="onOverlayClick">
        <div class="sheet">
            <div class="grip" />

            <!-- concentration : récupérer UN sort épuisé -->
            <template v-if="feuille.mode === 'concentration'">
                <h3>{{ feuille.option.libelle || 'Se concentrer' }}</h3>
                <p class="sh-sub">Sacrifie le tour — choisis le sort à récupérer</p>
                <div class="choices">
                    <ChoiceCard
                        v-for="s in feuille.sorts"
                        :key="s.sort_id"
                        :icon="carteSort(s).ic"
                        :el-class="carteSort(s).elClass"
                        :title="s.nom ?? `Sort n°${s.sort_id}`"
                        :badge="carteSort(s).badge"
                        meta="Épuisé — redevient lançable"
                        @click="emit('sort', s)"
                    />
                </div>
            </template>

            <!-- confirmation tir ami (S3 : les héros sont des cibles légales) -->
            <template v-else-if="allieAConfirmer">
                <h3><MSym n="warning" fill :size="20" style="color: var(--danger); vertical-align: -3px" /> Tir ami</h3>
                <p class="sh-sub">{{ feuille.option.libelle }} — la cible choisie est un héros du groupe.</p>
                <div class="ami-warn">
                    <MSym n="warning" fill :size="22" />
                    <span><b>{{ allieAConfirmer.nom }}</b> subira l'effet comme un ennemi. Confirmer la cible ?</span>
                </div>
                <button class="btn btn-danger btn-block" style="margin-top: 14px" @click="emit('cible', allieAConfirmer)">
                    <MSym n="warning" fill :size="18" /> Confirmer — viser {{ allieAConfirmer.nom }}
                </button>
                <button class="btn btn-ghost btn-block" style="margin-top: 8px" @click="allieAConfirmer = null">
                    Choisir une autre cible
                </button>
            </template>

            <!-- choix de cible (parametres.cibles) -->
            <template v-else>
                <h3>{{ feuille.option.libelle }}</h3>
                <p class="sh-sub">Choisis une cible — le moteur résout</p>
                <div class="choices">
                    <ChoiceCard
                        v-for="c in feuille.cibles"
                        :key="c.cle"
                        :icon="c.ic"
                        :title="c.nom"
                        :meta="c.meta"
                        :danger="c.ami"
                        @click="choisir(c)"
                    />
                </div>
            </template>
        </div>
    </div>
</template>

<style>
.sheet .ami-warn { display: flex; align-items: center; gap: 10px; padding: 12px 14px; border-radius: var(--r-md);
  font-size: 13px; color: var(--parch-100); background: oklch(0.6 0.2 25 / 0.14); border: 1px solid oklch(0.6 0.2 25 / 0.5); }
.sheet .ami-warn .msym { color: var(--danger); flex: none; }
</style>
