<script setup>
// ACCUEIL / HUB — porte d'entrée de la SPA, port de reference/heroquest/index.html.
// On y choisit son rôle (écran de table ou manette joueur) et le code du
// groupe à rejoindre ; les autres moments de campagne sont accessibles dessous.
import { computed, ref } from 'vue';
import MSym from '../components/ui/MSym.vue';
import { ROSTER } from '../data/demo';

/* Code du groupe : saisi ici puis injecté dans toutes les routes. */
const codeBrut = ref('AMBR-3K');
const code = computed(() => (codeBrut.value.trim() || 'AMBR-3K').toUpperCase());

const clients = computed(() => [
    {
        tag: 'Hôte · paysage', ic: 'cast', titre: 'Écran de table', go: 'Ouvrir',
        desc: "L'affichage partagé : carte, figurines, narration et résolution de combat. Contemplatif, lisible à distance.",
        to: { name: 'table', params: { groupe: code.value } },
    },
    {
        tag: 'Joueur · portrait', ic: 'smartphone', titre: 'Manette joueur', go: 'Ouvrir',
        desc: "Un appareil par joueur : fiche, menu d'action, sorts, sac, marché et votes. Tactile et rapide.",
        to: { name: 'manette', params: { groupe: code.value } },
    },
    {
        tag: 'Onboarding', ic: 'group_add', titre: 'Créer / rejoindre', go: 'Ouvrir',
        desc: 'Forger une campagne (ton, longueur) et partager un code, ou rejoindre une table en choisissant son héros.',
        to: { name: 'direction' },
    },
]);

const moments = computed(() => [
    {
        tag: 'Hôte · paysage', ic: 'map', titre: 'Choix de la quête', go: 'Ouvrir',
        desc: 'La carte de campagne ramifiée : le groupe vote pour sa prochaine descente parmi les voies ouvertes.',
        to: { name: 'selection-quete', params: { groupe: code.value } },
    },
    {
        tag: 'Joueur · portrait', ic: 'trending_up', titre: 'Montée de niveau', go: 'Ouvrir',
        desc: "Le moment de progression d'un héros : gains automatiques et choix d'un talent ou d'un nouveau sort.",
        to: { name: 'montee-niveau', params: { groupe: code.value } },
    },
    {
        tag: 'Joueur · portrait', ic: 'wifi_tethering', titre: 'Reconnexion', go: 'Ouvrir',
        desc: 'Reprise transparente après une coupure, et la règle « une seule session active par joueur ».',
        to: { name: 'reconnexion', params: { groupe: code.value } },
    },
    {
        tag: 'Hôte · finale', ic: 'workspace_premium', titre: 'Clôture de campagne', go: 'Ouvrir',
        desc: "L'épilogue du MJ, les statistiques de la campagne et le partage solennel du butin entre les héros.",
        to: { name: 'cloture', params: { groupe: code.value } },
    },
]);
</script>

