<script setup>
// CLÔTURE DE CAMPAGNE (paysage, partagé hôte/joueurs) — port branché de
// reference/heroquest/"Cloture de campagne.html" (contrat « Clôture de
// campagne »). Au montage : GET cloture + abonnement au canal privé
// `groupe.{identifiant}` (.cloture.ouverte/.maj/.terminee). L'issue
// (victoire/échec/abandon) pilote le ton : braises dorées ou cendres.
// Tap un héros sur un objet → PUT repartition (optimiste, l'état revient
// par .cloture.maj) ; « Confirmer » par joueur (k/n) ; .cloture.terminee
// → épilogue (résumé du héros du joueur) puis retour à l'accueil avec
// purge du store — le groupe n'existe plus.
// Repli : API injoignable / 401 → démo locale (badge « démo »).
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import MSym from '../components/ui/MSym.vue';
import DemoBadge from '../components/ui/DemoBadge.vue';
import { CLOTURE_HEROES, CLOTURE_REWARDS } from '../data/demo';
import { souscrireGroupe } from '../composables/useEcho';
import { estErreurDemo, useApi } from '../composables/useApi';
import {
    clotureVersConfirmations, clotureVersEquipements, clotureVersParts,
    issueCloture, resumeDuJoueur, useGameStore,
} from '../store/game';

const props = defineProps({
    groupe: { type: String, required: true },
});

const store = useGameStore();
store.setGroupe(props.groupe);
const api = useApi();
const router = useRouter();

/* ---- chargement + abonnement temps réel ---- */
const desabonnements = [];
onMounted(async () => {
    try {
        if (!store.state.joueur) {
            // La table (narrateur sans compte) prend un 401 sur /moi : c'est
            // normal, on l'ignore (sinon le catch global basculerait l'écran de
            // clôture en mode démo — estErreurDemo() considère 401 comme démo).
            // Seule une vraie panne réseau (status 0) → démo.
            try {
                const { joueur, personnages } = await api.moi();
                store.setJoueur(joueur, personnages);
            } catch (e) {
                if (e instanceof Error && e.status === 0) throw e;
                // 401 = narrateur sans compte → on continue (épilogue partagé).
            }
        }
        desabonnements.push(souscrireGroupe(props.groupe, {
            '.cloture.ouverte': (e) => store.appliquerCloture(e),
            '.cloture.maj': (e) => store.appliquerCloture(e),
            '.cloture.terminee': (e) => store.setClotureTerminee(e),
        }));
        // Si la finalisation est déjà passée (épilogue affiché), le groupe
        // n'existe plus : on ne re-GET rien.
        if (!store.state.clotureTerminee) {
            // Habillage (icônes de classe, nom du groupe) — best-effort.
            if (!store.state.etat) {
                api.getEtat(props.groupe).then((e) => store.appliquerEtat(e)).catch(() => {});
            }
            try {
                store.appliquerCloture(await api.getCloture(props.groupe));
            } catch (e) {
                if (estErreurDemo(e)) throw e;
                // 404/422 : pas de fenêtre ouverte — écran d'attente.
            }
        }
    } catch (e) {
        store.activerModeDemo(estErreurDemo(e) ? e.message : `erreur inattendue : ${e.message}`);
    }
});
onUnmounted(() => desabonnements.forEach((off) => off()));

/* ---- état serveur (démo en repli) ---- */
const enDemo = computed(() => store.state.modeDemo);
const cloture = computed(() => (enDemo.value ? null : store.state.cloture));
const terminee = computed(() => (enDemo.value ? null : store.state.clotureTerminee));

const habillage = computed(() => issueCloture(cloture.value?.issue));
const cendres = computed(() => !enDemo.value && habillage.value.ton === 'cendres');

const nomGroupe = computed(() => store.state.etat?.groupe?.nom ?? '');
const crumb = computed(() => (enDemo.value
    ? "Campagne achevée · 8 quêtes · La Crypte d'Ambre"
    : habillage.value.crumb + (nomGroupe.value ? ` · ${nomGroupe.value}` : '')));
const titre = computed(() => (enDemo.value ? 'La Lumière Revient' : habillage.value.titre));

/* ---- parts d'or et butin ---- */
const parts = computed(() => clotureVersParts(cloture.value, store.state.etat?.entites));

