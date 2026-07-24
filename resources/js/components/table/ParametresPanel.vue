<script setup>
// PANNEAU DE RÉGLAGES — overlay plein écran monté depuis DEUX pages
// (TableView.vue ET NarreurView.vue, avant même l'ouverture d'une table) :
// visuellement AUTONOME (style scoped, aucune dépendance à .table-screen ou
// .narreur — voir l'avertissement CLAUDE.md sur les collisions de classes
// globales non scopées).
//
// Deux mécaniques de persistance bien distinctes, à ne pas mélanger :
//  - IA / illustrations / voix du narrateur / équilibrage : réglages SERVEUR,
//    globaux, persistés en base (GET/PUT /api/parametres, route publique —
//    voir docs/contrat-api.md). Un seul formulaire local peuplé au montage,
//    bouton « Enregistrer » explicite → PUT (chargement/erreur/succès).
//  - Audio (voix + musique) : préférence de CET APPAREIL (ses enceintes),
//    persistée en localStorage par les composables useVoix/useAmbiance.
//    PAS de formulaire ni de bouton Enregistrer : chaque curseur/bouton
//    applique le changement immédiatement (mêmes composables que le reste de
//    la table). Toujours affichée, même si le chargement serveur échoue —
//    aucune dépendance à l'API.
import { computed, onMounted, reactive, ref } from 'vue';
import MSym from '../ui/MSym.vue';
import { useApi } from '../../composables/useApi';
import { useVoix } from '../../composables/useVoix';
import { useAmbiance } from '../../composables/useAmbiance';

defineEmits(['fermer']);

const api = useApi();
const voix = useVoix();
const ambiance = useAmbiance();

/* ---- chargement + formulaire serveur ---- */
const chargement = ref(true);
const erreurChargement = ref('');
const enregistrement = ref(false);
const erreurEnregistrement = ref('');
const enregistre = ref(false); // confirmation « Enregistré » (brève)

const parametres = ref(null); // dernière réponse GET/PUT brute (defauts, options, statut…)

const form = reactive({
    llm_provider: 'anthropic',
    modele_anthropic: '',
    modele_gemini: '',
    rag_actif: true,
    voix_dynamique_active: true,
    images_actif: true,
    narration_voix_mode: '', // '' = suit le défaut, une des voix connues, ou '__perso__'
    narration_voix_perso: '',
    rencontres_forts_par_quete: '',
    rencontres_forts_escalade_arc: '',
    rencontres_seuil_cout_fort: '',
    rencontres_taille_reference: '',
    rencontres_boss_pv_adaptatif: false,
});

function peuplerFormulaire(p) {
    form.llm_provider = p.llm_provider ?? 'anthropic';
    form.modele_anthropic = p.modele_anthropic ?? '';
    form.modele_gemini = p.modele_gemini ?? '';
    form.rag_actif = !!p.rag_actif;
    form.voix_dynamique_active = !!p.voix_dynamique_active;
    form.images_actif = !!p.images_actif;
    if (!p.narration_voix) {
        form.narration_voix_mode = '';
        form.narration_voix_perso = '';
    } else if ((p.narration_voix_options ?? []).includes(p.narration_voix)) {
        form.narration_voix_mode = p.narration_voix;
        form.narration_voix_perso = '';
    } else {
        form.narration_voix_mode = '__perso__';
        form.narration_voix_perso = p.narration_voix;
    }
    const r = p.rencontres ?? {};
    form.rencontres_forts_par_quete = r.forts_par_quete ?? '';
    form.rencontres_forts_escalade_arc = r.forts_escalade_arc ?? '';
    form.rencontres_seuil_cout_fort = r.seuil_cout_fort ?? '';
    form.rencontres_taille_reference = r.taille_reference ?? '';
    form.rencontres_boss_pv_adaptatif = !!r.boss_pv_adaptatif;
}

onMounted(async () => {
    try {
        const p = await api.getParametres();
        parametres.value = p;
        peuplerFormulaire(p);
    } catch (e) {
        erreurChargement.value = e.message;
    } finally {
        chargement.value = false;
    }
});