<template>
    <div class="accueil tex-vignette" style="position: relative">
        <header class="hero tex-stone">
            <div class="inner">
                <div class="crumb">Ville de Pierregivre · Campagne en cours</div>
                <h1>Le Repaire des Héros</h1>
                <p class="sub">Entre deux quêtes, le groupe se rassemble : on panse les plaies, on marchande, on choisit la prochaine descente.</p>
                <div class="campaign-meta">
                    <div class="cm"><span class="ic"><MSym n="flag" fill /></span><div><div class="k">Progression</div><div class="v">Quête 3 / 8</div></div></div>
                    <div class="cm"><span class="ic"><MSym n="paid" fill /></span><div><div class="k">Or du groupe</div><div class="v gold">2 480</div></div></div>
                    <div class="cm"><span class="ic"><MSym n="groups" fill /></span><div><div class="k">Héros</div><div class="v">4 vivants</div></div></div>
                    <div class="cm"><span class="ic"><MSym n="graphic_eq" fill /></span><div><div class="k">Ton narratif</div><div class="v">Héroïque-sombre</div></div></div>
                </div>
            </div>
        </header>

        <div class="wrap">
            <div class="sec-title">Rejoindre la table — choisis ton rôle <span class="ln" /></div>
            <div class="code-row">
                <label class="code-label" for="codeGroupe">Code du groupe</label>
                <input id="codeGroupe" v-model="codeBrut" class="code-input" placeholder="ex. AMBR-3K" spellcheck="false" />
                <span class="code-hint">Le même code relie l'écran de table et les manettes.</span>
            </div>
            <div class="clients">
                <RouterLink v-for="c in clients" :key="c.titre" class="client" :to="c.to">
                    <span class="tagm">{{ c.tag }}</span>
                    <span class="bigic"><MSym :n="c.ic" fill /></span>
                    <div><h3>{{ c.titre }}</h3><p>{{ c.desc }}</p></div>
                    <span class="go">{{ c.go }} <MSym n="arrow_forward" /></span>
                </RouterLink>
            </div>

            <div class="sec-title">Moments de campagne <span class="ln" /></div>
            <div class="clients">
                <RouterLink v-for="c in moments" :key="c.titre" class="client" :to="c.to">
                    <span class="tagm">{{ c.tag }}</span>
                    <span class="bigic"><MSym :n="c.ic" fill /></span>
                    <div><h3>{{ c.titre }}</h3><p>{{ c.desc }}</p></div>
                    <span class="go">{{ c.go }} <MSym n="arrow_forward" /></span>
                </RouterLink>
            </div>

            <div class="sec-title">La ville <span class="ln" /></div>
            <div class="town">
                <!-- prochaine quête -->
                <div class="panel quest-card">
                    <div class="imgph"><span class="tag">illustration de quête</span></div>
                    <div class="difficulty"><MSym n="skull" fill :size="16" /> Difficulté : Périlleuse</div>
                    <h3 class="qn">Les Catacombes Noyées</h3>
                    <p class="qd">L'eau monte dans les galeries inférieures, et quelque chose d'ancien remue sous la surface. Le MJ vous attend.</p>
                    <RouterLink class="btn btn-torch btn-block" :to="{ name: 'table', params: { groupe: code } }">
                        <MSym n="play_arrow" /> Descendre dans la quête
                    </RouterLink>
                </div>

                <!-- marché -->
                <div class="panel">
                    <div class="place-head market">
                        <span class="ic"><MSym n="storefront" fill /></span>
                        <div><h3>Le Marché</h3><div class="sb">Achats, ventes, marchandage</div></div>
                    </div>
                    <div class="mini-item"><span class="mi"><MSym n="science" /></span><span class="mn">Potion de soin</span><span class="mp"><MSym n="paid" :size="14" />50</span></div>
                    <div class="mini-item"><span class="mi"><MSym n="description" /></span><span class="mn">Parchemin de feu</span><span class="mp"><MSym n="paid" :size="14" />120</span></div>
                    <div class="mini-item"><span class="mi" style="color: var(--rar-unique)"><MSym n="diamond" fill /></span><span class="mn">Amulette du Spectre</span><span class="mp"><MSym n="paid" :size="14" />900</span></div>
                    <RouterLink class="btn btn-ghost btn-block" :to="{ name: 'manette', params: { groupe: code } }" style="margin-top: 16px">
                        <MSym n="storefront" /> Entrer au marché
                    </RouterLink>
                </div>

                <!-- forge -->
                <div class="panel">
                    <div class="place-head forge">
                        <span class="ic"><MSym n="hardware" fill /></span>
                        <div><h3>La Forge</h3><div class="sb">Réservée au Nain</div></div>
                    </div>
                    <div class="mini-item"><span class="mi"><MSym n="build" /></span><span class="mn">Aiguiser la lame</span><span class="mp"><MSym n="paid" :size="14" />160</span></div>
                    <div class="mini-item"><span class="mi"><MSym n="build" /></span><span class="mn">Renforcer l'armure</span><span class="mp"><MSym n="paid" :size="14" />200</span></div>
                    <div class="mini-item"><span class="mi"><MSym n="build" /></span><span class="mn">Gravure runique</span><span class="mp"><MSym n="paid" :size="14" />340</span></div>
                    <RouterLink class="btn btn-ghost btn-block" :to="{ name: 'manette', params: { groupe: code } }" style="margin-top: 16px">
                        <MSym n="hardware" /> Ouvrir la forge
                    </RouterLink>
                </div>
            </div>

            <div class="sec-title">Roster du groupe <span class="ln" /></div>
            <div class="roster">
                <div v-for="r in ROSTER" :key="r.n" class="rcard">
                    <div class="av"><MSym :n="r.ic" fill /></div>
                    <div class="rn">{{ r.n }}</div>
                    <div class="rc">{{ r.c }}</div>
                    <div class="rstats">
                        <div>Niveau<b>{{ r.lvl }}</b></div>
                        <div>Or<b class="gold">{{ r.gold }}</b></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
/* Port de index.html — préfixé .accueil (le bundle CSS est partagé). */
.accueil { min-height: 100vh; background: var(--stone-950); color: var(--ink-100); }

