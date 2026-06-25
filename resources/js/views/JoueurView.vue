<script setup>
// PAGE JOUEUR — compte + roster de personnages.
// Si pas connecté : onglets connexion / inscription.
// Connecté : liste des persos avec statut (libre / engagé + narrateur actif).
// Actions : Créer un groupe, Rejoindre par code, Reprendre, Créer un personnage.
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import MSym from '../components/ui/MSym.vue';
import Vignette from '../components/ui/Vignette.vue';
import DemoBadge from '../components/ui/DemoBadge.vue';
import { estErreurDemo, useApi } from '../composables/useApi';
import { CLASSES, ELEMENTS, statutPersonnage, useGameStore } from '../store/game';

const router = useRouter();
const api = useApi();
const store = useGameStore();

/* ---- session ---- */
const joueur = computed(() => store.state.joueur);
const personnages = computed(() => store.state.personnages ?? []);
const chargement = ref(true);
const erreurGlobale = ref('');

onMounted(async () => {
    try {
        const { joueur: moi, personnages: persos } = await api.moi();
        store.setJoueur(moi, persos ?? []);
    } catch (e) {
        if (e instanceof Error && e.status === 0) store.activerModeDemo(e.message);
        // 401 = pas connecté, c'est normal
    } finally {
        chargement.value = false;
    }
});

/* ---- onglet auth ---- */
const ongletAuth = ref('connexion'); // 'connexion' | 'inscription'
const identifiant = ref('');
const pseudo = ref('');
const authEnCours = ref(false);
const erreurAuth = ref('');

async function seConnecter() {
    authEnCours.value = true;
    erreurAuth.value = '';
    try {
        await api.connexion(identifiant.value.trim());
        const { joueur: moi, personnages: persos } = await api.moi();
        store.setJoueur(moi, persos ?? []);
    } catch (e) {
        if (estErreurDemo(e)) {
            store.activerModeDemo(e.message);
        } else {
            erreurAuth.value = e.message;
        }
    } finally {
        authEnCours.value = false;
    }
}

async function sInscrire() {
    authEnCours.value = true;
    erreurAuth.value = '';
    try {
        await api.inscription({
            pseudo: pseudo.value.trim(),
            identifiant: identifiant.value.trim(),
        });
        const { joueur: moi, personnages: persos } = await api.moi();
        store.setJoueur(moi, persos ?? []);
    } catch (e) {
        if (estErreurDemo(e)) {
            store.activerModeDemo(e.message);
        } else {
            erreurAuth.value = e.message;
        }
    } finally {
        authEnCours.value = false;
    }
}

async function seDeconnecter() {
    try { await api.deconnexion(); } catch { /* non bloquant */ }
    store.setJoueur(null, []);
}

/* ---- création de personnage ---- */
const montrerCreerPerso = ref(false);
const nouveauNom = ref('');
const nouvelleClasse = ref('barbare');
const elementsChoisis = ref([]);
const creerPersoEnCours = ref(false);
const erreurCreerPerso = ref('');

const classesMagiciennes = ['magicien', 'magicienne'];
const estMagicien = computed(() => classesMagiciennes.includes(nouvelleClasse.value));
const listeElements = Object.entries(ELEMENTS).map(([k, v]) => ({ v: k, l: v.l, ic: v.ic }));
const listeClasses = Object.entries(CLASSES).map(([k, v]) => ({ v: k, l: v.l }));

function toggleElement(el) {
    if (elementsChoisis.value.includes(el)) {
        elementsChoisis.value = elementsChoisis.value.filter((e) => e !== el);
    } else if (elementsChoisis.value.length < 2) {
        elementsChoisis.value = [...elementsChoisis.value, el];
    }
}

async function creerPersonnage() {
    if (!nouveauNom.value.trim()) return;
    creerPersoEnCours.value = true;
    erreurCreerPerso.value = '';
    try {
        const payload = { nom: nouveauNom.value.trim(), classe: nouvelleClasse.value };
        if (estMagicien.value && elementsChoisis.value.length === 2) {
            payload.elements = elementsChoisis.value;
        }
        await api.creerPersonnage(payload);
        const { joueur: moi, personnages: persos } = await api.moi();
        store.setJoueur(moi, persos ?? []);
        nouveauNom.value = '';
        nouvelleClasse.value = 'barbare';
        elementsChoisis.value = [];
        montrerCreerPerso.value = false;
    } catch (e) {
        erreurCreerPerso.value = e.message;
    } finally {
        creerPersoEnCours.value = false;
    }
}

/* ---- actions par personnage ---- */
// Rejoindre par code
const codeRejoindre = ref({});    // { [perso.id]: code }
const rejoindreEnCours = ref({}); // { [perso.id]: bool }
const erreurRejoindre = ref({}); // { [perso.id]: string }

