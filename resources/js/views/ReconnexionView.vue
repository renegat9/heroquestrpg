<script setup>
// RECONNEXION (joueur, portrait) — stub cohérent avec
// reference/heroquest/Reconnexion.html : la lanterne vacille, les étapes
// se valident, puis la carte de reprise de session apparaît.
import { computed, onMounted, onUnmounted, ref } from 'vue';
import MSym from '../components/ui/MSym.vue';

const props = defineProps({
    groupe: { type: String, required: true },
});

/* 0 = lien serveur ok, 1 = synchro en cours, 2 = héros restauré, 3 = prêt */
const phase = ref(1);
const done = computed(() => phase.value >= 3);

const steps = [
    { ic: 'link', label: 'Lien au serveur de la table' },
    { ic: 'progress_activity', label: "Synchronisation de l'état de jeu" },
    { ic: 'person', label: 'Restauration de ton héros' },
];

const timers = [];
onMounted(() => {
    // Démo : la vraie reprise interrogera l'API (etatGroupe) puis se
    // réabonnera aux canaux Echo avant d'afficher la carte de reprise.
    timers.push(setTimeout(() => { phase.value = 2; }, 1500));
    timers.push(setTimeout(() => { phase.value = 3; }, 3000));
});
onUnmounted(() => timers.forEach(clearTimeout));
</script>

<template>
    <div class="reconnect stage">
        <div class="phone">
            <div class="screen tex-stone">
                <div class="top">
                    <RouterLink class="home" to="/"><MSym n="arrow_back" :size="14" /> HUB</RouterLink>
                </div>

                <div class="core">
                    <!-- lanterne -->
                    <div class="lantern" :class="done ? 'ok' : 'flicker'">
                        <div class="ring" :class="{ spin: !done }" />
                        <div class="core-ic"><MSym :n="done ? 'check' : 'wifi_tethering'" fill /></div>
                    </div>
                    <h1>{{ done ? 'Te revoilà !' : 'Reconnexion…' }}</h1>
                    <p class="sub">
                        {{ done ? "La table t'a gardé ta place. Rien n'a été perdu." : 'La lanterne vacille — on rétablit ton lien avec la table.' }}
                    </p>

                    <!-- étapes -->
                    <div class="steps">
                        <div
                            v-for="(s, i) in steps"
                            :key="i"
                            class="step"
                            :class="{ done: phase > i, active: phase === i }"
                        >
                            <span class="si"><MSym :n="phase > i ? 'check' : s.ic" :fill="phase > i" /></span>
                            <span class="st">{{ s.label }}</span>
                        </div>
                    </div>

                    <!-- reprise de session -->
                    <div class="resume" :class="{ show: done }">
                        <div class="resume-card">
                            <div class="rl">Reprise de session</div>
                            <div class="rrow">
                                <span class="ic"><MSym n="auto_fix_high" fill /></span>
                                <div><div class="rt">Ton héros</div><div class="rv">Eldra Sombrelune · Magicienne</div></div>
                            </div>
                            <div class="rrow">
                                <span class="ic"><MSym n="castle" fill /></span>
                                <div><div class="rt">Quête en cours</div><div class="rv">Le Seuil des Ombres</div></div>
                            </div>
                            <div class="rrow">
                                <span class="ic"><MSym n="bolt" fill /></span>
                                <div><div class="rt">État</div><div class="rv turn">À toi de jouer — tour 7</div></div>
                            </div>
                        </div>
                        <RouterLink class="btn btn-torch btn-resume" :to="{ name: 'manette', params: { groupe } }">
                            <MSym n="login" fill /> Reprendre la partie
                        </RouterLink>
                    </div>
                </div>

                <div class="foot" :class="{ ok: done }">
                    <span class="dot" />
                    <span>{{ done ? 'Connecté — session restaurée' : 'Connexion instable — reprise automatique' }}</span>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
/* Stub porté de Reconnexion.html — préfixé .reconnect
   (le cadre téléphone .stage/.phone/.screen vient de manette.css). */
.reconnect .screen.tex-stone::before { content: ""; position: absolute; inset: 0;
  background: radial-gradient(70% 50% at 50% 0%, oklch(0.76 0.155 65 / 0.10), transparent 60%); pointer-events: none; }

.reconnect .top { flex: none; padding: 18px 18px 0; display: flex; align-items: center; }
.reconnect .home { color: var(--ink-500); text-decoration: none; font-size: 11px; font-weight: 700; letter-spacing: 0.08em;
  display: inline-flex; align-items: center; gap: 4px; }

.reconnect .core { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
  padding: 24px 28px; text-align: center; position: relative; z-index: 1; overflow-y: auto; }