const disponibles = computed(() => parametres.value?.fournisseurs_disponibles ?? ['anthropic']);

/* ---- test de connectivité des fournisseurs : mini-appel LLM RÉEL, avec le
   modèle du formulaire (même non enregistré — on valide AVANT d'enregistrer).
   Le résultat s'affiche en ligne ; StatutIA (bandeau) n'est pas touché. ---- */
const testEnCours = ref(''); // fournisseur en cours de test, '' sinon
const testResultat = ref(null); // {ok, fournisseur, modele, duree_ms, extrait|erreur}

async function testerFournisseur(fournisseur) {
    testEnCours.value = fournisseur;
    testResultat.value = null;
    try {
        testResultat.value = await api.testerParametres({
            fournisseur,
            modele: fournisseur === 'gemini' ? form.modele_gemini : form.modele_anthropic,
        });
    } catch (e) {
        testResultat.value = { ok: false, fournisseur, erreur: e.message };
    } finally {
        testEnCours.value = '';
    }
}

/* ---- écoute des voix du narrateur : Gemini (synthèse serveur d'une phrase
   d'exemple, cache par voix — réécouter est gratuit) et navigateur (Web
   Speech local, avec le débit/volume réglés — aucun serveur). ---- */
const voixTestEnCours = ref(false);
const voixTestErreur = ref('');
let voixTestAudio = null;

function voixChoisieFormulaire() {
    if (form.narration_voix_mode === '__perso__') return form.narration_voix_perso.trim() || null;
    return form.narration_voix_mode || null;
}

async function ecouterVoixGemini() {
    voixTestEnCours.value = true;
    voixTestErreur.value = '';
    try {
        const r = await api.testerVoixNarrateur(voixChoisieFormulaire());
        if (!r.ok) {
            voixTestErreur.value = `${r.voix} : ${r.erreur}`;
            return;
        }
        try { voixTestAudio?.pause(); } catch { /* noop */ }
        voixTestAudio = new Audio(r.url);
        voixTestAudio.volume = voix.volume.value;
        await voixTestAudio.play();
    } catch (e) {
        voixTestErreur.value = e.message;
    } finally {
        voixTestEnCours.value = false;
    }
}
const statutIA = computed(() => parametres.value?.statut_ia ?? { etat: 'inconnu' });
const voixOptions = computed(() => parametres.value?.narration_voix_options ?? []);

/* ---- « il y a Xmin » pour le statut IA — pas de lib de dates dans ce
   projet, et le panneau n'est pas rafraîchi en continu (chargé au montage),
   donc on affiche l'ancienneté pour ne pas laisser croire qu'un incident
   vieux d'une heure est encore en cours. ---- */
function tempsRelatif(iso) {
    if (!iso) return '';
    const diffMs = Date.now() - new Date(iso).getTime();
    const min = Math.floor(diffMs / 60000);
    if (min < 1) return "à l'instant";
    if (min < 60) return `il y a ${min} min`;
    const h = Math.floor(min / 60);
    if (h < 24) return `il y a ${h} h`;
    return `il y a ${Math.floor(h / 24)} j`;
}

function versEntier(v) {
    if (v === '' || v === null || v === undefined) return null;
    const n = Number.parseInt(v, 10);
    return Number.isNaN(n) ? null : n;
}

/* ---- enregistrement : IA + illustrations + voix du narrateur + équilibrage
   partagent UN SEUL PUT (la table `parametres` est une ligne unique côté
   serveur) — l'audio ci-dessous n'y participe jamais. ---- */
