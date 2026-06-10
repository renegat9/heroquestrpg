<script setup>
// MONTÉE DE NIVEAU (joueur, portrait) — stub cohérent avec
// reference/heroquest/"Montee de niveau.html" (version Magicienne).
// Gains automatiques + choix d'un talent, puis sceau de confirmation.
import { ref } from 'vue';
import MSym from '../components/ui/MSym.vue';
import { LEVELUP_HERO, LEVELUP_GAINS, LEVELUP_TALENTS } from '../data/demo';

const props = defineProps({
    groupe: { type: String, required: true },
});

const hero = LEVELUP_HERO;
const gains = LEVELUP_GAINS;
const talents = LEVELUP_TALENTS;

const selected = ref(null);
const confirmed = ref(false);
const talentChoisi = () => talents.find((t) => t.k === selected.value);
</script>

<template>
    <div class="lvlup-screen stage">
        <div class="phone">
            <div class="screen">
                <!-- bannière -->
                <div class="banner">
                    <RouterLink class="home" to="/"><MSym n="arrow_back" :size="14" /> HUB</RouterLink>
                    <div class="lvlup">Montée de niveau</div>
                    <div class="crest-wrap">
                        <div class="crest-ring" />
                        <div class="crest"><MSym :n="hero.icon" fill /></div>
                        <div class="levelpill">Niv. {{ hero.to }}</div>
                    </div>
                    <h1>{{ hero.name }}</h1>
                    <div class="arc">
                        {{ hero.cls }} · <span style="color: var(--ink-500)">Niv. {{ hero.from }}</span>
                        <MSym n="east" /> <b>Niv. {{ hero.to }}</b>
                    </div>
                </div>

                <!-- corps -->
                <div class="body">
                    <div class="sect gold"><MSym n="auto_awesome" fill /> Gains automatiques</div>
                    <div class="gains">
                        <div v-for="(g, i) in gains" :key="i" class="gain">
                            <span class="ic" :class="g.kind"><MSym :n="g.ic" fill /></span>
                            <div><div class="gt">{{ g.t }}</div><div class="gd">{{ g.d }}</div></div>
                            <span class="delta"><span class="old">{{ g.from }}</span><MSym n="east" /><span class="new">{{ g.to }}</span></span>
                        </div>
                    </div>

                    <div class="sect">
                        <MSym n="hub" fill /> Choisis un talent
                        <span style="margin-left: auto; font-size: 11px; color: var(--ink-600); letter-spacing: 0; text-transform: none; font-weight: 600">1 sur 3</span>
                    </div>
                    <div class="talents">
                        <button
                            v-for="t in talents"
                            :key="t.k"
                            class="talent"
                            :class="{ sel: selected === t.k }"
                            @click="selected = t.k"
                        >
                            <span class="ti"><MSym :n="t.ic" fill /></span>
                            <div>
                                <div class="tt">{{ t.t }}</div>
                                <div class="td">{{ t.d }}</div>
                                <span v-if="t.el" class="el-tag" :class="'el-' + t.el"><MSym :n="t.eli" :size="12" /> {{ t.elt }}</span>
                            </div>
                            <span class="tcheck"><MSym n="check" fill /></span>
                        </button>
                    </div>
                </div>

                <!-- pied : validation -->
                <div class="foot">
                    <p class="hint" :style="selected ? 'color: var(--torch)' : ''">
                        {{ selected ? 'Talent choisi : ' + talentChoisi().t : 'Sélectionne un talent pour continuer' }}
                    </p>
                    <button class="btn btn-gold" :disabled="!selected" @click="confirmed = true">
                        <MSym n="verified" fill /> Valider la progression
                    </button>
                </div>

                <!-- sceau de confirmation -->
                <div v-if="confirmed" class="done-ov">
                    <div class="seal"><MSym n="verified" fill /></div>
                    <h2>Progression scellée</h2>
                    <p>{{ hero.done }}</p>
                    <RouterLink class="btn btn-gold" :to="{ name: 'manette', params: { groupe } }">
                        <MSym n="login" /> Reprendre la partie
                    </RouterLink>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