function codeDePerso(id) {
    return codeRejoindre.value[id] ?? '';
}
function setCode(id, val) {
    codeRejoindre.value = { ...codeRejoindre.value, [id]: val.toUpperCase() };
}

async function rejoindre(perso) {
    const code = codeDePerso(perso.id).trim();
    if (!code) return;
    rejoindreEnCours.value = { ...rejoindreEnCours.value, [perso.id]: true };
    erreurRejoindre.value = { ...erreurRejoindre.value, [perso.id]: '' };
    try {
        // On navigue vers l'identifiant CANONIQUE renvoyé par le serveur (slug
        // minuscule), pas le code tapé (mis en majuscules par setCode) : sinon
        // la manette s'abonnerait à `groupe.MAJUSCULE` alors que le serveur
        // diffuse sur `groupe.minuscule` → le joueur ne recevrait AUCUN event
        // live (resterait bloqué au hub). Repli sur le code tapé.
        const reponse = await api.rejoindreGroupe(code, { personnage_id: perso.id });
        const ident = reponse?.groupe?.identifiant ?? code;
        const { joueur: moi, personnages: persos } = await api.moi();
        store.setJoueur(moi, persos ?? []);
        router.push({ name: 'manette', params: { groupe: ident } });
    } catch (e) {
        erreurRejoindre.value = { ...erreurRejoindre.value, [perso.id]: e.message };
    } finally {
        rejoindreEnCours.value = { ...rejoindreEnCours.value, [perso.id]: false };
    }
}

// Créer un groupe
const montrerCreerGroupe = ref({}); // { [perso.id]: bool }
const nomGroupe = ref({});
const themeGroupe = ref({});
const longueurGroupe = ref({});
const creerGroupeEnCours = ref({});
const erreurCreerGroupe = ref({});

const longueurs = [
    { v: 'courte', l: 'Court', sub: '3 à 5 quêtes' },
    { v: 'normale', l: 'Moyen', sub: '7 à 10 quêtes' },
    { v: 'longue', l: 'Épique', sub: '12 à 15 quêtes' },
];

function initCreerGroupe(id) {
    if (!nomGroupe.value[id]) nomGroupe.value = { ...nomGroupe.value, [id]: '' };
    if (!themeGroupe.value[id]) themeGroupe.value = { ...themeGroupe.value, [id]: 'Donjon classique' };
    if (!longueurGroupe.value[id]) longueurGroupe.value = { ...longueurGroupe.value, [id]: 'normale' };
    montrerCreerGroupe.value = { ...montrerCreerGroupe.value, [id]: true };
}

async function creerGroupe(perso) {
    const nom = (nomGroupe.value[perso.id] || '').trim() || `Groupe de ${perso.nom}`;
    creerGroupeEnCours.value = { ...creerGroupeEnCours.value, [perso.id]: true };
    erreurCreerGroupe.value = { ...erreurCreerGroupe.value, [perso.id]: '' };
    try {
        const { groupe } = await api.creerGroupe({
            nom,
            theme: themeGroupe.value[perso.id] || 'Donjon classique',
            longueur: longueurGroupe.value[perso.id] || 'normale',
            personnage_id: perso.id,
        });
        const { joueur: moi, personnages: persos } = await api.moi();
        store.setJoueur(moi, persos ?? []);
        router.push({ name: 'manette', params: { groupe: groupe.identifiant } });
    } catch (e) {
        erreurCreerGroupe.value = { ...erreurCreerGroupe.value, [perso.id]: e.message };
    } finally {
        creerGroupeEnCours.value = { ...creerGroupeEnCours.value, [perso.id]: false };
    }
}

// Copier le code de groupe (clipboard si dispo — sinon repli execCommand,
// car en HTTP le navigateur peut bloquer navigator.clipboard).
const codeCopie = ref(null);
async function copierCode(code) {
    if (!code) return;
    try {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(code);
        } else {
            const t = document.createElement('textarea');
            t.value = code; t.style.position = 'fixed'; t.style.opacity = '0';
            document.body.appendChild(t); t.select();
            document.execCommand('copy'); document.body.removeChild(t);
        }
        codeCopie.value = code;
        setTimeout(() => { if (codeCopie.value === code) codeCopie.value = null; }, 1500);
    } catch { /* best-effort : le code reste lisible/sélectionnable à l'écran */ }
}

// Reprendre
async function reprendre(perso) {
    const statut = statutPersonnage(perso);
    if (!statut.narrateur_actif) return; // désactivé
    router.push({ name: 'manette', params: { groupe: statut.identifiant } });
}