.accueil .hero { position: relative; overflow: hidden; padding: 60px 40px 44px; }
.accueil .hero.tex-stone::before { content: ""; position: absolute; inset: 0;
  background: radial-gradient(60% 80% at 18% -10%, oklch(0.76 0.155 65 / 0.18), transparent 60%),
              radial-gradient(50% 60% at 92% 0%, oklch(0.62 0.17 42 / 0.14), transparent 60%); pointer-events: none; }
.accueil .hero .inner { max-width: 1180px; margin: 0 auto; position: relative; }
.accueil .crumb { font-size: 12px; letter-spacing: 0.3em; text-transform: uppercase; color: var(--ember); font-weight: 700; }
.accueil .hero h1 { font-family: var(--font-display); font-weight: 800; font-size: clamp(34px, 5vw, 60px); margin: 12px 0 0;
  letter-spacing: 0.02em; background: linear-gradient(180deg, var(--parch-100), var(--torch-bright) 140%);
  -webkit-background-clip: text; background-clip: text; color: transparent; }
.accueil .hero .sub { font-family: var(--font-narr); font-style: italic; color: var(--ink-300); font-size: 19px; margin: 14px 0 0; max-width: 560px; line-height: 1.5; }
.accueil .campaign-meta { display: flex; gap: 26px; margin-top: 26px; flex-wrap: wrap; }
.accueil .cm { display: flex; align-items: center; gap: 9px; }
.accueil .cm .ic { width: 40px; height: 40px; border-radius: 11px; display: grid; place-items: center; background: var(--stone-800); color: var(--torch); border: var(--line); }
.accueil .cm .k { font-size: 11px; color: var(--ink-500); text-transform: uppercase; letter-spacing: 0.08em; font-weight: 700; }
.accueil .cm .v { font-size: 17px; font-weight: 800; color: var(--parch-100); }
.accueil .cm .v.gold { color: var(--gold); }

.accueil .wrap { max-width: 1180px; margin: 0 auto; padding: 0 40px 80px; }
.accueil .sec-title { font-family: var(--font-display); font-size: 15px; letter-spacing: 0.1em; text-transform: uppercase;
  color: var(--ink-300); font-weight: 600; margin: 44px 0 16px; display: flex; align-items: center; gap: 10px; }
.accueil .sec-title .ln { flex: 1; height: 1px; background: var(--stone-700); }

/* saisie du code de groupe */
.accueil .code-row { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 16px; }
.accueil .code-label { font-size: 11px; letter-spacing: 0.1em; text-transform: uppercase; color: var(--ink-500); font-weight: 700; }
.accueil .code-input { background: var(--stone-850); border: 1px dashed var(--torch); border-radius: var(--r-md);
  padding: 10px 16px; color: var(--torch); font-family: var(--font-display); font-size: 20px; font-weight: 800;
  letter-spacing: 0.25em; text-transform: uppercase; text-align: center; outline: none; width: 200px; }
.accueil .code-input:focus { box-shadow: var(--glow-torch); }
.accueil .code-input::placeholder { color: var(--ink-700); letter-spacing: 0.1em; }
.accueil .code-hint { font-size: 12px; color: var(--ink-500); }

