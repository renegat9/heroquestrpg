<script setup>
// ÉCRAN DE TABLE (hôte, paysage) — port fidèle de reference/heroquest/Table.html.
// Au montage : GET /api/groupes/{id}/etat + abonnement au canal privé
// `groupe.{identifiant}` (.groupe.etat, .narration.diffusee, .mj.reflechit).
// Repli : si l'API est injoignable ou refuse la session (401), on bascule
// sur les données de démo (store.modeDemo + badge « démo »).
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import MSym from '../components/ui/MSym.vue';
import DemoBadge from '../components/ui/DemoBadge.vue';
import InitiativeBar from '../components/table/InitiativeBar.vue';
import DungeonMap from '../components/table/DungeonMap.vue';
import GroupPanel from '../components/table/GroupPanel.vue';
import NarrationBand from '../components/table/NarrationBand.vue';
import CombatOverlay from '../components/table/CombatOverlay.vue';
import MarketPanel from '../components/table/MarketPanel.vue';
import { buildTableMap, TABLE_ENTITIES, TABLE_INIT_ORDER, TABLE_PARTY, TABLE_TRAPS } from '../data/demo';
import { souscrireGroupe } from '../composables/useEcho';
import { estErreurDemo, useApi } from '../composables/useApi';
import { useVoix } from '../composables/useVoix';
import {
    carteVersMap, clotureVersConfirmations, entitesVersFigurines, entitesVersGroupe,
    initiativeVersBarre, issueCloture, niveauMonteVersListe, piegesVersMarqueurs,
    useGameStore, voteVersFeuille,
} from '../store/game';

const props = defineProps({
    groupe: { type: String, required: true },
});

const store = useGameStore();
store.setGroupe(props.groupe);
const api = useApi();
const router = useRouter();
const voix = useVoix();

/* ---- chargement de l'état + abonnement temps réel ---- */
const desabonnements = [];
onMounted(async () => {
    try {
        store.appliquerEtat(await api.getEtat(props.groupe));
        desabonnements.push(souscrireGroupe(props.groupe, {
            '.groupe.etat': (e) => store.appliquerEtat(e),
            '.narration.diffusee': (e) => { store.setNarration(e.texte); voix.narrer({ texte: e.texte, url: e.url }); },
            '.bark.diffuse': (e) => voix.jouerBark(e),
            '.mj.reflechit': (e) => store.setMjReflechit(e.actif),
            '.marche.ouvert': (e) => store.appliquerMarche(e),
            '.marche.maj': (e) => store.appliquerMarche(e),
            '.marche.finalise': (e) => store.fermerMarche(e?.applique ?? null),
            '.vote.lance': (e) => store.appliquerVote(e?.vote ?? e),
            '.vote.maj': (e) => store.setVoteDecompte(e),
            '.vote.resultat': (e) => store.setVoteResultat(e),
            '.niveau.monte': (e) => store.setNiveauMonte(e),
            '.cloture.ouverte': (e) => store.appliquerCloture(e),
            '.cloture.maj': (e) => store.appliquerCloture(e),
            '.cloture.terminee': (e) => store.setClotureTerminee(e),
            '.prets.maj': (e) => store.appliquerPrets(e),
        }));
        // Rattrapage : phase marché, vote ou clôture déjà en cours (rechargement).
        api.getMarche(props.groupe).then((m) => store.appliquerMarche(m)).catch(() => {});
        api.getVote(props.groupe).then((r) => {
            const v = r?.vote ?? r;
            if (v && (v.type || v.options)) store.appliquerVote(v);
        }).catch(() => {});
        api.getCloture(props.groupe).then((c) => store.appliquerCloture(c)).catch(() => {});

        // Heartbeat Narrateur : POST /api/table/ping toutes les 15 s.
        // Maintient « table active » (cache TTL 30 s côté serveur).
        // En mode démo ou si le ping échoue, on continue sans bloquer.
        demarrerHeartbeat();
    } catch (e) {
        // 401 / API absente → on continue sur la démo (badge à l'écran).
        store.activerModeDemo(estErreurDemo(e) ? e.message : `erreur inattendue : ${e.message}`);
        setTimeout(() => combatRef.value?.play(), 1200); // séquence de la maquette
    }
});
onUnmounted(() => {
    desabonnements.forEach((off) => off());
    arreterHeartbeat();
});

/* ---- heartbeat Narrateur (POST /api/table/ping toutes les 15 s) ---- */
let heartbeatTimer = null;