// Portrait unique (génération IA à la demande) — prime sur l'image de classe.
const portraitEnCours = ref({});
async function genererPortrait(perso) {
    if (portraitEnCours.value[perso.id]) return;
    portraitEnCours.value = { ...portraitEnCours.value, [perso.id]: true };
    try {
        const { portrait_url } = await api.genererPortrait(perso.id);
        const persos = (store.state.personnages ?? [])
            .map((p) => (p.id === perso.id ? { ...p, portrait_url } : p));
        store.setJoueur(store.state.joueur, persos);
    } catch { /* non bloquant : on garde l'image de classe */ }
    finally {
        portraitEnCours.value = { ...portraitEnCours.value, [perso.id]: false };
    }
}

// Icône de classe
function iconeClasse(classe) {
    return CLASSES[(classe ?? '').toLowerCase()]?.ic ?? 'person';
}
function libelleClasse(classe) {
    return CLASSES[(classe ?? '').toLowerCase()]?.l ?? classe;
}
</script>

<template>
    <div class="joueur tex-vignette">
        <div class="joueur-wrap">
            <!-- en-tête -->
            <div class="joueur-header">
                <RouterLink to="/" class="joueur-back">
                    <MSym n="arrow_back" :size="16" /> Retour
                </RouterLink>
                <h1 class="joueur-title">
                    <MSym n="smartphone" fill /> Je suis un Joueur
                </h1>
                <p class="joueur-sub">Connectez-vous pour accéder à vos personnages et rejoindre une partie.</p>
            </div>

            <!-- chargement -->
            <div v-if="chargement" class="joueur-loading">
                <span class="dots"><i /><i /><i /></span> Chargement…
            </div>

            <!-- non connecté : onglets connexion / inscription -->
            <template v-else-if="!joueur">
                <div class="joueur-tabs">
                    <button
                        :class="{ on: ongletAuth === 'connexion' }"
                        @click="ongletAuth = 'connexion'; erreurAuth = ''"
                    >
                        <MSym n="login" /> Se connecter
                    </button>
                    <button
                        :class="{ on: ongletAuth === 'inscription' }"
                        @click="ongletAuth = 'inscription'; erreurAuth = ''"
                    >
                        <MSym n="person_add" /> Créer un compte
                    </button>
                </div>

                <!-- connexion (nom seul — jeu LAN, pas de mot de passe) -->
                <div v-if="ongletAuth === 'connexion'" class="joueur-auth-card">
                    <form class="joueur-auth-form" @submit.prevent="seConnecter">
                        <label class="joueur-lbl">Votre nom de joueur</label>
                        <input
                            v-model="identifiant"
                            class="joueur-input"
                            placeholder="ex. renegat"
                            autocomplete="username"
                            spellcheck="false"
                        />
                        <p v-if="erreurAuth" class="joueur-err">
                            <MSym n="error" :size="14" /> {{ erreurAuth }}
                        </p>
                        <button
                            class="joueur-btn-primary"
                            type="submit"
                            :disabled="authEnCours || !identifiant.trim()"
                        >
                            <MSym n="login" /> {{ authEnCours ? 'Connexion…' : 'Entrer' }}
                        </button>
                    </form>
                </div>

                <!-- inscription (pas de mot de passe) -->
                <div v-else class="joueur-auth-card">
                    <form class="joueur-auth-form" @submit.prevent="sInscrire">
                        <label class="joueur-lbl">Pseudo affiché</label>
                        <input
                            v-model="pseudo"
                            class="joueur-input"
                            placeholder="votre pseudo dans le jeu"
                            autocomplete="nickname"
                            spellcheck="false"
                        />
                        <label class="joueur-lbl">Identifiant de connexion</label>
                        <input
                            v-model="identifiant"
                            class="joueur-input"
                            placeholder="login unique (ex. renegat)"
                            autocomplete="username"
                            spellcheck="false"
                        />
                        <p v-if="erreurAuth" class="joueur-err">
                            <MSym n="error" :size="14" /> {{ erreurAuth }}
                        </p>
                        <button
                            class="joueur-btn-primary"
                            type="submit"
                            :disabled="authEnCours || !pseudo.trim() || !identifiant.trim()"
                        >
                            <MSym n="person_add" /> {{ authEnCours ? 'Création…' : 'Créer mon compte' }}
                        </button>
                    </form>
                </div>
            </template>

            <!-- connecté : roster -->
            <template v-else>
                <!-- barre utilisateur -->
                <div class="joueur-userbar">
                    <div class="joueur-who">
                        <span class="joueur-avatar"><MSym n="person" fill /></span>
                        <div>
                            <div class="joueur-pseudo">{{ joueur.pseudo }}</div>
                            <div class="joueur-sub-sm">Joueur connecté</div>
                        </div>
                    </div>
                    <button class="joueur-btn-ghost" @click="seDeconnecter">
                        <MSym n="logout" /> Déconnexion
                    </button>
                </div>

                <!-- roster vide -->
                <div v-if="personnages.length === 0" class="joueur-empty">
                    <MSym n="sentiment_neutral" :size="36" />
                    <p>Aucun personnage pour l'instant.<br>Forgez votre premier héros ci-dessous.</p>
                </div>

                <!-- cartes de personnage -->
                <div v-else class="joueur-roster">
                    <div
                        v-for="perso in personnages"
                        :key="perso.id"
                        class="joueur-pcard"
                        :class="{ engage: !statutPersonnage(perso).disponible }"
                    >
                        <!-- en-tête de la carte -->
                        <div class="pcard-head">
                            <div class="pcard-crest">
                                <Vignette :src="perso.portrait_url" :icon="iconeClasse(perso.classe)" fill />
                                <button
                                    class="pcard-portrait-btn"
                                    type="button"
                                    :disabled="portraitEnCours[perso.id]"
                                    :title="portraitEnCours[perso.id] ? 'Génération du portrait…' : 'Générer un portrait IA'"
                                    @click="genererPortrait(perso)"
                                >
                                    <MSym :n="portraitEnCours[perso.id] ? 'hourglass_top' : 'auto_awesome'" :size="13" />
                                </button>
                            </div>
                            <div class="pcard-info">
                                <div class="pcard-nom">{{ perso.nom }}</div>
                                <div class="pcard-cls">
                                    {{ libelleClasse(perso.classe) }}
                                    <span v-if="perso.niveau"> · Niv.&nbsp;{{ perso.niveau }}</span>
                                </div>
                            </div>
                            <!-- badge statut -->
                            <div
                                v-if="statutPersonnage(perso).disponible"
                                class="pcard-badge libre"
                            >
                                <MSym n="check_circle" :size="13" fill /> Libre
                            </div>
                            <div v-else class="pcard-badge engage">
                                <MSym n="group" :size="13" fill /> {{ statutPersonnage(perso).nom ?? 'Engagé' }}
                            </div>
                        </div>

                        <!-- indicateur narrateur actif si engagé -->
                        <div
                            v-if="!statutPersonnage(perso).disponible"
                            class="pcard-narrateur"
                            :class="{ actif: statutPersonnage(perso).narrateur_actif }"
                        >
                            <MSym :n="statutPersonnage(perso).narrateur_actif ? 'cast_connected' : 'cast'" :size="14" />
                            {{
                                statutPersonnage(perso).narrateur_actif
                                    ? 'Narrateur actif'
                                    : 'En attente d\'un narrateur'
                            }}
                        </div>

                        <!-- code du groupe (à donner au narrateur / aux autres joueurs) -->
                        <div v-if="!statutPersonnage(perso).disponible" class="pcard-code">
                            <span class="pcard-code-lbl">Code du groupe</span>
                            <button
                                class="pcard-code-val"
                                type="button"
                                :title="codeCopie === statutPersonnage(perso).identifiant ? 'Copié !' : 'Copier le code'"
                                @click="copierCode(statutPersonnage(perso).identifiant)"
                            >
                                <code>{{ statutPersonnage(perso).identifiant }}</code>
                                <MSym :n="codeCopie === statutPersonnage(perso).identifiant ? 'check' : 'content_copy'" :size="13" />
                            </button>
                        </div>

                        <!-- actions : perso LIBRE -->
                        <template v-if="statutPersonnage(perso).disponible">
                            <!-- créer un groupe -->
                            <div v-if="!montrerCreerGroupe[perso.id]" class="pcard-actions">
                                <button
                                    class="joueur-btn-action"
                                    @click="initCreerGroupe(perso.id)"
                                >
                                    <MSym n="add_circle" /> Créer un groupe
                                </button>
                            </div>
                            <div v-else class="pcard-mini-form">
                                <label class="joueur-lbl">Nom de la campagne</label>
                                <input
                                    v-model="nomGroupe[perso.id]"
                                    class="joueur-input-sm"
                                    :placeholder="`Groupe de ${perso.nom}`"
                                />
                                <label class="joueur-lbl">Thème</label>
                                <input
                                    v-model="themeGroupe[perso.id]"
                                    class="joueur-input-sm"
                                    placeholder="Donjon classique"
                                />
                                <label class="joueur-lbl">Longueur</label>
                                <div class="joueur-radio-row">
                                    <label
                                        v-for="l in longueurs"
                                        :key="l.v"
                                        class="joueur-radio"
                                        :class="{ on: (longueurGroupe[perso.id] || 'normale') === l.v }"
                                    >
                                        <input
                                            type="radio"
                                            :name="`longueur-${perso.id}`"
                                            :value="l.v"
                                            :checked="(longueurGroupe[perso.id] || 'normale') === l.v"
                                            @change="longueurGroupe = { ...longueurGroupe, [perso.id]: l.v }"
                                        />
                                        {{ l.l }} <span class="joueur-radio-sub">{{ l.sub }}</span>
                                    </label>
                                </div>
                                <p v-if="erreurCreerGroupe[perso.id]" class="joueur-err">
                                    <MSym n="error" :size="14" /> {{ erreurCreerGroupe[perso.id] }}
                                </p>
                                <div class="joueur-mini-btns">
                                    <button
                                        class="joueur-btn-primary-sm"
                                        :disabled="creerGroupeEnCours[perso.id]"
                                        @click="creerGroupe(perso)"
                                    >
                                        <MSym n="add_circle" />
                                        {{ creerGroupeEnCours[perso.id] ? 'Création…' : 'Forger la campagne' }}
                                    </button>
                                    <button
                                        class="joueur-btn-ghost-sm"
                                        @click="montrerCreerGroupe = { ...montrerCreerGroupe, [perso.id]: false }"
                                    >
                                        Annuler
                                    </button>
                                </div>
                            </div>

                            <!-- rejoindre par code -->
                            <div class="pcard-actions pcard-join-row">
                                <input
                                    class="joueur-code-input"
                                    :value="codeDePerso(perso.id)"
                                    placeholder="CODE-XX"
                                    spellcheck="false"
                                    @input="setCode(perso.id, $event.target.value)"
                                />
                                <button
                                    class="joueur-btn-ghost-sm"
                                    :disabled="rejoindreEnCours[perso.id] || !codeDePerso(perso.id).trim()"
                                    @click="rejoindre(perso)"
                                >
                                    <MSym n="group_add" />
                                    {{ rejoindreEnCours[perso.id] ? 'Rejoindre…' : 'Rejoindre' }}
                                </button>
                            </div>
                            <p v-if="erreurRejoindre[perso.id]" class="joueur-err">
                                <MSym n="error" :size="14" /> {{ erreurRejoindre[perso.id] }}
                            </p>
                        </template>

                        <!-- actions : perso ENGAGÉ -->
                        <template v-else>
                            <button
                                class="joueur-btn-reprendre"
                                :disabled="!statutPersonnage(perso).narrateur_actif"
                                :title="!statutPersonnage(perso).narrateur_actif
                                    ? 'En attente d\'un narrateur pour démarrer'
                                    : 'Reprendre la partie'"
                                @click="reprendre(perso)"
                            >
                                <MSym n="play_circle" fill />
                                {{
                                    statutPersonnage(perso).narrateur_actif
                                        ? 'Reprendre la partie'
                                        : 'En attente d\'un narrateur'
                                }}
                            </button>
                        </template>
                    </div>
                </div>

                <!-- créer un personnage -->
                <div class="joueur-creer-perso">
                    <button
                        v-if="!montrerCreerPerso"
                        class="joueur-btn-ghost"
                        @click="montrerCreerPerso = true"
                    >
                        <MSym n="add_circle" /> Créer un personnage
                    </button>

                    <div v-else class="joueur-auth-card">
                        <h3 class="joueur-section-title">Nouveau personnage</h3>
                        <form class="joueur-auth-form" @submit.prevent="creerPersonnage">
                            <label class="joueur-lbl">Nom du héros</label>
                            <input
                                v-model="nouveauNom"
                                class="joueur-input"
                                placeholder="ex. Gorrim le Brutal"
                                spellcheck="false"
                            />
                            <label class="joueur-lbl">Classe</label>
                            <div class="joueur-classe-grid">
                                <label
                                    v-for="cls in listeClasses"
                                    :key="cls.v"
                                    class="joueur-radio"
                                    :class="{ on: nouvelleClasse === cls.v }"
                                >
                                    <input
                                        type="radio"
                                        name="nouvelleClasse"
                                        :value="cls.v"
                                        v-model="nouvelleClasse"
                                    />
                                    <MSym :n="CLASSES[cls.v]?.ic ?? 'person'" :size="14" />
                                    {{ cls.l }}
                                </label>
                            </div>

                            <!-- éléments (magicien/magicienne uniquement) -->
                            <template v-if="estMagicien">
                                <label class="joueur-lbl">
                                    Éléments de magie
                                    <span class="joueur-lbl-hint">(choisissez 2)</span>
                                </label>
                                <div class="joueur-elem-grid">
                                    <button
                                        v-for="el in listeElements"
                                        :key="el.v"
                                        type="button"
                                        class="joueur-elem-btn"
                                        :class="{
                                            on: elementsChoisis.includes(el.v),
                                            disabled: !elementsChoisis.includes(el.v) && elementsChoisis.length >= 2,
                                        }"
                                        @click="toggleElement(el.v)"
                                    >
                                        <MSym :n="el.ic" :size="16" />
                                        {{ el.l }}
                                    </button>
                                </div>
                            </template>

                            <p v-if="erreurCreerPerso" class="joueur-err">
                                <MSym n="error" :size="14" /> {{ erreurCreerPerso }}
                            </p>

                            <div class="joueur-mini-btns">
                                <button
                                    class="joueur-btn-primary-sm"
                                    type="submit"
                                    :disabled="creerPersoEnCours || !nouveauNom.trim()
                                        || (estMagicien && elementsChoisis.length < 2)"
                                >
                                    <MSym n="add_circle" />
                                    {{ creerPersoEnCours ? 'Création…' : 'Créer le personnage' }}
                                </button>
                                <button
                                    type="button"
                                    class="joueur-btn-ghost-sm"
                                    @click="montrerCreerPerso = false; erreurCreerPerso = ''"
                                >
                                    Annuler
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </template>
        </div>
        <DemoBadge />
    </div>
