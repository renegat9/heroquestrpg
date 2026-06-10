<script setup>
// CRÉER / REJOINDRE UN GROUPE — port de reference/heroquest/"Creer un groupe.html".
// Onglet « Créer » : nom, ton narratif, longueur de campagne → code de groupe.
// Onglet « Rejoindre » : code + héros + nom de joueur.
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import MSym from '../components/ui/MSym.vue';
import { useApi } from '../composables/useApi';

const router = useRouter();
const api = useApi();

const mode = ref('create'); // 'create' | 'join'

/* ---- création ---- */
const campName = ref("La Crypte d'Ambre");
const tone = ref('hero'); // hero | dark | comic
const len = ref('moyen'); // court | moyen | long
const tones = [
    { v: 'hero', ic: 'military_tech', l: 'Héroïque', sub: 'flamboyant' },
    { v: 'dark', ic: 'dark_mode', l: 'Sombre', sub: 'oppressant' },
    { v: 'comic', ic: 'mood', l: 'Comique', sub: 'décalé' },
];
const lengths = [
    { v: 'court', ic: 'bolt', l: 'Court', sub: '3 quêtes' },
    { v: 'moyen', ic: 'route', l: 'Moyen', sub: '8 quêtes' },
    { v: 'long', ic: 'explore', l: 'Épique', sub: '15+ quêtes' },
];

/* code dérivé du nom + du ton (comme la maquette) */
const code = computed(() => {
    const n = (campName.value || 'CAMP').replace(/[^A-Za-zÀ-ÿ]/g, '').toUpperCase().slice(0, 4) || 'CAMP';
    const map = { hero: '3K', dark: '9X', comic: '7Q' };
    return `${n}-${map[tone.value] || '3K'}`;
});

async function forger() {
    // POINT D'INTÉGRATION API : création de la campagne par le moteur.
    // Tant que l'API n'existe pas, on continue en démo vers l'écran de table.
    try {
        await api.creerGroupe({ nom: campName.value, ton: tone.value, longueur: len.value, code: code.value });
    } catch {
        /* mode démo : non bloquant */
    }
    router.push({ name: 'table', params: { groupe: code.value } });
}

/* ---- rejoindre ---- */
const joinCode = ref('');
const heroPick = ref(null);
const playerName = ref('');
const heroes = [
    { v: 'barb', ic: 'sports_martial_arts', l: 'Barbare', taken: false },
    { v: 'mage', ic: 'auto_fix_high', l: 'Magicien', taken: false },
    { v: 'dwarf', ic: 'construction', l: 'Nain', taken: true },
    { v: 'elf', ic: 'park', l: 'Elfe', taken: false },
];
const canJoin = computed(() =>
    joinCode.value.trim().length >= 4 && !!heroPick.value && playerName.value.trim().length > 0);

async function rejoindre() {
    const groupe = joinCode.value.trim().toUpperCase();
    // POINT D'INTÉGRATION API : inscription du joueur dans le groupe.
    try {
        await api.rejoindreGroupe(groupe, { heros: heroPick.value, joueur: playerName.value.trim() });
    } catch {
        /* mode démo : non bloquant */
    }
    router.push({ name: 'manette', params: { groupe } });
}
</script>