function demarrerHeartbeat() {
    arreterHeartbeat();
    // Premier ping immédiat, puis toutes les 15 s.
    api.pingTable().catch(() => {}); // non bloquant
    heartbeatTimer = setInterval(() => {
        api.pingTable().catch(() => {}); // non bloquant
    }, 15_000);
}

function arreterHeartbeat() {
    if (heartbeatTimer !== null) {
        clearInterval(heartbeatTimer);
        heartbeatTimer = null;
    }
}

/* ---- état serveur mappé vers les composants (démo en repli) ---- */
const etat = computed(() => (store.state.modeDemo ? null : store.state.etat));
const enQuete = computed(() => !!etat.value?.carte);

const demoMap = buildTableMap();
const map = computed(() => (enQuete.value ? carteVersMap(etat.value.carte) : demoMap));
const traps = computed(() => (enQuete.value ? piegesVersMarqueurs(etat.value.carte) : TABLE_TRAPS));
const entities = computed(() => (etat.value
    ? entitesVersFigurines(etat.value.entites, etat.value.initiative)
    : TABLE_ENTITIES));
const initOrder = computed(() => (etat.value
    ? initiativeVersBarre(etat.value.initiative)
    : TABLE_INIT_ORDER));
const party = computed(() => (etat.value
    ? entitesVersGroupe(etat.value.entites, etat.value.initiative)
    : TABLE_PARTY));
const narration = computed(() => store.state.narration);
const mjReflechit = computed(() => (etat.value ? store.state.mjReflechit : true));
const joueursConnectes = computed(() => (etat.value
    ? etat.value.entites?.filter((e) => e.type === 'heros').length ?? 0
    : 4));

const titreQuete = computed(() => etat.value?.quete?.titre ?? 'Le Seuil des Ombres');
const sousTitre = computed(() => (etat.value
    ? etat.value.groupe?.nom ?? ''
    : "Quête III · La crypte d'ambre"));

/* ---- phase hub (mode connecté) : lancer la quête suivante ---- */
const lancementEnCours = ref(false);
const erreurLancement = ref('');
const phaseHub = computed(() => !!etat.value && etat.value.groupe?.phase === 'hub');
async function lancerQuete() {
    lancementEnCours.value = true;
    erreurLancement.value = '';
    try {
        await api.demarrerQuete(props.groupe);
        store.appliquerEtat(await api.getEtat(props.groupe));
    } catch (e) {
        erreurLancement.value = e.message;
    } finally {
        lancementEnCours.value = false;
    }
}

/* ---- phase marché (vue partagée, doc 04 §5) : or commun, panier
   consolidé étiqueté, total projeté, confirmations — MarketPanel. ---- */
const marche = computed(() => (etat.value ? store.state.marche : null));
const ouvertureMarche = ref(false);
const erreurMarche = ref('');
async function ouvrirMarche() {
    ouvertureMarche.value = true;
    erreurMarche.value = '';
    try {
        // Sans profil : le MJ IA choisit (repli serveur : bourg).
        const r = await api.ouvrirMarche(props.groupe);
        if (r) store.appliquerMarche(r); // .marche.ouvert arrive aussi par Reverb
    } catch (e) {
        erreurMarche.value = e.message;
    } finally {
        ouvertureMarche.value = false;
    }
}
async function annulerMarche() {
    try {
        await api.annulerMarche(props.groupe);
        store.fermerMarche(false); // confirmé par .marche.finalise
    } catch (e) {
        erreurMarche.value = e.message;
    }
}

/* ---- vote de groupe : bandeau de décompte en direct (.vote.maj),
   résultat affiché quelques secondes (.vote.resultat) puis fermé. ---- */
const voteTable = computed(() => (etat.value
    ? voteVersFeuille(store.state.vote, store.state.voteDecompte, store.state.voteResultat,
        null, null, store.state.etat)
    : null));
let voteTimer = null;
watch(() => store.state.voteResultat, (r) => {
    clearTimeout(voteTimer);
    if (r) voteTimer = setTimeout(() => store.fermerVote(), 6000);
});
onUnmounted(() => clearTimeout(voteTimer));

/* ---- montée de niveau (.niveau.monte, avant .groupe.etat) : bandeau de
   célébration doré (style maquette Montee de niveau.html) avec les gains
   par héros, refermé après quelques secondes ou d'un clic. ---- */
