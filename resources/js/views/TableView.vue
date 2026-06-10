<script setup>
// ÉCRAN DE TABLE (hôte, paysage) — port fidèle de reference/heroquest/Table.html.
// Données de démo locales ; les points d'intégration temps réel / API sont
// regroupés ci-dessous (useGroupChannel + useGameStore).
import { onMounted, ref } from 'vue';
import MSym from '../components/ui/MSym.vue';
import InitiativeBar from '../components/table/InitiativeBar.vue';
import DungeonMap from '../components/table/DungeonMap.vue';
import GroupPanel from '../components/table/GroupPanel.vue';
import NarrationBand from '../components/table/NarrationBand.vue';
import CombatOverlay from '../components/table/CombatOverlay.vue';
import { buildTableMap, TABLE_ENTITIES, TABLE_INIT_ORDER, TABLE_PARTY } from '../data/demo';
import { useGroupChannel } from '../composables/useEcho';
import { useGameStore } from '../store/game';

const props = defineProps({
    groupe: { type: String, required: true },
});

const store = useGameStore();
store.setGroupe(props.groupe);

/* ---- données de démo (remplacées à terme par l'état serveur) ---- */
const map = buildTableMap();
const entities = ref(TABLE_ENTITIES);
const initOrder = ref(TABLE_INIT_ORDER);
const party = ref(TABLE_PARTY);
const narration = ref("Une lueur d'ambre danse sur les murs suintants. Trois ombres trapues se redressent en grognant — l'Elfe encoche déjà une flèche…");
const mjReflechit = ref(true);
const joueursConnectes = ref(4);

/* ---- POINT D'INTÉGRATION temps réel : canal de groupe `groupe.{id}` ----
   La table écoute ici narration, état du groupe, déplacements et
   résolutions de combat diffusés par le moteur (Reverb). */
useGroupChannel(props.groupe, {
    '.narration.diffusee': (e) => { narration.value = e.texte; },
    '.groupe.etat': (e) => { party.value = e.party; initOrder.value = e.initiative; },
    '.combat.resolu': () => combatRef.value?.play(),
    '.mj.reflechit': (e) => { mjReflechit.value = e.actif; },
});

/* ---- combat (démo : rejoué localement) ---- */
const combatRef = ref(null);
onMounted(() => {
    setTimeout(() => combatRef.value?.play(), 1200);
});
</script>

<template>
    <div class="table-screen">
        <div class="table tex-stone tex-vignette" style="position: relative">
            <!-- bandeau haut -->
            <div class="top">
                <div class="quest">
                    <RouterLink to="/" class="hub-link">
                        <MSym n="arrow_back" :size="14" /> HUB
                    </RouterLink>
                    <span class="ep">Quête III · La crypte d'ambre</span>
                    <h1>Le Seuil des Ombres</h1>
                </div>
                <InitiativeBar :order="initOrder" />
                <div class="status-top">
                    <div v-if="mjReflechit" class="think">
                        <span class="dots"><i /><i /><i /></span> Le MJ réfléchit…
                    </div>
                    <div class="conn"><span class="dot" />{{ joueursConnectes }} joueurs connectés</div>
                </div>
            </div>

            <!-- zone principale : carte + groupe -->
            <div class="main">
                <div class="map-wrap">
                    <div class="torchspot" style="left: 8%; top: 20%" />
                    <div class="torchspot" style="right: 14%; bottom: 14%; animation-delay: 1.2s" />
                    <DungeonMap :map="map" :entities="entities" />
                    <CombatOverlay ref="combatRef" @narrate="narration = $event" />
                </div>
                <GroupPanel :party="party" />
            </div>

            <!-- narration -->
            <NarrationBand :text="narration" @replay="combatRef?.play()" />
        </div>
    </div>
</template>

<style>
/* Port de Table.html — préfixé .table-screen pour ne pas fuir sur les
   autres écrans (la SPA partage un seul bundle CSS). */