async function enregistrer() {
    enregistrement.value = true;
    erreurEnregistrement.value = '';
    enregistre.value = false;
    const narrationVoix = form.narration_voix_mode === ''
        ? null
        : (form.narration_voix_mode === '__perso__'
            ? (form.narration_voix_perso.trim() || null)
            : form.narration_voix_mode);
    try {
        const p = await api.majParametres({
            llm_provider: form.llm_provider,
            modele_anthropic: form.modele_anthropic,
            modele_gemini: form.modele_gemini,
            rag_actif: form.rag_actif,
            voix_dynamique_active: form.voix_dynamique_active,
            images_actif: form.images_actif,
            narration_voix: narrationVoix,
            rencontres_forts_par_quete: versEntier(form.rencontres_forts_par_quete),
            rencontres_forts_escalade_arc: versEntier(form.rencontres_forts_escalade_arc),
            rencontres_seuil_cout_fort: versEntier(form.rencontres_seuil_cout_fort),
            rencontres_taille_reference: versEntier(form.rencontres_taille_reference),
            rencontres_boss_pv_adaptatif: form.rencontres_boss_pv_adaptatif,
        });
        parametres.value = p;
        peuplerFormulaire(p);
        enregistre.value = true;
        setTimeout(() => { enregistre.value = false; }, 2500);
    } catch (e) {
        erreurEnregistrement.value = e.message;
    } finally {
        enregistrement.value = false;
    }
}
</script>