const niveauMonte = computed(() => {
    const heros = niveauMonteVersListe(store.state.niveauMonte, store.state.etat?.entites);
    return heros.length ? heros : null;
});
let lvlTimer = null;
watch(() => store.state.niveauMonte, (p) => {
    clearTimeout(lvlTimer);
    if (p) lvlTimer = setTimeout(() => store.fermerNiveauMonte(), 14000);
});
onUnmounted(() => clearTimeout(lvlTimer));

/* ---- clôture de campagne (contrat « Clôture de campagne ») : au hub
   « Clôturer » (fin décidée), après une quête échouée le choix double
   recharger/abandonner (TPK doc 05 §6, l'abandon = cloture {abandon}) ;
   .cloture.ouverte → bandeau routant vers l'écran de clôture ;
   .cloture.terminee → épilogue (écran de clôture) puis accueil. ---- */
const cloture = computed(() => (etat.value ? store.state.cloture : null));
const queteEchouee = computed(() => (etat.value?.quete?.etat ?? '') === 'echouee');
const habillageCloture = computed(() => issueCloture(cloture.value?.issue));
const clotureConfirmations = computed(() => clotureVersConfirmations(cloture.value));
const ouvertureCloture = ref(false);
const erreurCloture = ref('');
async function ouvrirCloture(abandon = false) {
    ouvertureCloture.value = true;
    erreurCloture.value = '';
    try {
        const r = await api.ouvrirCloture(props.groupe, { abandon });
        if (r) store.appliquerCloture(r); // .cloture.ouverte arrive aussi par Reverb
        router.push({ name: 'cloture', params: { groupe: props.groupe } });
    } catch (e) {
        erreurCloture.value = e.message;
    } finally {
        ouvertureCloture.value = false;
    }
}
/* ---- reprise après TPK (contrat « Snapshots & reprise ») : POST reprise
   sans snapshot_id → le serveur restaure le snapshot `debut_quete` de la
   quête échouée. L'état restauré revient par .groupe.etat (la quête
   repasse en_cours → le bandeau cendres se referme de lui-même) ; on
   re-GET l'état en rattrapage, comme pour le lancement de quête. ---- */
const repriseEnCours = ref(false);
const erreurReprise = ref('');
async function rechargerQuete() {
    repriseEnCours.value = true;
    erreurReprise.value = '';
    try {
        await api.reprendrePartie(props.groupe);
        store.appliquerEtat(await api.getEtat(props.groupe));
    } catch (e) {
        erreurReprise.value = e.message; // 422 : quête en cours non échouée, etc.
    } finally {
        repriseEnCours.value = false;
    }
}

// Finalisation (.cloture.terminee) : l'écran de clôture porte l'épilogue
// (résumés) et le « Retour à l'accueil » qui purge le store.
watch(() => store.state.clotureTerminee, (t) => {
    if (t && !store.state.modeDemo) {
        router.push({ name: 'cloture', params: { groupe: props.groupe } });
    }
}, { immediate: true });