/* Stub porté de "Montee de niveau.html" — préfixé .lvlup-screen
   (le cadre téléphone .stage/.phone/.screen vient de manette.css). */
.lvlup-screen .banner { flex: none; position: relative; text-align: center; padding: 26px 18px 20px;
  background: linear-gradient(180deg, oklch(0.24 0.02 90 / 0.35), var(--stone-900)); border-bottom: var(--line-gold); }
.lvlup-screen .banner .home { position: absolute; top: 14px; left: 14px; color: var(--ink-500); text-decoration: none; font-size: 11px;
  font-weight: 700; letter-spacing: 0.08em; display: inline-flex; align-items: center; gap: 4px; z-index: 3; }
.lvlup-screen .banner .lvlup { font-size: 12px; letter-spacing: 0.34em; text-transform: uppercase; color: var(--gold); font-weight: 700; }
.lvlup-screen .crest-wrap { position: relative; width: 88px; height: 88px; margin: 14px auto 10px; }
.lvlup-screen .crest-ring { position: absolute; inset: -7px; border-radius: 50%; border: 2px dashed oklch(0.80 0.135 88 / 0.45);
  animation: lvlup-spin 14s linear infinite; }
@keyframes lvlup-spin { to { transform: rotate(360deg); } }
.lvlup-screen .crest { width: 88px; height: 88px; border-radius: 50%; display: grid; place-items: center;
  background: linear-gradient(150deg, var(--gold), var(--ember-deep)); color: var(--stone-950);
  box-shadow: 0 0 26px oklch(0.80 0.135 88 / 0.45); }
.lvlup-screen .crest .msym { font-size: 44px; }
.lvlup-screen .levelpill { position: absolute; bottom: -6px; left: 50%; transform: translateX(-50%); font-size: 11px; font-weight: 800;
  background: var(--gold); color: var(--stone-950); border-radius: 99px; padding: 3px 10px; white-space: nowrap; }
.lvlup-screen .banner h1 { font-family: var(--font-display); font-weight: 800; font-size: 26px; margin: 8px 0 2px; color: var(--parch-100); letter-spacing: 0.02em; }
.lvlup-screen .banner .arc { font-size: 14px; color: var(--ink-300); font-weight: 600; display: inline-flex; align-items: center; gap: 8px; }
.lvlup-screen .banner .arc b { color: var(--torch); }
.lvlup-screen .banner .arc .msym { font-size: 16px; color: var(--ink-500); }

.lvlup-screen .body { padding: 18px; }
.lvlup-screen .sect { font-family: var(--font-display); font-size: 13px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--ink-300);
  font-weight: 600; margin: 4px 0 12px; display: flex; align-items: center; gap: 8px; }
.lvlup-screen .sect.gold { color: var(--gold); }

.lvlup-screen .gains { display: flex; flex-direction: column; gap: 8px; margin-bottom: 22px; }
.lvlup-screen .gain { display: flex; align-items: center; gap: 13px; padding: 12px 14px; border-radius: var(--r-md);
  background: linear-gradient(180deg, oklch(0.24 0.02 90 / 0.4), var(--stone-850)); border: 1px solid oklch(0.62 0.08 80 / 0.4); }
.lvlup-screen .gain .ic { width: 40px; height: 40px; border-radius: 11px; display: grid; place-items: center; flex: none; }
.lvlup-screen .gain .ic.body { background: oklch(0.58 0.185 25 / 0.16); color: var(--body-bright); }
.lvlup-screen .gain .ic.mind { background: oklch(0.64 0.14 270 / 0.16); color: var(--mind-bright); }
.lvlup-screen .gain .ic.atk { background: oklch(0.76 0.155 65 / 0.16); color: var(--torch); }
.lvlup-screen .gain .gt { font-size: 14px; font-weight: 700; color: var(--parch-100); }
.lvlup-screen .gain .gd { font-size: 11.5px; color: var(--ink-500); margin-top: 1px; }
.lvlup-screen .gain .delta { margin-left: auto; display: inline-flex; align-items: center; gap: 5px; font-weight: 800; font-size: 14px; font-variant-numeric: tabular-nums; }
.lvlup-screen .gain .delta .old { color: var(--ink-700); }
.lvlup-screen .gain .delta .new { color: var(--gold); }
.lvlup-screen .gain .delta .msym { font-size: 15px; color: var(--ink-600); }

