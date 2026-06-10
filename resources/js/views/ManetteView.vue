<script setup>
// MANETTE JOUEUR (portrait, téléphone) — port fidèle de
// reference/heroquest/manette-app.jsx (+ manette.css, importé globalement).
// Données de démo locales ; les points d'intégration temps réel / API sont
// isolés ci-dessous (usePlayerChannel + useApi), comme dans TableView.
import { computed, onUnmounted, ref, watch } from 'vue';
import MSym from '../components/ui/MSym.vue';
import ActionTab from '../components/manette/ActionTab.vue';
import FicheTab from '../components/manette/FicheTab.vue';
import SpellsTab from '../components/manette/SpellsTab.vue';
import SacTab from '../components/manette/SacTab.vue';
import MarketTab from '../components/manette/MarketTab.vue';
import FlowSheet from '../components/manette/FlowSheet.vue';
import VoteSheet from '../components/manette/VoteSheet.vue';
import { HEROES, NARRATION_OUVERTURE, SHOP } from '../data/demo';
import { usePlayerChannel } from '../composables/useEcho';
import { useApi } from '../composables/useApi';
import { useGameStore } from '../store/game';

const props = defineProps({
    groupe: { type: String, required: true },
});

const store = useGameStore();
store.setGroupe(props.groupe);
const api = useApi();

/* ---- état local (données de démo, remplacées à terme par le serveur) ---- */
const heroKey = ref('mage');
const hero = computed(() => HEROES[heroKey.value]);
const tab = ref('action');
const scene = ref('combat'); // combat | marche
const thinking = ref(false);
const conn = ref('ok'); // 'ok' | 'warn'

const body = ref({ ...HEROES[heroKey.value].body });
const mind = ref({ ...HEROES[heroKey.value].mind });
watch(heroKey, (k) => {
    body.value = { ...HEROES[k].body };
    mind.value = { ...HEROES[k].mind };
});

const myTurn = ref(true);
const narr = ref(NARRATION_OUVERTURE);

// flow : { kind: 'attack'|'spell', step: 'target'|'rolling'|'result', spell?, target?, dice?, heal? }
const flow = ref(null);
const vote = ref(null);
const gold = ref(640);
const basket = ref([]);

/* timers de démo (nettoyés au démontage) */
const timers = [];
const later = (fn, ms) => timers.push(setTimeout(fn, ms));
onUnmounted(() => timers.forEach(clearTimeout));

function think(ms = 1400) {
    thinking.value = true;
    later(() => { thinking.value = false; }, ms);
}

/* ---- POINT D'INTÉGRATION temps réel : canal privé `joueur.{id}` ----
   La manette y recevra SON menu de choix, la narration, l'état du héros
   et les votes en cours diffusés par le moteur (Reverb). En démo, l'id
   du joueur n'existe pas encore : on s'abonne avec le héros courant. */
usePlayerChannel(`${props.groupe}.${heroKey.value}`, {
    '.menu.propose': (e) => { myTurn.value = e.monTour; },
    '.narration.diffusee': (e) => { narr.value = e.texte; },
    '.heros.etat': (e) => { body.value = e.body; mind.value = e.mind; },
    '.vote.lance': (e) => { vote.value = e.vote; },
    '.mj.reflechit': (e) => { thinking.value = e.actif; },
});

/* ---- POINT D'INTÉGRATION API : le choix part au moteur, la suite
   (narration, nouveau menu) reviendra par Reverb. Tant que l'API
   n'existe pas, l'échec est silencieux et la démo continue en local. */
function envoyerChoix(payload) {
    api.envoyerChoix(props.groupe, { heros: heroKey.value, ...payload }).catch(() => {});
}

