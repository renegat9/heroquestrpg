<script setup>
// MANETTE JOUEUR (portrait, téléphone) — port fidèle de
// reference/heroquest/manette-app.jsx (+ manette.css, importé globalement).
// Mode connecté : GET /moi + GET etat au montage, abonnement aux canaux
// privés `groupe.{identifiant}` (état, narration, MJ) et `joueur.{id}`
// (.menu.propose → onglet Action) ; chaque tap envoie POST choix
// {option_id} et gèle les boutons jusqu'au prochain .groupe.etat.
// Repli : API injoignable / 401 → démo locale (badge « démo »).
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import MSym from '../components/ui/MSym.vue';
import DemoBadge from '../components/ui/DemoBadge.vue';
import ActionTab from '../components/manette/ActionTab.vue';
import FicheTab from '../components/manette/FicheTab.vue';
import SpellsTab from '../components/manette/SpellsTab.vue';
import SacTab from '../components/manette/SacTab.vue';
import MarketTab from '../components/manette/MarketTab.vue';
import FlowSheet from '../components/manette/FlowSheet.vue';
import VoteSheet from '../components/manette/VoteSheet.vue';
import { HEROES, NARRATION_OUVERTURE, SHOP } from '../data/demo';
import { souscrireGroupe, souscrireJoueur } from '../composables/useEcho';
import { estErreurDemo, useApi } from '../composables/useApi';
import {
    acteurCourant, CLASSES, initiativeVersMini, labelCourt, useGameStore,
} from '../store/game';

const props = defineProps({
    groupe: { type: String, required: true },
});

const store = useGameStore();
store.setGroupe(props.groupe);
const api = useApi();

/* ---- chargement de l'état + abonnements temps réel ---- */
const desabonnements = [];
onMounted(async () => {
    try {
        const { joueur, personnages } = await api.moi();
        store.setJoueur(joueur, personnages);
        store.appliquerEtat(await api.getEtat(props.groupe));
        desabonnements.push(
            souscrireGroupe(props.groupe, {
                '.groupe.etat': (e) => store.appliquerEtat(e),
                '.narration.diffusee': (e) => store.setNarration(e.texte),
                '.mj.reflechit': (e) => store.setMjReflechit(e.actif),
            }),
            souscrireJoueur(joueur.id, {
                '.menu.propose': (e) => store.setMenu(e.menu),
            }),
        );
    } catch (e) {
        store.activerModeDemo(estErreurDemo(e) ? e.message : `erreur inattendue : ${e.message}`);
    }
});
onUnmounted(() => desabonnements.forEach((off) => off()));

const enDemo = computed(() => store.state.modeDemo || !store.state.etat);

/* ---- mon héros (mode connecté) : entité EtatGroupe ↔ personnage /moi.
   L'habillage statique (icônes, équipement des onglets Fiche/Sac) vient
   du gabarit de démo de la même classe ; nom et PV sont les vrais. ---- */
const monEntite = computed(() => {
    if (enDemo.value) return null;
    const ids = new Set((store.state.personnages ?? []).map((p) => p.id));
    return store.state.etat.entites?.find((e) => e.type === 'heros' && ids.has(e.id)) ?? null;
});

/* ---- état local (mode démo) ---- */
const heroKey = ref('mage');
const tab = ref('action');
const demoScene = ref('combat'); // combat | marche
const demoThinking = ref(false);

const demoBody = ref({ ...HEROES[heroKey.value].body });
const demoMind = ref({ ...HEROES[heroKey.value].mind });
watch(heroKey, (k) => {
    demoBody.value = { ...HEROES[k].body };
    demoMind.value = { ...HEROES[k].mind };
});

const hero = computed(() => {
    const e = monEntite.value;
    if (!e) return HEROES[heroKey.value];
    const base = HEROES[CLASSES[(e.classe ?? '').toLowerCase()]?.demo ?? 'barb'];
    return { ...base, name: e.nom, cls: CLASSES[(e.classe ?? '').toLowerCase()]?.l ?? e.classe };
});
const body = computed(() => (monEntite.value
    ? { cur: monEntite.value.tombe ? 0 : monEntite.value.pv_body, max: monEntite.value.pv_body_max }
    : demoBody.value));