/* Réassignations optimistes : {inventaire_id → personnage_id}, vidées
   quand l'état serveur revient (.cloture.maj fait foi). */
const reassignations = ref({});
const erreurCloture = ref('');
watch(cloture, () => {
    reassignations.value = {};
    erreurCloture.value = '';
    // L'état serveur fait foi (mienne dans confirmations) — l'optimisme
    // local retombe à chaque .cloture.maj (une réassignation d'un autre
    // joueur annule toutes les confirmations).
    confirmEnvoyee.value = false;
});

const equipements = computed(() => clotureVersEquipements(cloture.value).map((e) => ({
    ...e,
    personnage_id: reassignations.value[e.inventaire_id] ?? e.personnage_id,
})));

async function assigner(equipement, personnageId) {
    if (equipement.personnage_id === personnageId) return;
    reassignations.value = { ...reassignations.value, [equipement.inventaire_id]: personnageId };
    confirmEnvoyee.value = false; // toute réassignation annule les confirmations
    erreurCloture.value = '';
    try {
        const r = await api.reassignerEquipement(props.groupe, {
            inventaire_id: equipement.inventaire_id,
            personnage_id: personnageId,
        });
        if (r) store.appliquerCloture(r); // .cloture.maj arrive aussi par Reverb
    } catch (e) {
        const { [equipement.inventaire_id]: retiree, ...reste } = reassignations.value;
        reassignations.value = reste;
        if (estErreurDemo(e)) store.activerModeDemo(e.message);
        else erreurCloture.value = e.message;
    }
}

/* ---- confirmations (k/n, par joueur) ---- */
const confirmEnvoyee = ref(false);
const confirmations = computed(() => clotureVersConfirmations(cloture.value, store.state.joueur?.id));
const maConfirmation = computed(() => confirmations.value.mienne || confirmEnvoyee.value);

async function confirmer() {
    if (maConfirmation.value) return;
    confirmEnvoyee.value = true; // optimiste — l'état revient par .cloture.maj
    erreurCloture.value = '';
    try {
        const r = await api.confirmerCloture(props.groupe);
        if (r) store.appliquerCloture(r);
    } catch (e) {
        confirmEnvoyee.value = false;
        if (estErreurDemo(e)) store.activerModeDemo(e.message);
        else erreurCloture.value = e.message;
    }
}

const annulation = ref(false);
async function annuler() {
    annulation.value = true;
    erreurCloture.value = '';
    try {
        await api.annulerCloture(props.groupe);
        store.fermerCloture();
        router.back(); // rien appliqué — retour à l'écran d'où l'on vient
    } catch (e) {
        if (estErreurDemo(e)) store.activerModeDemo(e.message);
        else erreurCloture.value = e.message;
    } finally {
        annulation.value = false;
    }
}

/* ---- épilogue (.cloture.terminee : {resumes}) ---- */
const monResume = computed(() => resumeDuJoueur(terminee.value, store.state.personnages));
const resumes = computed(() => terminee.value?.resumes ?? []);
const nomDuHeros = (personnageId) =>
    parts.value.find((p) => p.personnage_id === personnageId)?.nom ?? `Héros n°${personnageId}`;

/** Le groupe n'existe plus : purge du store puis accueil. */
function retourAccueil() {
    store.purgerGroupe();
    router.push('/');
}

/* ---- démo : attribution locale (comme la maquette) ---- */
const demoAssignments = ref(CLOTURE_REWARDS.map((r) => r.to));
const RAR_LABEL = { uncommon: 'Peu commun', rare: 'Rare', unique: 'Unique' };

/* braises flottantes (générées une fois) */
const embers = Array.from({ length: 26 }, () => {
    const size = 2 + Math.random() * 4;
    return {
        left: Math.random() * 100 + '%',
        width: size + 'px',
        height: size + 'px',
        animationDuration: 5 + Math.random() * 7 + 's',
        animationDelay: -Math.random() * 8 + 's',
        opacity: 0.5 + Math.random() * 0.5,
    };
});
</script>