/* ---- overlay de combat (séquence visuelle, mode démo) ---- */
const combatRef = ref(null);
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
                    <span class="ep">{{ sousTitre }}</span>
                    <h1>{{ titreQuete }}</h1>
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
                    <!-- phase marché (mode connecté) : vue partagée des paniers -->
                    <MarketPanel v-if="marche" :marche="marche" @annuler="annulerMarche" />
                    <!-- phase hub (mode connecté) : pas encore de carte, on lance la quête -->
                    <div v-else-if="phaseHub" class="hub-panel">
                        <MSym n="map" :size="40" fill />
                        <p>Le groupe se tient prêt au hub. La prochaine descente attend.</p>
                        <div style="display: flex; gap: 10px">
                            <button class="btn torch" :disabled="lancementEnCours" @click="lancerQuete">
                                <MSym n="play_arrow" /> {{ lancementEnCours ? 'Préparation…' : 'Lancer la quête' }}
                            </button>
                            <button class="btn" :disabled="ouvertureMarche" @click="ouvrirMarche">
                                <MSym n="storefront" /> {{ ouvertureMarche ? 'Ouverture…' : 'Ouvrir le marché' }}
                            </button>
                            <button class="btn" :disabled="ouvertureCloture" @click="ouvrirCloture(false)">
                                <MSym n="workspace_premium" /> {{ ouvertureCloture ? 'Ouverture…' : 'Clôturer' }}
                            </button>
                        </div>
                        <p v-if="erreurLancement" class="hub-err">{{ erreurLancement }}</p>
                        <p v-if="erreurMarche" class="hub-err">{{ erreurMarche }}</p>
                        <p v-if="erreurCloture" class="hub-err">{{ erreurCloture }}</p>
                    </div>
                    <DungeonMap v-else :map="map" :entities="entities" :traps="traps" />
                    <!-- montée de niveau : célébration dorée (gains par héros) -->
                    <div v-if="niveauMonte" class="lvlup-ov" @click="store.fermerNiveauMonte()">
                        <div class="panel">
                            <div class="seal"><MSym n="military_tech" fill /></div>
                            <h2>Montée de niveau&nbsp;!</h2>
                            <p class="sub">Le jalon est franchi — les héros gagnent en puissance.</p>
                            <div class="lv-heroes">
                                <div v-for="h in niveauMonte" :key="h.id" class="lv-hero">
                                    <span class="crest"><MSym :n="h.ic" fill /></span>
                                    <div class="hn">{{ h.nom }}</div>
                                    <div class="lv">Niv. {{ h.niveau }}</div>
                                    <ul v-if="h.gains.length" class="gains">
                                        <li v-for="(g, i) in h.gains" :key="i">
                                            <MSym n="auto_awesome" fill :size="13" /> {{ g }}
                                        </li>
                                    </ul>
                                    <span v-if="h.points > 0" class="pts">
                                        <MSym n="hub" fill :size="14" /> +{{ h.points }} point{{ h.points > 1 ? 's' : '' }} de compétence
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- vote de groupe : bandeau de décompte en direct -->
                    <div v-if="voteTable" class="vote-band">
                        <MSym n="how_to_vote" fill :size="20" />
                        <span class="vq">{{ voteTable.q }}</span>
                        <span v-for="o in voteTable.opts" :key="o.k" class="vopt" :class="{ win: o.gagnant }">
                            {{ o.l }} <b>{{ o.c }}</b>
                        </span>
                        <span v-if="voteTable.done" class="vst done">
                            <MSym n="check_circle" fill :size="15" /> Décision prise
                        </span>
                        <span v-else class="vst">
                            <MSym n="hourglass_top" :size="15" /> {{ voteTable.missing }} bulletin{{ voteTable.missing > 1 ? 's' : '' }} manquant{{ voteTable.missing > 1 ? 's' : '' }}
                        </span>
                    </div>
                    <!-- clôture ouverte (.cloture.ouverte) : bandeau vers l'écran de clôture -->
                    <div v-if="cloture" class="cloture-band" :class="{ cendres: habillageCloture.ton === 'cendres' }">
                        <MSym :n="habillageCloture.ic" fill :size="20" />
                        <span class="cq">{{ habillageCloture.crumb }} — {{ clotureConfirmations.confirmes }}/{{ clotureConfirmations.total || '?' }} confirmation{{ clotureConfirmations.total > 1 ? 's' : '' }}</span>
                        <RouterLink class="btn torch" :to="{ name: 'cloture', params: { groupe } }">
                            <MSym n="workspace_premium" :size="16" /> Clôturer la campagne
                        </RouterLink>
                    </div>
                    <!-- quête échouée (TPK doc 05 §6) : recharger (POST reprise)
                         ou abandonner la campagne (POST cloture {abandon}) -->
                    <div v-else-if="queteEchouee" class="cloture-band cendres">
                        <MSym n="skull" fill :size="20" />
                        <span class="cq">☠ Le groupe est tombé — recharger la quête, ou abandonner ?</span>
                        <button class="btn torch" :disabled="repriseEnCours || ouvertureCloture" @click="rechargerQuete">
                            <MSym n="replay" :size="16" /> {{ repriseEnCours ? 'Le donjon se reforme…' : 'Recharger la quête' }}
                        </button>
                        <button class="btn" :disabled="repriseEnCours || ouvertureCloture" @click="ouvrirCloture(true)">
                            <MSym n="flag" :size="16" /> {{ ouvertureCloture ? 'Ouverture…' : 'Abandonner la campagne' }}
                        </button>
                        <span v-if="erreurReprise || erreurCloture" class="cerr">{{ erreurReprise || erreurCloture }}</span>
                    </div>
                    <CombatOverlay ref="combatRef" @narrate="store.setNarration($event)" />
                </div>
                <GroupPanel :party="party" />
            </div>

            <!-- narration -->
            <NarrationBand :text="narration" :speaking="voix.speaking.value" @replay="combatRef?.play()" />
        </div>

        <!-- Activation de la voix du MJ (déblocage autoplay : geste requis par le navigateur) -->
        <button
            v-if="voix.supporte && !voix.actif.value"
            class="voix-activer"
            type="button"
            @click="voix.activer()"
        >
            <MSym n="volume_up" /> Activer la voix du MJ
        </button>

        <DemoBadge />
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

