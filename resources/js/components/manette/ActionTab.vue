<script setup>
// Onglet Action (combat) — port de ActionTab (manette-app.jsx).
// Le menu contextuel reçu par .menu.propose ({contexte, options: [{id,
// libelle, type, parametres}]}) — chaque tap émet 'choose' avec l'option ;
// `pending` gèle les boutons jusqu'au prochain .groupe.etat.
import { nextTick, ref, watch } from 'vue';
import MSym from '../ui/MSym.vue';
import ChoiceCard from './ChoiceCard.vue';
import InitMini from './InitMini.vue';
import JetDes from '../ui/JetDes.vue';
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
    // ⚠ `retraite` : PAS `sprint`, déjà pris par `franchir` (le saut de fosse,
    // ci-dessous) — deux actions qui partageraient une icône se confondraient
    // exactement au moment où elles apparaissent ensemble au menu (René,
    // 2026-09-18 : « un bonhomme qui court »). `directions_run` est le coureur
    // simple, distinct.
    sortie: 'logout',
    retraite: 'directions_run',
    desamorcer: 'handyman',
    franchir: 'sprint',
    // CHUTE DE BLOCS (livret p. 14, 2026-09-24) : le seul choix qui reste au
    // héros debout sur le bloc — même glyphe que le bloc lui-même sur la carte
    // (`BLOC_ICONE` dans symboles.js), pour qu'un joueur qui vient de VOIR le
    // bloc tombé reconnaisse l'action qui en parle.
    s_ecarter_du_bloc: 'square',
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
    // ⚠ `equiper` PARTAGEAIT `swords` avec `attaque` juste au-dessus dans ce
    // même menu — capture `combat-apres-haut.png` (2026-09-18) : « Attaquer »
    // et « Équiper », deux lignes consécutives, icône PIXEL POUR PIXEL
    // identique, seul le libellé les distingue. `checkroom` (le cintre —
    // « on s'habille ») dit qu'on ÉQUIPE une pièce, sans reprendre l'épée
    // croisée réservée à l'action qui FRAPPE.
    equiper: 'checkroom',
    // ⚠ `desequiper` (« Ranger ») portait `backpack` — EXACTEMENT l'icône
    // d'`objet_libre` (« Utiliser un objet ») juste en dessous dans ce même
    // menu (René, 2026-09-18, capture `armes-avant.png`/`combat-avant2-haut`:
    // deux lignes consécutives strictement identiques). Ranger RANGE une
    // pièce déjà portée — `archive` (le tiroir qu'on referme) le distingue
    // d'« Utiliser un objet », qui reste au sac.
    desequiper: 'archive',
    // Échanger / jeter (2026-09-17) : sans entrée ici, tombaient sur
    // `touch_app` comme `objet`/`objet_libre` avant eux — même défaut, repéré
    // en ajoutant leurs voisins directs ci-dessus.
    echanger: 'swap_horiz',
    jeter: 'delete_forever',
    // Ajouté le 2026-09-04 avec l'*Étreinte des Ronces* (carte de Dread
    // *Creeping Grasp*) : « spend an action to DESTROY THE VINES ». On coupe,
    // d'où le sécateur plutôt qu'une main tendue.
    liberer_entraves: 'content_cut',
    // ⚠ Ces deux-là MANQUAIENT et tombaient sur `touch_app` depuis leur
    // création — même défaut que celui noté plus haut le 2026-09-01, repéré en
    // ajoutant le voisin du dessus.
    soin_allie: 'healing',
    detacher_rejetons: 'pest_control',
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
 * Depuis le 2026-09-18 (contrat « creneau — chaque option dit ce qu'elle
 * coûte ») : LIT `option.creneau`, publié par `ResolveurTour::creneauOption()`,
 * IL NE LE RE-DÉRIVE PLUS DU TYPE. Ce fichier recopiait cette règle serveur en
 * JS depuis toujours, et la copie a MENTI TROIS FOIS quand la règle a bougé
 * sans elle — les trois cicatrices sont racontées plus bas, dans le REPLI qui
 * les a vues naître et qui est le seul endroit où leur cause existe encore.
 * Publier la décision supprime la classe de défaut : ce fichier n'a plus de
 * table à tenir à jour, il n'a qu'un champ à lire.
 *
 * Le serveur RETIRE les options d'un créneau consommé, mais le menu affiché
 * peut dater d'avant l'action : on grise plutôt que de laisser le joueur
 * récolter un 422 « Tu as déjà agi ce tour ».
 */