</template>

<style>
.joueur { min-height: 100vh; background: var(--stone-950); color: var(--ink-100);
  padding: 32px 20px 80px; }
.joueur-wrap { max-width: 620px; margin: 0 auto; display: flex; flex-direction: column; gap: 24px; }

.joueur-back { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 700;
  color: var(--ink-500); text-decoration: none; transition: color .15s; }
.joueur-back:hover { color: var(--torch); }

.joueur-header { display: flex; flex-direction: column; gap: 8px; }
.joueur-title { font-family: var(--font-display); font-size: 28px; font-weight: 800;
  color: var(--parch-100); margin: 0; display: flex; align-items: center; gap: 12px; }
.joueur-title .msym { color: var(--torch); font-size: 28px; }
.joueur-sub { font-family: var(--font-narr); font-style: italic; color: var(--ink-300);
  font-size: 16px; margin: 0; }
.joueur-sub-sm { font-size: 11px; color: var(--ink-500); }

.joueur-loading { display: flex; align-items: center; gap: 10px; color: var(--ink-500);
  font-size: 14px; padding: 20px 0; }

/* ---- onglets auth ---- */
.joueur-tabs { display: flex; gap: 4px; border-bottom: var(--line); }
.joueur-tabs button { background: none; border: none; padding: 12px 18px; font-family: var(--font-ui);
  font-size: 14px; font-weight: 700; color: var(--ink-500); cursor: pointer;
  display: flex; align-items: center; gap: 7px; border-bottom: 2px solid transparent;
  transition: color .15s, border-color .15s; margin-bottom: -1px; border-radius: 0; }