/* pièges (couche dédiée — contrat « Pièges » : detecte / desarme / declenche) */
.table-screen .trap-holder { position: relative; z-index: 3; pointer-events: none; }
.table-screen .trap { position: absolute; inset: 10%; border-radius: 6px; display: grid; place-items: center; }
.table-screen .trap .msym { font-size: clamp(12px, 1.3vw, 20px); filter: drop-shadow(0 1px 2px oklch(0 0 0 / 0.6)); }
.table-screen .trap.detecte { color: var(--warn); background: oklch(0.78 0.15 75 / 0.13);
  box-shadow: inset 0 0 0 1.5px oklch(0.78 0.15 75 / 0.55); animation: trappulse 2.2s ease-in-out infinite; }
@keyframes trappulse { 50% { box-shadow: inset 0 0 0 1.5px oklch(0.78 0.15 75 / 0.95); } }
.table-screen .trap.desarme { color: var(--ink-600); background: oklch(0.3 0.01 255 / 0.4);
  box-shadow: inset 0 0 0 1px oklch(0.4 0.01 255 / 0.5); opacity: 0.75; }
.table-screen .trap.desarme::after { content: ""; position: absolute; left: 14%; right: 14%; top: 50%; height: 2px;
  background: var(--ink-500); transform: rotate(-24deg); border-radius: 2px; }
.table-screen .trap.declenche { inset: 6%; border-radius: 50%;
  background: radial-gradient(circle at 50% 45%, oklch(0.08 0.01 255) 0 36%, oklch(0.24 0.045 40 / 0.85) 56%, transparent 74%);
  box-shadow: inset 0 0 10px oklch(0 0 0 / 0.85); }

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

/* ---- panneau de hub (mode connecté, avant la première quête) ---- */
.table-screen .hub-panel { display: grid; place-items: center; gap: 14px; text-align: center; padding: 40px;
  border-radius: var(--r-lg); border: var(--line); background: linear-gradient(180deg, var(--stone-850), var(--stone-900));
  color: var(--ink-300); max-width: 460px; }