/* ---- résolution attaque / sort (démo locale, cf. manette-app.jsx) ---- */
const rng = Math.random;
function rollDice(nAtk, nDef) {
    const atk = Array.from({ length: nAtk }, () => (rng() < 0.5 ? 'skull' : 'blank'));
    const def = Array.from({ length: nDef }, () => (rng() < 0.34 ? 'shield' : 'blank'));
    return { atk, def };
}
function beginAttack() {
    flow.value = { kind: 'attack', step: 'target' };
}
function beginSpell(spell) {
    if (spell.target === 'tile') { resolveSpell(spell, null); return; }
    flow.value = { kind: 'spell', step: 'target', spell };
}
function chooseTarget(target) {
    const f = flow.value;
    if (f.kind === 'attack') {
        const dice = rollDice(hero.value.atk, target.id === 'orc' ? 3 : 1);
        flow.value = { ...f, step: 'rolling', target, dice };
        later(() => { if (flow.value) flow.value = { ...flow.value, step: 'result' }; }, 950);
    } else {
        resolveSpell(f.spell, target);
    }
}
function resolveSpell(spell, target) {
    if (spell.target === 'ally' && spell.id === 'heal') {
        flow.value = { kind: 'spell', step: 'result', spell, target, heal: true };
        return;
    }
    const dice = rollDice(spell.id === 'fb' ? 2 : 1, target ? (target.id === 'orc' ? 3 : 1) : 0);
    flow.value = { kind: 'spell', step: 'rolling', spell, target, dice };
    later(() => { if (flow.value) flow.value = { ...flow.value, step: 'result' }; }, 950);
}
function confirmResolve() {
    const f = flow.value;
    let txt = '';
    if (f.kind === 'attack') {
        const sk = f.dice.atk.filter((d) => d === 'skull').length;
        const sh = f.dice.def.filter((d) => d === 'shield').length;
        const dmg = Math.max(0, sk - sh);
        txt = dmg > 0
            ? `Ton arme s'abat sur ${f.target.name} — ${dmg} blessure${dmg > 1 ? 's' : ''} !`
            : `${f.target.name} pare le coup de justesse.`;
        envoyerChoix({ action: 'attaque', cible: f.target.id });
    } else if (f.heal) {
        mind.value = { ...mind.value, cur: Math.max(0, mind.value.cur - 1) };
        txt = 'Une eau claire enveloppe ton allié — +2 Body.';
        envoyerChoix({ action: 'sort', sort: f.spell.id, cible: f.target?.id });
    } else {
        const sk = f.dice.atk.filter((d) => d === 'skull').length;
        mind.value = { ...mind.value, cur: Math.max(0, mind.value.cur - 1) };
        txt = `${f.spell.name} frappe ${f.target ? f.target.name : 'la zone'} — ${sk} dégât${sk > 1 ? 's' : ''} !`;
        envoyerChoix({ action: 'sort', sort: f.spell.id, cible: f.target?.id });
    }
    narr.value = txt;
    flow.value = null;
    endTurn(1600, 2400);
}

/* actions simples (déplacement, fouille, passe) */
function quickAction(action, texte, backMs = 2200) {
    narr.value = texte;
    envoyerChoix({ action });
    endTurn(1400, backMs);
}
function endTurn(thinkMs, backMs) {
    myTurn.value = false;
    think(thinkMs);
    later(() => {
        myTurn.value = true;
        narr.value = 'À toi de jouer. Les gobelins resserrent leur cercle…';
    }, backMs);
}

/* ---- vote de groupe (démo : les autres joueurs arrivent peu à peu) ---- */
function launchVote() {
    vote.value = {
        q: 'Recharger la quête ? (TPK)',
        opts: [{ k: 'reload', l: 'Recharger', c: 1 }, { k: 'quit', l: 'Abandonner', c: 0 }],
        mine: null,
        missing: 2,
    };
    later(() => {
        const v = vote.value;
        if (v) vote.value = { ...v, opts: v.opts.map((o) => (o.k === 'reload' ? { ...o, c: o.c + 1 } : o)), missing: 1 };
    }, 1400);
    later(() => {
        const v = vote.value;
        if (v) vote.value = { ...v, opts: v.opts.map((o) => (o.k === 'reload' ? { ...o, c: o.c + 1 } : o)), missing: v.mine != null ? 0 : 1 };
    }, 2800);
}
function castVote(k) {
    const v = vote.value;
    if (!v) return;
    vote.value = {
        ...v,
        mine: k,
        opts: v.opts.map((o) => (o.k === k ? { ...o, c: o.c + 1 } : o)),
        missing: Math.max(0, v.missing - 1),
    };
    // POINT D'INTÉGRATION API : le vote part au moteur (non bloquant en démo).
    api.voter(props.groupe, { heros: heroKey.value, choix: k }).catch(() => {});
}

/* ---- marché ---- */
const projected = computed(() =>
    basket.value.reduce((s, id) => s + (SHOP.find((x) => x.id === id)?.price || 0), 0));
function toggleBasket(id) {
    basket.value = basket.value.includes(id)
        ? basket.value.filter((x) => x !== id)
        : [...basket.value, id];
}

/* scène → onglet par défaut */
watch(scene, (s) => { if (s === 'marche') tab.value = 'action'; });

const navItems = computed(() => (scene.value === 'marche'
    ? [['action', 'storefront', 'Marché'], ['fiche', 'person', 'Fiche'], ['sorts', 'auto_awesome', 'Sorts'], ['sac', 'backpack', 'Sac']]
    : [['action', 'swords', 'Action'], ['fiche', 'person', 'Fiche'], ['sorts', 'auto_awesome', 'Sorts'], ['sac', 'backpack', 'Sac']]));
</script>