.lvlup-screen .talents { display: flex; flex-direction: column; gap: 10px; margin-bottom: 8px; }
.lvlup-screen .talent { position: relative; display: flex; align-items: flex-start; gap: 13px; padding: 15px; border-radius: var(--r-md); cursor: pointer;
  background: var(--stone-850); border: 1px solid var(--stone-700); transition: all .15s; text-align: left; width: 100%;
  font-family: var(--font-ui); -webkit-tap-highlight-color: transparent; }
.lvlup-screen .talent:active { transform: scale(0.99); }
.lvlup-screen .talent .ti { width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center; flex: none; background: var(--stone-800); color: var(--torch); }
.lvlup-screen .talent .ti .msym { font-size: 25px; }
.lvlup-screen .talent .tt { font-size: 15.5px; font-weight: 700; color: var(--parch-100); }
.lvlup-screen .talent .td { font-size: 12.5px; color: var(--ink-400); margin-top: 3px; line-height: 1.4; }
.lvlup-screen .talent .tcheck { position: absolute; top: 13px; right: 13px; width: 22px; height: 22px; border-radius: 50%;
  border: 2px solid var(--stone-600); display: grid; place-items: center; transition: all .15s; }
.lvlup-screen .talent .tcheck .msym { font-size: 14px; color: transparent; }
.lvlup-screen .talent.sel { border-color: var(--torch); background: oklch(0.76 0.155 65 / 0.10); box-shadow: var(--glow-torch); }
.lvlup-screen .talent.sel .ti { background: var(--torch); color: var(--stone-950); }
.lvlup-screen .talent.sel .tcheck { border-color: var(--torch); background: var(--torch); }
.lvlup-screen .talent.sel .tcheck .msym { color: var(--stone-950); }
.lvlup-screen .el-tag { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.04em; margin-top: 7px; padding: 2px 7px; border-radius: 99px; }

.lvlup-screen .foot { flex: none; padding: 14px 18px calc(16px + env(safe-area-inset-bottom)); border-top: var(--line);
  background: linear-gradient(180deg, var(--stone-900), var(--stone-850)); position: relative; z-index: 3; }
.lvlup-screen .foot .hint { font-size: 11.5px; color: var(--ink-500); text-align: center; margin: 0 0 10px; }
.lvlup-screen .btn-gold { background: linear-gradient(180deg, var(--gold), var(--ember-deep)); color: var(--stone-950); box-shadow: var(--sh-2);
  width: 100%; text-decoration: none; box-sizing: border-box; }

.lvlup-screen .done-ov { position: absolute; inset: 0; z-index: 60; background: oklch(0.16 0.012 255 / 0.92); backdrop-filter: blur(4px);
  display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 30px; animation: lvlup-fadein .25s ease; }
@keyframes lvlup-fadein { from { opacity: 0; } }
.lvlup-screen .done-ov .seal { width: 96px; height: 96px; border-radius: 50%; display: grid; place-items: center; margin-bottom: 20px;
  background: linear-gradient(150deg, var(--gold), var(--ember-deep)); color: var(--stone-950);
  box-shadow: 0 0 30px oklch(0.80 0.135 88 / 0.5); animation: lvlup-pop .4s cubic-bezier(.2, 1.5, .4, 1); }
@keyframes lvlup-pop { from { transform: scale(0.4); opacity: 0; } }
.lvlup-screen .done-ov .seal .msym { font-size: 50px; }
.lvlup-screen .done-ov h2 { font-family: var(--font-display); font-size: 24px; color: var(--parch-100); margin: 0 0 8px; letter-spacing: 0.02em; }
.lvlup-screen .done-ov p { font-family: var(--font-narr); font-style: italic; color: var(--ink-300); font-size: 15px; line-height: 1.5; max-width: 280px; margin: 0 0 24px; }
</style>
