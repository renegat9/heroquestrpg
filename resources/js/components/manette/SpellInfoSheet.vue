<script setup>
// Feuille d'information d'un sort (correctif D) : taper une carte de
// SpellsTab ouvre CETTE feuille (nom, élément, type, disponibilité,
// description) plutôt que de lancer directement — « Lancer » y déclenche
// l'envoi (même flux qu'avant : l'option de menu part vers le parent, qui
// ouvre CibleSheet si elle porte parametres.cibles). Même style que
// CibleSheet/DeplacementSheet (.overlay/.sheet globaux de manette.css).
import { computed } from 'vue';
import MSym from '../ui/MSym.vue';
import { descriptionSort, elementInfo, TYPES_SORT } from '../../store/game';

const props = defineProps({
    /** Sort réel (GET /moi) : {sort_id, nom, element, type, disponible}. */
    sort: { type: Object, required: true },
    /** Résultat de carteDe(sort) côté SpellsTab : {badge, meta, option, disabled}. */
    carte: { type: Object, required: true },
});

const emit = defineEmits(['lancer', 'close']);

const el = computed(() => elementInfo(props.sort.element));
const type = computed(() => TYPES_SORT[(props.sort.type ?? '').toLowerCase()] ?? null);
const epuise = computed(() => props.sort.disponible === false);
const description = computed(() => descriptionSort(props.sort));

function onOverlayClick(e) {
    if (e.target.classList.contains('overlay')) emit('close');
}
</script>

<template>
    <div class="overlay" @click="onOverlayClick">
        <div class="sheet spellinfo-sheet">
            <div class="grip" />

            <div class="spellinfo-head">
                <span class="spellinfo-ic" :class="el ? `el-${el.cle}` : ''">
                    <MSym :n="el?.ic ?? 'auto_awesome'" fill :size="26" />
                </span>
                <div class="spellinfo-titles">
                    <h3>{{ sort.nom }}</h3>
                    <p class="sh-sub" style="margin: 0">
                        {{ el?.l ?? 'Sort' }}<template v-if="type"> · {{ type.l }}</template>
                    </p>
                </div>
            </div>

            <div class="spellinfo-badges">
                <span v-if="type" class="badge spellinfo-badge-type">
                    <MSym :n="type.ic" :size="15" />{{ type.l }}
                </span>
                <span class="badge" :class="epuise ? 'spellinfo-badge-epuise' : 'spellinfo-badge-dispo'">
                    <MSym :n="epuise ? 'block' : 'check_circle'" fill :size="15" />
                    {{ epuise ? 'Épuisé' : 'Disponible' }}
                </span>
            </div>

            <p class="spellinfo-desc">{{ description }}</p>

            <p class="spellinfo-meta"><MSym n="info" :size="14" /> {{ carte.meta }}</p>

            <button class="btn btn-torch btn-block" type="button" :disabled="carte.disabled" @click="emit('lancer')">
                <MSym n="auto_awesome" fill :size="18" /> Lancer
            </button>
            <button class="btn btn-ghost btn-block" type="button" style="margin-top: 8px" @click="emit('close')">
                <MSym n="close" :size="16" /> Fermer
            </button>
        </div>
    </div>
</template>

<style>
.spellinfo-head { display: flex; align-items: center; gap: 12px; margin-bottom: 4px; }
.spellinfo-ic { width: 46px; height: 46px; border-radius: 13px; flex: none; display: grid; place-items: center;
  background: var(--stone-800); color: var(--torch); }
/* couleurs par élément — mêmes teintes que .el-fire .ic etc. (manette.css) */
.spellinfo-ic.el-fire { background: oklch(0.64 0.205 35 / 0.16); color: var(--elem-fire); }
.spellinfo-ic.el-water { background: oklch(0.66 0.150 245 / 0.16); color: var(--elem-water); }
.spellinfo-ic.el-earth { background: oklch(0.60 0.115 145 / 0.18); color: var(--elem-earth); }
.spellinfo-ic.el-air { background: oklch(0.86 0.075 215 / 0.20); color: oklch(0.5 0.08 215); }
.spellinfo-titles { min-width: 0; }
.spellinfo-titles h3 { margin: 0; }
.spellinfo-badges { display: flex; gap: 8px; flex-wrap: wrap; margin: 10px 0 14px; }
.spellinfo-badge-type { color: var(--ink-300); }
.spellinfo-badge-dispo { color: var(--ok, #7cc47c); }
.spellinfo-badge-epuise { color: var(--ink-500); }
.spellinfo-desc { font-family: var(--font-narr); font-style: italic; font-size: 14.5px; line-height: 1.5;
  color: var(--ink-100); margin: 0 0 14px; }
.spellinfo-meta { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--ink-500); margin: 0 0 16px; }
.spellinfo-meta .msym { color: var(--torch); flex: none; }
</style>
