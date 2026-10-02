<script setup>
// PAGE SYSTÈME (René, 2026-10-02) — état des services externes/internes, et
// si le crédit tient encore. Tout le verdict (etat, credit) est DÉCIDÉ CÔTÉ
// SERVEUR (GET /api/systeme, voir docs/contrat-api.md « Système ») : cette
// vue AFFICHE, elle ne recalcule jamais un badge depuis les ingrédients bruts
// (hard rule « le serveur publie la décision »). Aucun repli de données
// factices (No demo mode) : chargement/erreur réels, jamais un faux état.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import MSym from '../components/ui/MSym.vue';
import { useApi } from '../composables/useApi';

const api = useApi();

const chargement = ref(true);
const erreurChargement = ref('');
const donnees = ref(null); // EtatSysteme brut (contrat)
let minuteur = null;

async function charger({ silencieux = false } = {}) {
    if (!silencieux) {
        chargement.value = true;
        erreurChargement.value = '';
    }
    try {
        const r = await api.getSysteme();
        donnees.value = r;
        erreurChargement.value = '';
    } catch (e) {
        erreurChargement.value = e.message;
    } finally {
        chargement.value = false;
    }
}

onMounted(() => {
    charger();
    // Auto-rafraîchissement toutes les 30 s TANT QUE la page est visible
    // (onglet au premier plan) — pas d'intérêt à sonder un onglet caché.
    minuteur = setInterval(() => {
        if (document.visibilityState === 'visible') charger({ silencieux: true });
    }, 30000);
});
onBeforeUnmount(() => {
    if (minuteur) clearInterval(minuteur);
});

const servicesExternes = computed(() => (donnees.value?.services ?? []).filter((s) => s.famille === 'externe'));
const servicesInternes = computed(() => (donnees.value?.services ?? []).filter((s) => s.famille === 'interne'));
const avertissements = computed(() => donnees.value?.avertissements ?? []);
const consommation = computed(() => donnees.value?.consommation ?? null);

// Seuls ces trois services ont un test payant réel câblé côté serveur —
// gemini_tts/gemini_image renvoient 422 exprès (quota protégé, voir contrat).
const SERVICES_TESTABLES = ['anthropic', 'gemini_texte', 'voyage'];
function estTestable(id) {
    return SERVICES_TESTABLES.includes(id);
}

const testEnCours = ref('');
const testResultats = ref({});

async function tester(id) {
    testEnCours.value = id;
    try {
        const r = await api.testerSysteme(id);
        testResultats.value = { ...testResultats.value, [id]: r };
    } catch (e) {
        testResultats.value = {
            ...testResultats.value,
            [id]: { ok: false, erreur: e.donnees?.erreur ?? e.message },
        };
    } finally {
        testEnCours.value = '';
        // Le test vient de modifier le verdict de crédit côté serveur (il est
        // retagué `source: "test"`) : on recharge pour l'afficher à jour.
        charger({ silencieux: true });
    }
}

const LIBELLES_ETAT = {
    ok: 'Opérationnel',
    degrade: 'Dégradé',
    panne: 'En panne',
    non_configure: 'Non configuré',
    inconnu: 'Inconnu',
};
const ICONES_ETAT = {
    ok: 'check_circle',
    degrade: 'warning',
    panne: 'error',
    non_configure: 'power_off',
    inconnu: 'help',
};
const LIBELLES_CREDIT = {
    ok: 'Crédit probablement OK',
    epuise: 'Crédit probablement épuisé',
    quota_atteint: 'Quota atteint',
    inconnu: 'Crédit inconnu',
};
const LIBELLES_SOURCE = {
    dernier_appel: "d'après le dernier appel de jeu",
    test: "d'après un test manuel",
    aucune: 'aucune donnée disponible',
};
const LIBELLES_CATEGORIE_ECHEC = {
    credit_epuise: 'crédit épuisé',
    quota_atteint: 'quota atteint',
    limite_debit: 'limite de débit (transitoire)',
    cle_invalide: 'clé invalide',
    indisponible: 'service indisponible',
    autre: 'autre',
};