<template>
    <div class="parametres-ov" @click.self="$emit('fermer')">
        <div class="parametres-carte">
            <button class="parametres-fermer" type="button" title="Fermer" @click="$emit('fermer')">
                <MSym n="close" />
            </button>

            <div class="parametres-tete">
                <div class="parametres-orn"><MSym n="settings" fill /></div>
                <h2 class="parametres-titre">Réglages</h2>
                <p class="parametres-sous">MJ IA, illustrations, voix du narrateur, équilibrage des rencontres — et l'audio de cet appareil.</p>
            </div>

            <div v-if="chargement" class="parametres-charge">
                <MSym n="hourglass_top" :size="20" /> Chargement des réglages…
            </div>
            <p v-else-if="erreurChargement" class="parametres-err">
                <MSym n="error" fill :size="16" /> {{ erreurChargement }}
            </p>

            <template v-else>
                <!-- bandeau de statut IA : l'info la plus urgente si elle est dégradée -->
                <div v-if="statutIA.etat === 'nominal'" class="parametres-statut nominal">
                    <MSym n="check_circle" fill :size="16" />
                    <span>Fournisseur actif : <b>{{ statutIA.fournisseur }}</b> — dernier appel {{ tempsRelatif(statutIA.a) }}</span>
                </div>
                <div v-else-if="statutIA.etat === 'repli'" class="parametres-statut repli">
                    <MSym n="warning" fill :size="18" />
                    <span>Bascule automatique : <b>{{ statutIA.depuis }}</b> a échoué, <b>{{ statutIA.fournisseur }}</b> est utilisé à la place ({{ tempsRelatif(statutIA.a) }}).</span>
                </div>
                <div v-else-if="statutIA.etat === 'indisponible'" class="parametres-statut indisponible">
                    <MSym n="error" fill :size="18" />
                    <span>Aucun modèle IA disponible — le MJ narre en mode dégradé (texte/menus génériques), le jeu reste jouable. Vérifiez les clés API. Dernier essai {{ tempsRelatif(statutIA.a) }}.</span>
                </div>

                <form class="parametres-form" @submit.prevent="enregistrer">
                    <!-- IA -->
                    <section class="parametres-section">
                        <h3><MSym n="smart_toy" :size="16" /> Intelligence artificielle</h3>

                        <label class="parametres-champ">
                            <span>Fournisseur</span>
                            <select v-model="form.llm_provider">
                                <option value="anthropic" :disabled="!disponibles.includes('anthropic')">Anthropic (Claude)</option>
                                <option value="gemini" :disabled="!disponibles.includes('gemini')">Gemini</option>
                            </select>
                        </label>

                        <label class="parametres-champ">
                            <span>Modèle Anthropic</span>
                            <input
                                v-model.trim="form.modele_anthropic"
                                type="text"
                                :placeholder="parametres?.modele_anthropic_defaut || 'défaut .env'"
                                spellcheck="false"
                                autocomplete="off"
                            />
                        </label>

                        <label class="parametres-champ">
                            <span>Modèle Gemini</span>
                            <input
                                v-model.trim="form.modele_gemini"
                                type="text"
                                :placeholder="parametres?.modele_gemini_defaut || 'défaut .env'"
                                spellcheck="false"
                                autocomplete="off"
                            />
                        </label>

                        <div class="parametres-tests">
                            <button
                                type="button"
                                class="parametres-test-btn"
                                :disabled="!disponibles.includes('anthropic') || testEnCours !== ''"
                                @click="testerFournisseur('anthropic')"
                            >
                                <MSym n="network_check" :size="15" />
                                {{ testEnCours === 'anthropic' ? 'Test en cours…' : 'Tester Anthropic' }}
                            </button>
                            <button
                                type="button"
                                class="parametres-test-btn"
                                :disabled="!disponibles.includes('gemini') || testEnCours !== ''"
                                @click="testerFournisseur('gemini')"
                            >
                                <MSym n="network_check" :size="15" />
                                {{ testEnCours === 'gemini' ? 'Test en cours…' : 'Tester Gemini' }}
                            </button>
                        </div>
                        <p v-if="testResultat" :class="testResultat.ok ? 'parametres-ok' : 'parametres-err'">
                            <MSym :n="testResultat.ok ? 'check_circle' : 'error'" fill :size="15" />
                            <span v-if="testResultat.ok">
                                {{ testResultat.fournisseur }} répond — {{ testResultat.modele }},
                                {{ (testResultat.duree_ms / 1000).toFixed(1) }} s
                            </span>
                            <span v-else>{{ testResultat.fournisseur }} : {{ testResultat.erreur }}</span>
                        </p>

                        <label class="parametres-case">
                            <input v-model="form.rag_actif" type="checkbox" />
                            <span>Bible sémantique (RAG) — recherche de contexte dans la campagne</span>
                        </label>

                        <label class="parametres-case">
                            <input v-model="form.voix_dynamique_active" type="checkbox" />
                            <span>Synthèse vocale IA en cours de partie (narration + barks de boss)</span>
                        </label>

                        <p class="parametres-lecture-seule">
                            <MSym n="info" :size="14" />
                            <span>Bible sémantique : {{ parametres?.bible_semantique === 'voyage' ? 'Voyage AI (sémantique)' : 'repli lexical' }} — non modifiable ici.</span>
                        </p>
                    </section>

                    <!-- Illustrations -->
                    <section class="parametres-section">
                        <h3><MSym n="image" :size="16" /> Illustrations</h3>
                        <label class="parametres-case">
                            <input v-model="form.images_actif" type="checkbox" />
                            <span>Génération d'illustrations IA en cours de partie</span>
                        </label>
                        <p class="parametres-aide">
                            <MSym n="info" :size="14" />
                            <span>Désactivée si aucune clé Gemini n'est configurée — sans effet dans ce cas.</span>
                        </p>
                    </section>

                    <!-- Voix du narrateur -->
                    <section class="parametres-section">
                        <h3><MSym n="record_voice_over" :size="16" /> Voix du narrateur</h3>
                        <label class="parametres-champ">
                            <span>Voix Gemini</span>
                            <select v-model="form.narration_voix_mode">
                                <option value="">Défaut ({{ parametres?.narration_voix_defaut || 'Iapetus' }})</option>
                                <option v-for="v in voixOptions" :key="v" :value="v">{{ v }}</option>
                                <option value="__perso__">Personnalisée…</option>
                            </select>
                        </label>
                        <label v-if="form.narration_voix_mode === '__perso__'" class="parametres-champ">
                            <span>Nom de la voix</span>
                            <input
                                v-model.trim="form.narration_voix_perso"
                                type="text"
                                placeholder="ex. Zephyr"
                                spellcheck="false"
                                autocomplete="off"
                            />
                        </label>
                        <div class="parametres-tests">
                            <button
                                type="button"
                                class="parametres-test-btn"
                                :disabled="!disponibles.includes('gemini') || voixTestEnCours"
                                @click="ecouterVoixGemini"
                            >
                                <MSym n="graphic_eq" :size="15" />
                                {{ voixTestEnCours ? 'Synthèse…' : 'Écouter (Gemini)' }}
                            </button>
                            <button
                                type="button"
                                class="parametres-test-btn"
                                :disabled="!voix.supporte"
                                @click="voix.testerVoix()"
                            >
                                <MSym n="record_voice_over" :size="15" />
                                Écouter (navigateur)
                            </button>
                        </div>
                        <p v-if="voixTestErreur" class="parametres-err">
                            <MSym n="error" fill :size="15" /> {{ voixTestErreur }}
                        </p>
                        <p class="parametres-aide">
                            <MSym n="info" :size="14" />
                            <span>Ne s'applique immédiatement qu'à la narration générée en direct — les répliques pré-enregistrées gardent l'ancienne voix jusqu'à régénération manuelle.
                            L'écoute Gemini synthétise la voix choisie (mise en cache : réécouter ne consomme pas le quota) ; l'écoute navigateur joue la voix locale avec le débit réglé plus bas.</span>
                        </p>
                    </section>

                    <!-- Équilibrage des rencontres -->
                    <section class="parametres-section">
                        <h3><MSym n="balance" :size="16" /> Équilibrage des rencontres</h3>
                        <div class="parametres-grille">
                            <label class="parametres-champ">
                                <span>Monstres forts par quête</span>
                                <input
                                    v-model="form.rencontres_forts_par_quete"
                                    type="number"
                                    min="0"
                                    :placeholder="String(parametres?.rencontres_defaut?.forts_par_quete ?? '')"
                                />
                            </label>
                            <label class="parametres-champ">
                                <span>Escalade de l'arc (forts)</span>
                                <input
                                    v-model="form.rencontres_forts_escalade_arc"
                                    type="number"
                                    min="0"
                                    :placeholder="String(parametres?.rencontres_defaut?.forts_escalade_arc ?? '')"
                                />
                            </label>
                            <label class="parametres-champ">
                                <span>Seuil de coût « fort »</span>
                                <input
                                    v-model="form.rencontres_seuil_cout_fort"
                                    type="number"
                                    min="0"
                                    :placeholder="String(parametres?.rencontres_defaut?.seuil_cout_fort ?? '')"
                                />
                            </label>
                            <label class="parametres-champ">
                                <span>Taille de groupe de référence</span>
                                <input
                                    v-model="form.rencontres_taille_reference"
                                    type="number"
                                    min="0"
                                    :placeholder="String(parametres?.rencontres_defaut?.taille_reference ?? '')"
                                />
                            </label>
                        </div>
                        <label class="parametres-case">
                            <input v-model="form.rencontres_boss_pv_adaptatif" type="checkbox" />
                            <span>PV de boss adaptatifs à la taille du groupe</span>
                        </label>
                        <p class="parametres-aide">
                            <MSym n="info" :size="14" />
                            <span>S'applique à la prochaine quête lancée, pas à la quête en cours.</span>
                        </p>
                    </section>

                    <p v-if="erreurEnregistrement" class="parametres-err">
                        <MSym n="error" fill :size="16" /> {{ erreurEnregistrement }}
                    </p>

                    <div class="parametres-actions">
                        <span v-if="enregistre" class="parametres-ok"><MSym n="check_circle" fill :size="16" /> Enregistré</span>
                        <button class="parametres-save" type="submit" :disabled="enregistrement">
                            <MSym n="save" :size="16" /> {{ enregistrement ? 'Enregistrement…' : 'Enregistrer' }}
                        </button>
                    </div>
                </form>
            </template>

            <!-- Audio (cet appareil) : préférence locale, application immédiate,
                 toujours disponible même si le chargement serveur ci-dessus échoue. -->
            <section class="parametres-section parametres-audio">
                <h3><MSym n="volume_up" :size="16" /> Audio (cet appareil)</h3>

                <div class="parametres-audio-ligne">
                    <button
                        type="button"
                        class="parametres-mute"
                        :class="{ actif: voix.muet.value }"
                        :title="voix.muet.value ? 'Rétablir la voix' : 'Couper la voix'"
                        @click="voix.basculerMuet()"
                    >
                        <MSym :n="voix.muet.value ? 'mic_off' : 'mic'" :size="18" />
                    </button>
                    <label class="parametres-slider">
                        <span>Volume voix (narration + barks)</span>
                        <input
                            type="range"
                            min="0"
                            max="1"
                            step="0.01"
                            :value="voix.volume.value"
                            @input="voix.definirVolume($event.target.valueAsNumber)"
                        />
                    </label>
                </div>

                <div class="parametres-audio-ligne">
                    <span class="parametres-mute-spacer" />
                    <label class="parametres-slider">
                        <span>Débit de la voix</span>
                        <input
                            type="range"
                            min="0.5"
                            max="2"
                            step="0.05"
                            :value="voix.debit.value"
                            @input="voix.definirDebit($event.target.valueAsNumber)"
                        />
                    </label>
                </div>

                <div class="parametres-audio-ligne">
                    <button
                        type="button"
                        class="parametres-mute"
                        :class="{ actif: ambiance.muet.value }"
                        :title="ambiance.muet.value ? 'Rétablir la musique' : 'Couper la musique'"
                        @click="ambiance.basculerMuet()"
                    >
                        <MSym :n="ambiance.muet.value ? 'music_off' : 'music_note'" :size="18" />
                    </button>
                    <label class="parametres-slider">
                        <span>Volume musique d'ambiance</span>
                        <input
                            type="range"
                            min="0"
                            max="1"
                            step="0.01"
                            :value="ambiance.volume.value"
                            @input="ambiance.definirVolume($event.target.valueAsNumber)"
                        />
                    </label>
                </div>

                <div class="parametres-audio-ligne">
                    <button
                        type="button"
                        class="parametres-mute"
                        title="Écouter cette voix"
                        :disabled="!voix.supporte"
                        @click="voix.testerVoix()"
                    >
                        <MSym n="play_arrow" :size="18" />
                    </button>
                    <label class="parametres-slider">
                        <span>Voix du navigateur (selon cet appareil)</span>
                        <select
                            :value="voix.voixChoisie.value"
                            :disabled="!voix.supporte"
                            @change="voix.choisirVoixNavigateur($event.target.value)"
                        >
                            <option value="">Automatique (première voix française)</option>
                            <option v-for="v in voix.voixDisponibles.value" :key="v.voiceURI" :value="v.voiceURI">
                                {{ v.name }} — {{ v.lang }}
                            </option>
                        </select>
                    </label>
                </div>

                <label class="parametres-case">
                    <input
                        type="checkbox"
                        :checked="voix.voixNavigateur.value"
                        @change="voix.basculerVoixNavigateur()"
                    />
                    <span>Narration par la voix du navigateur (remplace la voix générée du narrateur)</span>
                </label>
                <p class="parametres-aide">
                    <MSym n="info" :size="14" />
                    <span>Les textes du MJ — générés par l'IA comme les répliques pré-enregistrées,
                    c'est le même narrateur — sont alors lus par la synthèse du navigateur. Les
                    barks de monstres gardent leurs voix audio. (Pour arrêter de dépenser le quota
                    de synthèse pour toutes les tables, utilise plutôt la bascule « Synthèse
                    vocale IA » plus haut.)</span>
                </p>

                <p class="parametres-aide">
                    <MSym n="info" :size="14" />
                    <span>Préférence propre à cet appareil (ses enceintes) — non partagée avec les autres joueurs.</span>
                </p>
            </section>
        </div>
    </div>
