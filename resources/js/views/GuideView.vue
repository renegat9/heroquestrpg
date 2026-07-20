<script setup>
// GUIDE / COMPENDIUM (public, accessible depuis l'accueil) — données de
// référence en lecture seule : classes de héros + talents, bestiaire,
// équipements, sorts, pièges. Source : GET /api/guide (catalogues seedés).
// Les effets mécaniques sont traduits en libellés lisibles via compendium.js.
import { computed, onMounted, ref } from 'vue';
import MSym from '../components/ui/MSym.vue';
import { useApi } from '../composables/useApi';
import {
    capacitesVersChips,
    CATEGORIE_OBJET,
    CLASSE,
    DESARMABLE,
    effetVersChips,
    ELEMENT,
    EMPLACEMENT,
    RARETE,
    TIER_MONSTRE,
    TYPE_SORT,
    TYPE_TALENT,
} from '../compendium';

const api = useApi();
const guide = ref(null);
const pret = ref(false);
const erreur = ref('');
const tab = ref('heros');

const ONGLETS = [
    ['heros', 'shield_person', 'Héros'],
    ['bestiaire', 'skull', 'Bestiaire'],
    ['equipement', 'inventory_2', 'Équipements'],
    ['sorts', 'auto_awesome', 'Sorts'],
    ['pieges', 'crisis_alert', 'Pièges'],
];

async function charger() {
    pret.value = false;
    erreur.value = '';
    try {
        guide.value = await api.getGuide();
    } catch (e) {
        erreur.value = e.message || 'Chargement impossible.';
    } finally {
        pret.value = true;
    }
}
onMounted(charger);

/* ---- regroupements ---- */
const classes = computed(() => guide.value?.classes ?? []);
const talentsParClasse = computed(() => {
    const m = {};
    for (const c of guide.value?.competences ?? []) (m[c.classe] ??= []).push(c);
    return m;
});
const monstresParTier = computed(() => {
    const ordre = ['base', 'sous_boss', 'boss'];
    const m = {};
    for (const x of guide.value?.monstres ?? []) (m[x.tier] ??= []).push(x);
    return ordre.filter((t) => m[t]).map((t) => [t, m[t]]);
});
const objetsParCategorie = computed(() => {
    const ordre = ['arme', 'armure', 'outil', 'consommable', 'parchemin'];
    const m = {};
    for (const x of guide.value?.objets ?? []) (m[x.categorie] ??= []).push(x);
    return ordre.filter((c) => m[c]).map((c) => [c, m[c]]);
});
const sortsParElement = computed(() => {
    const ordre = ['feu', 'eau', 'terre', 'air'];
    const m = {};
    for (const x of guide.value?.sorts ?? []) (m[x.element] ??= []).push(x);
    return ordre.filter((e) => m[e]).map((e) => [e, m[e]]);
});
const pieges = computed(() => guide.value?.pieges ?? []);

const nomClasse = (c) => CLASSE[c]?.l ?? c;
</script>