/* cartes de lancement */
.accueil .clients { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
@media (max-width: 900px) { .accueil .clients { grid-template-columns: 1fr; } }
.accueil .client { position: relative; overflow: hidden; border-radius: var(--r-lg); border: var(--line); text-decoration: none;
  background: linear-gradient(180deg, var(--stone-850), var(--stone-900)); padding: 24px; color: var(--ink-100);
  transition: transform .15s, border-color .15s, box-shadow .15s; display: flex; flex-direction: column; gap: 14px; min-height: 168px; }
.accueil .client:hover { transform: translateY(-3px); border-color: var(--torch); box-shadow: var(--glow-torch); }
.accueil .client .bigic { width: 56px; height: 56px; border-radius: 14px; display: grid; place-items: center; flex: none;
  background: linear-gradient(150deg, var(--ember), var(--ember-deep)); color: var(--parch-100); box-shadow: var(--sh-1); }
.accueil .client .bigic .msym { font-size: 32px; }
.accueil .client h3 { font-family: var(--font-display); font-size: 20px; margin: 0; color: var(--parch-100); letter-spacing: 0.02em; }
.accueil .client p { margin: 4px 0 0; font-size: 13.5px; color: var(--ink-500); line-height: 1.45; }
.accueil .client .go { margin-top: auto; display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 700; color: var(--torch); }
.accueil .client .tagm { position: absolute; top: 18px; right: 18px; font-size: 10px; font-weight: 700; letter-spacing: 0.08em;
  text-transform: uppercase; color: var(--ink-500); border: var(--line); border-radius: 99px; padding: 4px 9px; }

/* la ville */
.accueil .town { display: grid; grid-template-columns: 1.4fr 1fr 1fr; gap: 16px; }
@media (max-width: 900px) { .accueil .town { grid-template-columns: 1fr; } }
.accueil .panel { border-radius: var(--r-lg); border: var(--line); padding: 22px; background: linear-gradient(180deg, var(--stone-850), var(--stone-900)); }
.accueil .quest-card { position: relative; overflow: hidden; }
.accueil .quest-card .imgph { height: 130px; border-radius: var(--r-md); margin-bottom: 16px; position: relative; overflow: hidden;
  background: repeating-linear-gradient(45deg, var(--stone-800) 0 10px, var(--stone-850) 10px 20px); border: var(--line); display: grid; place-items: center; }
.accueil .imgph .tag { font-family: ui-monospace, monospace; font-size: 11px; color: var(--ink-500); background: oklch(0 0 0 / 0.4); padding: 4px 10px; border-radius: 99px; }
.accueil .quest-card .qn { font-family: var(--font-display); font-size: 22px; color: var(--parch-100); margin: 0 0 6px; letter-spacing: 0.02em; }
.accueil .quest-card .qd { font-family: var(--font-narr); font-style: italic; color: var(--ink-300); font-size: 15px; line-height: 1.5; margin: 0 0 16px; }
.accueil .difficulty { display: inline-flex; gap: 4px; align-items: center; font-size: 12px; color: var(--torch); font-weight: 700; margin-bottom: 16px; }
.accueil .btn { border: none; border-radius: var(--r-md); padding: 13px 20px; font-family: var(--font-ui); font-weight: 700; font-size: 15px;
  cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; transition: transform .1s; }
.accueil .btn:active { transform: scale(0.98); }
.accueil .btn-torch { background: linear-gradient(180deg, var(--torch-bright), var(--torch)); color: var(--stone-950); box-shadow: var(--sh-2); }
.accueil .btn-ghost { background: var(--stone-800); color: var(--ink-100); border: var(--line-strong); }
.accueil .btn-block { width: 100%; justify-content: center; box-sizing: border-box; }

.accueil .place-head { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
.accueil .place-head .ic { width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center; flex: none; }
.accueil .place-head.market .ic { background: oklch(0.66 0.150 245 / 0.16); color: var(--elem-water); }
.accueil .place-head.forge .ic { background: oklch(0.64 0.205 35 / 0.16); color: var(--elem-fire); }
.accueil .place-head h3 { font-family: var(--font-display); font-size: 18px; margin: 0; color: var(--parch-100); letter-spacing: 0.02em; }
.accueil .place-head .sb { font-size: 12px; color: var(--ink-500); }
.accueil .mini-item { display: flex; align-items: center; gap: 10px; padding: 9px 0; border-bottom: var(--line); }
.accueil .mini-item:last-of-type { border-bottom: none; }
.accueil .mini-item .mi { width: 30px; height: 30px; border-radius: 8px; background: var(--stone-800); display: grid; place-items: center; color: var(--torch); flex: none; }
.accueil .mini-item .mn { font-size: 13.5px; font-weight: 600; }
.accueil .mini-item .mp { margin-left: auto; font-weight: 800; color: var(--gold); font-size: 13px; display: flex; gap: 4px; align-items: center; }

/* roster (surclasse le .roster flex de manette.css) */
.accueil .roster { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 0; }
@media (max-width: 900px) { .accueil .roster { grid-template-columns: repeat(2, 1fr); } }
.accueil .rcard { border-radius: var(--r-lg); border: var(--line); padding: 18px; background: linear-gradient(180deg, var(--stone-850), var(--stone-900)); text-align: center; }
.accueil .rcard .av { width: 64px; height: 64px; border-radius: 16px; margin: 0 auto 12px; display: grid; place-items: center;
  background: repeating-linear-gradient(45deg, var(--stone-800) 0 8px, var(--stone-850) 8px 16px); border: var(--line-strong); }
.accueil .rcard .av .msym { font-size: 34px; color: var(--ember); }
.accueil .rcard .rn { font-family: var(--font-display); font-size: 15px; color: var(--parch-100); }
.accueil .rcard .rc { font-size: 11px; color: var(--ink-500); font-weight: 600; margin-top: 2px; }
.accueil .rcard .rstats { display: flex; justify-content: center; gap: 14px; margin-top: 12px; padding-top: 12px; border-top: var(--line); }
.accueil .rcard .rstats > div { font-size: 11px; color: var(--ink-500); }
.accueil .rcard .rstats b { display: block; font-size: 15px; color: var(--parch-100); font-weight: 800; margin-top: 1px; }
.accueil .rcard .gold { color: var(--gold) !important; }
</style>