</template>

<style scoped>
.parametres-ov {
    position: fixed; inset: 0; z-index: 90; display: grid; place-items: center;
    padding: 24px; background: oklch(0.12 0.02 60 / 0.82); backdrop-filter: blur(6px);
    animation: parametres-fade .25s ease;
}
@keyframes parametres-fade { from { opacity: 0; } to { opacity: 1; } }

.parametres-carte {
    position: relative; width: 100%; max-width: 640px; max-height: min(88vh, 860px);
    overflow-y: auto; padding: 30px 30px 26px; border-radius: var(--r-xl); border: var(--line);
    background: linear-gradient(180deg, var(--stone-850), var(--stone-900));
    box-shadow: 0 0 60px oklch(0.76 0.155 65 / 0.14), var(--sh-3);
    display: flex; flex-direction: column; gap: 18px; color: var(--ink-100);
}

.parametres-fermer {
    position: absolute; top: 16px; right: 16px; width: 34px; height: 34px; border-radius: 999px;
    display: grid; place-items: center; border: var(--line); background: var(--stone-800); color: var(--ink-300);
    cursor: pointer; transition: color .15s, border-color .15s;
}
.parametres-fermer:hover { color: var(--parch-100); border-color: var(--torch); }

.parametres-tete { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 8px; }
.parametres-orn {
    width: 56px; height: 56px; border-radius: 16px; display: grid; place-items: center;
    background: linear-gradient(150deg, var(--ember), var(--ember-deep)); color: var(--parch-100);
    box-shadow: 0 0 28px oklch(0.76 0.155 65 / 0.25);
}
.parametres-orn .msym { font-size: 30px; }
.parametres-titre { font-family: var(--font-display); font-size: 22px; font-weight: 800; color: var(--parch-100);
    letter-spacing: 0.02em; margin: 0; }
