<script setup>
// ÉCRAN DE TABLE (hôte, paysage) — port fidèle de reference/heroquest/Table.html.
// Au montage : GET /api/groupes/{id}/etat + abonnement au canal privé
// `groupe.{identifiant}` (.groupe.etat, .narration.diffusee, .mj.reflechit).
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import MSym from '../components/ui/MSym.vue';
import InitiativeBar from '../components/table/InitiativeBar.vue';
import DungeonMap from '../components/table/DungeonMap.vue';
import GroupPanel from '../components/table/GroupPanel.vue';
import NarrationBand from '../components/table/NarrationBand.vue';
import PrologueOverlay from '../components/table/PrologueOverlay.vue';
import MarketPanel from '../components/table/MarketPanel.vue';
import { souscrireGroupe } from '../composables/useEcho';
import { useApi } from '../composables/useApi';
import { useVoix } from '../composables/useVoix';
import { useAmbiance } from '../composables/useAmbiance';
import {
    clotureVersConfirmations, entitesVersFigurines, entitesVersGroupe,
    initiativeVersBarre, issueCloture, niveauMonteVersListe, piegesVersMarqueurs,
    statsFigure, useGameStore, voteVersFeuille,
} from '../store/game';

const props = defineProps({
    groupe: { type: String, required: true },
});

const store = useGameStore();
store.setGroupe(props.groupe);
const api = useApi();
const router = useRouter();
const voix = useVoix();
const ambiance = useAmbiance();

/** Active la voix ET la musique d'un seul geste (déblocage autoplay navigateur). */
function activerSon() {
    voix.activer();
    ambiance.activer();
}