<template>
    <div class="stage">
        <div>
            <div class="phone">
                <div class="screen tex-vignette" style="position: relative">
                    <!-- barre de statut -->
                    <div class="topbar">
                        <div class="hero-chip">
                            <span class="crest"><MSym :n="hero.crest" fill :size="22" /></span>
                            <div>
                                <div class="nm">{{ hero.name }}</div>
                                <div class="cls">{{ hero.cls }} · Niv. {{ hero.lvl }}</div>
                            </div>
                        </div>
                        <div v-if="thinking" class="think" style="margin-left: auto">
                            <span class="dots"><i /><i /><i /></span>MJ réfléchit…
                        </div>
                        <div v-else class="conn" :class="conn" style="margin-left: auto">
                            <span class="dot" />{{ conn === 'ok' ? 'Connecté' : 'Reconnexion…' }}
                        </div>
                    </div>

                    <!-- mini jauges PV -->
                    <div class="mini-pv">
                        <div class="g">
                            <div class="lab" style="color: var(--body-bright)">
                                <span><MSym n="favorite" fill :size="12" /> BODY</span><span>{{ body.cur }}/{{ body.max }}</span>
                            </div>
                            <div class="pips"><div v-for="i in body.max" :key="i" class="pip" :class="{ b: i <= body.cur }" /></div>
                        </div>
                        <div class="g">
                            <div class="lab" style="color: var(--mind-bright)">
                                <span><MSym n="psychology" fill :size="12" /> MIND</span><span>{{ mind.cur }}/{{ mind.max }}</span>
                            </div>
                            <div class="pips"><div v-for="i in mind.max" :key="i" class="pip" :class="{ m: i <= mind.cur }" /></div>
                        </div>
                    </div>

                    <!-- narration compacte -->
                    <div class="narr-peek">
                        <div class="hd">
                            <span class="who">LE MAÎTRE DE JEU</span>
                            <span class="bars"><i /><i /><i /></span>
                        </div>
                        <p>{{ narr }}</p>
                    </div>

                    <!-- zone principale -->
                    <div class="body">
                        <ActionTab
                            v-if="tab === 'action' && scene === 'combat'"
                            :my-turn="myTurn"
                            :hero="hero"
                            @attack="beginAttack"
                            @open-spells="tab = 'sorts'"
                            @move="quickAction('deplacement', 'Tu avances prudemment entre les colonnes brisées.')"
                            @search="quickAction('fouille', 'Tu fouilles les décombres… une fiole roule à tes pieds.')"
                            @pass="quickAction('passe', 'Tu restes sur tes gardes, arme levée.', 2000)"
                        />
                        <MarketTab
                            v-else-if="tab === 'action' && scene === 'marche'"
                            :hero="hero"
                            :gold="gold"
                            :basket="basket"
                            :projected="projected"
                            @toggle="toggleBasket"
                        />
                        <FicheTab
                            v-else-if="tab === 'fiche'"
                            :hero="hero"
                            :hero-key="heroKey"
                            :body="body"
                            :mind="mind"
                            @select-hero="heroKey = $event"
                        />
                        <SpellsTab v-else-if="tab === 'sorts'" :hero="hero" :mind="mind" @cast="beginSpell" />
                        <SacTab v-else-if="tab === 'sac'" :hero="hero" />
                    </div>

                    <!-- navigation basse -->
                    <div class="botnav">
                        <button v-for="[k, ic, l] in navItems" :key="k" :class="{ on: tab === k }" @click="tab = k">
                            <MSym :n="ic" /><span class="bl">{{ l }}</span>
                        </button>
                    </div>

                    <!-- overlays -->
                    <FlowSheet
                        v-if="flow"
                        :flow="flow"
                        :hero="hero"
                        @target="chooseTarget"
                        @confirm="confirmResolve"
                        @close="flow = null"
                    />
                    <VoteSheet v-if="vote" :vote="vote" @cast="castVote" @close="vote = null" />
                </div>
            </div>

            <!-- contrôle de démo (comme dans la maquette) -->
            <div class="scene-ctrl">
                <RouterLink
                    to="/"
                    title="Hub"
                    style="display: grid; place-items: center; width: 30px; height: 30px; border-radius: 50%; color: var(--ink-300); text-decoration: none"
                >
                    <MSym n="home" :size="18" />
                </RouterLink>
                <span class="lbl">Démo</span>
                <button :class="{ on: scene === 'combat' }" @click="scene = 'combat'">Combat</button>
                <button :class="{ on: scene === 'marche' }" @click="scene = 'marche'">Marché</button>
                <button @click="launchVote">Vote</button>
                <button @click="body = { ...body, cur: Math.max(0, body.cur - 1) }; narr = 'Une griffe te lacère — tu encaisses 1 blessure.'">Subir</button>
            </div>
        </div>
    </div>
</template>