.parametres-sous { font-size: 13px; color: var(--ink-500); margin: 0; max-width: 46ch; }

.parametres-charge { display: flex; align-items: center; gap: 10px; color: var(--ink-500); font-size: 14px; padding: 6px 0; }
.parametres-charge .msym { color: var(--torch); }

.parametres-err {
    display: flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 600; color: var(--danger); margin: 0;
}

/* ---- bandeau de statut IA ---- */
.parametres-statut {
    display: flex; align-items: flex-start; gap: 10px; padding: 10px 14px; border-radius: var(--r-md);
    font-size: 13px; font-weight: 600; line-height: 1.45;
}
.parametres-statut b { color: inherit; }
.parametres-statut.nominal { background: oklch(0.70 0.140 150 / 0.10); border: 1px solid oklch(0.70 0.140 150 / 0.35); color: var(--ok); }
.parametres-statut.repli { background: oklch(0.78 0.150 75 / 0.12); border: 1px solid oklch(0.78 0.150 75 / 0.45); color: var(--warn); }
.parametres-statut.indisponible { background: oklch(0.60 0.200 25 / 0.12); border: 1px solid oklch(0.60 0.200 25 / 0.45); color: var(--danger); }

/* ---- formulaire (IA / illustrations / voix / équilibrage) ---- */
.parametres-form { display: flex; flex-direction: column; gap: 18px; }