.table-screen .hub-panel .msym { color: var(--torch); }
.table-screen .hub-panel p { font-family: var(--font-narr); font-style: italic; font-size: 17px; margin: 0; }
.table-screen .hub-panel .hub-err { font-family: var(--font-ui); font-style: normal; font-size: 13px; color: var(--danger, #c33); }

/* ---- montée de niveau (célébration dorée, style Montee de niveau.html) ---- */
.table-screen .lvlup-ov { position: absolute; inset: 0; z-index: 40; display: grid; place-items: center;
  background: oklch(0.16 0.012 255 / 0.78); backdrop-filter: blur(3px); animation: fadein .25s ease; cursor: pointer; }
.table-screen .lvlup-ov .panel { background: linear-gradient(180deg, oklch(0.24 0.02 90 / 0.45), var(--stone-900));
  border: var(--line-gold); border-radius: var(--r-xl); box-shadow: 0 0 40px oklch(0.80 0.135 88 / 0.25), var(--sh-3);
  padding: 26px 34px 28px; text-align: center; max-width: min(860px, 92%); }
.table-screen .lvlup-ov .seal { width: 72px; height: 72px; border-radius: 50%; display: grid; place-items: center; margin: 0 auto 12px;
  background: linear-gradient(150deg, var(--gold), var(--ember-deep)); color: var(--stone-950);
  box-shadow: 0 0 28px oklch(0.80 0.135 88 / 0.5); animation: lvlpop .4s cubic-bezier(.2, 1.5, .4, 1); }
@keyframes lvlpop { from { transform: scale(0.4); opacity: 0; } }
.table-screen .lvlup-ov .seal .msym { font-size: 40px; }
.table-screen .lvlup-ov h2 { font-family: var(--font-display); font-size: 26px; color: var(--gold); margin: 0 0 4px; letter-spacing: 0.04em; }
.table-screen .lvlup-ov .sub { font-family: var(--font-narr); font-style: italic; color: var(--ink-300); font-size: 15px; margin: 0 0 18px; }
.table-screen .lv-heroes { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
.table-screen .lv-hero { background: var(--stone-850); border: 1px solid oklch(0.62 0.08 80 / 0.4); border-radius: var(--r-md);
  padding: 14px 16px; min-width: 160px; max-width: 200px; display: flex; flex-direction: column; align-items: center; gap: 4px; }
.table-screen .lv-hero .crest { width: 44px; height: 44px; border-radius: 50%; display: grid; place-items: center;
  background: linear-gradient(150deg, var(--gold), var(--ember-deep)); color: var(--stone-950); margin-bottom: 4px; }
.table-screen .lv-hero .crest .msym { font-size: 24px; }
.table-screen .lv-hero .hn { font-family: var(--font-display); font-size: 14px; color: var(--parch-100); letter-spacing: 0.02em; }
.table-screen .lv-hero .lv { font-size: 12px; font-weight: 800; color: var(--gold); }
.table-screen .lv-hero .gains { list-style: none; margin: 6px 0 0; padding: 0; display: flex; flex-direction: column; gap: 3px; }
.table-screen .lv-hero .gains li { font-size: 11.5px; color: var(--ink-300); display: flex; align-items: center; gap: 5px; }
.table-screen .lv-hero .gains .msym { color: var(--gold); }
.table-screen .lv-hero .pts { margin-top: 8px; display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 800;
  color: var(--stone-950); background: var(--gold); border-radius: 99px; padding: 3px 10px; }

/* ---- bandeau de vote (décompte en direct) ---- */
.table-screen .vote-band { position: absolute; top: 10px; left: 50%; transform: translateX(-50%); z-index: 20;
  display: flex; align-items: center; gap: 14px; padding: 10px 18px; border-radius: 99px; max-width: 92%;
  background: linear-gradient(180deg, var(--stone-850), var(--stone-900)); border: var(--line-gold);
  box-shadow: var(--sh-3); animation: fadein .25s ease; }
.table-screen .vote-band .msym { color: var(--torch); }
.table-screen .vote-band .vq { font-family: var(--font-display); font-size: 15px; color: var(--parch-100);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.table-screen .vote-band .vopt { font-size: 13px; font-weight: 700; color: var(--ink-300); white-space: nowrap;
  background: var(--stone-800); border: var(--line); border-radius: 99px; padding: 4px 12px; }
.table-screen .vote-band .vopt b { color: var(--torch); font-variant-numeric: tabular-nums; margin-left: 4px; }
.table-screen .vote-band .vopt.win { border-color: var(--ok); color: var(--ok); }
.table-screen .vote-band .vst { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 700;
  color: var(--ink-500); white-space: nowrap; }
.table-screen .vote-band .vst.done { color: var(--ok); }
.table-screen .vote-band .vst .msym { color: currentColor; }
/* ---- bandeau de clôture de campagne (.cloture.ouverte / quête échouée) ---- */
.table-screen .cloture-band { position: absolute; bottom: 12px; left: 50%; transform: translateX(-50%); z-index: 20;
  display: flex; align-items: center; gap: 14px; padding: 10px 18px; border-radius: 99px; max-width: 92%;
  background: linear-gradient(180deg, var(--stone-850), var(--stone-900)); border: var(--line-gold);
  box-shadow: var(--sh-3); animation: fadein .25s ease; }
.table-screen .cloture-band > .msym { color: var(--gold); }
.table-screen .cloture-band.cendres { border: var(--line-strong); }
.table-screen .cloture-band.cendres > .msym { color: var(--ink-500); }
.table-screen .cloture-band .cq { font-family: var(--font-display); font-size: 15px; color: var(--parch-100);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.table-screen .cloture-band .btn { padding: 8px 13px; font-size: 12.5px; text-decoration: none; white-space: nowrap; }
.table-screen .cloture-band .cerr { font-size: 12px; font-weight: 700; color: var(--danger, #c33); white-space: nowrap; }
/* (keyframes fadein : déjà défini globalement dans manette.css) */

/* Bouton d'activation de la voix du MJ (déblocage autoplay) */
.voix-activer { position: fixed; right: 18px; bottom: 18px; z-index: 60;
  display: inline-flex; align-items: center; gap: 7px; cursor: pointer;
  padding: 10px 16px; border-radius: 999px; border: var(--line);
  background: linear-gradient(150deg, var(--ember), var(--ember-deep));
  color: var(--parch-100); font-weight: 700; font-size: 13px;
  box-shadow: 0 0 24px oklch(0.76 0.155 65 / 0.25), var(--sh-3);
  animation: table-voix-pulse 2s ease-in-out infinite; }
.voix-activer:hover { filter: brightness(1.08); }
@keyframes table-voix-pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.04); } }
</style>