function formaterNombre(n) {
    if (n === null || n === undefined) return '—';
    return new Intl.NumberFormat('fr-FR').format(n);
}

function tempsRelatif(iso) {
    if (!iso) return null;
    const diffMs = Date.now() - new Date(iso).getTime();
    const min = Math.floor(diffMs / 60000);
    if (min < 1) return "à l'instant";
    if (min < 60) return `il y a ${min} min`;
    const h = Math.floor(min / 60);
    if (h < 24) return `il y a ${h} h`;
    return `il y a ${Math.floor(h / 24)} j`;
}
</script>

<template>
    <div class="systeme">
        <header class="systeme-tete">
            <RouterLink to="/narrateur" class="systeme-back">
                <MSym n="arrow_back" :size="16" /> Retour
            </RouterLink>
            <div class="systeme-titre-bloc">
                <MSym n="monitor_heart" fill :size="22" />
                <h1 class="systeme-titre">Système</h1>
            </div>
            <button class="systeme-refresh" type="button" title="Rafraîchir" :disabled="chargement" @click="charger()">
                <MSym n="refresh" :size="18" />
            </button>
        </header>

        <p class="systeme-sous">
            État des services utilisés par le jeu, et ce qu'on peut raisonnablement déduire sur le crédit restant.
            <strong>Aucun fournisseur n'expose un solde réel</strong> — chaque verdict de crédit ci-dessous est une
            déduction à partir du dernier appel réel, jamais une lecture de compte. Pour le solde exact, utilisez les
            liens « Console » de chaque service.
        </p>

        <div v-if="chargement && !donnees" class="systeme-charge">
            <MSym n="hourglass_top" :size="20" /> Chargement de l'état du système…
        </div>
        <p v-else-if="erreurChargement && !donnees" class="systeme-err">
            <MSym n="error" fill :size="16" /> {{ erreurChargement }}
        </p>

        <template v-else-if="donnees">
            <p v-if="erreurChargement" class="systeme-err systeme-err-discret">
                <MSym n="error" fill :size="14" /> Dernier rafraîchissement en échec : {{ erreurChargement }} (affichage de l'état précédent)
            </p>

            <div v-if="avertissements.length" class="systeme-avertissements">
                <h2><MSym n="campaign" fill :size="16" /> À surveiller</h2>
                <ul>
                    <li v-for="(a, i) in avertissements" :key="i">{{ a }}</li>
                </ul>
            </div>

            <section class="systeme-groupe">
                <h2 class="systeme-groupe-titre"><MSym n="cloud" :size="16" /> Services externes</h2>
                <div class="systeme-grille">
                    <article v-for="s in servicesExternes" :key="s.id" class="systeme-carte" :class="`etat-${s.etat}`">
                        <div class="systeme-carte-tete">
                            <span class="systeme-badge" :class="`etat-${s.etat}`">
                                <MSym :n="ICONES_ETAT[s.etat] ?? 'help'" fill :size="14" />
                                {{ LIBELLES_ETAT[s.etat] ?? s.etat }}
                            </span>
                            <h3>{{ s.libelle }}</h3>
                        </div>
                        <p class="systeme-detail">{{ s.detail }}</p>

                        <div class="systeme-lignes">
                            <p v-if="s.latence_ms !== null" class="systeme-ligne">
                                <MSym n="speed" :size="14" /> Latence sonde : {{ s.latence_ms }} ms
                            </p>
                            <p v-if="s.derniere_reussite" class="systeme-ligne">
                                <MSym n="check" :size="14" /> Dernier appel réussi {{ tempsRelatif(s.derniere_reussite) }}
                            </p>
                            <p v-if="s.dernier_echec" class="systeme-ligne systeme-ligne-echec">
                                <MSym n="close" :size="14" /> Dernier échec {{ tempsRelatif(s.dernier_echec.a) }}
                                ({{ LIBELLES_CATEGORIE_ECHEC[s.dernier_echec.categorie] ?? s.dernier_echec.categorie }}) : {{ s.dernier_echec.message }}
                            </p>
                        </div>

                        <div class="systeme-credit" :class="`credit-${s.credit.etat}`">
                            <div class="systeme-credit-tete">
                                <MSym n="toll" :size="14" />
                                <b>{{ LIBELLES_CREDIT[s.credit.etat] ?? s.credit.etat }}</b>
                                <span class="systeme-credit-source">({{ LIBELLES_SOURCE[s.credit.source] ?? s.credit.source }})</span>
                            </div>
                            <p>{{ s.credit.explication }}</p>
                        </div>

                        <div class="systeme-actions">
                            <a v-if="s.console_url" :href="s.console_url" target="_blank" rel="noopener" class="systeme-lien">
                                <MSym n="open_in_new" :size="14" /> Console
                            </a>
                            <button
                                v-if="estTestable(s.id)"
                                type="button"
                                class="systeme-test-btn"
                                :disabled="testEnCours !== '' || s.etat === 'non_configure'"
                                @click="tester(s.id)"
                            >
                                <MSym n="bolt" :size="14" />
                                {{ testEnCours === s.id ? 'Test en cours…' : 'Tester (appel réel, quelques jetons)' }}
                            </button>
                        </div>
                        <p v-if="testResultats[s.id]" :class="testResultats[s.id].ok ? 'systeme-ok' : 'systeme-err'">
                            <MSym :n="testResultats[s.id].ok ? 'check_circle' : 'error'" fill :size="14" />
                            <span v-if="testResultats[s.id].ok">
                                Réponse reçue en {{ (testResultats[s.id].duree_ms / 1000).toFixed(1) }} s — « {{ testResultats[s.id].extrait }} »
                            </span>
                            <span v-else>{{ testResultats[s.id].erreur }}</span>
                        </p>
                    </article>
                </div>
            </section>

            <section class="systeme-groupe">
                <h2 class="systeme-groupe-titre"><MSym n="dns" :size="16" /> Services internes</h2>
                <div class="systeme-grille">
                    <article v-for="s in servicesInternes" :key="s.id" class="systeme-carte" :class="`etat-${s.etat}`">
                        <div class="systeme-carte-tete">
                            <span class="systeme-badge" :class="`etat-${s.etat}`">
                                <MSym :n="ICONES_ETAT[s.etat] ?? 'help'" fill :size="14" />
                                {{ LIBELLES_ETAT[s.etat] ?? s.etat }}
                            </span>
                            <h3>{{ s.libelle }}</h3>
                        </div>
                        <p class="systeme-detail">{{ s.detail }}</p>
                        <div class="systeme-lignes">
                            <p v-if="s.latence_ms !== null" class="systeme-ligne">
                                <MSym n="speed" :size="14" /> Latence : {{ s.latence_ms }} ms
                            </p>
                            <p v-if="s.derniere_reussite" class="systeme-ligne">
                                <MSym n="check" :size="14" /> Vu {{ tempsRelatif(s.derniere_reussite) }}
                            </p>
                        </div>
                    </article>
                </div>
            </section>

            <section v-if="consommation" class="systeme-conso">
                <h2 class="systeme-groupe-titre"><MSym n="query_stats" :size="16" /> Consommation IA (tokens)</h2>
                <p class="systeme-aide">
                    <MSym n="info" :size="14" />
                    <span>Tokens uniquement — aucun fournisseur ne nous donne de grille tarifaire sourcée, donc aucun prix en euros/dollars n'est inventé ici.</span>
                </p>
                <div class="systeme-conso-grille">
                    <div class="systeme-conso-tuile">
                        <span class="systeme-conso-valeur">{{ formaterNombre(consommation.tokens_entree) }}</span>
                        <span class="systeme-conso-label">Tokens entrée</span>
                    </div>
                    <div class="systeme-conso-tuile">
                        <span class="systeme-conso-valeur">{{ formaterNombre(consommation.tokens_sortie) }}</span>
                        <span class="systeme-conso-label">Tokens sortie</span>
                    </div>
                    <div class="systeme-conso-tuile">
                        <span class="systeme-conso-valeur">{{ formaterNombre(consommation.appels) }}</span>
                        <span class="systeme-conso-label">Appels IA</span>
                    </div>
                    <div class="systeme-conso-tuile">
                        <span class="systeme-conso-valeur">{{ formaterNombre(consommation.appels_retries) }}</span>
                        <span class="systeme-conso-label">Dont retries/repli</span>
                    </div>
                </div>
                <p class="systeme-aide">
                    <MSym n="info" :size="14" />
                    <span>
                        Cumul depuis {{ consommation.depuis ? tempsRelatif(consommation.depuis) : "la mise en service de cette mesure" }}
                        — survit à la clôture d'une campagne (table de télémétrie, pas de l'état de jeu).
                    </span>
                </p>
            </section>

            <p class="systeme-genere-a">Généré {{ tempsRelatif(donnees.genere_a) }} — rafraîchi automatiquement toutes les 30 s.</p>
        </template>
    </div>