function creneauConsomme(option) {
    const moi = props.creneaux;
    if (!moi) return false;
    if (moi.a_joue) return true;

    // ⚠ DEUX exceptions, et seulement deux, qui restent des `if` sur le TYPE :
    // Potion d'héroïsme / Rage guerrière (`attaque`, corrigé 2026-09-11) et
    // Réserve arcanique / Baguette de Rappel (`sort`). Elles ne dépendent PAS
    // du prix de l'option — une attaque coûte bien l'action — mais de QUI la
    // regarde : le même bouton `attaque` (ou `sort`) est tantôt permis tantôt
    // refusé selon un bonus déjà consommé ce tour. « `creneau` dit le prix ;
    // ces drapeaux disent qui a déjà payé » (contrat) — un champ de PRIX ne
    // peut pas trancher une question d'ÉTAT, donc ça ne migre jamais dans le
    // bloc qui lit `creneau` ci-dessous, quel que soit ce que publiera un
    // jour `creneauOption()` pour ces deux types.
    if (option?.type === 'attaque') return !!moi.a_agi && !moi.attaque_supplementaire;
    if (option?.type === 'sort') return !!moi.a_agi && !moi.sort_bonus_disponible;

    if (option?.creneau) {
        switch (option.creneau) {
            case 'mouvement':
                return !!moi.a_deplace;
            // Interaction LIBRE (porte, retraite, style, objet_libre, jeter…)
            // et action TERMINANTE (concentration, relever, attente) : aucune
            // des deux ne grise avant `a_joue`, déjà tranché plus haut.
            case 'interaction':
            case 'tour':
                return false;
            default: // 'action'
                return !!moi.a_agi;
        }
    }

    // ⚠ REPLI DÉGRADÉ — `option.creneau` ABSENT (un menu resté en cache d'avant
    // ce champ, ou le menu de secours de `GenererMenu::failed()`). C'est ICI,
    // et UNIQUEMENT ici jusqu'au 2026-09-18, que vivait la règle : une copie
    // JS de `ResolveurTour::creneauOption()`, recopiée à la main plutôt que
    // lue, qui a menti TROIS FOIS quand le serveur a changé sans elle —
    // `actionner_levier` s'y croyait encore gratuit après que le serveur lui
    // eut donné un prix (jet de Body, 2026-08-24), `objet_libre` y manquait
    // tout à fait et grisait « Utiliser un objet » dès `a_agi` alors qu'une
    // potion se boit justement après avoir frappé (2026-09-01), et `jeter` a
    // bien failli subir le même sort avant d'être ajouté ici à temps
    // (2026-09-17). La CAUSE — cette table recopiée — est supprimée du chemin
    // normal ci-dessus ; ce switch ne reste que comme filet pour un menu trop
    // vieux pour porter `creneau`, jamais comme source de vérité : retomber
    // dessus pour TOUT dégriser à l'aveugle aurait été pire que le tolérer.
    switch (option?.type) {
        case 'deplacement':
        case 'franchissement':
            return !!moi.a_deplace;
        case 'ouvrir_porte':
        case 'sortie':
        case 'objet_libre':
        case 'retraite':
        case 'style':
        case 'jeter':
        case 'concentration':
        case 'relever':
        case 'attente':
            return false;
        default:
            return !!moi.a_agi;
    }
}

/**
 * Le fil du combat défile MAINTENANT dans son propre cadre (René, 2026-09-05 :
 * « séparer la section des actions et des logs pour avoir 2 scrolls
 * indépendants »). Il faut donc le ramener sur la dernière ligne à chaque
 * ajout : un journal qui ne suit pas son entrée la plus récente est pire que
 * pas de journal du tout — avant la séparation, la page entière défilait et la
 * nouveauté arrivait naturellement en bas.
 */
const filDuCombat = ref(null);

