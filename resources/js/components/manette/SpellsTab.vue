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
import SortsElfiquesPicker from '../SortsElfiquesPicker.vue';
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
    /** Au hub : le rechoix des sorts elfiques n'est offert qu'entre deux quêtes. */
    auHub: { type: Boolean, default: false },
    /** Envoi du rechoix en cours. */
    rechoixEnCours: { type: Boolean, default: false },
});

const emit = defineEmits(['choose', 'rechoisir-elfiques']);

/* RECHOIX ELFIQUE (doc 02 §7bis) — réservé à l'Elfe qui a pris la voie du
   répertoire : ses 3 sorts se rechoisissent au hub, là où une école élémentaire
   est définitive. On le déduit de SES sorts plutôt que d'un drapeau : porter un
   sort `elfique`, c'est avoir choisi cette voie. */
const voieElfique = computed(() => (props.sorts ?? []).some((s) => s.element === 'elfique'));
const rechoixOuvert = ref(false);
const nouveauxSorts = ref([]);

function ouvrirRechoix() {
    nouveauxSorts.value = (props.sorts ?? [])
        .filter((s) => s.element === 'elfique')
        .map((s) => s.sort_id);
    rechoixOuvert.value = true;
}

function confirmerRechoix() {
    emit('rechoisir-elfiques', [...nouveauxSorts.value]);
    rechoixOuvert.value = false;
}

// Le rechoix est refermé dès que les sorts du héros changent (le serveur a
// répondu et /moi a été relu) : garder la feuille ouverte laisserait croire
// que rien ne s'est passé.
watch(() => props.sorts, () => { rechoixOuvert.value = false; });

const groupes = computed(() => sortsParElement(props.sorts ?? []));
const dispos = computed(() => (props.sorts ?? []).filter((s) => s.disponible !== false).length);
// Élision devant voyelle (« L'Elfe » et non « Le Elfe ») — les classes non
// classes restantes sont masculines (Le Barbare / Le Nain / Le Magicien).
const article = computed(() => (/^[aeiouyéèêh]/i.test(props.hero?.cls ?? '') ? "L'" : 'Le '));

/** Carte d'un sort réel : option de menu associée + raison du blocage
 *  (consommée par la carte de la LISTE et par le bouton « Lancer » de
 *  SpellInfoSheet). */
function carteDe(sort) {
    const type = TYPES_SORT[(sort.type ?? '').toLowerCase()] ?? null;
    const epuise = sort.disponible === false;
    // ⚠ `optionPourSort` rend désormais une PAIRE {option, entree} : le menu
    // ne porte plus une option par sort, mais une liste. L'onglet Sorts reste
    // un raccourci — choisir ici évite au joueur de rouvrir la liste.
    const choix = epuise ? null : optionPourSort(props.menu, sort.sort_id);
    const option = choix;
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
    const choix = infoOuverte.value?.option;
    sortOuvert.value = null;
    // On remonte la PAIRE : ManetteView saute alors le niveau de liste et
    // passe directement au ciblage, puisque le sort est déjà désigné.
    if (choix) emit('choose', choix);
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

        <!-- Voie elfique : les 3 sorts se rechoisissent AU HUB, entre deux
             quêtes (une école élémentaire, elle, est définitive). -->
        <div v-if="voieElfique && auHub" class="spl-rechoix">
            <template v-if="!rechoixOuvert">
                <button class="sac-btn ghost" :disabled="rechoixEnCours" @click="ouvrirRechoix">
                    <MSym n="swap_horiz" :size="16" /> Rechoisir mes trois sorts
                </button>
                <p class="spl-note">Avant de repartir, tu peux emporter d'autres sorts du répertoire.</p>
            </template>
            <template v-else>
                <SortsElfiquesPicker v-model="nouveauxSorts" :max="3" />
                <div class="spl-btns">
                    <button
                        class="sac-btn gold"
                        :disabled="rechoixEnCours || nouveauxSorts.length !== 3"
                        @click="confirmerRechoix"
                    >Emporter ces trois-là</button>
                    <button class="sac-btn ghost" :disabled="rechoixEnCours" @click="rechoixOuvert = false">
                        Annuler
                    </button>
                </div>
            </template>
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

<style scoped>
/* Préfixe `spl-` : les blocs <style> globaux du projet font fuir les noms
   génériques d'une vue à l'autre. */
.spl-rechoix { margin: 10px 0 14px; }
.spl-note { margin: 6px 0 0; font-size: 12px; color: var(--ink-300, #b6a88a); }
.spl-btns { display: flex; gap: 8px; margin-top: 8px; }
</style>
