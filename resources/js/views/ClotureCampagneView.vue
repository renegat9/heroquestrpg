<script setup>
// CLÔTURE DE CAMPAGNE (hôte, paysage) — stub cohérent avec
// reference/heroquest/"Cloture de campagne.html" : épilogue du MJ,
// statistiques de campagne et partage du butin entre les héros.
import { reactive } from 'vue';
import MSym from '../components/ui/MSym.vue';
import { CLOTURE_HEROES, CLOTURE_REWARDS } from '../data/demo';

const props = defineProps({
    groupe: { type: String, required: true },
});

/* attribution du butin (démo : pré-assignée comme dans la maquette) */
const assignments = reactive(CLOTURE_REWARDS.map((r) => r.to));
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
        <div class="stage-c tex-stone tex-vignette">
            <RouterLink class="home" to="/"><MSym n="arrow_back" :size="14" /> HUB</RouterLink>
            <div class="embers"><div v-for="(e, i) in embers" :key="i" class="ember" :style="e" /></div>

            <div class="head">
                <div class="crumb">Campagne achevée · 8 quêtes · La Crypte d'Ambre</div>
                <h1>La Lumière Revient</h1>
                <div class="laurel"><span class="ln" /><MSym n="military_tech" fill /><span class="ln r" /></div>
            </div>

            <div class="main">
                <!-- épilogue -->
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

                <!-- partage du butin -->
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
                                    :class="{ on: assignments[idx] === h.k }"
                                    @click="assignments[idx] = h.k"
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

            <div class="foot">
                <RouterLink class="btn btn-ghost" to="/"><MSym n="holiday_village" /> Retour à la ville</RouterLink>
                <RouterLink class="btn btn-gold" :to="{ name: 'direction' }"><MSym n="auto_stories" fill /> Nouvelle campagne</RouterLink>
            </div>
        </div>
    </div>
</template>

<style>
/* Stub porté de "Cloture de campagne.html" — préfixé .cloture. */
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

/* grille principale */
.cloture .main { position: relative; z-index: 2; display: grid; grid-template-columns: 1.1fr 1fr; gap: 24px; padding: 18px 40px; min-height: 0; }
@media (max-width: 1100px) { .cloture .main { grid-template-columns: 1fr; overflow-y: auto; } }

.cloture .epilogue { background: linear-gradient(180deg, oklch(0.22 0.014 255 / 0.9), oklch(0.17 0.012 255 / 0.92)); border: var(--line);
  border-left: 4px solid var(--gold); border-radius: var(--r-lg); padding: 26px 28px; display: flex; flex-direction: column; }
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

/* pied */
.cloture .foot { position: relative; z-index: 3; display: flex; align-items: center; gap: 16px; justify-content: center; padding: 18px 40px 26px; }
.cloture .btn { border: none; border-radius: var(--r-md); padding: 15px 28px; font-family: var(--font-ui); font-weight: 700; font-size: 16px;
  cursor: pointer; display: inline-flex; align-items: center; gap: 10px; transition: transform .1s; text-decoration: none; width: auto; }
.cloture .btn:active { transform: scale(0.98); }
.cloture .btn-gold { background: linear-gradient(180deg, var(--gold), var(--ember-deep)); color: var(--stone-950); box-shadow: var(--sh-2); }
.cloture .btn-ghost { background: var(--stone-800); color: var(--ink-100); border: var(--line-strong); }
</style>
