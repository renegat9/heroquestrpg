<script setup>
// Onglet Action (combat) — port de ActionTab (manette-app.jsx).
// Le menu contextuel reçu par .menu.propose ({contexte, options: [{id,
// libelle, type, parametres}]}) — chaque tap émet 'choose' avec l'option ;
// `pending` gèle les boutons jusqu'au prochain .groupe.etat.
import MSym from '../ui/MSym.vue';
import ChoiceCard from './ChoiceCard.vue';
import InitMini from './InitMini.vue';
import JetDes from './JetDes.vue';
import { elementInfo, TYPES_SORT } from '../../store/game';

const props = defineProps({
    hero: { type: Object, required: true },
    /** Menu réel ({contexte, options}) — null hors de mon tour. */
    menu: { type: Object, default: null },
    /** Boutons gelés : mon choix envoyé OU le MJ réfléchit pour le groupe. */
    pending: { type: Boolean, default: false },
    /** Créneaux du tour de MON héros ({a_joue, a_deplace, a_agi}) — grise les
     *  options dont le créneau est déjà consommé. `null` = ne rien griser. */
    creneaux: { type: Object, default: null },
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
    // Fouiller un meuble (coffre, tombeau, armoire — doc 17) : icône distincte
    // de la fouille de salle, ce n'est pas la même action ni le même créneau.
    fouille_mobilier: 'inventory_2',
    jet: 'casino',
    attaque: 'swords',
    // Frappe balayée (Frénésie sanguinaire) : sans cible à choisir, elle part
    // au clic — l'icône doit dire que ça tourne sur soi-même.
    attaque_balayee: 'cyclone',
    // Styles Élémentaires du Moine : activation, rayon, braise.
    style: 'self_improvement',
    rayon: 'bolt',
    degat_differe: 'local_fire_department',
    deplacement: 'directions_walk',
    // Proposer de rentrer (donjon nettoyé) vs BATTRE EN RETRAITE (ça tourne
    // mal) : deux gestes opposés, deux icônes — l'une part, l'autre recule.
    sortie: 'logout',
    retraite: 'u_turn_left',
    desamorcer: 'handyman',
    franchir: 'sprint',
    sort: 'auto_awesome',
    parchemin: 'description',
    // Ajoutés le 2026-09-01 : sans entrée ici, ils tombaient sur `touch_app`.
    objet: 'backpack',
    objet_libre: 'backpack',
    sacrifice_sort: 'bloodtype',
    relever: 'accessibility_new',
    attente: 'hourglass_bottom',
    franchissement: 'moving',
    fouiller: 'search',
    concentration: 'self_improvement',
    ouvrir_porte: 'door_open',
    actionner_levier: 'toggle_on',
    equiper: 'swords',
    desequiper: 'backpack',
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
    // ÉPREUVE : sa DESCRIPTION de catalogue, la phrase qui dit ce qu'on voit et
    // ce qu'on tente. Elle était publiée dans l'option et affichée nulle part —
    // le joueur lisait « Fresque en langue morte — jet de Mind (difficulté 2) »
    // sans savoir de quoi il s'agit. ⚠ C'est aussi le SEUL endroit qui la donne
    // quand aucune clé d'API n'écrit le récit de la salle, et une partie sans
    // clé est un mode supporté.
    if (o.parametres?.description) return o.parametres.description;
    return '';
}

/**
 * Créneau consommé par cette option pour MON héros ?
 *
 * Miroir de `App\Partie\ResolveurTour::creneauOption()` — garder les deux en
 * phase. Le serveur RETIRE les options d'un créneau consommé, mais le menu
 * affiché peut dater d'avant l'action : on grise plutôt que de laisser le joueur
 * récolter un 422 « Tu as déjà agi ce tour ».
 *
 * Tolérant à l'absence des drapeaux (client plus ancien que le serveur) : sans
 * eux, rien n'est grisé — le comportement d'avant.
 */
function creneauConsomme(option) {
    const moi = props.creneaux;
    if (!moi) return false;
    if (moi.a_joue) return true;

    switch (option?.type) {
        case 'deplacement':
        case 'franchissement':
            return !!moi.a_deplace;
        // Ouvrir une porte = interaction LIBRE (E2).
        // ⚠ `actionner_levier` N'EST PLUS ICI (corrigé 2026-09-01) : le serveur
        // lui fait coûter l'action depuis le 2026-08-24, quand forcer un levier
        // est devenu un jet de Body. Ce miroir croyait encore qu'il était
        // gratuit, et gardait donc l'option cliquable après avoir agi — pour un
        // 422. Un miroir se vérifie, il ne se déclare pas.
        case 'ouvrir_porte':
        case 'sortie':
        // ⚠ `objet_libre` MANQUAIT : il tombait au `default` et l'option
        // « Utiliser un objet » se grisait dès que le héros avait agi, alors
        // qu'une potion se boit justement après avoir frappé. Le coût d'un
        // objet dépend de l'OBJET, pas du type : la liste, elle, ne contient
        // que du gratuit quand l'action est dépensée.
        case 'objet_libre':
        // Proposer la retraite ne coûte pas son tour : c'est une proposition
        // au groupe, pas une action — et elle doit rester possible au pire
        // moment, sinon ce n'est pas une retraite.
        case 'retraite':
        // Activer un Style Élémentaire ne coûte aucun créneau (miroir de
        // `ResolveurTour::creneauOption`).
        case 'style':
            return false;
        // Actions terminantes : disponibles tant que le tour n'est pas fini.
        case 'concentration':
        case 'relever':
        case 'attente':
            return false;
        default:
            return !!moi.a_agi;
    }
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
    tresor: 'diamond',
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
                :disabled="pending || creneauConsomme(o)"
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
            <div v-for="l in journal" :key="l.id" class="cbt-entree">
                <div class="cbt-line" :class="`t-${l.ton}`">
                    <MSym :n="ICONE_JOURNAL[l.ton] || 'chevron_right'" :size="15" fill />
                    <span>{{ l.texte }}</span>
                </div>
                <!-- Le jet qui a produit la ligne : c'est l'HISTORIQUE. Les dés
                     du monstre qui vient de frapper y sont aussi — l'overlay ne
                     révélait que ceux de sa propre action. -->
                <JetDes v-if="l.des" :jet="l.des" compact />
            </div>
        </div>
    </div>
</template>

<style scoped>
.cbt-log { margin-top: 16px; }
.cbt-lines { display: flex; flex-direction: column; gap: 4px; }
.cbt-entree { display: flex; flex-direction: column; gap: 3px; }
.cbt-entree :deep(.jet-des) { padding: 2px 9px 5px; }
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
/* Butin de fouille : or/potion/artefact — le seul ton « gain » du fil. */
.cbt-line.t-tresor { color: oklch(0.85 0.14 90); font-weight: 700; }
.cbt-line.t-echec,
.cbt-line.t-pare   { color: var(--ink-500, oklch(0.6 0.02 70)); }
</style>