const mind = computed(() => (monEntite.value
    ? { cur: monEntite.value.pv_mind, max: monEntite.value.pv_mind_max }
    : demoMind.value));

const scene = computed(() => (enDemo.value
    ? demoScene.value
    : (store.state.etat.groupe?.phase === 'hub' ? 'marche' : 'combat')));
const thinking = computed(() => (enDemo.value ? demoThinking.value : store.state.mjReflechit));
const conn = computed(() => store.state.connexion); // 'ok' | 'warn'
const narration = computed(() => (enDemo.value ? narr.value : store.state.narration));

/* ---- menu réel (.menu.propose) + envoi du choix (POST choix) ---- */
const menuCourant = computed(() => (enDemo.value ? null : store.state.menu));
const menuEnAttente = computed(() => store.state.menuEnAttente);
const initMini = computed(() => (enDemo.value ? null : initiativeVersMini(store.state.etat.initiative)));
const initCur = computed(() => {
    if (enDemo.value) return null;
    const cur = acteurCourant(store.state.etat.initiative);
    return cur ? labelCourt(cur.nom) : null;
});

async function choisirOption(option) {
    if (store.state.menuEnAttente) return;
    store.choixEnvoye(); // optimiste : boutons gelés jusqu'au prochain .groupe.etat
    try {
        await api.envoyerChoix(props.groupe, { option_id: option.id, parametres: option.parametres });
    } catch (e) {
        store.annulerChoixEnAttente(); // 422 option illégale, etc. : on rend la main
        if (estErreurDemo(e)) store.activerModeDemo(e.message);
        else store.setNarration(e.message);
    }
}

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
    demoThinking.value = true;
    later(() => { demoThinking.value = false; }, ms);
}

/* ---- résolution attaque / sort (démo locale, cf. manette-app.jsx ;
   en mode connecté, le vrai envoi passe par choisirOption ci-dessus) ---- */
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
    } else if (f.heal) {
        demoMind.value = { ...demoMind.value, cur: Math.max(0, demoMind.value.cur - 1) };
        txt = 'Une eau claire enveloppe ton allié — +2 Body.';
    } else {
        const sk = f.dice.atk.filter((d) => d === 'skull').length;
        demoMind.value = { ...demoMind.value, cur: Math.max(0, demoMind.value.cur - 1) };
        txt = `${f.spell.name} frappe ${f.target ? f.target.name : 'la zone'} — ${sk} dégât${sk > 1 ? 's' : ''} !`;
    }
    narr.value = txt;
    flow.value = null;
    endTurn(1600, 2400);
}

/* actions simples (déplacement, fouille, passe) */
function quickAction(action, texte, backMs = 2200) {
    narr.value = texte;
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
    // Les votes ne sont pas encore au contrat API : démo locale uniquement.
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
                        <p>{{ narration }}</p>
                    </div>

                    <!-- zone principale -->
                    <div class="body">
                        <ActionTab
                            v-if="tab === 'action' && scene === 'combat'"
                            :my-turn="enDemo ? myTurn : !!menuCourant"
                            :hero="hero"
                            :menu="menuCourant"
                            :pending="menuEnAttente"
                            :init-order="initMini"
                            :init-cur="initCur"
                            @choose="choisirOption"
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

            <!-- contrôle de démo (comme dans la maquette ; masqué en mode connecté) -->
            <div class="scene-ctrl">
                <RouterLink
                    to="/"
                    title="Hub"
                    style="display: grid; place-items: center; width: 30px; height: 30px; border-radius: 50%; color: var(--ink-300); text-decoration: none"
                >
                    <MSym n="home" :size="18" />
                </RouterLink>
                <template v-if="enDemo">
                    <span class="lbl">Démo</span>
                    <button :class="{ on: demoScene === 'combat' }" @click="demoScene = 'combat'">Combat</button>
                    <button :class="{ on: demoScene === 'marche' }" @click="demoScene = 'marche'">Marché</button>
                    <button @click="launchVote">Vote</button>
                    <button @click="demoBody = { ...demoBody, cur: Math.max(0, demoBody.cur - 1) }; narr = 'Une griffe te lacère — tu encaisses 1 blessure.'">Subir</button>
                </template>
            </div>
        </div>
        <DemoBadge />
    </div>
</template>