.joueur-tabs button:hover { color: var(--ink-200); }
.joueur-tabs button.on { color: var(--torch); border-bottom-color: var(--torch); }

/* ---- cartes ---- */
.joueur-auth-card { background: linear-gradient(180deg, var(--stone-850), var(--stone-900));
  border: var(--line); border-radius: var(--r-lg); padding: 24px; }
.joueur-auth-form { display: flex; flex-direction: column; gap: 10px; }
.joueur-section-title { font-family: var(--font-display); font-size: 18px; color: var(--parch-100);
  margin: 0 0 16px; letter-spacing: 0.02em; }

/* ---- champs ---- */
.joueur-lbl { font-size: 11px; letter-spacing: 0.1em; text-transform: uppercase;
  color: var(--ink-500); font-weight: 700; }
.joueur-lbl-hint { font-size: 10px; color: var(--ink-600); text-transform: none;
  letter-spacing: 0; margin-left: 6px; }
.joueur-input { background: var(--stone-850); border: var(--line-strong); border-radius: var(--r-md);
  padding: 11px 14px; color: var(--parch-100); font-family: var(--font-ui); font-size: 15px;
  outline: none; width: 100%; box-sizing: border-box; }
.joueur-input:focus { border-color: var(--torch); box-shadow: var(--glow-torch); }
.joueur-input::placeholder { color: var(--ink-700); }
.joueur-input-sm { background: var(--stone-850); border: var(--line-strong); border-radius: var(--r-md);
  padding: 9px 12px; color: var(--parch-100); font-family: var(--font-ui); font-size: 14px;
  outline: none; width: 100%; box-sizing: border-box; }
