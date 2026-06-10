<script setup>
// CHOIX DE LA QUÊTE (hôte, paysage) — port de reference/heroquest/"Selection de quete.html".
// Carte de campagne ramifiée + panneau de détail + vote du groupe.
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import MSym from '../components/ui/MSym.vue';
import { QUEST_NODES, QUEST_EDGES, QUESTS, QUEST_PLAYERS } from '../data/demo';
import { useGroupChannel } from '../composables/useEcho';
import { useGameStore } from '../store/game';

const props = defineProps({
    groupe: { type: String, required: true },
});

const router = useRouter();
const store = useGameStore();
store.setGroupe(props.groupe);

const selected = ref('qc');
const votes = reactive({}); // clé joueur -> clé quête
const quest = computed(() => QUESTS[selected.value]);

/* ---- POINT D'INTÉGRATION temps réel : les votes des autres joueurs
   arrivent par le canal de groupe (en démo : timeouts ci-dessous). */
useGroupChannel(props.groupe, {
    '.vote.quete': (e) => { votes[e.joueur] = e.quete; },
});

const missing = computed(() => QUEST_PLAYERS.length - Object.keys(votes).length);
const winner = computed(() => {
    if (missing.value > 0) return null;
    const counts = {};
    Object.values(votes).forEach((v) => { counts[v] = (counts[v] || 0) + 1; });
    return Object.entries(counts).sort((a, b) => b[1] - a[1])[0][0];
});

const voteCount = (k) => Object.values(votes).filter((v) => v === k).length;
const choiceLabel = (qk) => QUESTS[qk].name.split(' ').slice(-1)[0];

function selectQuest(k) {
    if (QUEST_NODES[k].state !== 'avail') return;
    selected.value = k;
    // le joueur local (barbare en démo) vote sa sélection
    castVote('barb', k);
}
function castVote(joueur, quete) {
    votes[joueur] = quete;
    // Les votes de quête ne sont pas encore au contrat API : démo locale.
}
function lancerQuete() {
    router.push({ name: 'table', params: { groupe: props.groupe } });
}

/* démo : les autres joueurs votent peu à peu */
const timers = [];
onMounted(() => {
    castVote('barb', selected.value);
    timers.push(setTimeout(() => castVote('elf', 'qc'), 1400));
    timers.push(setTimeout(() => castVote('mage', 'qa'), 2700));
    timers.push(setTimeout(() => castVote('dwarf', 'qc'), 4000));
});
onUnmounted(() => timers.forEach(clearTimeout));

/* arêtes du graphe (coordonnées en % via viewBox 0..100) */
const edges = QUEST_EDGES.map(([a, b]) => {
    const A = QUEST_NODES[a];
    const B = QUEST_NODES[b];
    const done = A.state === 'done' && (B.state === 'done' || B.state === 'current');
    return {
        x1: A.x, y1: A.y, x2: B.x, y2: B.y,
        stroke: done ? 'oklch(0.5 0.015 255)' : 'oklch(0.34 0.016 255 / 0.7)',
        width: done ? 0.7 : 0.5,
        dash: B.state === 'locked' ? '1.4 1.4' : 'none',
    };
});
</script>