</template>

<style scoped>
.systeme {
    min-height: 100vh; background: var(--stone-950); color: var(--ink-100);
    padding: 20px 16px 48px; display: flex; flex-direction: column; gap: 16px;
    max-width: 1100px; margin: 0 auto; box-sizing: border-box;
}

.systeme-tete { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.systeme-back { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 700;
  color: var(--ink-500); text-decoration: none; letter-spacing: 0.02em; }
.systeme-back:hover { color: var(--torch); }
.systeme-titre-bloc { display: flex; align-items: center; gap: 8px; color: var(--torch); }
.systeme-titre { font-family: var(--font-display); font-size: 22px; font-weight: 800; color: var(--parch-100); margin: 0; }
.systeme-refresh {
    width: 36px; height: 36px; border-radius: 999px; display: grid; place-items: center;
    border: var(--line); background: var(--stone-850); color: var(--ink-300); cursor: pointer;
    transition: color .15s, border-color .15s;
}
.systeme-refresh:hover:not(:disabled) { color: var(--parch-100); border-color: var(--torch); }
.systeme-refresh:disabled { opacity: 0.5; cursor: not-allowed; }

.systeme-sous { font-size: 13.5px; color: var(--ink-500); line-height: 1.55; margin: 0; max-width: 72ch; }
.systeme-sous strong { color: var(--ink-300); }

.systeme-charge { display: flex; align-items: center; gap: 10px; color: var(--ink-500); font-size: 14px; padding: 20px 0; }
.systeme-charge .msym { color: var(--torch); }

.systeme-err { display: flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 600; color: var(--danger); margin: 0; }
.systeme-err-discret { font-weight: 500; opacity: 0.85; }
.systeme-ok { display: flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 600; color: var(--ok); margin: 6px 0 0; }

.systeme-avertissements {
    display: flex; flex-direction: column; gap: 6px; padding: 12px 16px; border-radius: var(--r-md);
    background: oklch(0.78 0.150 75 / 0.12); border: 1px solid oklch(0.78 0.150 75 / 0.45);
}
.systeme-avertissements h2 { display: flex; align-items: center; gap: 7px; margin: 0; font-size: 13px;
  font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--warn); }
