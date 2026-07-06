<script setup>
// Onglet Action (combat) — port de ActionTab (manette-app.jsx).
// Deux sources possibles :
// - `menu` (mode connecté) : le menu contextuel reçu par .menu.propose
//   ({contexte, options: [{id, libelle, type, parametres}]}) — chaque tap
//   émet 'choose' avec l'option ; `pending` gèle les boutons jusqu'au
//   prochain .groupe.etat ;
// - sans `menu` (mode démo) : les actions locales des maquettes.
import MSym from '../ui/MSym.vue';
import ChoiceCard from './ChoiceCard.vue';
import InitMini from './InitMini.vue';
import { elementInfo, TYPES_SORT } from '../../store/game';

const props = defineProps({
    myTurn: { type: Boolean, required: true },
    hero: { type: Object, required: true },
    /** Menu réel ({contexte, options}) — null en mode démo. */
    menu: { type: Object, default: null },
    /** Boutons gelés : mon choix envoyé OU le MJ réfléchit pour le groupe. */
    pending: { type: Boolean, default: false },
    /** Geste du MJ en cours (job LLM), distinct d'un choix envoyé — affine le
     *  libellé du bandeau (sinon « Choix envoyé » serait trompeur). */
    thinking: { type: Boolean, default: false },
    /** Initiative réelle pour InitMini ([{k, foe}]) — null en démo. */
    initOrder: { type: Array, default: null },
    /** Jeton courant de la file d'initiative (mode connecté). */
    initCur: { type: String, default: null },
    /** Sorts du héros (GET /moi) — sert à l'icône par élément des options
     *  type "sort" quand l'option ne porte pas elle-même son élément. */
    sorts: { type: Array, default: null },
});

const emit = defineEmits(['attack', 'open-spells', 'move', 'search', 'pass', 'choose']);

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
</script>

<template>
    <!-- mode connecté : le menu vient du MJ (.menu.propose) -->
    <div v-if="menu">
        <div v-if="pending" class="turn-banner wait">
            <MSym n="hourglass_top" />
            {{ thinking ? 'Le MJ réfléchit…' : 'Choix envoyé — le moteur résout…' }}
        </div>
        <div v-else class="turn-banner mine"><MSym n="bolt" fill /> C'est ton tour — choisis une action</div>
        <InitMini :cur="initCur ?? hero.key.toUpperCase().slice(0, 3)" :order="initOrder" />
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

    <!-- en attente (pas de menu) -->
    <div v-else-if="!myTurn">
        <div class="turn-banner wait"><MSym n="hourglass_top" /> Le maître du jeu prépare la suite…</div>
        <InitMini :cur="initCur ?? 'orc'" :order="initOrder" />
        <div class="empty-note">La partie se poursuit — tu reprendras la main dans un instant.</div>
    </div>

    <!-- mode démo : actions locales des maquettes -->
    <div v-else>
        <div class="turn-banner mine"><MSym n="bolt" fill /> C'est ton tour — choisis une action</div>
        <InitMini :cur="initCur ?? hero.key.toUpperCase().slice(0, 3)" :order="initOrder" />
        <div class="sect-title"><MSym n="touch_app" :size="16" /> Actions</div>
        <div class="choices">
            <ChoiceCard icon="swords" title="Attaquer" :meta="`${hero.atk} dés de crâne · ennemi à portée`" @click="emit('attack')" />
            <ChoiceCard v-if="hero.hasSpells" icon="auto_awesome" title="Lancer un sort" meta="Choisir dans le grimoire" @click="emit('open-spells')" />
            <ChoiceCard icon="directions_walk" title="Se déplacer" meta="Jusqu'à 5 cases" @click="emit('move')" />
            <ChoiceCard icon="travel_explore" title="Fouiller la pièce" meta="Pièges · trésors · passages" @click="emit('search')" />
            <ChoiceCard icon="forum" title="Parler" meta="Interpeller une créature" disabled />
            <ChoiceCard icon="skip_next" title="Passer le tour" meta="Garder ses forces" danger :chev="false" @click="emit('pass')" />
        </div>
    </div>
</template>