<template>
    <div class="selquete">
        <div class="stage-q tex-stone tex-vignette">
            <!-- bandeau haut -->
            <div class="top">
                <RouterLink class="home" to="/"><MSym n="arrow_back" :size="14" /> HUB</RouterLink>
                <div class="ttl">
                    <span class="ep">Conseil de Pierregivre · Carte de campagne</span>
                    <h1>Quelle voie prendre ?</h1>
                </div>
                <div class="think"><span class="dots"><i /><i /><i /></span> Le MJ prépare les voies…</div>
            </div>

            <!-- carte + détail -->
            <div class="main">
                <div class="mapcard">
                    <div class="maplabel">La Crypte d'Ambre · 3 / 8</div>
                    <svg class="paths" viewBox="0 0 100 100" preserveAspectRatio="none">
                        <line
                            v-for="(e, i) in edges"
                            :key="i"
                            :x1="e.x1" :y1="e.y1" :x2="e.x2" :y2="e.y2"
                            :stroke="e.stroke" :stroke-width="e.width" :stroke-dasharray="e.dash"
                        />
                    </svg>
                    <div
                        v-for="(n, k) in QUEST_NODES"
                        :key="k"
                        class="node"
                        :class="[n.state, { sel: k === selected && n.state === 'avail' }]"
                        :style="{ left: n.x + '%', top: n.y + '%' }"
                        @click="selectQuest(k)"
                    >
                        <div class="dot"><MSym :n="n.ic" fill /></div>
                        <div class="nl">{{ n.label }}</div>
                        <div class="votedots"><i v-for="i in voteCount(k)" :key="i" /></div>
                    </div>
                </div>

                <div class="detail">
                    <div class="imgph">
                        <span class="tag">illustration · {{ quest.el }}</span>
                        <span class="badge-diff" :class="'diff-' + quest.diff"><MSym n="skull" fill :size="14" /> {{ quest.dl }}</span>
                    </div>
                    <h2>{{ quest.name }}</h2>
                    <div class="qmeta">
                        <span class="m"><MSym n="groups" fill /> {{ quest.lvl }}</span>
                        <span class="m"><MSym n="schedule" /> {{ quest.dur }}</span>
                        <span class="m"><MSym n="bolt" fill /> Élément {{ quest.el }}</span>
                    </div>
                    <p class="hook">{{ quest.hook }}</p>
                    <div class="rewards">
                        <div class="rh">Récompenses attendues</div>
                        <div class="reward-chips">
                            <span v-for="(r, i) in quest.rewards" :key="i" class="rchip" :class="r[0]">
                                <MSym :n="r[0] === 'gold' ? 'paid' : 'workspace_premium'" :fill="r[0] !== 'gold'" />{{ r[1] }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- vote -->
            <div class="votebar">
                <div class="vlabel">
                    <span class="ic"><MSym n="how_to_vote" fill /></span>
                    <div>
                        <div class="vt">Vote — quête suivante</div>
                        <div class="vs">Le groupe choisit ensemble la prochaine descente</div>
                    </div>
                </div>
                <div class="players">
                    <div v-for="p in QUEST_PLAYERS" :key="p.k" class="pv" :class="{ voted: votes[p.k] }">
                        <div class="av"><MSym :n="p.ic" fill /></div>
                        <div class="pn" :class="{ choice: votes[p.k] }">{{ votes[p.k] ? choiceLabel(votes[p.k]) : p.n }}</div>
                    </div>
                </div>
                <div class="status">
                    <div v-if="missing > 0" class="waiting-q">
                        <MSym n="hourglass_top" :size="16" /> En attente de {{ missing }} joueur{{ missing > 1 ? 's' : '' }}…
                    </div>
                    <div v-else class="waiting-q">
                        <MSym n="check_circle" fill :size="16" style="color: var(--ok)" /> Décision :
                        <b style="color: var(--torch); margin-left: 3px">{{ QUESTS[winner].name }}</b>
                    </div>
                    <button class="btn btn-torch" :disabled="missing > 0" @click="lancerQuete">
                        <MSym n="play_arrow" /> Lancer la quête
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
/* Port de "Selection de quete.html" — préfixé .selquete. */
.selquete { background: #000; color: var(--ink-100); overflow: hidden; --ambiance: 0.6; }
.selquete .stage-q { position: relative; width: 100vw; height: 100vh; display: grid; grid-template-rows: auto 1fr auto; padding: 16px 22px 18px; gap: 12px; box-sizing: border-box; }
.selquete .stage-q.tex-stone::before { content: ""; position: absolute; inset: 0;
  background: radial-gradient(60% 70% at 30% -10%, oklch(0.76 0.155 65 / 0.14), transparent 60%),
              radial-gradient(50% 50% at 95% 0%, oklch(0.62 0.17 42 / 0.10), transparent 60%); pointer-events: none; }

/* bandeau haut */
.selquete .top { position: relative; z-index: 3; display: flex; align-items: center; gap: 20px; }
.selquete .home { color: var(--ink-500); text-decoration: none; font-size: 11px; font-weight: 700; letter-spacing: 0.08em; display: inline-flex; align-items: center; gap: 4px; }
.selquete .ttl { display: flex; flex-direction: column; }
.selquete .ttl .ep { font-size: 12px; letter-spacing: 0.28em; text-transform: uppercase; color: var(--ember); font-weight: 700; }
.selquete .ttl h1 { font-family: var(--font-display); font-weight: 700; font-size: clamp(20px, 2.1vw, 30px); margin: 2px 0 0; color: var(--parch-100); letter-spacing: 0.03em; }
.selquete .top .think { margin-left: auto; }

/* carte de campagne */
.selquete .main { position: relative; z-index: 2; display: grid; grid-template-columns: 1.45fr 1fr; gap: 20px; min-height: 0; }
.selquete .mapcard { position: relative; border-radius: var(--r-lg); border: var(--line); overflow: hidden;
  background: linear-gradient(180deg, oklch(0.20 0.013 255), oklch(0.155 0.011 255)); box-shadow: inset 0 0 60px oklch(0 0 0 / 0.6); }
.selquete .maplabel { position: absolute; top: 14px; left: 16px; z-index: 5; font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--ink-500); font-weight: 700; }
.selquete svg.paths { position: absolute; inset: 0; width: 100%; height: 100%; z-index: 1; }
.selquete .node { position: absolute; transform: translate(-50%, -50%); z-index: 2; width: 108px; text-align: center; cursor: pointer; }
.selquete .node .dot { width: 54px; height: 54px; border-radius: 50%; margin: 0 auto; display: grid; place-items: center; position: relative;
  background: var(--stone-800); border: 2px solid var(--stone-600); color: var(--ink-300); transition: all .18s; }
.selquete .node .dot .msym { font-size: 26px; }
.selquete .node .nl { font-size: 11px; font-weight: 700; color: var(--ink-400); margin-top: 7px; line-height: 1.2; }
.selquete .node.done .dot { background: linear-gradient(150deg, var(--stone-600), var(--stone-700)); border-color: var(--stone-500); color: var(--parch-200); }
.selquete .node.done .dot::after { content: "check"; font-family: 'Material Symbols Outlined'; position: absolute; bottom: -4px; right: -4px;
  font-size: 13px; background: var(--ok); color: var(--stone-950); border-radius: 50%; width: 18px; height: 18px; display: grid; place-items: center; font-variation-settings: 'FILL' 1; }
.selquete .node.current .dot { border-color: var(--gold); color: var(--gold); box-shadow: 0 0 0 4px oklch(0.80 0.135 88 / 0.18); }
.selquete .node.avail .dot { background: linear-gradient(150deg, var(--ember), var(--ember-deep)); border-color: var(--torch); color: var(--parch-100); }
.selquete .node.avail.sel .dot { box-shadow: var(--glow-torch); transform: scale(1.12); }
.selquete .node.avail .nl { color: var(--torch); }
.selquete .node.locked { cursor: not-allowed; }
.selquete .node.locked .dot { background: var(--stone-850); border-style: dashed; border-color: var(--stone-700); color: var(--ink-700); }
.selquete .node.locked .nl { color: var(--ink-700); }
.selquete .node .votedots { display: flex; gap: 3px; justify-content: center; margin-top: 5px; height: 8px; }
.selquete .node .votedots i { width: 7px; height: 7px; border-radius: 50%; background: var(--torch); box-shadow: 0 0 6px var(--torch); animation: selquete-pop .3s ease; }
@keyframes selquete-pop { from { transform: scale(0); } }

/* panneau de détail */
.selquete .detail { border-radius: var(--r-lg); border: var(--line); background: linear-gradient(180deg, var(--stone-850), var(--stone-900));
  padding: 20px 22px; display: flex; flex-direction: column; min-height: 0; }
.selquete .detail .imgph { height: 128px; border-radius: var(--r-md); margin-bottom: 16px; position: relative; overflow: hidden; flex: none;
  background: repeating-linear-gradient(45deg, var(--stone-800) 0 10px, var(--stone-850) 10px 20px); border: var(--line); display: grid; place-items: center; }
.selquete .detail .imgph .tag { font-family: ui-monospace, monospace; font-size: 11px; color: var(--ink-500); background: oklch(0 0 0 / 0.4); padding: 4px 10px; border-radius: 99px; }
.selquete .detail .imgph .badge-diff { position: absolute; top: 10px; right: 10px; display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 800;
  padding: 5px 11px; border-radius: 99px; background: oklch(0 0 0 / 0.55); backdrop-filter: blur(3px); }
.selquete .detail h2 { font-family: var(--font-display); font-size: 24px; color: var(--parch-100); margin: 0 0 4px; letter-spacing: 0.02em; }
.selquete .qmeta { display: flex; gap: 14px; margin-bottom: 14px; flex-wrap: wrap; }
.selquete .qmeta .m { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--ink-400); font-weight: 600; }
.selquete .qmeta .m .msym { font-size: 16px; color: var(--ink-500); }
.selquete .hook { font-family: var(--font-narr); font-style: italic; font-size: 15.5px; line-height: 1.55; color: var(--ink-200); margin: 0 0 16px; }
.selquete .rewards { margin-top: auto; }
.selquete .rewards .rh { font-size: 11px; letter-spacing: 0.1em; text-transform: uppercase; color: var(--ink-500); font-weight: 700; margin-bottom: 9px; }
.selquete .reward-chips { display: flex; gap: 8px; flex-wrap: wrap; }
.selquete .rchip { display: inline-flex; align-items: center; gap: 7px; padding: 7px 12px; border-radius: 99px; background: var(--stone-800); border: var(--line); font-size: 12.5px; font-weight: 600; }
.selquete .rchip .msym { font-size: 16px; }
.selquete .rchip.gold { color: var(--gold); }
.selquete .rchip.gold .msym { color: var(--gold); }
.selquete .rchip.item { color: var(--rar-rare); }
.selquete .rchip.item .msym { color: var(--rar-rare); }
.selquete .diff-1 { color: var(--ok); } .selquete .diff-2 { color: var(--warn); } .selquete .diff-3 { color: var(--body-bright); }