/* ---- chargement de l'état + abonnement temps réel ---- */
const desabonnements = [];
const chargement = ref(true);
const erreurChargement = ref('');
onMounted(async () => {
    try {
        store.appliquerEtat(await api.getEtatReprise(props.groupe));
        desabonnements.push(souscrireGroupe(props.groupe, {
            // Animation case-par-case : arrive JUSTE AVANT `.groupe.etat` (ordre
            // Reverb préservé) → on amorce le glissement avant que l'état ne pose
            // les positions finales.
            '.mouvement.anime': (e) => jouerMouvements(e.mouvements ?? []),
            '.groupe.etat': (e) => store.appliquerEtat(e),
            '.narration.diffusee': (e) => {
                // Narration périmée (arrivée en retard derrière une plus
                // récente) : ni affichée, ni lue à voix haute. Sinon on lit en
                // INTERROMPANT la précédente (B2) : la voix suit l'état courant
                // au lieu d'empiler des lignes en retard.
                if (!store.setNarration(e.texte, e.sequence)) return;

                // À la FIN de la lecture, signaler au serveur que le narrateur a
                // terminé → le joueur suivant est enfin activé (B1). `narrer`
                // appelle `apres` à la fin du TTS (ou tout de suite sans voix).
                const done = () => api.lectureTerminee().catch(() => {});
                voix.narrer({ texte: e.texte, url: e.url, interrompre: true, apres: done });
            },
            '.bark.diffuse': (e) => voix.jouerBark(e),
            // Journal MÉCANIQUE (dés, dégâts, morts, tours des monstres) : le même
            // fil que sur la manette, désormais AUSSI sur la table (C1/C2).
            '.combat.journal': (e) => store.pousserJournalCombat(e),
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
        // Maintient « table active » (cache TTL 30 s côté serveur). Si le
        // ping échoue, on continue sans bloquer.
        demarrerHeartbeat();
    } catch (e) {
        erreurChargement.value = e.message;
    } finally {
        chargement.value = false;
    }
});
onUnmounted(() => {
    animDemonte = true; // stoppe la boucle d'animation de déplacement
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

/* ---- état serveur mappé vers les composants ---- */
const etat = computed(() => store.state.etat);
const enQuete = computed(() => !!etat.value?.carte);

const traps = computed(() => (enQuete.value ? piegesVersMarqueurs(etat.value.carte) : []));
const entitesBrutes = computed(() => (etat.value
    ? entitesVersFigurines(etat.value.entites, etat.value.initiative)
    : []));

/* Animation CASE PAR CASE (E4) : pendant qu'une figurine « marche » le long de
   son chemin (.mouvement.anime), sa position affichée est PILOTÉE par
   `overrides` (clé "type:id" → {x,y}) et prime sur celle de l'état ; l'override
   est libéré en fin de trajet → la figurine se pose à sa position finale (état,
   source de vérité). */
const overrides = ref({});
const entities = computed(() => entitesBrutes.value.map((e) => {
    const o = overrides.value[`${e.type}:${e.id}`];
    return o ? { ...e, x: o.x, y: o.y } : e;
}));

/* Recentre la carte sur le HÉROS actif au début de son tour (initiative =
   lui). Basé sur les positions BRUTES (destination finale) → la caméra vise
   l'arrivée pendant que la figurine s'y rend, sans saccader case par case.
   Pendant le tour des monstres, la caméra ne bouge pas. */
const heroActif = computed(() => entitesBrutes.value.find((e) => e.k === 'hero' && e.cur) ?? null);

/* File d'animations de déplacement jouées séquentiellement (héros puis
   monstres) — glissement d'une case à l'autre le long du chemin. */
const DUREE_PAS_MS = 150;
let animEnCours = false;
let animDemonte = false;
const fileMouvements = [];
const attendre = (ms) => new Promise((r) => setTimeout(r, ms));

async function jouerMouvements(mouvements) {
    for (const m of mouvements ?? []) {
        if (m?.chemin?.length) fileMouvements.push(m);
    }
    if (animEnCours) return;
    animEnCours = true;
    while (fileMouvements.length && !animDemonte) {
        const mv = fileMouvements.shift();
        const cle = `${mv.type}:${mv.id}`;
        // Ancre sur le départ (souvent = position courante), puis avance.
        overrides.value = { ...overrides.value, [cle]: { x: mv.depart.x, y: mv.depart.y } };
        await attendre(40);
        for (const c of mv.chemin) {
            if (animDemonte) break;
            overrides.value = { ...overrides.value, [cle]: { x: c.x, y: c.y } };
            await attendre(DUREE_PAS_MS);
        }
        const copie = { ...overrides.value };
        delete copie[cle]; // libère → position finale (état)
        overrides.value = copie;
    }
    animEnCours = false;
}
/* ---- fil des événements mécaniques (.combat.journal) sur la table (C2) : les
   plus récents en bas, comme sur la manette. ---- */
const journalTable = computed(() => store.state.journalCombat);

/* ---- fiche de stats d'une figure au clic sur l'ordre de jeu (C3) ---- */
const figureInspectee = ref(null);
function inspecter(item) {
    figureInspectee.value = statsFigure(item, etat.value?.entites ?? []);
}

const initOrder = computed(() => (etat.value
    ? initiativeVersBarre(etat.value.initiative)
    : []));
const party = computed(() => (etat.value
    ? entitesVersGroupe(etat.value.entites, etat.value.initiative)
    : []));
const narration = computed(() => store.state.narration);
const mjReflechit = computed(() => (etat.value ? store.state.mjReflechit : false));
// En quête : héros présents sur la carte ; au hub (entités vides) : taille du
// groupe (statuts « prêt »), sinon le compteur affichait toujours 0 au hub.
const joueursConnectes = computed(() => (phaseHub.value
    ? (store.state.prets ?? []).length
    : (etat.value?.entites?.filter((e) => e.type === 'heros').length ?? 0)));
// Roster du hub (nom + prêt) pour le panneau « Le groupe » quand il n'y a pas
// encore de carte — le narrateur voyait un panneau vide au repos.
const presenceHub = computed(() => store.state.prets ?? []);

// Réordonner l'ordre du tour ENTRE les quêtes (au hub) : on monte/descend un
// héros et on persiste la nouvelle permutation. Le broadcast .prets.maj
// rediffuse le roster réordonné (et rétablit l'ordre serveur en cas d'échec).
const reordreEnCours = ref(false);
async function deplacerMembre(index, direction) {
    const membres = presenceHub.value;
    const cible = index + direction;
    if (reordreEnCours.value || cible < 0 || cible >= membres.length) return;
    const ordre = membres.map((m) => m.personnage_id);
    [ordre[index], ordre[cible]] = [ordre[cible], ordre[index]];
    reordreEnCours.value = true;
    try {
        await api.reordonnerGroupe(props.groupe, ordre);
    } catch { /* .prets.maj rétablit l'ordre serveur */ }
    finally { reordreEnCours.value = false; }
}

const titreQuete = computed(() => etat.value?.quete?.titre ?? 'Hub');
// Illustrations dynamiques (générées en arrière-plan) : lieu de repos (hub) et
// scène de quête. Null tant qu'absentes → repli sur le fond/l'icône.
const hubImage = computed(() => etat.value?.groupe?.image_url ?? null);
const sceneImage = computed(() => etat.value?.quete?.image_url ?? null);
const sousTitre = computed(() => etat.value?.groupe?.nom ?? '');

/* ---- prologue de campagne (écran d'histoire au lancement) ---- */
const prologue = computed(() => (etat.value ? etat.value.groupe?.prologue ?? null : null));
const prologueOuvert = ref(false);
let prologueVu = false; // une seule ouverture auto par session de table

function ouvrirPrologue() {
    if (!prologue.value) return;
    prologueOuvert.value = true;
    prologueVu = true;
    voix.narrer({ texte: prologue.value.texte, url: prologue.value.url });
}
function fermerPrologue() { prologueOuvert.value = false; }
function rejouerPrologue() {
    if (prologue.value) voix.narrer({ texte: prologue.value.texte, url: prologue.value.url });
}

// Ouverture AUTOMATIQUE au lancement de campagne (aucune quête encore jouée).
watch(prologue, (p) => {
    if (p && p.auto && !prologueVu) ouvrirPrologue();
}, { immediate: true });

/* ---- musique d'ambiance : suit la scène sonore de l'EtatGroupe ---- */
const sceneAmbiance = computed(() => (etat.value ? etat.value.groupe?.ambiance : null));
watch(sceneAmbiance, (s) => { if (s) ambiance.definirScene(s); }, { immediate: true });

/* ---- phase hub (mode connecté) : lancer la quête suivante ---- */
const lancementEnCours = ref(false);
const erreurLancement = ref('');
const phaseHub = computed(() => !!etat.value && etat.value.groupe?.phase === 'hub');
// Alliés recrutés (3.5) exposés au hub : la table les affiche pour que le
// narrateur voie les renforts avant de lancer la quête (le recrutement lui-même
// se fait sur la manette d'un joueur, bourse commune).
const recruesHub = computed(() => etat.value?.groupe?.mercenaires ?? []);
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
    if (t) {
        router.push({ name: 'cloture', params: { groupe: props.groupe } });
    }
}, { immediate: true });
</script>

<template>
    <div class="table-screen">
        <div v-if="chargement" class="table-loading">
            <MSym n="hourglass_top" :size="34" />
            <p>Connexion à la table…</p>
        </div>
        <div v-else-if="erreurChargement" class="table-loading">
            <MSym n="error" fill :size="34" />
            <p>{{ erreurChargement }}</p>
        </div>
        <div v-else class="table tex-stone tex-vignette" style="position: relative">
            <!-- bandeau haut -->
            <div class="top">
                <img v-if="enQuete && sceneImage" :src="sceneImage" alt="" class="scene-vignette" />
                <div class="quest">
                    <RouterLink to="/" class="hub-link">
                        <MSym n="arrow_back" :size="14" /> HUB
                    </RouterLink>
                    <span class="ep">{{ sousTitre }}</span>
                    <h1>{{ titreQuete }}</h1>
                </div>
                <InitiativeBar :order="initOrder" @inspecter="inspecter" />
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
                        <img v-if="hubImage" :src="hubImage" alt="Lieu de repos" class="hub-illus" />
                        <MSym v-else n="map" :size="40" fill />
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
                        <div v-if="recruesHub.length" class="hub-allies">
                            <span class="hub-allies-titre"><MSym n="diversity_3" fill :size="15" /> Renforts</span>
                            <span v-for="r in recruesHub" :key="r.id" class="hub-allie">
                                <MSym :n="r.animal ? 'pets' : 'shield'" :size="14" /> {{ r.nom }}
                            </span>
                        </div>
                    </div>
                    <DungeonMap
                        v-else
                        :carte="etat.carte"
                        :entities="entities"
                        :traps="traps"
                        :active-x="heroActif?.x ?? null"
                        :active-y="heroActif?.y ?? null"
                    />
                    <!-- fil des événements mécaniques (dés, dégâts, morts…) : C1/C2 -->
                    <div v-if="enQuete && journalTable.length" class="evt-log">
                        <div class="evt-ttl"><MSym n="receipt_long" :size="14" /> Fil des événements</div>
                        <ul>
                            <li v-for="l in journalTable" :key="l.id" :class="`t-${l.ton}`">{{ l.texte }}</li>
                        </ul>
                    </div>
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
                </div>
                <GroupPanel v-if="!phaseHub" :party="party" />
                <div v-else class="hub-roster">
                    <h2><MSym n="groups" fill /> Le groupe</h2>
                    <p v-if="presenceHub.length > 1" class="hub-roster-hint">
                        <MSym n="swap_vert" :size="14" /> Ordre du tour — réordonne avant de lancer la quête
                    </p>
                    <div v-if="presenceHub.length" class="hub-roster-list">
                        <div
                            v-for="(m, i) in presenceHub"
                            :key="m.personnage_id"
                            class="hub-roster-line"
                            :class="{ pret: m.pret }"
                        >
                            <span class="hr-ordre">{{ i + 1 }}</span>
                            <MSym :n="m.pret ? 'check_circle' : 'radio_button_unchecked'" fill :size="17" />
                            <span class="hr-nom">{{ m.nom }}</span>
                            <span class="hr-etat">{{ m.pret ? 'Prêt' : 'En attente' }}</span>
                            <span v-if="presenceHub.length > 1" class="hr-reordre">
                                <button type="button" :disabled="i === 0 || reordreEnCours"
                                    title="Monter" @click="deplacerMembre(i, -1)"><MSym n="keyboard_arrow_up" :size="18" /></button>
                                <button type="button" :disabled="i === presenceHub.length - 1 || reordreEnCours"
                                    title="Descendre" @click="deplacerMembre(i, 1)"><MSym n="keyboard_arrow_down" :size="18" /></button>
                            </span>
                        </div>
                    </div>
                    <p v-else class="hub-roster-vide">Aucun héros rattaché pour l'instant.</p>
                </div>
            </div>

            <!-- narration -->
            <NarrationBand :text="narration" :speaking="voix.speaking.value" />

            <!-- fiche de stats d'une figure (clic sur l'ordre de jeu — C3) -->
            <div v-if="figureInspectee" class="stat-ov" @click.self="figureInspectee = null">
                <div class="stat-card">
                    <button class="stat-x" type="button" @click="figureInspectee = null">
                        <MSym n="close" />
                    </button>
                    <h3>
                        {{ figureInspectee.nom }}
                        <span v-if="figureInspectee.elite" class="stat-elite"><MSym n="star" fill :size="14" /> Élite</span>
                    </h3>
                    <p class="stat-sub">
                        {{ figureInspectee.type === 'heros'
                            ? `${figureInspectee.classe ?? 'Héros'}${figureInspectee.niveau ? ' · Niv. ' + figureInspectee.niveau : ''}`
                            : (figureInspectee.type === 'allie' ? 'Allié' : 'Monstre') }}
                    </p>
                    <div class="stat-grid">
                        <div class="stat-b"><span>PV Body</span><b>{{ figureInspectee.pv_body }}<i v-if="figureInspectee.pv_body_max"> / {{ figureInspectee.pv_body_max }}</i></b></div>
                        <div v-if="figureInspectee.pv_mind_max" class="stat-b"><span>PV Mind</span><b>{{ figureInspectee.pv_mind }} / {{ figureInspectee.pv_mind_max }}</b></div>
                        <div v-if="figureInspectee.des_attaque != null" class="stat-b"><span>Attaque</span><b>{{ figureInspectee.des_attaque }} dés</b></div>
                        <div v-if="figureInspectee.des_defense != null" class="stat-b"><span>Défense</span><b>{{ figureInspectee.des_defense }} dés</b></div>
                        <div v-if="figureInspectee.attribut_body != null" class="stat-b"><span>Body</span><b>{{ figureInspectee.attribut_body }}</b></div>
                        <div v-if="figureInspectee.attribut_mind != null" class="stat-b"><span>Mind</span><b>{{ figureInspectee.attribut_mind }}</b></div>
                    </div>
                    <div v-if="figureInspectee.conditions?.length" class="stat-conds">
                        <span v-for="(c, i) in figureInspectee.conditions" :key="i" class="stat-cond">{{ c.nom }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Prologue de campagne : écran d'histoire au lancement (et relisible) -->
        <PrologueOverlay
            v-if="prologueOuvert && prologue"
            :prologue="prologue"
            :parle="voix.speaking.value"
            @commencer="fermerPrologue"
            @rejouer="rejouerPrologue"
        />
        <button
            v-if="prologue && !prologueOuvert"
            class="prologue-rouvrir"
            type="button"
            @click="ouvrirPrologue"
        >
            <MSym n="auto_stories" /> Prologue
        </button>

        <!-- Activation du son (voix du MJ + musique) : geste requis par le navigateur -->
        <button
            v-if="!voix.actif.value"
            class="voix-activer"
            type="button"
            @click="activerSon"
        >
            <MSym n="volume_up" /> Activer le son
        </button>
        <!-- Une fois le son actif : couper / rétablir la musique d'ambiance -->
        <button
            v-else
            class="ambiance-muet"
            type="button"
            :title="ambiance.muet.value ? 'Rétablir la musique' : 'Couper la musique'"
            @click="ambiance.basculerMuet()"
        >
            <MSym :n="ambiance.muet.value ? 'music_off' : 'music_note'" />
        </button>
    </div>
</template>

<style>
/* Port de Table.html — préfixé .table-screen pour ne pas fuir sur les
   autres écrans (la SPA partage un seul bundle CSS). */
.table-screen { background: #000; color: var(--ink-100); overflow: hidden; --ambiance: 0.62; }
.table-loading { width: 100vw; height: 100vh; display: flex; flex-direction: column; align-items: center;
  justify-content: center; gap: 14px; color: var(--ink-400); text-align: center; padding: 32px; }
.table-loading .msym { color: var(--torch); }
.table-loading p { font-family: var(--font-narr); font-style: italic; font-size: 17px; margin: 0; max-width: 480px; }
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

/* Fil des événements mécaniques (dés, dégâts, morts…) — C1/C2 : overlay discret
   en bas à gauche de la carte, plus récents en bas. */
.table-screen .evt-log { position: absolute; left: 14px; bottom: 14px; z-index: 6; width: min(300px, 34%);
  max-height: 40%; overflow: hidden; display: flex; flex-direction: column;
  background: oklch(0.14 0.01 255 / 0.86); border: 1px solid var(--stone-700); border-radius: var(--r-md, 10px);
  box-shadow: var(--sh-2); backdrop-filter: blur(3px); }
.table-screen .evt-log .evt-ttl { display: flex; align-items: center; gap: 6px; padding: 6px 10px;
  font-size: 10px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--ink-500); font-weight: 700;
  border-bottom: 1px solid var(--stone-800); }
.table-screen .evt-log ul { list-style: none; margin: 0; padding: 6px 10px; overflow-y: auto; display: flex; flex-direction: column; gap: 3px; }
.table-screen .evt-log li { font-size: 12px; line-height: 1.35; color: var(--ink-300); }
.table-screen .evt-log li.t-degats, .table-screen .evt-log li.t-mort { color: var(--body-bright); }
.table-screen .evt-log li.t-subit, .table-screen .evt-log li.t-chute { color: oklch(0.78 0.13 55); }
.table-screen .evt-log li.t-pare, .table-screen .evt-log li.t-succes { color: oklch(0.8 0.13 150); }

/* Fiche de stats d'une figure (clic sur l'ordre de jeu — C3). */
.table-screen .stat-ov { position: absolute; inset: 0; z-index: 30; display: grid; place-items: center;
  background: oklch(0 0 0 / 0.55); backdrop-filter: blur(2px); }
.table-screen .stat-card { position: relative; width: min(340px, 86%); padding: 20px 22px;
  background: linear-gradient(160deg, var(--stone-800), var(--stone-900)); border: 1px solid var(--stone-600);
  border-radius: var(--r-lg); box-shadow: var(--sh-3); color: var(--ink-200); }
.table-screen .stat-x { position: absolute; top: 10px; right: 10px; background: none; border: none; color: var(--ink-500); cursor: pointer; }
.table-screen .stat-card h3 { margin: 0 0 2px; font-size: 20px; display: flex; align-items: center; gap: 8px; color: var(--parch-100); }
.table-screen .stat-elite { display: inline-flex; align-items: center; gap: 3px; font-size: 11px; color: var(--torch); }
.table-screen .stat-sub { margin: 0 0 14px; font-size: 12px; letter-spacing: 0.05em; color: var(--ink-500); text-transform: uppercase; }
.table-screen .stat-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
.table-screen .stat-b { display: flex; flex-direction: column; gap: 2px; padding: 8px 10px; background: oklch(0.16 0.01 255 / 0.7); border-radius: 8px; border: 1px solid var(--stone-700); }
.table-screen .stat-b span { font-size: 10px; letter-spacing: 0.1em; text-transform: uppercase; color: var(--ink-500); }
.table-screen .stat-b b { font-size: 17px; color: var(--parch-100); font-weight: 800; }
.table-screen .stat-b i { font-style: normal; color: var(--ink-500); font-weight: 600; font-size: 14px; }
.table-screen .stat-conds { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; }
.table-screen .stat-cond { font-size: 11px; padding: 3px 8px; border-radius: 999px; background: oklch(0.3 0.05 300 / 0.4); border: 1px solid oklch(0.5 0.08 300 / 0.5); color: var(--ink-200); }
.table-screen .status-top { display: flex; align-items: center; gap: 14px; }
.table-screen .think { display: inline-flex; align-items: center; gap: 9px; padding: 8px 15px; border-radius: 99px;
  background: var(--stone-850); border: var(--line-gold); color: var(--torch); font-size: 13px; font-weight: 700; }
.table-screen .conn { display: flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 700; color: var(--ok); }
.table-screen .conn .dot { width: 8px; height: 8px; border-radius: 50%; background: currentColor; box-shadow: 0 0 8px currentColor; }

/* ---- zone principale : carte + panneau de groupe ---- */
.table-screen .main { display: grid; grid-template-columns: 1fr 320px; gap: 18px; min-height: 0; z-index: 2; }
.table-screen .map-wrap { position: relative; display: grid; place-items: center; min-height: 0; }
/* Fenêtre fixe (mêmes proportions/zoom qu'avant, 14×9 cases visibles) —
   `overflow: hidden` recadre .map-grid, plus grand si la vraie carte
   dépasse cette densité (ex. 22×6), pendant que .map-grid se recentre
   sur le héros actif (voir DungeonMap.vue, largeur/hauteur/transform en
   ligne, calculées en JS à partir de la taille réelle de cette fenêtre). */
.table-screen .map { position: relative; overflow: hidden; aspect-ratio: 14 / 9; height: 100%; max-width: 100%;
  padding: 14px; border-radius: var(--r-lg); background: oklch(0.12 0.01 255);
  box-shadow: inset 0 0 60px oklch(0 0 0 / 0.7), var(--sh-3); border: 1px solid oklch(0.3 0.02 255 / 0.6); }
/* Le TERRAIN (cases / portes / pièges) est rendu par le socle partagé
   DungeonGrid (mêmes teintes que la manette). Ici : seule la couche figurines. */

/* figurines */
.table-screen .ent-holder { position: relative; }
.table-screen .fig { position: absolute; inset: 8%; border-radius: 50%; display: grid; place-items: center; z-index: 4;
  font-weight: 800; font-size: clamp(10px, 1vw, 15px); box-shadow: var(--sh-2); border: 2px solid; }
.table-screen .fig .msym { font-size: clamp(14px, 1.4vw, 22px); }
.table-screen .fig.hero { background: linear-gradient(160deg, var(--stone-300), var(--stone-500)); color: var(--stone-950); border-color: var(--parch-100); }
.table-screen .fig.foe { background: linear-gradient(160deg, var(--body-bright), var(--ember-deep)); color: var(--parch-100); border-color: oklch(0.7 0.18 28); }
/* allié recruté (3.5) : teinte verte amicale, distincte des héros et ennemis */
.table-screen .fig.ally { background: linear-gradient(160deg, oklch(0.7 0.13 155), oklch(0.5 0.12 158)); color: var(--parch-100); border-color: oklch(0.8 0.14 150); }
.table-screen .fig.cur { box-shadow: var(--glow-torch), var(--sh-2); border-color: var(--torch); animation: figpulse 2s ease-in-out infinite; }
/* monstre élite (3.6) : liseré doré + badge couronne/étoile */
.table-screen .fig.elite { border-color: var(--gold); box-shadow: 0 0 0 2px oklch(0.82 0.15 85 / 0.55), var(--sh-2); }
.table-screen .fig .elite-badge { position: absolute; top: -6px; right: -6px; z-index: 5; color: var(--gold);
  filter: drop-shadow(0 1px 2px oklch(0 0 0 / 0.7)); }
.table-screen .fig .elite-badge .msym { font-size: clamp(10px, 1vw, 15px); }
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
.table-screen .hub-panel .hub-illus { width: 100%; max-width: 380px; aspect-ratio: 16 / 10; object-fit: cover;
  border-radius: var(--r-md); border: var(--line); box-shadow: var(--sh-2); }
/* vignette de scène dans le bandeau de quête */
.table-screen .top .scene-vignette { width: 56px; height: 56px; object-fit: cover; border-radius: var(--r-md);
  border: var(--line); flex: none; box-shadow: var(--sh-1); }
.table-screen .hub-panel p { font-family: var(--font-narr); font-style: italic; font-size: 17px; margin: 0; }
.table-screen .hub-panel .hub-err { font-family: var(--font-ui); font-style: normal; font-size: 13px; color: var(--danger, #c33); }
.table-screen .hub-allies { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 8px 12px;
  font-family: var(--font-ui); font-style: normal; }
.table-screen .hub-allies-titre { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.05em; color: var(--gold, #c9a24a); }
.table-screen .hub-allies .hub-allie { display: inline-flex; align-items: center; gap: 4px; font-size: 13px; font-weight: 700;
  color: var(--ink-300); padding: 3px 10px; border-radius: 999px; border: var(--line); background: var(--stone-850); }
/* ---- roster du hub (panneau « Le groupe » sans carte) ---- */
.table-screen .hub-roster { border-radius: var(--r-lg); border: var(--line);
  background: linear-gradient(180deg, var(--stone-850), var(--stone-900)); padding: 16px; }
.table-screen .hub-roster h2 { display: flex; align-items: center; gap: 8px; margin: 0 0 12px; font-size: 15px;
  text-transform: uppercase; letter-spacing: 0.06em; color: var(--ink-300); }
.table-screen .hub-roster h2 .msym { color: var(--torch); }
.table-screen .hub-roster-list { display: flex; flex-direction: column; gap: 8px; }
.table-screen .hub-roster-line { display: flex; align-items: center; gap: 8px; font-size: 14px; color: var(--ink-500); }
.table-screen .hub-roster-line.pret { color: var(--ink-100, #f0e9d8); }
.table-screen .hub-roster-line.pret .msym { color: var(--ok, #6bbf59); }
.table-screen .hub-roster-line .hr-nom { font-weight: 700; }
.table-screen .hub-roster-line .hr-etat { margin-left: auto; font-size: 12px; font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.04em; }
.table-screen .hub-roster-vide { font-family: var(--font-narr); font-style: italic; color: var(--ink-500); margin: 0; }
/* Ordre du tour : pastille numérotée + flèches monter/descendre (réordonnable au hub). */
.table-screen .hub-roster-hint { display: flex; align-items: center; gap: 6px; margin: -4px 0 12px; font-size: 12px;
  color: var(--ink-500); font-style: italic; }
.table-screen .hub-roster-hint .msym { color: var(--torch); }
.table-screen .hub-roster-line .hr-ordre { flex: none; width: 22px; height: 22px; border-radius: 999px; display: grid;
  place-items: center; font-size: 12px; font-weight: 800; background: var(--stone-800); border: var(--line); color: var(--ink-300); }
.table-screen .hub-roster-line .hr-reordre { display: inline-flex; gap: 2px; margin-left: 8px; }
.table-screen .hub-roster-line .hr-reordre button { display: grid; place-items: center; width: 28px; height: 28px;
  border-radius: 8px; border: var(--line); background: var(--stone-850); color: var(--ink-300); cursor: pointer; transition: all .12s; }
.table-screen .hub-roster-line .hr-reordre button:hover:not(:disabled) { color: var(--torch); border-color: var(--torch); }
.table-screen .hub-roster-line .hr-reordre button:disabled { opacity: 0.3; cursor: default; }

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

/* Bouton couper/rétablir la musique d'ambiance */
.ambiance-muet { position: fixed; right: 18px; bottom: 18px; z-index: 60;
  display: grid; place-items: center; cursor: pointer; width: 44px; height: 44px;
  border-radius: 999px; border: var(--line); background: var(--stone-850);
  color: var(--ink-200); box-shadow: var(--sh-2); transition: color .15s, border-color .15s; }
.ambiance-muet:hover { color: var(--parch-100); border-color: var(--torch); }

/* Bouton « Prologue » (rouvrir l'écran d'histoire) */
.prologue-rouvrir { position: fixed; left: 18px; bottom: 18px; z-index: 60;
  display: inline-flex; align-items: center; gap: 7px; cursor: pointer;
  padding: 9px 15px; border-radius: 999px; border: var(--line);
  background: var(--stone-850); color: var(--ink-200); font-weight: 700; font-size: 13px;
  box-shadow: var(--sh-2); transition: color .15s, border-color .15s; }
.prologue-rouvrir:hover { color: var(--parch-100); border-color: var(--torch); }
</style>