<template>
    <div class="cloture">
        <div class="stage-c tex-stone tex-vignette" :class="{ cendres }">
            <RouterLink class="home" to="/"><MSym n="arrow_back" :size="14" /> HUB</RouterLink>
            <div class="embers"><div v-for="(e, i) in embers" :key="i" class="ember" :style="e" /></div>

            <div class="head">
                <div class="crumb">{{ crumb }}</div>
                <h1>{{ titre }}</h1>
                <div class="laurel"><span class="ln" /><MSym :n="enDemo ? 'military_tech' : habillage.ic" fill /><span class="ln r" /></div>
            </div>

            <!-- ================= épilogue (.cloture.terminee) ================= -->
            <div v-if="terminee" class="main fin">
                <div class="epilogue">
                    <div class="who">
                        <span class="av"><MSym n="menu_book" fill /></span>
                        <div>
                            <div class="lbl">LE MAÎTRE DE JEU · ÉPILOGUE</div>
                            <div class="sub">La campagne est close — chaque héros emporte son histoire</div>
                        </div>
                    </div>
                    <template v-if="monResume">
                        <p><span class="drop">{{ (monResume.resume || ' ').charAt(0) }}</span>{{ (monResume.resume || '').slice(1) }}</p>
                    </template>
                    <template v-else-if="resumes.length">
                        <div v-for="r in resumes" :key="r.personnage_id" class="resume-bloc">
                            <div class="resume-nom"><MSym n="person" fill :size="15" /> {{ nomDuHeros(r.personnage_id) }}</div>
                            <p>{{ r.resume }}</p>
                        </div>
                    </template>
                    <p v-else>La compagnie se dissout dans la brume du hub. Les chroniques retiendront son passage…</p>
                    <div v-if="cloture" class="campstats">
                        <div class="cs"><div class="v gold">{{ cloture.or_a_partager ?? 0 }}</div><div class="k">Or partagé</div></div>
                        <div class="cs"><div class="v">{{ parts.length }}</div><div class="k">Parts</div></div>
                        <div class="cs"><div class="v">{{ equipements.length }}</div><div class="k">Objets répartis</div></div>
                    </div>
                </div>
            </div>

            <!-- ================= fenêtre ouverte (EtatCloture) ================= -->
            <div v-else-if="cloture" class="main">
                <!-- parts d'or -->
                <div class="epilogue">
                    <div class="who">
                        <span class="av"><MSym n="paid" fill /></span>
                        <div>
                            <div class="lbl">LE PARTAGE DE L'OR</div>
                            <div class="sub">{{ habillage.sous }}</div>
                        </div>
                    </div>
                    <div class="parts">
                        <div v-for="p in parts" :key="p.personnage_id" class="part">
                            <span class="pic"><MSym :n="p.ic" fill /></span>
                            <span class="pn">{{ p.nom }}</span>
                            <span class="pm"><MSym n="paid" :size="15" /> {{ p.montant }} or</span>
                        </div>
                        <div v-if="!parts.length" class="part vide">— aucun héros actif —</div>
                    </div>
                    <div class="campstats">
                        <div class="cs"><div class="v gold">{{ cloture.or_a_partager ?? 0 }}</div><div class="k">Or à partager</div></div>
                        <div class="cs"><div class="v">{{ parts.length }}</div><div class="k">Parts égales</div></div>
                        <div class="cs"><div class="v">{{ equipements.length }}</div><div class="k">Objets à répartir</div></div>
                    </div>
                </div>

                <!-- partage du butin -->
                <div class="loot">
                    <div class="loot-head">
                        <h2><MSym n="workspace_premium" fill /> Partage du butin</h2>
                        <div class="pool"><MSym n="paid" :size="16" /><span>{{ cloture.or_a_partager ?? 0 }}</span> or · {{ parts.length }} part{{ parts.length > 1 ? 's' : '' }}</div>
                    </div>
                    <div v-if="equipements.length" class="reward-grid">
                        <div v-for="eq in equipements" :key="eq.inventaire_id" class="reward">
                            <div class="rh">
                                <span class="ic" :class="eq.rar"><MSym :n="eq.ic" fill /></span>
                                <div><div class="rn">{{ eq.nom }}</div><div class="rr" :class="'rar-' + eq.rar">{{ eq.rarLabel }}</div></div>
                            </div>
                            <div class="assign">
                                <button
                                    v-for="p in parts"
                                    :key="p.personnage_id"
                                    :class="{ on: eq.personnage_id === p.personnage_id }"
                                    @click="assigner(eq, p.personnage_id)"
                                >
                                    <MSym :n="p.ic" fill /><span class="an">{{ p.court }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div v-else class="loot-vide">
                        <MSym n="backpack" :size="22" /> Aucun équipement à répartir — chacun garde son sac.
                    </div>
                    <div style="font-size: 12px; color: var(--ink-500); display: flex; align-items: center; gap: 7px; margin-top: 2px">
                        <MSym n="how_to_reg" :size="15" /> Réassigner un objet annule les confirmations — tous les joueurs doivent confirmer.
                    </div>
                    <p v-if="erreurCloture" class="cl-err">{{ erreurCloture }}</p>
                </div>
            </div>

            <!-- ================= démo (stub maquette) ================= -->
            <div v-else-if="enDemo" class="main">
                <div class="epilogue">
                    <div class="who">
                        <span class="av"><MSym n="menu_book" fill /></span>
                        <div>
                            <div class="lbl">LE MAÎTRE DE JEU · ÉPILOGUE</div>
                            <div class="sub">Narration finale de la campagne</div>
                        </div>
                    </div>
                    <p><span class="drop">L</span>e Spectre d'Ambre s'effondre en une pluie d'étincelles froides, et pour la première fois depuis des siècles, la crypte se tait. Vous remontez les galeries noyées, trempés et meurtris, mais vivants — porteurs d'une lumière que les ténèbres croyaient avoir éteinte.</p>
                    <p>On chantera votre nom à Pierregivre. Mais d'autres seuils, ailleurs, attendent déjà qu'on les franchisse…</p>
                    <div class="campstats">
                        <div class="cs"><div class="v">8</div><div class="k">Quêtes</div></div>
                        <div class="cs"><div class="v">137</div><div class="k">Ennemis vaincus</div></div>
                        <div class="cs"><div class="v gold">5 940</div><div class="k">Or amassé</div></div>
                    </div>
                </div>

                <div class="loot">
                    <div class="loot-head">
                        <h2><MSym n="workspace_premium" fill /> Partage du butin</h2>
                        <div class="pool"><MSym n="paid" :size="16" /><span>1 200</span> or · 4 parts</div>
                    </div>
                    <div class="reward-grid">
                        <div v-for="(r, idx) in CLOTURE_REWARDS" :key="idx" class="reward">
                            <div class="rh">
                                <span class="ic" :class="r.rar"><MSym :n="r.ic" fill /></span>
                                <div><div class="rn">{{ r.n }}</div><div class="rr" :class="'rar-' + r.rar">{{ RAR_LABEL[r.rar] }}</div></div>
                            </div>
                            <div class="assign">
                                <button
                                    v-for="h in CLOTURE_HEROES"
                                    :key="h.k"
                                    :class="{ on: demoAssignments[idx] === h.k }"
                                    @click="demoAssignments[idx] = h.k"
                                >
                                    <MSym :n="h.ic" fill /><span class="an">{{ h.n }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div style="font-size: 12px; color: var(--ink-500); display: flex; align-items: center; gap: 7px; margin-top: 2px">
                        <MSym n="how_to_vote" :size="15" /> Le groupe assigne chaque objet ; un vote tranche les égalités.
                    </div>
                </div>
            </div>

            <!-- ================= connecté, pas de fenêtre ouverte ================= -->
            <div v-else class="main fin">
                <div class="attente">
                    <MSym n="door_front" :size="38" fill />
                    <p>Aucune clôture de campagne n'est ouverte pour ce groupe.</p>
                </div>
            </div>

            <!-- ================= pied ================= -->
            <div class="foot">
                <template v-if="terminee">
                    <button class="btn btn-gold" @click="retourAccueil">
                        <MSym n="holiday_village" fill /> Retour à l'accueil
                    </button>
                </template>
                <template v-else-if="cloture">
                    <div class="confirm-chips">
                        <span v-for="c in confirmations.liste" :key="c.joueur_id" class="chip" :class="{ ok: c.confirme }">
                            <MSym :n="c.confirme ? 'check_circle' : 'hourglass_top'" :size="14" :fill="c.confirme" />
                            {{ c.pseudo ?? `Joueur n°${c.joueur_id}` }}
                        </span>
                    </div>
                    <button class="btn btn-ghost" :disabled="annulation" @click="annuler">
                        <MSym n="close" /> {{ annulation ? 'Annulation…' : 'Annuler la clôture' }}
                    </button>
                    <button class="btn btn-gold" :disabled="maConfirmation" @click="confirmer">
                        <MSym :n="maConfirmation ? 'hourglass_top' : 'check_circle'" fill />
                        {{ maConfirmation
                            ? `En attente des autres (${confirmations.confirmes}/${confirmations.total || '?'})`
                            : `Confirmer le partage (${confirmations.confirmes}/${confirmations.total || '?'})` }}
                    </button>
                </template>
                <template v-else-if="enDemo">
                    <RouterLink class="btn btn-ghost" to="/"><MSym n="holiday_village" /> Retour à la ville</RouterLink>
                    <RouterLink class="btn btn-gold" :to="{ name: 'direction' }"><MSym n="auto_stories" fill /> Nouvelle campagne</RouterLink>
                </template>
                <RouterLink v-else class="btn btn-ghost" to="/"><MSym n="holiday_village" /> Retour à l'accueil</RouterLink>
            </div>
        </div>
        <DemoBadge />
    </div>
</template>

<style>
/* Port de "Cloture de campagne.html" — préfixé .cloture. */
.cloture { background: #000; color: var(--ink-100); overflow: hidden; --ambiance: 0.7; }
.cloture .stage-c { position: relative; width: 100vw; height: 100vh; display: grid; grid-template-rows: auto 1fr auto; box-sizing: border-box; }
.cloture .stage-c.tex-stone::before { content: ""; position: absolute; inset: 0;
  background: radial-gradient(60% 70% at 50% -10%, oklch(0.80 0.135 88 / 0.18), transparent 60%); pointer-events: none; }
.cloture .home { position: absolute; top: 18px; left: 24px; z-index: 4; color: var(--ink-500); text-decoration: none; font-size: 11px;
  font-weight: 700; letter-spacing: 0.08em; display: inline-flex; align-items: center; gap: 4px; }

/* braises */
.cloture .embers { position: absolute; inset: 0; overflow: hidden; pointer-events: none; z-index: 1; }
.cloture .ember { position: absolute; bottom: -10px; border-radius: 50%; background: var(--torch);
  box-shadow: 0 0 8px var(--torch); opacity: 0; animation-name: cloture-rise; animation-timing-function: linear; animation-iteration-count: infinite; }
@keyframes cloture-rise { 0% { transform: translateY(0) scale(1); opacity: 0; } 10% { opacity: 0.9; } 100% { transform: translateY(-100vh) scale(0.3); opacity: 0; } }

/* en-tête */
.cloture .head { position: relative; z-index: 3; text-align: center; padding: 46px 32px 12px; }
.cloture .crumb { font-size: 12px; letter-spacing: 0.4em; text-transform: uppercase; color: var(--gold); font-weight: 700; }
.cloture .head h1 { font-family: var(--font-display); font-weight: 800; font-size: clamp(34px, 5vw, 58px); margin: 12px 0 0; letter-spacing: 0.04em;
  background: linear-gradient(180deg, var(--parch-100), var(--gold) 150%); -webkit-background-clip: text; background-clip: text; color: transparent;
  text-shadow: 0 4px 40px oklch(0.80 0.135 88 / 0.25); }
.cloture .laurel { display: flex; align-items: center; justify-content: center; gap: 16px; margin-top: 10px; color: var(--gold); }
.cloture .laurel .ln { width: 80px; height: 1px; background: linear-gradient(90deg, transparent, var(--gold)); }
.cloture .laurel .ln.r { background: linear-gradient(90deg, var(--gold), transparent); }

/* ton « cendres » (échec / abandon) : les braises dorées retombent en gris */
.cloture .stage-c.cendres.tex-stone::before {
  background: radial-gradient(60% 70% at 50% -10%, oklch(0.45 0.01 255 / 0.2), transparent 60%); }
.cloture .stage-c.cendres .ember { background: var(--ink-500); box-shadow: 0 0 6px oklch(0.45 0.01 255 / 0.6); }
.cloture .stage-c.cendres .crumb { color: var(--ink-500); }
.cloture .stage-c.cendres .head h1 { background: linear-gradient(180deg, var(--ink-100), var(--ink-500) 150%);
  -webkit-background-clip: text; background-clip: text; text-shadow: 0 4px 40px oklch(0.4 0.01 255 / 0.3); }
.cloture .stage-c.cendres .laurel { color: var(--ink-500); }
.cloture .stage-c.cendres .laurel .ln { background: linear-gradient(90deg, transparent, var(--ink-500)); }
.cloture .stage-c.cendres .laurel .ln.r { background: linear-gradient(90deg, var(--ink-500), transparent); }
.cloture .stage-c.cendres .epilogue { border-left-color: var(--ink-500); }
.cloture .stage-c.cendres .epilogue .who .av { background: linear-gradient(150deg, var(--ink-300), var(--stone-600)); }
.cloture .stage-c.cendres .epilogue .who .lbl { color: var(--ink-300); }

/* grille principale */
.cloture .main { position: relative; z-index: 2; display: grid; grid-template-columns: 1.1fr 1fr; gap: 24px; padding: 18px 40px; min-height: 0; }
@media (max-width: 1100px) { .cloture .main { grid-template-columns: 1fr; overflow-y: auto; } }
.cloture .main.fin { grid-template-columns: 1fr; max-width: 980px; width: 100%; margin: 0 auto; box-sizing: border-box; }

.cloture .epilogue { background: linear-gradient(180deg, oklch(0.22 0.014 255 / 0.9), oklch(0.17 0.012 255 / 0.92)); border: var(--line);
  border-left: 4px solid var(--gold); border-radius: var(--r-lg); padding: 26px 28px; display: flex; flex-direction: column; min-height: 0; overflow-y: auto; }
.cloture .epilogue .who { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.cloture .epilogue .who .av { width: 48px; height: 48px; border-radius: 50%; display: grid; place-items: center;
  background: linear-gradient(150deg, var(--gold), var(--ember-deep)); color: var(--stone-950); }
.cloture .epilogue .who .av .msym { font-size: 26px; }
.cloture .epilogue .who .lbl { font-family: var(--font-display); font-size: 12px; letter-spacing: 0.12em; color: var(--gold); font-weight: 700; }
.cloture .epilogue .who .sub { font-size: 11px; color: var(--ink-500); }
.cloture .epilogue p { font-family: var(--font-narr); font-size: clamp(16px, 1.5vw, 20px); line-height: 1.62; color: var(--ink-100); margin: 0 0 14px; }
.cloture .epilogue p .drop { font-family: var(--font-display); font-size: 2.6em; float: left; line-height: 0.8; margin: 6px 10px 0 0; color: var(--gold); }
.cloture .campstats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: auto; padding-top: 20px; border-top: var(--line); }
.cloture .campstats .cs { text-align: center; }
.cloture .campstats .cs .v { font-family: var(--font-display); font-size: 26px; font-weight: 800; color: var(--parch-100); }
.cloture .campstats .cs .v.gold { color: var(--gold); }
.cloture .campstats .cs .k { font-size: 10.5px; letter-spacing: 0.06em; text-transform: uppercase; color: var(--ink-500); font-weight: 700; margin-top: 3px; }

/* parts d'or (mode connecté) */
.cloture .parts { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
.cloture .part { display: flex; align-items: center; gap: 10px; background: var(--stone-850); border: var(--line);
  border-radius: var(--r-md); padding: 9px 13px; }
.cloture .part.vide { color: var(--ink-700); font-style: italic; justify-content: center; }
.cloture .part .pic { width: 34px; height: 34px; border-radius: 9px; display: grid; place-items: center; flex: none;
  background: linear-gradient(150deg, var(--ember), var(--ember-deep)); color: var(--parch-100); }
.cloture .part .pn { flex: 1; min-width: 0; font-family: var(--font-display); font-size: 15px; color: var(--parch-100);
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cloture .part .pm { display: inline-flex; align-items: center; gap: 5px; font-weight: 800; color: var(--gold);
  font-variant-numeric: tabular-nums; font-size: 14px; }

/* résumés (épilogue multi-héros, écran de table) */
.cloture .resume-bloc { margin-bottom: 14px; }
.cloture .resume-nom { display: flex; align-items: center; gap: 6px; font-family: var(--font-display); font-size: 13px;
  letter-spacing: 0.08em; color: var(--gold); font-weight: 700; margin-bottom: 4px; }
.cloture .stage-c.cendres .resume-nom { color: var(--ink-300); }

/* butin */
.cloture .loot { display: flex; flex-direction: column; gap: 14px; min-height: 0; }
.cloture .loot-head { display: flex; align-items: center; justify-content: space-between; }
.cloture .loot-head h2 { font-family: var(--font-display); font-size: 16px; letter-spacing: 0.08em; text-transform: uppercase;
  color: var(--ink-300); margin: 0; display: flex; align-items: center; gap: 9px; }
.cloture .loot-head .pool { display: flex; align-items: center; gap: 7px; font-size: 14px; font-weight: 800; color: var(--gold);
  background: oklch(0.80 0.135 88 / 0.12); border: var(--line-gold); padding: 6px 13px; border-radius: 99px; }
.cloture .reward-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; overflow-y: auto; padding-right: 4px; }
.cloture .reward { background: linear-gradient(180deg, var(--stone-850), var(--stone-900)); border: var(--line); border-radius: var(--r-md); padding: 13px; }
.cloture .reward .rh { display: flex; align-items: center; gap: 9px; margin-bottom: 10px; }
.cloture .reward .ic { width: 40px; height: 40px; border-radius: 10px; display: grid; place-items: center; flex: none; background: var(--stone-800); color: var(--torch); }
.cloture .reward .ic.unique { color: var(--rar-unique); background: oklch(0.74 0.15 78 / 0.14); }
.cloture .reward .ic.rare { color: var(--rar-rare); background: oklch(0.66 0.15 245 / 0.14); }
.cloture .reward .rn { font-size: 14px; font-weight: 700; color: var(--parch-100); line-height: 1.15; }
.cloture .reward .rr { font-size: 10.5px; font-weight: 700; letter-spacing: 0.04em; }
.cloture .assign { display: flex; gap: 5px; }
.cloture .assign button { flex: 1; background: var(--stone-800); border: var(--line); border-radius: 8px; padding: 7px 4px; cursor: pointer;
  display: flex; flex-direction: column; align-items: center; gap: 3px; transition: all .14s; }
.cloture .assign button .msym { font-size: 18px; color: var(--ink-500); }
.cloture .assign button .an { font-size: 9px; font-weight: 700; color: var(--ink-700); }
.cloture .assign button.on { border-color: var(--torch); background: oklch(0.76 0.155 65 / 0.16); }
.cloture .assign button.on .msym, .cloture .assign button.on .an { color: var(--torch); }
.cloture .loot-vide { display: flex; align-items: center; gap: 9px; justify-content: center; padding: 26px 14px;
  border: var(--line); border-radius: var(--r-md); background: var(--stone-900); color: var(--ink-500);
  font-family: var(--font-narr); font-style: italic; font-size: 15px; }
.cloture .cl-err { font-size: 13px; color: var(--danger, #c33); margin: 4px 0 0; }

/* écran d'attente (connecté, pas de fenêtre) */
.cloture .attente { display: grid; place-items: center; gap: 12px; text-align: center; align-self: center; justify-self: center;
  padding: 40px 50px; border-radius: var(--r-lg); border: var(--line); background: linear-gradient(180deg, var(--stone-850), var(--stone-900));
  color: var(--ink-300); max-width: 460px; }
.cloture .attente .msym { color: var(--torch); }
.cloture .attente p { font-family: var(--font-narr); font-style: italic; font-size: 17px; margin: 0; }

/* pied */
.cloture .foot { position: relative; z-index: 3; display: flex; align-items: center; gap: 16px; justify-content: center; padding: 18px 40px 26px; flex-wrap: wrap; }
.cloture .btn { border: none; border-radius: var(--r-md); padding: 15px 28px; font-family: var(--font-ui); font-weight: 700; font-size: 16px;
  cursor: pointer; display: inline-flex; align-items: center; gap: 10px; transition: transform .1s; text-decoration: none; width: auto; }
.cloture .btn:active { transform: scale(0.98); }
.cloture .btn:disabled { opacity: 0.6; cursor: default; }
.cloture .btn-gold { background: linear-gradient(180deg, var(--gold), var(--ember-deep)); color: var(--stone-950); box-shadow: var(--sh-2); }
.cloture .btn-ghost { background: var(--stone-800); color: var(--ink-100); border: var(--line-strong); }
.cloture .confirm-chips { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.cloture .confirm-chips .chip { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 700;
  color: var(--ink-500); background: var(--stone-850); border: var(--line); border-radius: 99px; padding: 6px 12px; }
.cloture .confirm-chips .chip.ok { color: var(--ok); border-color: var(--ok); }
</style>