watch(() => props.journal.length, async () => {
    await nextTick();
    const el = filDuCombat.value;

    if (el) el.scrollTop = el.scrollHeight;
});

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
    <!-- ⚠ DEUX VOLETS À DÉFILEMENT SÉPARÉ. Les actions et le fil partageaient
         le défilement de la page : lire le fil poussait les boutons hors de
         l'écran, et jouer masquait ce qui venait de se passer. Le cadre ne
         prend de la hauteur que s'il y a un fil — sans lui, les actions
         occupent tout. -->
    <div class="act-volets">
    <div class="act-scroll">
    <!-- le menu vient du MJ (.menu.propose) -->
    <div v-if="menu">
        <div v-if="pending" class="turn-banner wait">
            <MSym n="hourglass_top" />
            {{ thinking ? 'Le MJ réfléchit…' : 'Choix envoyé — le moteur résout…' }}
        </div>
        <div v-else class="turn-banner mine"><MSym n="bolt" fill /> C'est ton tour — choisis une action</div>
        <InitMini :cur="initCur ?? hero.name.slice(0, 3).toUpperCase()" :order="initOrder" />
        <div class="sect-title"><MSym n="touch_app" :size="16" /> {{ menu.contexte || 'Actions' }}</div>

        <!-- ⚠ `situation` était PRODUIT par le serveur et rendu NULLE PART
             (constaté le 2026-09-11 en cherchant pourquoi la Potion d'héroïsme
             « ne donnait pas de 2e attaque » : elle la donnait, et rien ne le
             disait). Un effet automatique que rien n'annonce est injouable —
             le joueur frappait une fois, revoyait le même menu, et terminait
             son tour sans savoir qu'une seconde frappe l'attendait.
             Il porte aussi « Vous ne pouvez pas agir ce tour », « Tour terminé »
             et le message de secours d'un menu de repli : trois situations où
             le silence se lit comme une panne. -->
        <p v-if="menu.situation" class="menu-situation">
            <MSym n="info" :size="15" fill /> {{ menu.situation }}
        </p>
        <div class="choices">
            <ChoiceCard
                v-for="o in menu.options"
                :key="o.id"
                :icon="iconeOption(o)"
                :title="o.libelle"
                :meta="metaOption(o)"
                :el-class="classeOption(o)"
                :infini="o.creneau === 'interaction'"
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

    </div>

    <!-- journal de combat mécanique (.combat.journal) : ce que le moteur vient
         de résoudre (attaques, dégâts, tour des monstres) — visible même hors
         de mon tour, sinon on ne verrait que ses PV bouger. -->
    <div v-if="journal.length" class="cbt-log">
        <div class="sect-title"><MSym n="history" :size="16" /> Fil du combat</div>
        <div ref="filDuCombat" class="cbt-lines">
            <div v-for="l in journal" :key="l.id" class="cbt-entree">
                <div class="cbt-line" :class="`t-${l.ton}`">
                    <!-- Un talent qui s'active tout seul se VOIT (2026-09-25) :
                         son ICÔNE PROPRE (App\Engine\MotsClesTalent), pas celle
                         générique du ton — c'est ce qui distingue « Œil du
                         mineur » de « Contresort » d'un coup d'œil. -->
                    <MSym :n="l.ton === 'talent' ? (l.talent?.icone || 'hub') : (ICONE_JOURNAL[l.ton] || 'chevron_right')" :size="15" fill />
                    <span>{{ l.texte }}</span>
                </div>
                <!-- Le jet qui a produit la ligne : c'est l'HISTORIQUE. Les dés
                     du monstre qui vient de frapper y sont aussi — l'overlay ne
                     révélait que ceux de sa propre action. -->
                <JetDes v-if="l.des" :jet="l.des" compact />
            </div>
        </div>
    </div>
    </div>
</template>

<style scoped>
/* Les deux volets remplissent la hauteur offerte par `.body`, qui cesse alors
   de défiler lui-même (son contenu tient exactement). `min-height: 0` est
   indispensable : sans lui un enfant de flex refuse de rétrécir, les deux
   volets poussent leur propre plafond et plus rien ne défile. */
.act-volets { display: flex; flex-direction: column; height: 100%; min-height: 0; gap: 12px; }
.act-scroll { flex: 1 1 auto; min-height: 0; overflow-y: auto; -webkit-overflow-scrolling: touch; }
.act-scroll::-webkit-scrollbar { width: 0; }

/* ⚠ Le fil prend ce dont il a besoin, sans jamais dépasser 40 % : les actions
   sont ce pour quoi on ouvre la manette, et un fil bavard ne doit pas les
   chasser de l'écran. */
.cbt-log { flex: 0 0 auto; max-height: 40%; display: flex; flex-direction: column; min-height: 0;
  border-top: var(--line); padding-top: 10px; }
.cbt-lines { display: flex; flex-direction: column; gap: 4px;
  min-height: 0; overflow-y: auto; -webkit-overflow-scrolling: touch; }
.cbt-lines::-webkit-scrollbar { width: 0; }
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
/* Talent qui s'active tout seul (2026-09-25) — même jeton doré que le popup
   (TalentPopup.vue) : un talent n'est ni un dégât ni un gain, sa propre teinte. */
.cbt-line.t-talent { color: var(--gold, #c9a24a); font-weight: 700; }
</style>