.table-screen { background: #000; color: var(--ink-100); overflow: hidden; --ambiance: 0.62; }
.table-screen .table { position: relative; width: 100vw; height: 100vh; display: grid;
  grid-template-rows: auto 1fr auto; gap: 14px; padding: 16px 20px 18px; }
.table-screen .table.tex-stone::before { content: ""; position: absolute; inset: 0;
  background: radial-gradient(70% 60% at 30% 0%, oklch(0.76 0.155 65 / 0.12), transparent 60%),
              radial-gradient(60% 50% at 95% 10%, oklch(0.62 0.17 42 / 0.10), transparent 60%); pointer-events: none; }

/* ---- bandeau haut ---- */
.table-screen .top { display: flex; align-items: center; gap: 22px; z-index: 3; }
.table-screen .quest { display: flex; flex-direction: column; }
.table-screen .hub-link { text-decoration: none; color: var(--ink-500); font-size: 11px; font-weight: 700;
  letter-spacing: 0.08em; display: inline-flex; align-items: center; gap: 4px; margin-bottom: 4px; }
.table-screen .quest .ep { font-size: 12px; letter-spacing: 0.28em; text-transform: uppercase; color: var(--ember); font-weight: 700; }
.table-screen .quest h1 { font-family: var(--font-display); font-weight: 700; font-size: clamp(20px, 2vw, 30px); margin: 2px 0 0;
  color: var(--parch-100); letter-spacing: 0.03em; }
.table-screen .init { display: flex; align-items: center; gap: 10px; margin: 0 auto; }
.table-screen .init .ttl { font-size: 11px; letter-spacing: 0.16em; text-transform: uppercase; color: var(--ink-500); font-weight: 700; margin-right: 4px; }
.table-screen .tok { width: 52px; height: 52px; border-radius: 50%; display: grid; place-items: center; font-weight: 800; font-size: 14px;
  background: var(--stone-800); border: 2px solid var(--stone-600); color: var(--ink-300); position: relative; transition: all .3s; }
.table-screen .tok.cur { border-color: var(--torch); background: var(--torch); color: var(--stone-950); box-shadow: var(--glow-torch); transform: scale(1.14); }
.table-screen .tok.foe { border-color: var(--body); color: var(--body-bright); }
.table-screen .init .arrow { color: var(--ink-700); }
.table-screen .status-top { display: flex; align-items: center; gap: 14px; }
.table-screen .think { display: inline-flex; align-items: center; gap: 9px; padding: 8px 15px; border-radius: 99px;
  background: var(--stone-850); border: var(--line-gold); color: var(--torch); font-size: 13px; font-weight: 700; }
.table-screen .conn { display: flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 700; color: var(--ok); }
.table-screen .conn .dot { width: 8px; height: 8px; border-radius: 50%; background: currentColor; box-shadow: 0 0 8px currentColor; }

/* ---- zone principale : carte + panneau de groupe ---- */
.table-screen .main { display: grid; grid-template-columns: 1fr 320px; gap: 18px; min-height: 0; z-index: 2; }
.table-screen .map-wrap { position: relative; display: grid; place-items: center; min-height: 0; }
.table-screen .map { display: grid; gap: 3px; aspect-ratio: 14 / 9; height: 100%; max-width: 100%;
  grid-template-columns: repeat(14, 1fr); grid-template-rows: repeat(9, 1fr);
  padding: 14px; border-radius: var(--r-lg); background: oklch(0.12 0.01 255);
  box-shadow: inset 0 0 60px oklch(0 0 0 / 0.7), var(--sh-3); border: 1px solid oklch(0.3 0.02 255 / 0.6); }
.table-screen .cell { border-radius: 3px; position: relative; }
.table-screen .cell.void { background: transparent; }
.table-screen .cell.wall { background:
    linear-gradient(160deg, oklch(0.30 0.014 255), oklch(0.22 0.012 255));
  box-shadow: inset 0 1px 0 oklch(1 0 0 / 0.05), inset 0 -2px 3px oklch(0 0 0 / 0.5);
  border: 1px solid oklch(0.34 0.016 255 / 0.5); }
.table-screen .cell.floor, .table-screen .cell.door { background:
    linear-gradient(150deg, oklch(0.235 0.013 255), oklch(0.20 0.012 255));
  box-shadow: inset 0 0 0 1px oklch(0.3 0.014 255 / 0.35); }
.table-screen .cell.door::after { content: ""; position: absolute; inset: 18% 30%; border-radius: 2px;
  background: repeating-linear-gradient(90deg, var(--ember-deep) 0 3px, oklch(0.3 0.05 40) 3px 6px); opacity: 0.8; }
.table-screen .cell.fog { background: oklch(0.16 0.01 255); }
.table-screen .cell.fog::after { content: ""; position: absolute; inset: 0; border-radius: 3px;
  background: radial-gradient(circle at 50% 40%, oklch(0.26 0.015 255 / 0.6), oklch(0.1 0.008 255 / 0.95));
  backdrop-filter: blur(1px); }
.table-screen .cell.range { box-shadow: inset 0 0 0 2px oklch(0.76 0.155 65 / 0.45); background:
    linear-gradient(150deg, oklch(0.28 0.04 75), oklch(0.235 0.025 75)); animation: rangepulse 2.4s ease-in-out infinite; }
@keyframes rangepulse { 50% { box-shadow: inset 0 0 0 2px oklch(0.76 0.155 65 / 0.85); } }

/* figurines */
.table-screen .ent-holder { position: relative; }
.table-screen .fig { position: absolute; inset: 8%; border-radius: 50%; display: grid; place-items: center; z-index: 4;
  font-weight: 800; font-size: clamp(10px, 1vw, 15px); box-shadow: var(--sh-2); border: 2px solid; }
.table-screen .fig .msym { font-size: clamp(14px, 1.4vw, 22px); }
.table-screen .fig.hero { background: linear-gradient(160deg, var(--stone-300), var(--stone-500)); color: var(--stone-950); border-color: var(--parch-100); }
.table-screen .fig.foe { background: linear-gradient(160deg, var(--body-bright), var(--ember-deep)); color: var(--parch-100); border-color: oklch(0.7 0.18 28); }
.table-screen .fig.cur { box-shadow: var(--glow-torch), var(--sh-2); border-color: var(--torch); animation: figpulse 2s ease-in-out infinite; }
@keyframes figpulse { 50% { box-shadow: 0 0 0 3px oklch(0.76 0.155 65 / 0.7), 0 0 28px oklch(0.76 0.155 65 / 0.5); } }
.table-screen .fig.chest { background: linear-gradient(160deg, var(--gold), var(--ember-deep)); border-color: var(--gold); color: var(--stone-950); }
.table-screen .fig.tgt::before { content: ""; position: absolute; inset: -7px; border-radius: 50%; border: 2px dashed var(--body-bright); animation: tspin 6s linear infinite; }
@keyframes tspin { to { transform: rotate(360deg); } }
.table-screen .fig .hp { position: absolute; bottom: -3px; left: 50%; transform: translateX(-50%); display: flex; gap: 1.5px; }
.table-screen .fig .hp i { width: 4px; height: 4px; border-radius: 1px; background: var(--body-bright); border: 0.5px solid oklch(0 0 0 / 0.4); }

/* halos de torche sur la carte */
.table-screen .torchspot { position: absolute; width: 30%; height: 36%; border-radius: 50%; pointer-events: none; z-index: 1;
  background: radial-gradient(circle, oklch(0.76 0.155 65 / 0.16), transparent 70%); animation: flick 3s ease-in-out infinite; }
@keyframes flick { 0%, 100% { opacity: .7 } 45% { opacity: 1 } 70% { opacity: .55 } }

/* ---- panneau de groupe ---- */
.table-screen .group { background: linear-gradient(180deg, var(--stone-850), var(--stone-900)); border: var(--line); border-radius: var(--r-lg);
  padding: 14px; display: flex; flex-direction: column; gap: 10px; overflow-y: auto; }
.table-screen .group h2 { font-family: var(--font-display); font-size: 13px; letter-spacing: 0.1em; text-transform: uppercase; color: var(--ink-300);
  margin: 2px 4px 4px; display: flex; align-items: center; gap: 8px; }
.table-screen .hcard { background: var(--stone-850); border: var(--line); border-radius: var(--r-md); padding: 10px 12px; transition: all .25s; }
.table-screen .hcard.acting { border-color: var(--torch); box-shadow: var(--glow-torch); background: oklch(0.27 0.02 75 / 0.5); }
.table-screen .hcard.downed { opacity: 0.6; border-color: var(--danger); }
.table-screen .hcard .hh { display: flex; align-items: center; gap: 9px; margin-bottom: 8px; }
.table-screen .hcard .crest { width: 32px; height: 32px; border-radius: 9px; display: grid; place-items: center; flex: none;
  background: linear-gradient(150deg, var(--ember), var(--ember-deep)); color: var(--parch-100); }
.table-screen .hcard .hn { font-family: var(--font-display); font-size: 14px; color: var(--parch-100); letter-spacing: 0.02em; line-height: 1.05; }
.table-screen .hcard .hc { font-size: 10.5px; color: var(--ink-500); font-weight: 600; }
.table-screen .hcard .conds { margin-left: auto; display: flex; gap: 4px; }
.table-screen .mini-badge { width: 22px; height: 22px; border-radius: 6px; display: grid; place-items: center; border: 1px solid currentColor; }
.table-screen .mini-badge .msym { font-size: 14px; }
.table-screen .b-poison { color: var(--cond-poison); background: oklch(0.68 0.165 145/0.14); }
.table-screen .b-buff { color: var(--cond-buff); background: oklch(0.8 0.13 90/0.14); }
.table-screen .b-burn { color: var(--cond-burn); background: oklch(0.64 0.19 45/0.14); }
.table-screen .pv-line { display: flex; align-items: center; gap: 8px; margin-top: 5px; }
.table-screen .pv-line .lab { width: 38px; font-size: 10px; font-weight: 800; letter-spacing: 0.04em; }
.table-screen .pv-line .pips { display: flex; gap: 2px; flex: 1; }
.table-screen .pv-line .pip { height: 9px; flex: 1; border-radius: 2px; background: oklch(0.3 0.01 255); border: none; }
.table-screen .pv-line .pip.b { background: linear-gradient(180deg, var(--body-bright), var(--body)); }
.table-screen .pv-line .pip.m { background: linear-gradient(180deg, var(--mind-bright), var(--mind)); }
.table-screen .pv-line .num { font-size: 11px; font-weight: 700; font-variant-numeric: tabular-nums; width: 30px; text-align: right; color: var(--ink-300); }
.table-screen .downed-tag { font-size: 10px; color: var(--danger); font-weight: 800; display: flex; align-items: center; gap: 4px; margin-top: 6px; }

/* ---- bandeau de narration ---- */
.table-screen .narr { z-index: 3; display: flex; align-items: center; gap: 16px; padding: 16px 22px; border-radius: var(--r-lg);
  background: linear-gradient(180deg, oklch(0.22 0.014 255 / 0.96), oklch(0.17 0.012 255 / 0.96));
  border: var(--line); border-left: 4px solid var(--torch); box-shadow: var(--sh-2); }
.table-screen .narr .av { width: 52px; height: 52px; border-radius: 50%; flex: none; display: grid; place-items: center;
  background: linear-gradient(150deg, var(--ember), var(--ember-deep)); color: var(--parch-100); box-shadow: var(--sh-1); }
.table-screen .narr .av .msym { font-size: 28px; }
.table-screen .narr .nbody { flex: 1; min-width: 0; }
.table-screen .narr .who { font-family: var(--font-display); font-size: 12px; letter-spacing: 0.12em; color: var(--torch); font-weight: 700; }
.table-screen .narr p { font-family: var(--font-narr); font-size: clamp(16px, 1.5vw, 22px); line-height: 1.4; margin: 4px 0 0; color: var(--ink-100); }
.table-screen .narr .tts { display: flex; align-items: flex-end; gap: 3px; height: 26px; flex: none; }
.table-screen .narr .tts i { width: 4px; background: var(--torch); border-radius: 2px; animation: table-eq 0.9s ease-in-out infinite; }
.table-screen .narr .tts i:nth-child(2) { animation-delay: .12s } .table-screen .narr .tts i:nth-child(3) { animation-delay: .24s }
.table-screen .narr .tts i:nth-child(4) { animation-delay: .36s } .table-screen .narr .tts i:nth-child(5) { animation-delay: .48s }
@keyframes table-eq { 0%, 100% { height: 5px } 50% { height: 26px } }
.table-screen .replay { margin-left: auto; }
.table-screen .btn { border: none; border-radius: var(--r-md); padding: 11px 17px; font-family: var(--font-ui); font-weight: 700; font-size: 14px;
  cursor: pointer; display: inline-flex; align-items: center; gap: 8px; background: var(--stone-800); color: var(--ink-100); border: var(--line-strong); transition: transform .1s; }
.table-screen .btn:active { transform: scale(0.97); }
.table-screen .btn.torch { background: var(--torch); color: var(--stone-950); border: none; box-shadow: none; }

/* ---- résolution de combat (overlay) ---- */
.table-screen .combat { position: absolute; inset: 0; z-index: 30; display: grid; place-items: center; pointer-events: none; opacity: 0; transition: opacity .3s; }
.table-screen .combat.show { opacity: 1; }
.table-screen .combat .panel { background: linear-gradient(180deg, var(--stone-850), var(--stone-900)); border: var(--line-strong); border-radius: var(--r-xl);
  padding: 26px 36px; box-shadow: var(--sh-3); text-align: center; min-width: 460px; transform: translateY(12px); transition: transform .3s; }
.table-screen .combat.show .panel { transform: translateY(0); }
.table-screen .combat .ctitle { font-family: var(--font-display); font-size: 19px; color: var(--parch-100); letter-spacing: 0.03em; margin-bottom: 18px; }
.table-screen .combat .ctitle b { color: var(--torch); }
.table-screen .combat .ctitle .foe { color: var(--body-bright); }
.table-screen .dice-stage { display: flex; align-items: center; justify-content: center; gap: 26px; }
.table-screen .dgrp { text-align: center; }
.table-screen .dgrp .gl { font-size: 11px; letter-spacing: 0.1em; text-transform: uppercase; font-weight: 700; color: var(--ink-500); margin-bottom: 10px; }
.table-screen .dice-row { display: flex; gap: 12px; justify-content: center; }
.table-screen .die { width: 58px; height: 58px; border-radius: 13px; }
.table-screen .die .msym { font-size: 32px; }
.table-screen .vs { font-family: var(--font-display); color: var(--ink-500); font-size: 22px; }
.table-screen .verdict { margin-top: 20px; font-family: var(--font-display); font-size: 24px; color: var(--parch-100); opacity: 0; transition: opacity .3s .2s; }
.table-screen .verdict.show { opacity: 1; }
.table-screen .verdict .dmg { color: var(--body-bright); }
</style>