<template>
    <div class="guide-screen tex-vignette">
        <header class="guide-top">
            <RouterLink to="/" class="guide-back"><MSym n="arrow_back" :size="16" /> Accueil</RouterLink>
            <div class="guide-titrewrap">
                <div class="guide-crest"><MSym n="menu_book" fill /></div>
                <div>
                    <h1 class="guide-titre">Guide de jeu</h1>
                    <p class="guide-sub">Bestiaire, talents, équipements, sorts et pièges — les règles en un coup d'œil.</p>
                </div>
            </div>
        </header>

        <!-- onglets -->
        <nav class="guide-tabs">
            <button
                v-for="[id, ic, lbl] in ONGLETS"
                :key="id"
                class="guide-tab"
                :class="{ on: tab === id }"
                type="button"
                @click="tab = id"
            >
                <MSym :n="ic" :size="18" :fill="tab === id" /> {{ lbl }}
            </button>
        </nav>

        <!-- états -->
        <div v-if="!pret" class="guide-note"><MSym n="hourglass_top" :size="26" /><p>Consultation des archives…</p></div>
        <div v-else-if="erreur" class="guide-note err">
            <MSym n="error" fill :size="26" /><p>{{ erreur }}</p>
            <button class="guide-retry" type="button" @click="charger"><MSym n="refresh" :size="16" /> Réessayer</button>
        </div>

        <main v-else class="guide-body">
            <!-- ===================== HÉROS ===================== -->
            <section v-show="tab === 'heros'" class="guide-sec">
                <article v-for="c in classes" :key="c.nom" class="hero-card">
                    <div class="hero-head">
                        <div class="hero-seal"><MSym :n="CLASSE[c.nom]?.ic ?? 'person'" fill /></div>
                        <h2>{{ nomClasse(c.nom) }}</h2>
                    </div>
                    <div class="stat-row">
                        <span class="stat" title="PV de Body"><MSym n="favorite" fill :size="15" class="c-body" /> {{ c.pv_body }} <em>Body</em></span>
                        <span class="stat" title="PV de Mind"><MSym n="psychology" fill :size="15" class="c-mind" /> {{ c.pv_mind }} <em>Mind</em></span>
                        <span class="stat" title="Attribut Body"><MSym n="fitness_center" :size="15" /> {{ c.attr_body }} <em>attr. Body</em></span>
                        <span class="stat" title="Attribut Mind"><MSym n="neurology" :size="15" /> {{ c.attr_mind }} <em>attr. Mind</em></span>
                        <span class="stat" title="Dés d'attaque"><MSym n="swords" :size="15" class="c-atk" /> {{ c.des_attaque }} <em>attaque</em></span>
                        <span class="stat" title="Dés de défense"><MSym n="shield" :size="15" class="c-def" /> {{ c.des_defense }} <em>défense</em></span>
                        <span class="stat" title="Déplacement de base"><MSym n="directions_walk" :size="15" /> {{ c.deplacement_base }} <em>dépl.</em></span>
                        <span v-if="c.bonus_sac" class="stat" title="Bonus de sac"><MSym n="backpack" :size="15" /> +{{ c.bonus_sac }} <em>sac</em></span>
                    </div>
                    <div class="hero-talents-t"><MSym n="hub" :size="14" /> Arbre de talents</div>
                    <ul class="talent-ul">
                        <li v-for="t in talentsParClasse[c.nom] ?? []" :key="t.id" class="talent-li">
                            <div class="tl-head">
                                <span class="tl-nom">{{ t.nom }}</span>
                                <span class="tl-type" :class="'tt-' + t.type">{{ TYPE_TALENT[t.type] ?? t.type }}</span>
                                <span v-if="t.prerequis_id" class="tl-prereq"><MSym n="lock" :size="11" /> prérequis</span>
                            </div>
                            <div v-if="t.description" class="tl-desc">{{ t.description }}</div>
                        </li>
                    </ul>
                </article>
            </section>

            <!-- ===================== BESTIAIRE ===================== -->
            <section v-show="tab === 'bestiaire'" class="guide-sec">
                <template v-for="[tier, liste] in monstresParTier" :key="tier">
                    <h3 class="grp-title"><MSym n="skull" :size="16" /> {{ TIER_MONSTRE[tier] ?? tier }} <span class="grp-n">{{ liste.length }}</span></h3>
                    <div class="card-grid">
                        <article v-for="m in liste" :key="m.nom_base" class="ent-card" :class="'tier-' + tier">
                            <div class="ent-head">
                                <h4>{{ m.nom_base }}</h4>
                                <span class="cout" title="Coût en budget de rencontre"><MSym n="toll" :size="13" /> {{ m.cout }}</span>
                            </div>
                            <div class="stat-row sm">
                                <span class="stat"><MSym n="favorite" fill :size="14" class="c-body" /> {{ m.pv_body }} <em>Body</em></span>
                                <span class="stat"><MSym n="psychology" fill :size="14" class="c-mind" /> {{ m.pv_mind }} <em>Mind</em></span>
                                <span class="stat"><MSym n="swords" :size="14" class="c-atk" /> {{ m.attaque }} <em>att.</em></span>
                                <span class="stat"><MSym n="shield" :size="14" class="c-def" /> {{ m.defense }} <em>déf.</em></span>
                                <span class="stat"><MSym n="directions_walk" :size="14" /> {{ m.deplacement }} <em>dépl.</em></span>
                            </div>
                            <div v-if="capacitesVersChips(m.capacites).length" class="chips">
                                <span v-for="(ch, i) in capacitesVersChips(m.capacites)" :key="i" class="chip cap"><MSym n="bolt" :size="11" /> {{ ch.texte }}</span>
                            </div>
                        </article>
                    </div>
                </template>
            </section>

            <!-- ===================== ÉQUIPEMENTS ===================== -->
            <section v-show="tab === 'equipement'" class="guide-sec">
                <template v-for="[cat, liste] in objetsParCategorie" :key="cat">
                    <h3 class="grp-title"><MSym n="inventory_2" :size="16" /> {{ CATEGORIE_OBJET[cat] ?? cat }} <span class="grp-n">{{ liste.length }}</span></h3>
                    <div class="card-grid">
                        <article v-for="o in liste" :key="o.nom" class="ent-card">
                            <div class="ent-head">
                                <h4>{{ o.nom }}</h4>
                                <span class="prix"><MSym n="paid" :size="13" /> {{ o.prix_base }}</span>
                            </div>
                            <div class="meta-row">
                                <span class="tag" :class="'rar-' + o.rarete">{{ RARETE[o.rarete] ?? o.rarete }}</span>
                                <span class="tag ghost">{{ EMPLACEMENT[o.emplacement] ?? o.emplacement }}</span>
                            </div>
                            <div v-if="effetVersChips(o.effet).length" class="chips">
                                <span v-for="(ch, i) in effetVersChips(o.effet)" :key="i" class="chip">{{ ch.texte }}</span>
                            </div>
                        </article>
                    </div>
                </template>
            </section>

            <!-- ===================== SORTS ===================== -->
            <section v-show="tab === 'sorts'" class="guide-sec">
                <template v-for="[el, liste] in sortsParElement" :key="el">
                    <h3 class="grp-title" :class="'el-' + el"><MSym :n="ELEMENT[el]?.ic ?? 'auto_awesome'" fill :size="16" /> {{ ELEMENT[el]?.l ?? el }} <span class="grp-n">{{ liste.length }}</span></h3>
                    <div class="card-grid">
                        <article v-for="s in liste" :key="s.nom" class="ent-card" :class="'el-b-' + el">
                            <div class="ent-head">
                                <h4>{{ s.nom }}</h4>
                                <span class="diff" title="Difficulté au parchemin"><MSym n="draw" :size="13" /> {{ s.difficulte_parchemin }}</span>
                            </div>
                            <div class="meta-row">
                                <span class="tag ghost">{{ TYPE_SORT[s.type] ?? s.type }}</span>
                            </div>
                            <div v-if="effetVersChips(s.effet).length" class="chips">
                                <span v-for="(ch, i) in effetVersChips(s.effet)" :key="i" class="chip">{{ ch.texte }}</span>
                            </div>
                        </article>
                    </div>
                </template>
            </section>

            <!-- ===================== PIÈGES ===================== -->
            <section v-show="tab === 'pieges'" class="guide-sec">
                <div class="card-grid">
                    <article v-for="p in pieges" :key="p.nom" class="ent-card">
                        <div class="ent-head"><h4>{{ p.nom }}</h4></div>
                        <div class="meta-row">
                            <span class="tag" :class="p.detectable ? 'ok' : 'ko'"><MSym :n="p.detectable ? 'visibility' : 'visibility_off'" :size="12" /> {{ p.detectable ? 'Détectable' : 'Indétectable' }}</span>
                            <span class="tag ghost">{{ DESARMABLE[p.desarmable] ?? p.desarmable }}</span>
                            <span class="tag ghost">{{ p.usage === 'unique' ? 'Usage unique' : 'Persistant' }}</span>
                        </div>
                        <div v-if="effetVersChips(p.effet).length" class="chips">
                            <span v-for="(ch, i) in effetVersChips(p.effet)" :key="i" class="chip">{{ ch.texte }}</span>
                        </div>
                    </article>
                </div>
            </section>
        </main>
    </div>