.joueur-input-sm:focus { border-color: var(--torch); box-shadow: var(--glow-torch); }
.joueur-code-input { background: var(--stone-850); border: 1px dashed var(--torch); border-radius: var(--r-md);
  padding: 9px 14px; color: var(--torch); font-family: var(--font-display); font-size: 18px;
  font-weight: 800; letter-spacing: 0.22em; text-transform: uppercase; outline: none;
  width: 140px; text-align: center; }
.joueur-code-input:focus { box-shadow: var(--glow-torch); border-style: solid; }

/* ---- boutons ---- */
.joueur-btn-primary { width: 100%; padding: 13px 18px; border: none; border-radius: var(--r-md);
  background: linear-gradient(180deg, var(--torch-bright), var(--torch)); color: var(--stone-950);
  font-family: var(--font-ui); font-weight: 800; font-size: 15px;
  cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;
  box-shadow: var(--sh-1); transition: transform .1s; }
.joueur-btn-primary:hover:not(:disabled) { transform: translateY(-1px); }
.joueur-btn-primary:disabled { opacity: 0.45; cursor: not-allowed; }

.joueur-btn-ghost { background: var(--stone-800); border: var(--line-strong); border-radius: var(--r-md);
  padding: 11px 16px; color: var(--ink-100); font-family: var(--font-ui); font-weight: 700;
  font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; gap: 7px;
  transition: border-color .15s; }
