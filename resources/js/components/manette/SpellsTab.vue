<script setup>
// Onglet Sorts (grimoire par élément) — port de SpellsTab (manette-app.jsx).
//
// `sorts` (GET /api/moi, [{sort_id, nom, element, type, disponible}],
// contrat « Sorts des héros ») — grille par élément, badge type (dégâts /
// mental / utilitaire). Taper une carte n'envoie plus rien directement
// (correctif D) : elle ouvre TOUJOURS SpellInfoSheet (nom, élément, type,
// disponibilité, description) — y compris pour un sort épuisé ou hors tour,
// pour qu'on puisse relire son effet sans pouvoir le lancer. C'est le
// bouton « Lancer » de cette feuille qui émet 'choose' avec l'OPTION DE
// MENU : si le menu courant (.menu.propose) contient l'option type "sort"
// du sort (même flux que ActionTab : ciblage via parametres.cibles puis
// POST choix) ; sinon « Lancer » reste désactivé avec la raison (épuisé /
// pas son tour / pas proposé ce tour).
import { computed, ref, watch } from 'vue';
import MSym from '../ui/MSym.vue';
import ChoiceCard from './ChoiceCard.vue';
import SpellInfoSheet from './SpellInfoSheet.vue';
import { optionPourSort, sortsParElement, TYPES_SORT } from '../../store/game';

const props = defineProps({
    hero: { type: Object, required: true },
    /** Sorts réels du héros (GET /moi). */
    sorts: { type: Array, default: () => [] },
    /** Menu courant ({contexte, options}) — null hors de mon tour. */
    menu: { type: Object, default: null },
    /** Choix envoyé, en attente de la résolution du moteur. */
    pending: { type: Boolean, default: false },
});

const emit = defineEmits(['choose']);

const groupes = computed(() => sortsParElement(props.sorts ?? []));
const dispos = computed(() => (props.sorts ?? []).filter((s) => s.disponible !== false).length);
// Élision devant voyelle (« L'Elfe » et non « Le Elfe ») — les classes non
// magiciennes restantes sont masculines (Le Barbare / Le Nain).
const article = computed(() => (/^[aeiouyéèêh]/i.test(props.hero?.cls ?? '') ? "L'" : 'Le '));

/** Carte d'un sort réel : option de menu associée + raison du blocage
 *  (consommée par la carte de la LISTE et par le bouton « Lancer » de
 *  SpellInfoSheet). */
function carteDe(sort) {
    const type = TYPES_SORT[(sort.type ?? '').toLowerCase()] ?? null;
    const epuise = sort.disponible === false;
    const option = epuise ? null : optionPourSort(props.menu, sort.sort_id);
    let meta;
    if (epuise) meta = 'Épuisé — redevient disponible à la prochaine quête';
    else if (option) meta = 'Prêt à lancer — touche pour voir';
    else meta = props.menu ? 'Pas proposé ce tour' : 'Attends ton tour pour lancer';
    return {
        badge: type?.l ?? '',
        meta,
        option,
        disabled: epuise || !option || props.pending,
    };
}

/* Feuille d'information ouverte (SpellInfoSheet) : le sort réel tapé, ou
 * null. `infoOuverte` recalcule carteDe() en direct — si le menu change
 * pendant que la feuille est ouverte, disponibilité/raison suivent. */
const sortOuvert = ref(null);
const infoOuverte = computed(() => (sortOuvert.value ? carteDe(sortOuvert.value) : null));

function ouvrirInfo(sort) {
    sortOuvert.value = sort;
}

/** Un nouveau menu rend l'option affichée périmée — referme la feuille,
 *  comme CibleSheet côté ManetteView. */
watch(() => props.menu, () => { sortOuvert.value = null; });

function lancerConfirme() {
    const option = infoOuverte.value?.option;
    sortOuvert.value = null;
    if (option) emit('choose', option);
}
</script>

<template>
    <div v-if="!sorts.length" class="empty-note">
        <MSym n="auto_awesome" :size="36" style="display: block; margin: 0 auto 12px; color: var(--ink-700)" />
        <template v-if="hero.classe === 'elfe'">
            L'Elfe ne manie pas encore la magie — elle s'éveille dans l'arbre de
            compétences (nœud « Première magie ».)
        </template>
        <template v-else>
            {{ article }}{{ hero.cls }} ne manie pas la magie. Sa puissance est dans l'acier.
        </template>
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
                    @click="ouvrirInfo(s)"
                />
            </div>
        </div>

        <!-- feuille d'information + lancement (correctif D) -->
        <SpellInfoSheet
            v-if="sortOuvert"
            :sort="sortOuvert"
            :carte="infoOuverte"
            @lancer="lancerConfirme"
            @close="sortOuvert = null"
        />
    </div>
</template>