/* barre de vote */
.selquete .votebar { position: relative; z-index: 3; display: flex; align-items: center; gap: 20px; padding: 14px 22px; border-radius: var(--r-lg);
  background: linear-gradient(180deg, oklch(0.22 0.014 255 / 0.96), oklch(0.17 0.012 255 / 0.96)); border: var(--line); }
.selquete .vlabel { display: flex; align-items: center; gap: 10px; }
.selquete .vlabel .ic { width: 42px; height: 42px; border-radius: 11px; display: grid; place-items: center; background: oklch(0.76 0.155 65 / 0.14); border: var(--line-gold); color: var(--torch); }
.selquete .vlabel .vt { font-family: var(--font-display); font-size: 15px; color: var(--parch-100); letter-spacing: 0.02em; }
.selquete .vlabel .vs { font-size: 11.5px; color: var(--ink-500); }
.selquete .players { display: flex; gap: 10px; margin-left: auto; }
.selquete .pv { display: flex; flex-direction: column; align-items: center; gap: 5px; }
.selquete .pv .av { width: 42px; height: 42px; border-radius: 50%; display: grid; place-items: center; background: var(--stone-800); border: 2px solid var(--stone-600); color: var(--ink-400); transition: all .25s; position: relative; }
.selquete .pv .av .msym { font-size: 22px; }
.selquete .pv.voted .av { border-color: var(--torch); color: var(--torch); background: oklch(0.76 0.155 65 / 0.12); }
.selquete .pv.voted .av::after { content: "how_to_vote"; font-family: 'Material Symbols Outlined'; position: absolute; bottom: -5px; right: -5px;
  font-size: 11px; background: var(--torch); color: var(--stone-950); border-radius: 50%; width: 18px; height: 18px; display: grid; place-items: center; font-variation-settings: 'FILL' 1; }
.selquete .pv .pn { font-size: 9.5px; font-weight: 700; color: var(--ink-500); }
.selquete .pv .pn.choice { color: var(--torch); }
.selquete .status { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
.selquete .waiting-q { font-size: 12px; color: var(--ink-500); display: flex; align-items: center; gap: 7px; }
.selquete .btn { border: none; border-radius: var(--r-md); padding: 13px 22px; font-family: var(--font-ui); font-weight: 700; font-size: 15px; cursor: pointer;
  display: inline-flex; align-items: center; gap: 9px; transition: transform .1s; width: auto; }
.selquete .btn:active { transform: scale(0.98); }
.selquete .btn:disabled { opacity: 0.4; pointer-events: none; }
.selquete .btn-torch { background: linear-gradient(180deg, var(--torch-bright), var(--torch)); color: var(--stone-950); box-shadow: var(--sh-2); }
</style>