<template>
    <div class="creation">
        <div class="card">
            <div class="card-head tex-stone">
                <RouterLink class="home" to="/"><MSym n="arrow_back" :size="14" /> HUB</RouterLink>
                <div class="seal"><MSym n="groups" fill /></div>
                <div class="ep">La Lanterne du Donjon</div>
                <h1>{{ mode === 'create' ? 'Rassembler un groupe' : 'Rejoindre une table' }}</h1>
            </div>

            <div class="tabs">
                <button :class="{ on: mode === 'create' }" @click="mode = 'create'"><MSym n="add_circle" /> Créer</button>
                <button :class="{ on: mode === 'join' }" @click="mode = 'join'"><MSym n="login" /> Rejoindre</button>
            </div>

            <div class="card-body">
                <!-- CRÉER -->
                <template v-if="mode === 'create'">
                    <div class="field">
                        <label for="campName">Nom de la campagne</label>
                        <input id="campName" v-model="campName" class="input" />
                    </div>
                    <div class="field">
                        <label>Ton narratif</label>
                        <div class="seg">
                            <button v-for="t in tones" :key="t.v" :class="{ on: tone === t.v }" @click="tone = t.v">
                                <MSym :n="t.ic" fill />{{ t.l }}<span class="sub">{{ t.sub }}</span>
                            </button>
                        </div>
                    </div>
                    <div class="field">
                        <label>Longueur de campagne</label>
                        <div class="seg">
                            <button v-for="l in lengths" :key="l.v" :class="{ on: len === l.v }" @click="len = l.v">
                                <MSym :n="l.ic" fill />{{ l.l }}<span class="sub">{{ l.sub }}</span>
                            </button>
                        </div>
                    </div>
                    <div class="code-display">
                        <div class="k">Code du groupe — à partager</div>
                        <div class="code">{{ code }}</div>
                    </div>
                    <button class="btn btn-torch" @click="forger"><MSym n="auto_stories" /> Forger la campagne</button>
                    <p class="note">Le maître de jeu IA générera le donjon et la première quête selon le ton choisi.</p>
                </template>

                <!-- REJOINDRE -->
                <template v-else>
                    <div class="field">
                        <label for="joinCode">Code du groupe</label>
                        <input
                            id="joinCode"
                            v-model="joinCode"
                            class="input input-code"
                            placeholder="ex. AMBR-3K"
                            spellcheck="false"
                        />
                    </div>
                    <div class="field">
                        <label>Choisis ton héros</label>
                        <div class="heroes">
                            <div
                                v-for="h in heroes"
                                :key="h.v"
                                class="h"
                                :class="{ on: heroPick === h.v, taken: h.taken }"
                                @click="!h.taken && (heroPick = h.v)"
                            >
                                <MSym :n="h.ic" fill /><span class="hn">{{ h.l }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label for="playerName">Ton nom de joueur</label>
                        <input id="playerName" v-model="playerName" class="input" placeholder="Comment t'appellent tes compagnons ?" />
                    </div>
                    <button class="btn btn-torch" :disabled="!canJoin" @click="rejoindre"><MSym n="login" /> Rejoindre la table</button>
                    <p class="note">Une seule session active par joueur. Une nouvelle connexion remplace l'ancienne.</p>
                </template>
            </div>
        </div>
    </div>
</template>

<style>
/* Port de "Creer un groupe.html" — préfixé .creation. */
.creation { min-height: 100vh; display: grid; place-items: center; padding: 28px; background: var(--stone-950); --ambiance: 0.62; }

.creation .card { width: 100%; max-width: 540px; position: relative; border-radius: var(--r-xl); overflow: hidden;
  border: var(--line-strong); box-shadow: var(--sh-3); }
.creation .card-head { position: relative; overflow: hidden; padding: 34px 32px 26px; text-align: center; }
.creation .card-head.tex-stone::before { content: ""; position: absolute; inset: 0;
  background: radial-gradient(70% 90% at 50% -20%, oklch(0.76 0.155 65 / 0.22), transparent 62%); pointer-events: none; }
.creation .home { position: absolute; top: 16px; left: 16px; color: var(--ink-500); text-decoration: none; font-size: 11px; font-weight: 700;
  letter-spacing: 0.08em; display: inline-flex; align-items: center; gap: 4px; z-index: 2; }
.creation .seal { width: 64px; height: 64px; border-radius: 50%; margin: 0 auto 16px; display: grid; place-items: center;
  background: linear-gradient(150deg, var(--ember), var(--ember-deep)); color: var(--parch-100); box-shadow: var(--glow-torch); position: relative; z-index: 1; }
.creation .seal .msym { font-size: 34px; }
.creation .card-head .ep { font-size: 11px; letter-spacing: 0.3em; text-transform: uppercase; color: var(--ember); font-weight: 700; }
.creation .card-head h1 { font-family: var(--font-display); font-weight: 800; font-size: 30px; margin: 8px 0 0; color: var(--parch-100);
  letter-spacing: 0.02em; position: relative; z-index: 1; }

.creation .tabs { display: flex; background: var(--stone-850); border-top: var(--line); border-bottom: var(--line); }
.creation .tabs button { flex: 1; background: none; border: none; cursor: pointer; padding: 15px; font-family: var(--font-ui); font-weight: 700;
  font-size: 15px; color: var(--ink-500); display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  border-bottom: 2px solid transparent; border-radius: 0; transition: color .15s, border-color .15s; }
.creation .tabs button.on { color: var(--torch); border-bottom-color: var(--torch); }

.creation .card-body { background: linear-gradient(180deg, var(--stone-900), var(--stone-950)); padding: 26px 32px 30px; }
.creation .field { margin-bottom: 20px; }
.creation .field > label { display: block; font-size: 11px; letter-spacing: 0.1em; text-transform: uppercase; color: var(--ink-500); font-weight: 700; margin-bottom: 9px; }
.creation .input { width: 100%; box-sizing: border-box; background: var(--stone-850); border: var(--line-strong); border-radius: var(--r-md); padding: 13px 15px;
  color: var(--parch-100); font-family: var(--font-ui); font-size: 16px; outline: none; transition: border-color .15s, box-shadow .15s; }
.creation .input:focus { border-color: var(--torch); box-shadow: var(--glow-torch); }
.creation .input::placeholder { color: var(--ink-700); }
.creation .input-code { text-transform: uppercase; letter-spacing: 0.2em; text-align: center; font-family: var(--font-display); font-size: 22px; }

.creation .seg { display: flex; gap: 8px; }
.creation .seg button { flex: 1; background: var(--stone-850); border: var(--line-strong); border-radius: var(--r-md); padding: 12px 8px;
  cursor: pointer; font-family: var(--font-ui); font-weight: 700; font-size: 13.5px; color: var(--ink-300);
  display: flex; flex-direction: column; align-items: center; gap: 6px; transition: all .15s; }
.creation .seg button .msym { font-size: 22px; color: var(--ink-500); }
.creation .seg button .sub { font-size: 10.5px; font-weight: 600; color: var(--ink-700); }
.creation .seg button.on { border-color: var(--torch); background: oklch(0.76 0.155 65 / 0.12); color: var(--torch); }
.creation .seg button.on .msym { color: var(--torch); }

.creation .heroes { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
.creation .heroes .h { background: var(--stone-850); border: var(--line-strong); border-radius: var(--r-md); padding: 12px 6px; cursor: pointer;
  display: flex; flex-direction: column; align-items: center; gap: 6px; transition: all .15s; position: relative; }
.creation .heroes .h .msym { font-size: 26px; color: var(--ink-300); }
.creation .heroes .h .hn { font-size: 11px; font-weight: 700; color: var(--ink-500); }
.creation .heroes .h.on { border-color: var(--torch); background: oklch(0.76 0.155 65 / 0.12); }
.creation .heroes .h.on .msym, .creation .heroes .h.on .hn { color: var(--torch); }
.creation .heroes .h.taken { opacity: 0.4; pointer-events: none; }
.creation .heroes .h.taken::after { content: "pris"; position: absolute; top: 5px; right: 5px; font-size: 8px; color: var(--ink-500); }

.creation .btn { border: none; border-radius: var(--r-md); padding: 15px; font-family: var(--font-ui); font-weight: 700; font-size: 16px;
  cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 9px; width: 100%; transition: transform .1s; }
.creation .btn:active { transform: scale(0.98); }
.creation .btn:disabled { opacity: 0.4; pointer-events: none; }
.creation .btn-torch { background: linear-gradient(180deg, var(--torch-bright), var(--torch)); color: var(--stone-950); box-shadow: var(--sh-2); }

.creation .code-display { background: var(--stone-850); border: 1px dashed var(--torch); border-radius: var(--r-md); padding: 16px;
  text-align: center; margin-bottom: 20px; }
.creation .code-display .k { font-size: 11px; letter-spacing: 0.1em; text-transform: uppercase; color: var(--ink-500); font-weight: 700; }
.creation .code-display .code { font-family: var(--font-display); font-size: 30px; font-weight: 800; color: var(--torch); letter-spacing: 0.3em; margin-top: 6px; }

.creation .note { font-size: 12px; color: var(--ink-500); text-align: center; margin-top: 14px; line-height: 1.5; }
</style>
