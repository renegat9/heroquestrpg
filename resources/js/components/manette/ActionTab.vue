<script setup>
// Onglet Action (combat) — port de ActionTab (manette-app.jsx).
// Le menu contextuel reçu par .menu.propose ({contexte, options: [{id,
// libelle, type, parametres}]}) — chaque tap émet 'choose' avec l'option ;
// `pending` gèle les boutons jusqu'au prochain .groupe.etat.
import MSym from '../ui/MSym.vue';
import ChoiceCard from './ChoiceCard.vue';
import InitMini from './InitMini.vue';
import { elementInfo, TYPES_SORT } from '../../store/game';

const props = defineProps({
    hero: { type: Object, required: true },
    /** Menu réel ({contexte, options}) — null hors de mon tour. */
    menu: { type: Object, default: null },
    /** Boutons gelés : mon choix envoyé OU le MJ réfléchit pour le groupe. */
    pending: { type: Boolean, default: false },
    /** Geste du MJ en cours (job LLM), distinct d'un choix envoyé — affine le
     *  libellé du bandeau (sinon « Choix envoyé » serait trompeur). */
    thinking: { type: Boolean, default: false },
    /** Initiative réelle pour InitMini ([{k, foe}]). */
    initOrder: { type: Array, default: null },
    /** Jeton courant de la file d'initiative. */
    initCur: { type: String, default: null },
    /** Sorts du héros (GET /moi) — sert à l'icône par élément des options
     *  type "sort" quand l'option ne porte pas elle-même son élément. */
    sorts: { type: Array, default: null },
    /** Journal de combat mécanique (.combat.journal) : [{id, texte, ton}] —
     *  les plus anciennes en premier, la plus récente en bas. */
    journal: { type: Array, default: () => [] },
});

const emit = defineEmits(['choose']);

/** Icône par type d'option du contrat (+ pièges doc 10 : désamorcer /
 *  franchir une fosse détectée — des jets de Body proposés en menu ;
 *  + sorts doc 02 : sort / parchemin / concentration). */
const ICONE_TYPE = {
    action: 'touch_app',
    dialogue: 'forum',
    jet: 'casino',
    attaque: 'swords',
    deplacement: 'directions_walk',
    desamorcer: 'handyman',
    franchir: 'sprint',
    sort: 'auto_awesome',
    parchemin: 'description',
    concentration: 'self_improvement',
};

/** Élément d'une option type "sort" : porté par l'option ou retrouvé
 *  dans les sorts du héros (/moi) via sort_id. */
function elementOption(o) {
    if (o.type !== 'sort') return null;
    const direct = elementInfo(o.parametres?.element);
    if (direct) return direct;
    const sortId = o.parametres?.sort_id;
    const sort = (props.sorts ?? []).find((s) => String(s.sort_id) === String(sortId));
    return elementInfo(sort?.element);
}

function iconeOption(o) {
    return elementOption(o)?.ic ?? ICONE_TYPE[o.type] ?? 'touch_app';
}

function classeOption(o) {
    const el = elementOption(o);
    return el ? `el-${el.cle}` : '';
}

/** Méta contextuelle des nouveaux types (sort/parchemin/concentration). */
function metaOption(o) {
    if (o.type === 'sort') {
        const el = elementOption(o);
        const sortId = o.parametres?.sort_id;
        const type = (props.sorts ?? []).find((s) => String(s.sort_id) === String(sortId))?.type;
        const badge = TYPES_SORT[(type ?? '').toLowerCase()]?.l;
        return [el?.l, badge, 'Sort — 1×/quête'].filter(Boolean).join(' · ');
    }
    if (o.type === 'parchemin') return 'Parchemin — consommé dans tous les cas';
    if (o.type === 'concentration') return 'Sacrifie le tour — récupère un sort épuisé';
    return '';
}

/** Icône du journal de combat par `ton` (voir App\Partie\JournalCombat). */
const ICONE_JOURNAL = {
    degats: 'swords',
    mort: 'skull',
    subit: 'bloodtype',
    chute: 'personal_injury',
    pare: 'shield',
    succes: 'check_circle',
    echec: 'cancel',
    info: 'chevron_right',
};
</script>

<template>
    <!-- le menu vient du MJ (.menu.propose) -->
    <div v-if="menu">
        <div v-if="pending" class="turn-banner wait">
            <MSym n="hourglass_top" />
            {{ thinking ? 'Le MJ réfléchit…' : 'Choix envoyé — le moteur résout…' }}
        </div>
        <div v-else class="turn-banner mine"><MSym n="bolt" fill /> C'est ton tour — choisis une action</div>
        <InitMini :cur="initCur ?? hero.name.slice(0, 3).toUpperCase()" :order="initOrder" />
        <div class="sect-title"><MSym n="touch_app" :size="16" /> {{ menu.contexte || 'Actions' }}</div>
        <div class="choices">
            <ChoiceCard
                v-for="o in menu.options"
                :key="o.id"
                :icon="iconeOption(o)"
                :title="o.libelle"
                :meta="metaOption(o)"
                :el-class="classeOption(o)"
                :disabled="pending"
                @click="emit('choose', o)"
            />
        </div>
    </div>

    <!-- en attente (pas de menu : pas mon tour, ou pas encore arrivé) -->
    <div v-else>
        <div class="turn-banner wait"><MSym n="hourglass_top" /> Le maître du jeu prépare la suite…</div>
        <InitMini :cur="initCur ?? '···'" :order="initOrder" />
        <div class="empty-note">La partie se poursuit — tu reprendras la main dans un instant.</div>
    </div>

    <!-- journal de combat mécanique (.combat.journal) : ce que le moteur vient
         de résoudre (attaques, dégâts, tour des monstres) — visible même hors
         de mon tour, sinon on ne verrait que ses PV bouger. -->
    <div v-if="journal.length" class="cbt-log">
        <div class="sect-title"><MSym n="history" :size="16" /> Fil du combat</div>
        <div class="cbt-lines">
            <div v-for="l in journal" :key="l.id" class="cbt-line" :class="`t-${l.ton}`">
                <MSym :n="ICONE_JOURNAL[l.ton] || 'chevron_right'" :size="15" fill />
                <span>{{ l.texte }}</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.cbt-log { margin-top: 16px; }
.cbt-lines { display: flex; flex-direction: column; gap: 4px; }
.cbt-line {
    display: flex; align-items: center; gap: 7px;
    font-size: 13.5px; line-height: 1.35;
    padding: 5px 9px; border-radius: 8px;
    background: var(--stone-800, oklch(0.2 0.015 60));
    color: var(--ink-300, oklch(0.82 0.02 70));
}
.cbt-line .msym { flex: none; opacity: 0.9; }
.cbt-line.t-degats { color: var(--torch, oklch(0.78 0.14 55)); }
.cbt-line.t-mort   { color: oklch(0.82 0.16 25); font-weight: 700; }
.cbt-line.t-subit  { color: oklch(0.72 0.15 25); }
.cbt-line.t-chute  { color: oklch(0.72 0.17 20); font-weight: 700; }
.cbt-line.t-succes { color: oklch(0.8 0.13 150); }
.cbt-line.t-echec,
.cbt-line.t-pare   { color: var(--ink-500, oklch(0.6 0.02 70)); }
</style>