.reconnect .lantern { width: 120px; height: 120px; border-radius: 50%; display: grid; place-items: center; margin-bottom: 28px; position: relative; flex: none; }
.reconnect .lantern .ring { position: absolute; inset: 0; border-radius: 50%; border: 2px solid oklch(0.76 0.155 65 / 0.25); }
.reconnect .lantern .ring.spin { border-top-color: var(--torch); animation: reconnect-spin 1.1s linear infinite; }
@keyframes reconnect-spin { to { transform: rotate(360deg); } }
.reconnect .lantern .core-ic { width: 80px; height: 80px; border-radius: 50%; display: grid; place-items: center;
  background: linear-gradient(150deg, var(--ember), var(--ember-deep)); color: var(--parch-100); box-shadow: var(--glow-torch); transition: all .4s; }
.reconnect .lantern .core-ic .msym { font-size: 42px; }
.reconnect .lantern.ok .core-ic { background: linear-gradient(150deg, var(--ok), oklch(0.5 0.12 150));
  box-shadow: 0 0 0 1px var(--ok), 0 0 24px oklch(0.7 0.14 150 / 0.5); }
.reconnect .lantern.flicker .core-ic { animation: reconnect-flick 0.9s ease-in-out infinite; }
@keyframes reconnect-flick { 0%, 100% { opacity: 1; } 50% { opacity: 0.55; } }

.reconnect .core h1 { font-family: var(--font-display); font-size: 26px; color: var(--parch-100); margin: 0 0 8px; letter-spacing: 0.02em; }
.reconnect .core .sub { font-family: var(--font-narr); font-style: italic; font-size: 16px; color: var(--ink-300); line-height: 1.5; margin: 0 0 6px; max-width: 300px; }

.reconnect .steps { width: 100%; max-width: 300px; margin: 24px 0 0; display: flex; flex-direction: column; gap: 10px; }
.reconnect .step { display: flex; align-items: center; gap: 12px; padding: 11px 14px; border-radius: var(--r-md);
  background: var(--stone-850); border: var(--line); text-align: left; transition: all .3s; }
.reconnect .step .si { width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; flex: none;
  background: var(--stone-800); color: var(--ink-500); border: var(--line); }
.reconnect .step .si .msym { font-size: 16px; }
.reconnect .step .st { font-size: 13px; font-weight: 600; color: var(--ink-400); }
.reconnect .step.done { border-color: oklch(0.7 0.14 150 / 0.4); }
.reconnect .step.done .si { background: var(--ok); color: var(--stone-950); border-color: var(--ok); }
.reconnect .step.done .st { color: var(--ink-200); }
.reconnect .step.active { border-color: var(--torch); box-shadow: var(--glow-torch); }
.reconnect .step.active .si { background: var(--torch); color: var(--stone-950); border-color: var(--torch); }
.reconnect .step.active .si .msym { animation: reconnect-spin 1s linear infinite; }
.reconnect .step.active .st { color: var(--parch-100); }

.reconnect .resume { width: 100%; max-width: 320px; margin-top: 18px; opacity: 0; transform: translateY(10px); transition: all .4s; visibility: hidden; }
.reconnect .resume.show { opacity: 1; transform: translateY(0); visibility: visible; }
.reconnect .resume-card { background: linear-gradient(180deg, var(--stone-850), var(--stone-900)); border: var(--line-strong);
  border-radius: var(--r-lg); padding: 18px; text-align: left; margin-bottom: 14px; }
.reconnect .resume-card .rl { font-size: 11px; letter-spacing: 0.1em; text-transform: uppercase; color: var(--ink-500); font-weight: 700; margin-bottom: 12px; }
.reconnect .rrow { display: flex; align-items: center; gap: 11px; margin-bottom: 11px; }
.reconnect .rrow:last-child { margin-bottom: 0; }
.reconnect .rrow .ic { width: 36px; height: 36px; border-radius: 10px; display: grid; place-items: center; flex: none; background: var(--stone-800); color: var(--torch); }
.reconnect .rrow .rt { font-size: 11px; color: var(--ink-500); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
.reconnect .rrow .rv { font-size: 14.5px; font-weight: 700; color: var(--parch-100); }
.reconnect .rrow .rv.turn { color: var(--torch); }
.reconnect .btn-resume { width: 100%; text-decoration: none; box-sizing: border-box; }

.reconnect .foot { flex: none; padding: 14px 18px calc(14px + env(safe-area-inset-bottom)); border-top: var(--line);
  display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 11.5px; color: var(--ink-500); font-weight: 600; }
.reconnect .foot .dot { width: 7px; height: 7px; border-radius: 50%; background: var(--warn); box-shadow: 0 0 6px var(--warn); transition: all .3s; }
.reconnect .foot.ok .dot { background: var(--ok); box-shadow: 0 0 6px var(--ok); }
</style>