.parametres-section { display: flex; flex-direction: column; gap: 10px; padding-top: 16px; border-top: var(--line); }
.parametres-form .parametres-section:first-child { padding-top: 0; border-top: none; }
.parametres-section h3 {
    display: flex; align-items: center; gap: 7px; margin: 0; font-family: var(--font-ui);
    font-size: 12.5px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: var(--ink-300);
}
.parametres-section h3 .msym { color: var(--torch); }

.parametres-champ { display: flex; flex-direction: column; gap: 5px; font-size: 12.5px; color: var(--ink-500); font-weight: 700; }
.parametres-champ input[type="text"],
.parametres-champ input[type="number"],
.parametres-champ select {
    background: var(--stone-850); border: var(--line); border-radius: var(--r-md);
    padding: 9px 12px; color: var(--ink-100); font-family: var(--font-ui); font-size: 14px; font-weight: 600;
    outline: none; transition: border-color .15s, box-shadow .15s;
}
.parametres-champ input:focus,
.parametres-champ select:focus { border-color: var(--torch); box-shadow: var(--glow-torch); }
.parametres-champ input::placeholder { color: var(--ink-700); font-weight: 500; }
.parametres-champ select { cursor: pointer; }
.parametres-champ select option:disabled { color: var(--ink-700); }