.systeme-avertissements ul { margin: 0; padding-left: 20px; display: flex; flex-direction: column; gap: 4px; }
.systeme-avertissements li { font-size: 13px; color: var(--ink-200); line-height: 1.45; }

.systeme-groupe-titre { display: flex; align-items: center; gap: 7px; margin: 0 0 10px; font-family: var(--font-ui);
  font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: var(--ink-300); }
.systeme-groupe-titre .msym { color: var(--torch); }

.systeme-grille { display: grid; grid-template-columns: repeat(auto-fit, minmax(270px, 1fr)); gap: 14px; }

.systeme-carte {
    display: flex; flex-direction: column; gap: 10px; padding: 16px; border-radius: var(--r-lg);
    background: linear-gradient(180deg, var(--stone-850), var(--stone-900)); border: var(--line);
    min-width: 0;
}
.systeme-carte.etat-panne { border-color: oklch(0.60 0.200 25 / 0.5); }
.systeme-carte.etat-degrade { border-color: oklch(0.78 0.150 75 / 0.5); }

.systeme-carte-tete { display: flex; flex-direction: column; gap: 6px; align-items: flex-start; }
.systeme-carte-tete h3 { margin: 0; font-family: var(--font-display); font-size: 16px; font-weight: 800; color: var(--parch-100); }

