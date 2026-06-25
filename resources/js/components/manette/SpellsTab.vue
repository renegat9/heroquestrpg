<script setup>
// Onglet Sorts (grimoire par élément) — port de SpellsTab (manette-app.jsx).
//
// Deux sources possibles :
// - `sorts` (mode connecté) : les sorts du héros depuis GET /api/moi
//   ([{sort_id, nom, element, type, disponible}], contrat « Sorts des
//   héros ») — grille par élément, sorts épuisés grisés (1×/quête), badge
//   type (dégâts / mental / utilitaire). Lancer passe par les OPTIONS DE
//   MENU : si le menu courant (.menu.propose) contient l'option type
//   "sort" du sort, le tap émet 'choose' avec cette option (même flux que
//   ActionTab : ciblage via parametres.cibles puis POST choix) ; sinon le
//   sort est désactivé avec la raison (pas son tour / pas proposé).
// - sans `sorts` (mode démo) : le grimoire local des maquettes.
import { computed } from 'vue';
import MSym from '../ui/MSym.vue';
import ChoiceCard from './ChoiceCard.vue';
import { SPELLS, EL_LABEL, EL_CLASS } from '../../data/demo';
import { optionPourSort, sortsParElement, TYPES_SORT } from '../../store/game';

const props = defineProps({
    hero: { type: Object, required: true },
    mind: { type: Object, required: true },
    /** Sorts réels du héros (GET /moi) — null en mode démo. */
    sorts: { type: Array, default: null },
    /** Menu courant ({contexte, options}) — null hors tour / démo. */
    menu: { type: Object, default: null },
    /** Choix envoyé, en attente de la résolution du moteur. */
    pending: { type: Boolean, default: false },
});

const emit = defineEmits(['cast', 'choose']);

const connecte = computed(() => Array.isArray(props.sorts));

/* ---- mode connecté : grille par élément + état par sort ---- */
const groupes = computed(() => sortsParElement(props.sorts ?? []));
const dispos = computed(() => (props.sorts ?? []).filter((s) => s.disponible !== false).length);

/** Carte d'un sort réel : option de menu associée + raison du blocage. */
function carteDe(sort) {
    const type = TYPES_SORT[(sort.type ?? '').toLowerCase()] ?? null;
    const epuise = sort.disponible === false;
    const option = epuise ? null : optionPourSort(props.menu, sort.sort_id);
    let meta;
    if (epuise) meta = 'Épuisé — redevient disponible à la prochaine quête';
    else if (option) meta = 'Prêt — touche pour lancer';
    else meta = props.menu ? 'Pas proposé ce tour' : 'Attends ton tour pour lancer';
    return {
        badge: type?.l ?? '',
        meta,
        option,
        disabled: epuise || !option || props.pending,
    };
}

function lancer(sort) {
    const { option } = carteDe(sort);
    if (option) emit('choose', option);
}

/* ---- mode démo : grimoire des maquettes ---- */
const byEl = computed(() => {
    const groups = {};
    SPELLS.forEach((s) => { (groups[s.el] = groups[s.el] || []).push(s); });
    return groups;
});
</script>

<template>
    <!-- ================= mode connecté (sorts de /moi) ================= -->
    <div v-if="connecte">
        <div v-if="!sorts.length" class="empty-note">
            <MSym n="auto_awesome" :size="36" style="display: block; margin: 0 auto 12px; color: var(--ink-700)" />
            Le {{ hero.cls }} ne manie pas la magie. Sa puissance est dans l'acier.
        </div>
        <div v-else>
            <div class="turn-banner mine" style="justify-content: space-between">
                <span><MSym n="psychology" fill /> Grimoire</span>
                <span style="font-size: 12px">{{ dispos }}/{{ sorts.length }} disponibles · 1×/quête</span>
            </div>
            <div v-for="g in groupes" :key="g.element">
                <div class="sect-title">
                    <span :style="{ width: '9px', height: '9px', borderRadius: '50%', background: g.cle ? `var(--elem-${g.cle})` : 'var(--ink-500)' }" />
                    {{ g.l }}
                </div>
                <div class="choices" style="margin-bottom: 16px">
                    <ChoiceCard
                        v-for="s in g.sorts"
                        :key="s.sort_id"
                        :icon="g.ic"
                        :image="s.image_url"
                        :title="s.nom"
                        :badge="carteDe(s).badge"
                        :meta="carteDe(s).meta"
                        :el-class="g.cle ? `el-${g.cle}` : ''"
                        :disabled="carteDe(s).disabled"
                        @click="lancer(s)"
                    />
                </div>
            </div>
        </div>
    </div>

    <!-- ================= mode démo (maquettes) ================= -->
    <div v-else-if="!hero.hasSpells" class="empty-note">
        <MSym n="auto_awesome" :size="36" style="display: block; margin: 0 auto 12px; color: var(--ink-700)" />
        Le {{ hero.cls }} ne manie pas la magie. Sa puissance est dans l'acier.
    </div>
    <div v-else>
        <div class="turn-banner mine" style="justify-content: space-between">
            <span><MSym n="psychology" fill /> Grimoire</span>
            <span style="font-size: 12px">Mind {{ mind.cur }}/{{ mind.max }}</span>
        </div>
        <div v-for="(spells, el) in byEl" :key="el">
            <div class="sect-title">
                <span :style="{ width: '9px', height: '9px', borderRadius: '50%', background: `var(--elem-${el})` }" />
                {{ EL_LABEL[el] }}
            </div>
            <div class="choices" style="margin-bottom: 16px">
                <ChoiceCard
                    v-for="sp in spells"
                    :key="sp.id"
                    :icon="sp.icon"
                    :title="sp.name"
                    :meta="sp.desc"
                    :el-class="EL_CLASS[el]"
                    :disabled="mind.cur < 1"
                    @click="emit('cast', sp)"
                />
            </div>
        </div>
    </div>
</template>