.parametres-case { display: flex; align-items: center; gap: 10px; font-size: 13.5px; color: var(--ink-300); font-weight: 600; cursor: pointer; }
.parametres-case input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--torch); cursor: pointer; flex: none; }

.parametres-lecture-seule,
.parametres-aide {
    display: flex; align-items: flex-start; gap: 7px; font-size: 12px; color: var(--ink-500);
    line-height: 1.5; margin: 2px 0 0;
}
.parametres-lecture-seule .msym,
.parametres-aide .msym { flex: none; margin-top: 1px; color: var(--ink-500); }

.parametres-grille { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }

.parametres-actions { display: flex; align-items: center; justify-content: flex-end; gap: 14px; padding-top: 4px; }
.parametres-ok { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 700; color: var(--ok); }
.parametres-tests { display: flex; gap: 10px; flex-wrap: wrap; }
.parametres-test-btn { display: inline-flex; align-items: center; gap: 7px; padding: 8px 14px;
  border: var(--line); border-radius: var(--r-md); background: var(--stone-800); color: var(--ink-200);
  font-family: var(--font-ui); font-weight: 700; font-size: 13px; cursor: pointer;
  transition: color .15s, border-color .15s; }
.parametres-test-btn:hover:not(:disabled) { color: var(--parch-100); border-color: var(--torch); }
.parametres-test-btn:disabled { opacity: 0.45; cursor: not-allowed; }
.parametres-save {
    border: none; border-radius: var(--r-md); padding: 11px 22px;
    font-family: var(--font-ui); font-weight: 800; font-size: 14px; cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px;
    background: linear-gradient(180deg, var(--torch-bright), var(--torch)); color: var(--stone-950);
    box-shadow: var(--sh-2); transition: transform .1s, opacity .15s;
}
.parametres-save:hover:not(:disabled) { transform: translateY(-1px); }
.parametres-save:active { transform: scale(0.97); }
.parametres-save:disabled { opacity: 0.5; cursor: not-allowed; }

/* ---- audio (préférence locale, pas de bouton Enregistrer) ---- */
.parametres-audio-ligne { display: flex; align-items: center; gap: 12px; }
.parametres-mute-spacer { width: 38px; flex: none; }
.parametres-slider select {
    background: var(--stone-850); border: var(--line); border-radius: var(--r-md);
    padding: 8px 11px; color: var(--ink-100); font-family: var(--font-ui); font-size: 13.5px; font-weight: 600;
    outline: none; cursor: pointer; transition: border-color .15s, box-shadow .15s; max-width: 100%;
}
.parametres-slider select:focus { border-color: var(--torch); box-shadow: var(--glow-torch); }
.parametres-slider select:disabled { opacity: 0.45; cursor: not-allowed; }
.parametres-mute {
    flex: none; width: 38px; height: 38px; border-radius: 999px; display: grid; place-items: center;
    border: var(--line); background: var(--stone-850); color: var(--ink-300); cursor: pointer;
    transition: color .15s, border-color .15s;
}
.parametres-mute:hover { color: var(--parch-100); border-color: var(--torch); }
.parametres-mute.actif { color: var(--danger); border-color: var(--danger); }
.parametres-slider { display: flex; flex-direction: column; gap: 5px; font-size: 12.5px; color: var(--ink-500); font-weight: 700; flex: 1; }
.parametres-slider input[type="range"] { accent-color: var(--torch); width: 100%; cursor: pointer; }

@media (max-width: 480px) {
    .parametres-carte { padding: 22px 18px 20px; border-radius: var(--r-lg); }
    .parametres-grille { grid-template-columns: 1fr; }
}
</style>