</template>

<style scoped>
.guide-screen { min-height: 100vh; background: var(--stone-950); color: var(--ink-100);
  padding: 22px clamp(14px, 4vw, 40px) 60px; }

/* ---- en-tête ---- */
.guide-top { max-width: 1100px; margin: 0 auto 18px; }
.guide-back { display: inline-flex; align-items: center; gap: 5px; color: var(--ink-400); text-decoration: none;
  font-size: 13px; font-weight: 700; padding: 6px 0; }
.guide-back:hover { color: var(--torch); }
.guide-titrewrap { display: flex; align-items: center; gap: 16px; margin-top: 6px; }
.guide-crest { width: 60px; height: 60px; border-radius: 16px; display: grid; place-items: center; flex: none;
  background: linear-gradient(150deg, var(--ember), var(--ember-deep)); color: var(--parch-100); box-shadow: var(--sh-2); }
.guide-crest .msym { font-size: 34px; }
.guide-titre { font-family: var(--font-display); font-size: clamp(26px, 4vw, 38px); font-weight: 800; margin: 0; letter-spacing: 0.02em;
  color: var(--parch-100); }
.guide-sub { font-family: var(--font-narr); font-style: italic; color: var(--ink-300); font-size: 15px; margin: 2px 0 0; }

/* ---- onglets ---- */
.guide-tabs { max-width: 1100px; margin: 0 auto 22px; display: flex; gap: 8px; overflow-x: auto; padding-bottom: 4px;
  border-bottom: var(--line); }