.joueur-btn-ghost:hover { border-color: var(--ink-400); }

.joueur-btn-primary-sm { padding: 10px 16px; border: none; border-radius: var(--r-md);
  background: linear-gradient(180deg, var(--torch-bright), var(--torch)); color: var(--stone-950);
  font-family: var(--font-ui); font-weight: 800; font-size: 14px;
  cursor: pointer; display: inline-flex; align-items: center; gap: 7px;
  transition: transform .1s; }
.joueur-btn-primary-sm:disabled { opacity: 0.45; cursor: not-allowed; }

.joueur-btn-ghost-sm { padding: 10px 14px; background: var(--stone-800); border: var(--line-strong);
  border-radius: var(--r-md); color: var(--ink-300); font-family: var(--font-ui); font-weight: 700;
  font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.joueur-btn-ghost-sm:disabled { opacity: 0.45; cursor: not-allowed; }

.joueur-btn-action { padding: 9px 14px; background: var(--stone-800); border: var(--line); border-radius: var(--r-md);
  color: var(--ink-200); font-family: var(--font-ui); font-weight: 700; font-size: 13px;
  cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
  transition: border-color .15s, color .15s; }
.joueur-btn-action:hover { border-color: var(--torch); color: var(--torch); }

.joueur-btn-reprendre { width: 100%; padding: 12px 16px; border: none; border-radius: var(--r-md);
  font-family: var(--font-ui); font-weight: 800; font-size: 14px;
  cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;
  transition: opacity .15s, transform .1s; }
.joueur-btn-reprendre:not(:disabled) {
  background: linear-gradient(180deg, var(--torch-bright), var(--torch)); color: var(--stone-950); }
.joueur-btn-reprendre:disabled {
  background: var(--stone-800); color: var(--ink-600); border: var(--line); cursor: not-allowed; }

/* ---- userbar ---- */
.joueur-userbar { display: flex; align-items: center; gap: 14px; padding: 16px 20px;
  background: linear-gradient(180deg, var(--stone-850), var(--stone-900));
  border: var(--line); border-radius: var(--r-lg); }
.joueur-who { display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; }
.joueur-avatar { width: 42px; height: 42px; border-radius: 50%; display: grid; place-items: center; flex: none;
  background: linear-gradient(150deg, var(--ember), var(--ember-deep)); color: var(--parch-100); }
.joueur-pseudo { font-family: var(--font-display); font-size: 17px; color: var(--parch-100); font-weight: 700; }

/* ---- roster ---- */
.joueur-empty { text-align: center; padding: 40px 20px; color: var(--ink-500);
  display: flex; flex-direction: column; align-items: center; gap: 12px;
  border: var(--line); border-radius: var(--r-lg); border-style: dashed; }
.joueur-empty p { font-family: var(--font-narr); font-style: italic; font-size: 15px; margin: 0; }

.joueur-roster { display: flex; flex-direction: column; gap: 14px; }

.joueur-pcard { background: linear-gradient(180deg, var(--stone-850), var(--stone-900));
  border: var(--line); border-radius: var(--r-lg); padding: 18px; display: flex; flex-direction: column; gap: 14px; }
.joueur-pcard.engage { border-color: oklch(0.5 0.05 250 / 0.6); }

.pcard-head { display: flex; align-items: center; gap: 12px; }
.pcard-crest { position: relative; width: 44px; height: 44px; border-radius: 12px; flex: none; display: grid; place-items: center;
  background: linear-gradient(150deg, var(--ember), var(--ember-deep)); color: var(--parch-100); overflow: visible; }
.pcard-portrait-btn { position: absolute; bottom: -5px; right: -5px; width: 20px; height: 20px; border-radius: 50%;
  display: grid; place-items: center; border: 1px solid var(--stone-950); background: var(--torch); color: var(--stone-950);
  cursor: pointer; padding: 0; box-shadow: var(--sh-1); }
.pcard-portrait-btn:disabled { opacity: 0.6; cursor: default; }
.pcard-crest .msym { font-size: 24px; }
.pcard-info { flex: 1; min-width: 0; }
.pcard-nom { font-family: var(--font-display); font-size: 17px; color: var(--parch-100);
  font-weight: 700; letter-spacing: 0.02em; }
.pcard-cls { font-size: 12px; color: var(--ink-500); font-weight: 600; margin-top: 2px; }

.pcard-badge { display: inline-flex; align-items: center; gap: 5px; font-size: 11px;
  font-weight: 700; padding: 4px 10px; border-radius: 99px; white-space: nowrap; flex: none; }
.pcard-badge.libre { color: var(--ok); border: 1px solid var(--ok); background: oklch(0.6 0.15 145 / 0.10); }
.pcard-badge.engage { color: var(--torch); border: 1px solid var(--torch); background: oklch(0.76 0.155 65 / 0.10);
  max-width: 160px; overflow: hidden; text-overflow: ellipsis; }

.pcard-narrateur { display: flex; align-items: center; gap: 6px; font-size: 12px;
  font-weight: 700; color: var(--ink-600); padding: 6px 10px; border-radius: var(--r-md);
  background: var(--stone-800); border: var(--line); }
.pcard-narrateur.actif { color: var(--ok); background: oklch(0.6 0.15 145 / 0.08); border-color: oklch(0.6 0.15 145 / 0.3); }

/* code du groupe (engagé) */
.pcard-code { display: flex; align-items: center; justify-content: space-between; gap: 8px;
  padding: 6px 10px; border-radius: var(--r-md); background: var(--stone-800); border: var(--line); }
.pcard-code-lbl { font-size: 11px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; color: var(--ink-600); }
.pcard-code-val { display: inline-flex; align-items: center; gap: 7px; cursor: pointer;
  border: none; background: none; color: var(--torch); font-weight: 800; }
.pcard-code-val code { font-family: var(--font-mono, monospace); font-size: 14px; letter-spacing: 0.06em;
  user-select: all; color: var(--parch-100); }
.pcard-code-val:hover code { color: var(--torch); }

.pcard-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.pcard-join-row { align-items: center; }

/* ---- mini formulaires ---- */
.pcard-mini-form { display: flex; flex-direction: column; gap: 8px;
  padding: 14px; background: var(--stone-800); border-radius: var(--r-md); border: var(--line); }
.joueur-mini-btns { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 4px; }

.joueur-radio-row { display: flex; gap: 8px; flex-wrap: wrap; }
.joueur-radio { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600;
  color: var(--ink-400); padding: 7px 12px; border-radius: var(--r-md); border: var(--line);
  background: var(--stone-850); cursor: pointer; transition: border-color .15s, color .15s; }
.joueur-radio:hover { border-color: var(--torch); color: var(--ink-200); }
.joueur-radio.on { border-color: var(--torch); color: var(--torch); background: oklch(0.76 0.155 65 / 0.08); }
.joueur-radio input { display: none; }
.joueur-radio-sub { font-size: 11px; color: var(--ink-600); margin-left: 2px; }

/* ---- sélecteur de classe ---- */
.joueur-classe-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
@media (max-width: 480px) { .joueur-classe-grid { grid-template-columns: repeat(2, 1fr); } }

/* ---- éléments de magie ---- */
.joueur-elem-grid { display: flex; gap: 8px; flex-wrap: wrap; }
.joueur-elem-btn { padding: 8px 14px; border: var(--line); border-radius: var(--r-md);
  background: var(--stone-850); color: var(--ink-400); font-family: var(--font-ui);
  font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center;
  gap: 6px; transition: border-color .15s, color .15s; }
.joueur-elem-btn:hover:not(.disabled) { border-color: var(--torch); color: var(--torch); }
.joueur-elem-btn.on { border-color: var(--elem-fire, var(--torch)); color: var(--elem-fire, var(--torch));
  background: oklch(0.76 0.155 65 / 0.1); }
.joueur-elem-btn.disabled { opacity: 0.4; cursor: not-allowed; }

/* ---- créer personnage ---- */
.joueur-creer-perso { display: flex; flex-direction: column; gap: 14px; }

/* ---- erreurs ---- */
.joueur-err { font-size: 13px; font-weight: 600; color: var(--danger, #c33);
  display: flex; align-items: center; gap: 6px; margin: 0; }

/* dots loader (copié de manette.css) */
.joueur .dots { display: inline-flex; gap: 5px; }
.joueur .dots i { width: 6px; height: 6px; border-radius: 50%; background: var(--torch);
  animation: jdots 1.2s ease-in-out infinite; }
.joueur .dots i:nth-child(2) { animation-delay: .2s }
.joueur .dots i:nth-child(3) { animation-delay: .4s }
@keyframes jdots { 0%, 80%, 100% { transform: scale(0.6); opacity: 0.4 } 40% { transform: scale(1); opacity: 1 } }
</style>
