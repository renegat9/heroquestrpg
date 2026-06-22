<script setup>
// PAGE NARRATEUR — saisie du code de groupe → POST /api/table → /table/:code.
// Pas de compte requis : la table « tient » la partie par session de table.
// Si le code est inconnu (404) → message d'erreur clair.
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import MSym from '../components/ui/MSym.vue';
import DemoBadge from '../components/ui/DemoBadge.vue';
import { useApi, estErreurDemo } from '../composables/useApi';
import { useGameStore } from '../store/game';

const router = useRouter();
const api = useApi();
const store = useGameStore();

const codeBrut = ref('');
const enCours = ref(false);
const erreur = ref('');

async function entrerTable() {
    const code = codeBrut.value.trim().toUpperCase();
    if (!code) return;
    enCours.value = true;
    erreur.value = '';
    try {
        // On navigue vers l'identifiant CANONIQUE renvoyé par le serveur (slug
        // minuscule), pas le code tapé (mis en majuscules à l'écran) : sinon la
        // table s'abonnerait au canal `groupe.MAJUSCULE` alors que le serveur
        // diffuse sur `groupe.minuscule` (aucun événement) et l'auth du canal
        // échouerait (session = identifiant canonique). Repli sur le code tapé.
        const reponse = await api.ouvrirTable(code);
        const ident = reponse?.groupe?.groupe?.identifiant ?? code;
        router.push({ name: 'table', params: { groupe: ident } });
    } catch (e) {
        if (estErreurDemo(e)) {
            store.activerModeDemo(e.message);
            router.push({ name: 'table', params: { groupe: code } });
        } else if (e.status === 404) {
            erreur.value = 'Code inconnu — vérifiez le code du groupe.';
        } else {
            erreur.value = e.message;
        }
    } finally {
        enCours.value = false;
    }
}
</script>

<template>
    <div class="narreur tex-vignette">
        <div class="narreur-wrap">
            <RouterLink to="/" class="narreur-back">
                <MSym n="arrow_back" :size="16" /> Retour à l'accueil
            </RouterLink>

            <div class="narreur-card">
                <div class="narreur-seal">
                    <MSym n="cast" fill />
                </div>
                <h1 class="narreur-title">Je suis le Narrateur</h1>
                <p class="narreur-sub">
                    Saisissez le code du groupe pour ouvrir l'écran de table.
                    La partie reste active tant que la table envoie son signal.
                </p>

                <form class="narreur-form" @submit.prevent="entrerTable">
                    <label class="narreur-label" for="codeTable">Code du groupe</label>
                    <input
                        id="codeTable"
                        v-model="codeBrut"
                        class="narreur-input"
                        placeholder="ex. AMBR-3K"
                        spellcheck="false"
                        autocomplete="off"
                        autofocus
                        @input="codeBrut = codeBrut.toUpperCase()"
                    />
                    <p v-if="erreur" class="narreur-err">
                        <MSym n="error" :size="15" /> {{ erreur }}
                    </p>
                    <button
                        class="narreur-btn"
                        type="submit"
                        :disabled="enCours || !codeBrut.trim()"
                    >
                        <MSym n="play_circle" fill />
                        {{ enCours ? 'Ouverture de la table…' : 'Ouvrir la table' }}
                    </button>
                </form>

                <div class="narreur-hint">
                    <MSym n="info" :size="14" />
                    Le code est créé quand un joueur forge une campagne. Il est visible dans l'écran de la manette.
                </div>
            </div>
        </div>
        <DemoBadge />
    </div>
</template>

<style>
.narreur { min-height: 100vh; background: var(--stone-950); color: var(--ink-100);
  display: grid; place-items: center; padding: 32px 20px; }

.narreur-wrap { width: 100%; max-width: 480px; display: flex; flex-direction: column; gap: 20px; }

.narreur-back { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 700;
  color: var(--ink-500); text-decoration: none; letter-spacing: 0.04em;
  transition: color .15s; }
.narreur-back:hover { color: var(--torch); }

.narreur-card { background: linear-gradient(180deg, var(--stone-850), var(--stone-900));
  border: var(--line); border-radius: var(--r-xl); padding: 36px 32px;
  display: flex; flex-direction: column; align-items: center; gap: 20px; text-align: center; }

.narreur-seal { width: 72px; height: 72px; border-radius: 20px; display: grid; place-items: center;
  background: linear-gradient(150deg, var(--ember), var(--ember-deep));
  color: var(--parch-100); box-shadow: var(--sh-2); }
.narreur-seal .msym { font-size: 40px; }

.narreur-title { font-family: var(--font-display); font-size: 28px; font-weight: 800;
  color: var(--parch-100); letter-spacing: 0.02em; margin: 0; }

.narreur-sub { font-family: var(--font-narr); font-style: italic; color: var(--ink-300);
  font-size: 16px; line-height: 1.5; margin: 0; max-width: 340px; }

.narreur-form { width: 100%; display: flex; flex-direction: column; align-items: center; gap: 14px; }

.narreur-label { font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase;
  color: var(--ink-500); font-weight: 700; align-self: flex-start; }

.narreur-input { width: 100%; box-sizing: border-box;
  background: var(--stone-850); border: 1px dashed var(--torch); border-radius: var(--r-md);
  padding: 14px 20px; color: var(--torch); font-family: var(--font-display); font-size: 26px;
  font-weight: 800; letter-spacing: 0.3em; text-align: center; outline: none; }
.narreur-input:focus { box-shadow: var(--glow-torch); border-style: solid; }
.narreur-input::placeholder { color: var(--ink-700); letter-spacing: 0.12em; }

.narreur-err { font-size: 13px; font-weight: 600; color: var(--danger, #c33);
  display: flex; align-items: center; gap: 6px; margin: 0; }

.narreur-btn { width: 100%; padding: 14px 20px; border: none; border-radius: var(--r-md);
  background: linear-gradient(180deg, var(--torch-bright), var(--torch)); color: var(--stone-950);
  font-family: var(--font-ui); font-weight: 800; font-size: 16px;
  cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px;
  box-shadow: var(--sh-2); transition: transform .1s, opacity .1s; }
.narreur-btn:hover:not(:disabled) { transform: translateY(-2px); }
.narreur-btn:active { transform: scale(0.98); }
.narreur-btn:disabled { opacity: 0.45; cursor: not-allowed; }

.narreur-hint { display: flex; align-items: flex-start; gap: 8px; font-size: 12px;
  color: var(--ink-600); line-height: 1.45; text-align: left; }
.narreur-hint .msym { flex: none; margin-top: 1px; color: var(--ink-500); }
</style>
