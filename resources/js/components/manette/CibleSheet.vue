<script setup>
// Feuille de ciblage des options de menu (overlay + sheet), branchée sur
// le contrat :
// - mode 'cible' : l'option (sort / parchemin / attaque ciblée) expose
//   parametres.cibles — les héros y figurent (tir ami S3) : taper un
//   allié demande une confirmation « ⚠ allié » avant l'envoi ;
// - mode 'concentration' : choisir LE sort épuisé à récupérer
//   (parametres: {sort_id}) — sacrifie le tour.
import { computed, ref, watch } from 'vue';
import MSym from '../ui/MSym.vue';
import ChoiceCard from './ChoiceCard.vue';
import { elementInfo, TYPES_SORT } from '../../store/game';

const props = defineProps({
    /** { option, mode: 'cible'|'concentration', cibles?: [...], sorts?: [...] } */
    feuille: { type: Object, required: true },
});

const emit = defineEmits(['cible', 'sort', 'close']);

// Ennemis d'abord, alliés (tir ami) à part : évite de viser un héros par erreur
// dans la même liste indifférenciée (le garde-fou de confirmation reste en place).
const ciblesEnnemies = computed(() => (props.feuille.cibles ?? []).filter((c) => !c.ami));
const ciblesAlliees = computed(() => (props.feuille.cibles ?? []).filter((c) => c.ami));

/* tir ami : la cible alliée tapée attend une confirmation explicite */
const allieAConfirmer = ref(null);
watch(() => props.feuille, () => { allieAConfirmer.value = null; });

/* Le sort est-il réellement offensif ? Un soin ou un buff visant un allié
   n'est PAS un tir ami : afficher « subira l'effet comme un ennemi » y était
   faux et imposait deux clics de confirmation en pleine urgence (§2.11).
   Par défaut on reste prudent (offensif) si l'information manque. */
const offensif = computed(() => props.feuille.offensif !== false);

/* §2.3 — un sort offensif sans AUCUN ennemi ciblable : la feuille n'affichait
   que les alliés sous un bandeau « tir ami », ce dont deux joueurs ont conclu
   que la magie était cassée. On le dit explicitement. */
const aucuneCibleEnVue = computed(() => offensif.value && ciblesEnnemies.value.length === 0);

function choisir(cible) {
    if (cible.ami && offensif.value) {
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
                        v-for="c in ciblesEnnemies"
                        :key="c.cle"
                        :icon="c.ic"
                        :title="c.nom"
                        :meta="c.meta"
                        @click="choisir(c)"
                    />
                </div>
                <!-- §2.3 : dire pourquoi la liste d'ennemis est vide, au lieu
                     de n'afficher que des alliés (ce qui se lit comme un bug). -->
                <p v-if="aucuneCibleEnVue" class="cible-vide">
                    <MSym n="visibility_off" :size="16" />
                    Aucune cible en vue — un mur ou un allié bloque ta ligne de vue.
                    Déplace-toi pour dégager un angle.
                </p>
                <template v-if="ciblesAlliees.length">
                    <div class="cible-sep" :class="{ 'cible-sep-neutre': !offensif }">
                        <MSym v-if="offensif" n="warning" fill :size="13" />
                        {{ offensif ? 'Alliés (tir ami)' : 'Alliés' }}
                    </div>
                    <div class="choices">
                        <ChoiceCard
                            v-for="c in ciblesAlliees"
                            :key="c.cle"
                            :icon="c.ic"
                            :title="c.nom"
                            :meta="c.meta"
                            :danger="offensif"
                            @click="choisir(c)"
                        />
                    </div>
                </template>
                <!-- Choisir la cible est la 2e étape : il faut pouvoir revenir
                     sans jouer. Taper l'overlay le faisait déjà, mais rien ne le
                     disait — on ne devine pas une zone tappable invisible. -->
                <button class="btn btn-ghost btn-block cible-retour" @click="emit('close')">
                    <MSym n="arrow_back" :size="18" /> Retour aux actions
                </button>
            </template>
        </div>
    </div>
</template>

<style>
.sheet .ami-warn { display: flex; align-items: center; gap: 10px; padding: 12px 14px; border-radius: var(--r-md);
  font-size: 13px; color: var(--parch-100); background: oklch(0.6 0.2 25 / 0.14); border: 1px solid oklch(0.6 0.2 25 / 0.5); }
.sheet .ami-warn .msym { color: var(--danger); flex: none; }
.sheet .cible-vide { display: flex; align-items: center; gap: 8px; margin: 10px 0 4px;
  font-size: 12.5px; line-height: 1.4; color: var(--ink-400); }
.cible-vide .msym { color: var(--ink-500); flex: none; }
.cible-sep-neutre { color: var(--ink-400) !important; }
.sheet .cible-retour { margin-top: 14px; }
.cible-sep { display: flex; align-items: center; gap: 6px; margin: 14px 0 8px; font-size: 12px; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.05em; color: var(--danger, #e66); }
</style>