.systeme-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 999px;
  font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; }
.systeme-badge.etat-ok { background: oklch(0.70 0.140 150 / 0.15); color: var(--ok); }
.systeme-badge.etat-degrade { background: oklch(0.78 0.150 75 / 0.18); color: var(--warn); }
.systeme-badge.etat-panne { background: oklch(0.60 0.200 25 / 0.18); color: var(--danger); }
.systeme-badge.etat-non_configure { background: var(--stone-800); color: var(--ink-500); }
.systeme-badge.etat-inconnu { background: var(--stone-800); color: var(--ink-500); }

.systeme-detail { font-size: 13px; color: var(--ink-300); line-height: 1.5; margin: 0; }

.systeme-lignes { display: flex; flex-direction: column; gap: 4px; }
.systeme-ligne { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--ink-500); margin: 0; }
.systeme-ligne-echec { color: var(--danger); align-items: flex-start; }
.systeme-ligne-echec .msym { margin-top: 1px; flex: none; }

.systeme-credit { display: flex; flex-direction: column; gap: 3px; padding: 9px 11px; border-radius: var(--r-md);
  border: 1px solid var(--stone-700); background: var(--stone-800); }
.systeme-credit-tete { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--ink-200); flex-wrap: wrap; }
.systeme-credit-source { font-weight: 500; color: var(--ink-600); font-size: 11.5px; }
.systeme-credit p { margin: 0; font-size: 11.5px; color: var(--ink-500); line-height: 1.45; }
.systeme-credit.credit-epuise { border-color: oklch(0.60 0.200 25 / 0.45); }
.systeme-credit.credit-epuise .systeme-credit-tete b { color: var(--danger); }
.systeme-credit.credit-quota_atteint .systeme-credit-tete b { color: var(--warn); }
.systeme-credit.credit-ok .systeme-credit-tete b { color: var(--ok); }

.systeme-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.systeme-lien { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 700;
  color: var(--ink-500); text-decoration: none; }
.systeme-lien:hover { color: var(--torch); }
.systeme-test-btn { display: inline-flex; align-items: center; gap: 7px; padding: 7px 12px;
  border: var(--line); border-radius: var(--r-md); background: var(--stone-800); color: var(--ink-200);
  font-family: var(--font-ui); font-weight: 700; font-size: 12.5px; cursor: pointer;
  transition: color .15s, border-color .15s; }
.systeme-test-btn:hover:not(:disabled) { color: var(--parch-100); border-color: var(--torch); }
.systeme-test-btn:disabled { opacity: 0.45; cursor: not-allowed; }

.systeme-conso { display: flex; flex-direction: column; gap: 10px; padding: 16px; border-radius: var(--r-lg);
  background: linear-gradient(180deg, var(--stone-850), var(--stone-900)); border: var(--line); }
.systeme-conso-grille { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; }
.systeme-conso-tuile { display: flex; flex-direction: column; align-items: center; gap: 3px; padding: 10px 8px;
  border-radius: var(--r-md); border: var(--line); background: var(--stone-800); }
.systeme-conso-valeur { font-family: var(--font-display); font-size: 19px; font-weight: 800; color: var(--parch-100); }
.systeme-conso-label { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--ink-500); text-align: center; }
.systeme-aide { display: flex; align-items: flex-start; gap: 7px; font-size: 12px; color: var(--ink-500); line-height: 1.5; margin: 0; }
.systeme-aide .msym { flex: none; margin-top: 1px; }

.systeme-genere-a { font-size: 11.5px; color: var(--ink-700); text-align: center; margin: 4px 0 0; }

@media (max-width: 480px) {
    .systeme { padding: 16px 16px 40px; }
    .systeme-grille { grid-template-columns: 1fr; }
}
</style>