.guide-tab { flex: none; display: inline-flex; align-items: center; gap: 7px; padding: 10px 16px; cursor: pointer;
  background: none; border: none; border-bottom: 2px solid transparent; color: var(--ink-400); font-family: var(--font-ui);
  font-size: 14px; font-weight: 700; white-space: nowrap; transition: color .15s, border-color .15s; }
.guide-tab:hover { color: var(--ink-200, #e7dcc6); }
.guide-tab.on { color: var(--torch); border-bottom-color: var(--torch); }

/* ---- états ---- */
.guide-note { max-width: 1100px; margin: 40px auto; display: flex; flex-direction: column; align-items: center; gap: 12px;
  text-align: center; color: var(--ink-500); }
.guide-note .msym { color: var(--torch); }
.guide-note p { font-family: var(--font-narr); font-style: italic; font-size: 16px; margin: 0; }
.guide-note.err .msym { color: var(--danger, oklch(0.62 0.2 25)); }
.guide-retry { display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border-radius: 10px; cursor: pointer;
  background: linear-gradient(180deg, var(--gold), var(--ember-deep)); color: var(--stone-950); border: none; font-weight: 800; }

/* ---- corps ---- */
.guide-body { max-width: 1100px; margin: 0 auto; }
.grp-title { font-family: var(--font-display); font-size: 17px; font-weight: 700; color: var(--ink-200, #e7dcc6);
  display: flex; align-items: center; gap: 8px; margin: 26px 0 12px; letter-spacing: 0.02em; }
.grp-title:first-child { margin-top: 4px; }
.grp-title .msym { color: var(--torch); }
.grp-n { font-size: 12px; font-weight: 700; color: var(--ink-600); background: var(--stone-850); border: var(--line);
  border-radius: 99px; padding: 1px 8px; }
.grp-title.el-feu .msym { color: var(--elem-fire, oklch(0.64 0.205 35)); }
.grp-title.el-eau .msym { color: var(--elem-water, oklch(0.66 0.15 245)); }
.grp-title.el-terre .msym { color: var(--elem-earth, oklch(0.60 0.115 145)); }
.grp-title.el-air .msym { color: var(--elem-air, oklch(0.86 0.075 215)); }

.card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 12px; }

/* ---- carte héros ---- */
.hero-card { background: linear-gradient(180deg, var(--stone-850), var(--stone-900)); border: var(--line); border-radius: var(--r-lg, 14px);
  padding: 18px; margin-bottom: 16px; }
.hero-head { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
.hero-seal { width: 46px; height: 46px; border-radius: 12px; display: grid; place-items: center; flex: none;
  background: linear-gradient(150deg, var(--ember), var(--ember-deep)); color: var(--parch-100); }
.hero-seal .msym { font-size: 26px; }
.hero-head h2 { font-family: var(--font-display); font-size: 22px; font-weight: 800; color: var(--parch-100); margin: 0; }

.stat-row { display: flex; flex-wrap: wrap; gap: 7px; }
.stat { display: inline-flex; align-items: center; gap: 5px; padding: 5px 10px; border-radius: 9px; background: var(--stone-800);
  border: 1px solid var(--stone-700); font-size: 13.5px; font-weight: 800; color: var(--parch-100); }
.stat em { font-style: normal; font-size: 11px; font-weight: 600; color: var(--ink-500); }
.stat-row.sm .stat { padding: 4px 8px; font-size: 12.5px; }
.c-body { color: var(--body-bright, oklch(0.7 0.17 25)); }
.c-mind { color: var(--mind-bright, oklch(0.72 0.13 270)); }
.c-atk { color: var(--torch); }
.c-def { color: var(--mind-bright, oklch(0.72 0.13 270)); }

.hero-talents-t { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 800; text-transform: uppercase;
  letter-spacing: 0.06em; color: var(--ink-400); margin: 16px 0 8px; }
.hero-talents-t .msym { color: var(--gold); }
.talent-ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.talent-li { padding: 10px 12px; border-radius: 10px; background: var(--stone-850); border: 1px solid var(--stone-700); }
.tl-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.tl-nom { font-size: 14px; font-weight: 700; color: var(--parch-100); }
.tl-type { font-size: 9.5px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; padding: 2px 7px; border-radius: 99px;
  border: 1px solid currentColor; }
.tt-passif { color: var(--ok, oklch(0.7 0.14 150)); }
.tt-actif { color: var(--torch); }
.tt-deblocage { color: var(--gold); }
.tl-prereq { display: inline-flex; align-items: center; gap: 3px; font-size: 10px; font-weight: 700; color: var(--ink-600); }
.tl-desc { font-size: 12.5px; color: var(--ink-300); margin-top: 4px; line-height: 1.45; }

/* ---- carte entité (monstre/objet/sort/piège) ---- */
.ent-card { background: linear-gradient(180deg, var(--stone-850), var(--stone-900)); border: var(--line); border-radius: var(--r-md, 11px);
  padding: 13px 14px; display: flex; flex-direction: column; gap: 9px; }
.ent-card.tier-sous_boss { border-color: oklch(0.62 0.08 80 / 0.5); }
.ent-card.tier-boss { border-color: oklch(0.62 0.17 25 / 0.55); }
.ent-card.el-b-feu { border-color: oklch(0.64 0.205 35 / 0.4); }
.ent-card.el-b-eau { border-color: oklch(0.66 0.15 245 / 0.4); }
.ent-card.el-b-terre { border-color: oklch(0.60 0.115 145 / 0.4); }
.ent-card.el-b-air { border-color: oklch(0.86 0.075 215 / 0.4); }
.ent-head { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
.ent-head h4 { font-family: var(--font-display); font-size: 16.5px; font-weight: 700; color: var(--parch-100); margin: 0; letter-spacing: 0.01em; }
.cout, .prix, .diff { display: inline-flex; align-items: center; gap: 3px; font-size: 12.5px; font-weight: 800; color: var(--torch); white-space: nowrap; flex: none; }
.prix { color: var(--gold); }
.diff { color: var(--ink-300); }

.meta-row { display: flex; flex-wrap: wrap; gap: 6px; }
.tag { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 99px;
  background: var(--stone-800); border: 1px solid var(--stone-700); color: var(--ink-300); }
.tag.ghost { color: var(--ink-400); }
.tag.ok { color: var(--ok, oklch(0.7 0.14 150)); border-color: oklch(0.7 0.14 150 / 0.4); }
.tag.ko { color: var(--ink-500); }
.rar-commun { color: var(--ink-400); }
.rar-peu_commun { color: var(--ok, oklch(0.7 0.14 150)); border-color: oklch(0.7 0.14 150 / 0.4); }
.rar-rare { color: var(--mind-bright, oklch(0.72 0.13 270)); border-color: oklch(0.72 0.13 270 / 0.4); }
.rar-unique { color: var(--gold); border-color: oklch(0.80 0.135 88 / 0.5); }

.chips { display: flex; flex-wrap: wrap; gap: 6px; }
.chip { display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; font-weight: 600; padding: 3px 9px; border-radius: 8px;
  background: var(--stone-800); border: 1px solid var(--stone-700); color: var(--ink-200, #e7dcc6); }
.chip.cap { color: var(--torch); border-color: oklch(0.76 0.155 65 / 0.35); }
.chip.cap .msym { color: var(--torch); }
</style>
