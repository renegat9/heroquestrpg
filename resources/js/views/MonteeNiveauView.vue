<script setup>
// MONTÉE DE NIVEAU (joueur, portrait) — style porté de
// reference/heroquest/"Montee de niveau.html".
//
// Mode connecté (contrat « Montée de niveau ») : GET /api/moi (mon héros :
// niveau, points_competence, competences acquises) + GET /api/competences
// (catalogue) → arbre de la classe rendu via competencesVersArbre (nœuds
// acquis / disponibles / verrouillés, types passif/actif/deblocage). Tap
// sur un nœud disponible → POST acquerirCompetence (maj optimiste, puis
// re-GET /moi ; 422 affiché et état rétabli). Quand le dernier point est
// dépensé : sceau « Progression scellée » (maquette).
//
// Repli : API injoignable / 401 (estErreurDemo) ou modeDemo déjà actif →
// rendu démo d'origine (gains + choix d'un talent, data/demo.js).
import { computed, onMounted, ref } from 'vue';
import MSym from '../components/ui/MSym.vue';
import DemoBadge from '../components/ui/DemoBadge.vue';
import { LEVELUP_HERO, LEVELUP_GAINS, LEVELUP_TALENTS } from '../data/demo';
import { estErreurDemo, useApi } from '../composables/useApi';
import { CLASSES, competencesVersArbre, ELEMENTS, TYPES_COMPETENCE, useGameStore } from '../store/game';

const props = defineProps({
    groupe: { type: String, required: true },
});

const store = useGameStore();
store.setGroupe(props.groupe);
const api = useApi();

/* ---- chargement (mode connecté) : /moi + catalogue + état du groupe ---- */
const pret = ref(false);
const erreurChargement = ref('');
async function charger() {
    pret.value = false;
    erreurChargement.value = '';
    try {
        const r = await api.moi();
        store.setJoueur(r.joueur, r.personnages ?? r.joueur?.personnages ?? []);
        store.setCompetences(await api.getCompetences());
        // L'état sert seulement à relier mon personnage au groupe (toléré en échec).
        api.getEtat(props.groupe).then((e) => store.appliquerEtat(e)).catch(() => {});
    } catch (e) {
        if (estErreurDemo(e)) store.activerModeDemo(e.message);
        else erreurChargement.value = e.message;
    } finally {
        pret.value = true;
    }
}
onMounted(() => {
    store.fermerNiveauMonte(); // le toast de la manette a fait son office
    charger();
});

const enDemo = computed(() => store.state.modeDemo);

/* ---- mon héros : personnage de /moi présent dans ce groupe ---- */
const monPerso = computed(() => {
    const persos = store.state.personnages ?? [];
    const idsHeros = new Set((store.state.etat?.entites ?? [])
        .filter((e) => e.type === 'heros')
        .map((e) => e.id));
    return persos.find((p) => idsHeros.has(p.id))
        ?? persos.find((p) => p.groupe_actif_id != null || p.disponible === false)
        ?? persos[0]
        ?? null;
});

const heros = computed(() => {
    const p = monPerso.value;
    if (!p) return null;
    const cls = CLASSES[(p.classe ?? '').toLowerCase()];
    return {
        nom: p.nom,
        classe: cls?.l ?? p.classe,
        ic: cls?.ic ?? 'person',
        niveau: p.niveau ?? 1,
    };
});

/* ---- arbre + points (maj optimiste : points_competence est dérivé
   serveur = (niveau − 1) − nœuds acquis, donc −1 par acquisition) ---- */
const acquisOptimistes = ref([]);
const points = computed(() => Math.max(
    0,
    (monPerso.value?.points_competence ?? 0) - acquisOptimistes.value.length,
));
const arbre = computed(() => {
    const p = monPerso.value;
    if (!p) return [];
    const acquis = [...(p.competences ?? []), ...acquisOptimistes.value];
    return competencesVersArbre(store.state.competences, p.classe, acquis, points.value);
});

/* ---- acquisition : optimiste, puis re-GET /moi ; 422 affiché ---- */
const enAttente = ref(null); // competence_id en cours d'acquisition
const erreurAction = ref('');
const aAcquis = ref(false); // au moins un nœud scellé sur cet écran

/* ---- nœuds à élément (contrat « Sorts des héros ») : Première magie /
   Second élément (Elfe) et Écoles (Magicien) exigent `element` dans le
   POST competences — mini-sélecteur, défaut eau. ---- */
const NOEUD_A_ELEMENT = /premi[èe]re\s+magie|second\s+[ée]l[ée]ment|[ée]cole/i;
const exigeElement = (noeud) => NOEUD_A_ELEMENT.test(noeud.nom ?? '');
const choixElement = ref(null); // { noeud, element } — sélecteur ouvert

function taper(noeud) {
    if (noeud.etat !== 'dispo' || enAttente.value || !monPerso.value) return;
    if (exigeElement(noeud)) {
        choixElement.value = { noeud, element: 'eau' }; // défaut contrat
        return;
    }
    acquerir(noeud);
}

function confirmerElement() {
    const { noeud, element } = choixElement.value;
    choixElement.value = null;
    acquerir(noeud, element);
}

async function acquerir(noeud, element = null) {
    if (noeud.etat !== 'dispo' || enAttente.value || !monPerso.value) return;
    erreurAction.value = '';
    enAttente.value = noeud.id;
    acquisOptimistes.value = [...acquisOptimistes.value, noeud.id];
    try {
        await api.acquerirCompetence(props.groupe, {
            personnage_id: monPerso.value.id,
            competence_id: noeud.id,
            ...(element ? { element } : {}),
        });
        aAcquis.value = true;
        const r = await api.moi(); // source de vérité : niveau/points/acquis frais
        store.setJoueur(r.joueur, r.personnages ?? r.joueur?.personnages ?? []);
        acquisOptimistes.value = [];
    } catch (e) {
        acquisOptimistes.value = acquisOptimistes.value.filter((id) => id !== noeud.id);
        if (estErreurDemo(e)) store.activerModeDemo(e.message);
        else erreurAction.value = e.message; // 422 : prérequis, points, classe…
    } finally {
        enAttente.value = null;
    }
}

/* sceau final : plus de point après au moins une acquisition */
const scelle = computed(() => aAcquis.value && points.value === 0 && !enAttente.value);

const verrouLibelle = (n) => (n.verrou === 'prerequis'
    ? `Prérequis : ${n.prerequisNom ?? 'nœud précédent'}`
    : 'Aucun point de compétence disponible');

/* ---- mode démo : stub d'origine (maquette, version Magicienne) ---- */
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
            <!-- ================= MODE DÉMO (stub maquette) ================= -->
            <div v-if="enDemo" class="screen">
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

            <!-- ================= MODE CONNECTÉ (arbre du héros) ================= -->
            <div v-else class="screen">
                <!-- bannière -->
                <div class="banner">
                    <RouterLink class="home" to="/"><MSym n="arrow_back" :size="14" /> HUB</RouterLink>
                    <div class="lvlup">Montée de niveau</div>
                    <div class="crest-wrap">
                        <div class="crest-ring" />
                        <div class="crest"><MSym :n="heros?.ic ?? 'person'" fill /></div>
                        <div class="levelpill">Niv. {{ heros?.niveau ?? '—' }}</div>
                    </div>
                    <h1>{{ heros?.nom ?? '…' }}</h1>
                    <div class="arc">{{ heros?.classe ?? '' }}</div>
                    <div class="pts-pill" :class="{ vide: points === 0 }">
                        <MSym n="hub" fill :size="14" />
                        {{ points }} point{{ points > 1 ? 's' : '' }} de compétence à dépenser
                    </div>
                </div>

                <!-- corps -->
                <div class="body">
                    <!-- chargement / erreur -->
                    <div v-if="!pret" class="state-note">
                        <MSym n="hourglass_top" :size="26" />
                        <p>Consultation des arbres de compétences…</p>
                    </div>
                    <div v-else-if="erreurChargement" class="state-note err">
                        <MSym n="error" fill :size="26" />
                        <p>{{ erreurChargement }}</p>
                        <button class="btn btn-gold" style="width: auto" @click="charger">
                            <MSym n="refresh" /> Réessayer
                        </button>
                    </div>
                    <template v-else>
                        <div v-if="erreurAction" class="err-band">
                            <MSym n="block" fill :size="16" /> {{ erreurAction }}
                        </div>

                        <div class="sect gold">
                            <MSym n="hub" fill /> Arbre de compétences
                            <span class="sect-note">{{ heros?.classe ?? '' }}</span>
                        </div>

                        <div v-if="!arbre.length" class="state-note">
                            <MSym n="forest" :size="26" />
                            <p>Aucun arbre publié pour cette classe.</p>
                        </div>
                        <div v-else class="talents tree">
                            <button
                                v-for="n in arbre"
                                :key="n.id"
                                class="talent"
                                :class="['st-' + n.etat, { busy: enAttente === n.id, child: n.profondeur > 0 }]"
                                :style="n.profondeur ? { marginLeft: Math.min(n.profondeur, 3) * 16 + 'px' } : null"
                                :disabled="n.etat !== 'dispo' || enAttente !== null"
                                @click="taper(n)"
                            >
                                <span class="ti"><MSym :n="n.ic" fill /></span>
                                <div>
                                    <div class="tt">
                                        {{ n.nom }}
                                        <span class="type-tag" :class="'tp-' + n.type">
                                            <MSym :n="TYPES_COMPETENCE[n.type].ic" :size="11" />
                                            {{ TYPES_COMPETENCE[n.type].l }}
                                        </span>
                                    </div>
                                    <div v-if="n.effets.length" class="td">
                                        <span v-for="(e, i) in n.effets" :key="i" class="fx">
                                            <MSym v-if="e.ic" :n="e.ic" :size="12" /> {{ e.texte }}
                                        </span>
                                    </div>
                                    <div v-if="n.etat === 'verrouille'" class="lockline">
                                        <MSym n="lock" :size="12" /> {{ verrouLibelle(n) }}
                                    </div>
                                    <div v-else-if="n.etat === 'dispo'" class="dispoline">
                                        <MSym n="touch_app" :size="12" />
                                        {{ enAttente === n.id ? 'Acquisition…' : 'Disponible — touche pour acquérir' }}
                                    </div>
                                </div>
                                <span class="tcheck"><MSym :n="n.etat === 'verrouille' ? 'lock' : 'check'" fill /></span>
                            </button>
                        </div>
                    </template>
                </div>

                <!-- pied : retour -->
                <div class="foot">
                    <p class="hint" :style="points > 0 ? 'color: var(--torch)' : ''">
                        {{ points > 0
                            ? 'Touche un nœud disponible pour le sceller.'
                            : 'Aucun point à dépenser — reviens après le prochain jalon.' }}
                    </p>
                    <RouterLink class="btn btn-gold" :to="{ name: 'manette', params: { groupe } }">
                        <MSym n="login" /> Reprendre la partie
                    </RouterLink>
                </div>

                <!-- mini-sélecteur d'élément (Première magie / Second élément / École) -->
                <div v-if="choixElement" class="elem-ov" @click.self="choixElement = null">
                    <div class="elem-card">
                        <h3><MSym n="auto_awesome" fill :size="18" /> {{ choixElement.noeud.nom }}</h3>
                        <p>Choisis l'élément à apprendre — ses 3 sorts rejoignent ton grimoire.</p>
                        <div class="elems">
                            <button
                                v-for="(e, k) in ELEMENTS"
                                :key="k"
                                class="el"
                                :class="['elc-' + e.cle, { on: choixElement.element === k }]"
                                @click="choixElement = { ...choixElement, element: k }"
                            >
                                <MSym :n="e.ic" fill /><span class="en">{{ e.l }}</span>
                            </button>
                        </div>
                        <button class="btn btn-gold" @click="confirmerElement">
                            <MSym n="verified" fill /> Sceller — élément {{ ELEMENTS[choixElement.element].l }}
                        </button>
                        <button class="annuler" @click="choixElement = null">Annuler</button>
                    </div>
                </div>

                <!-- sceau : tous les points dépensés -->
                <div v-if="scelle" class="done-ov">
                    <div class="seal"><MSym n="verified" fill /></div>
                    <h2>Progression scellée</h2>
                    <p>{{ heros?.nom ?? 'Ton héros' }} grave sa nouvelle puissance. L'aventure peut reprendre.</p>
                    <RouterLink class="btn btn-gold" :to="{ name: 'manette', params: { groupe } }">
                        <MSym n="login" /> Reprendre la partie
                    </RouterLink>
                </div>
            </div>
        </div>
        <DemoBadge />
    </div>
</template>

<style>
/* Port de "Montee de niveau.html" — préfixé .lvlup-screen
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

/* compteur de points (mode connecté) */
.lvlup-screen .pts-pill { display: flex; align-items: center; justify-content: center; gap: 5px; width: fit-content; margin: 10px auto 0;
  padding: 4px 12px; border-radius: 99px; font-size: 11.5px; font-weight: 800;
  background: linear-gradient(180deg, var(--gold), var(--ember-deep)); color: var(--stone-950); box-shadow: var(--sh-1); }
.lvlup-screen .pts-pill.vide { background: var(--stone-800); color: var(--ink-400); box-shadow: none; border: var(--line); }

.lvlup-screen .body { padding: 18px; }
.lvlup-screen .sect { font-family: var(--font-display); font-size: 13px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--ink-300);
  font-weight: 600; margin: 4px 0 12px; display: flex; align-items: center; gap: 8px; }
.lvlup-screen .sect.gold { color: var(--gold); }
.lvlup-screen .sect .sect-note { margin-left: auto; font-size: 11px; color: var(--ink-600); letter-spacing: 0; text-transform: none; font-weight: 600; }

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

/* ---- arbre (mode connecté) : états acquis / dispo / verrouillé ---- */
.lvlup-screen .tree .talent.child::before { content: ""; position: absolute; left: -12px; top: -11px; bottom: 50%; width: 12px;
  border-left: 2px solid var(--stone-700); border-bottom: 2px solid var(--stone-700); border-bottom-left-radius: 8px; }
.lvlup-screen .talent .tt { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
.lvlup-screen .type-tag { display: inline-flex; align-items: center; gap: 3px; font-size: 9.5px; font-weight: 800; text-transform: uppercase;
  letter-spacing: 0.05em; padding: 2px 7px; border-radius: 99px; border: 1px solid currentColor; }
.lvlup-screen .type-tag.tp-passif { color: var(--ok); background: oklch(0.7 0.14 150 / 0.1); }
.lvlup-screen .type-tag.tp-actif { color: var(--torch); background: oklch(0.76 0.155 65 / 0.1); }
.lvlup-screen .type-tag.tp-deblocage { color: var(--gold); background: oklch(0.80 0.135 88 / 0.1); }
.lvlup-screen .talent .td .fx { display: inline-flex; align-items: center; gap: 3px; margin-right: 9px; }
.lvlup-screen .talent .lockline { display: flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 700; color: var(--ink-600); margin-top: 6px; }
.lvlup-screen .talent .dispoline { display: flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 700; color: var(--torch); margin-top: 6px; }

.lvlup-screen .talent.st-acquis { border-color: oklch(0.62 0.08 80 / 0.55); background: linear-gradient(180deg, oklch(0.24 0.02 90 / 0.4), var(--stone-850)); cursor: default; }
.lvlup-screen .talent.st-acquis .ti { background: linear-gradient(150deg, var(--gold), var(--ember-deep)); color: var(--stone-950); }
.lvlup-screen .talent.st-acquis .tcheck { border-color: var(--gold); background: var(--gold); }
.lvlup-screen .talent.st-acquis .tcheck .msym { color: var(--stone-950); }
.lvlup-screen .talent.st-acquis:active { transform: none; }

.lvlup-screen .talent.st-dispo { border-color: var(--torch); box-shadow: 0 0 0 1px oklch(0.76 0.155 65 / 0.25); }
.lvlup-screen .talent.st-dispo .ti { background: oklch(0.76 0.155 65 / 0.16); }
.lvlup-screen .talent.st-dispo.busy { opacity: 0.7; }

.lvlup-screen .talent.st-verrouille { opacity: 0.55; cursor: default; }
.lvlup-screen .talent.st-verrouille .ti { color: var(--ink-500); }
.lvlup-screen .talent.st-verrouille .tcheck { border-color: var(--stone-700); }
.lvlup-screen .talent.st-verrouille .tcheck .msym { color: var(--ink-600); font-size: 12px; }
.lvlup-screen .talent.st-verrouille:active { transform: none; }

/* ---- mini-sélecteur d'élément (nœuds Première magie / Second élément / École) ---- */
.lvlup-screen .elem-ov { position: absolute; inset: 0; z-index: 55; background: oklch(0.16 0.012 255 / 0.88); backdrop-filter: blur(3px);
  display: flex; align-items: center; justify-content: center; padding: 24px; animation: lvlup-fadein .2s ease; }
.lvlup-screen .elem-card { width: 100%; max-width: 320px; background: var(--stone-900); border: var(--line-gold); border-radius: var(--r-xl);
  padding: 20px; display: flex; flex-direction: column; gap: 14px; box-shadow: var(--sh-3); }
.lvlup-screen .elem-card h3 { font-family: var(--font-display); font-size: 18px; color: var(--parch-100); margin: 0;
  display: flex; align-items: center; gap: 7px; letter-spacing: 0.02em; }
.lvlup-screen .elem-card h3 .msym { color: var(--gold); }
.lvlup-screen .elem-card p { font-family: var(--font-narr); font-style: italic; font-size: 13.5px; color: var(--ink-300); margin: 0; line-height: 1.45; }
.lvlup-screen .elem-card .elems { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
.lvlup-screen .elem-card .el { background: var(--stone-850); border: var(--line-strong); border-radius: var(--r-md); padding: 11px 4px; cursor: pointer;
  display: flex; flex-direction: column; align-items: center; gap: 5px; transition: all .15s; font-family: var(--font-ui); }
.lvlup-screen .elem-card .el .msym { font-size: 23px; color: var(--ink-300); }
.lvlup-screen .elem-card .el .en { font-size: 10.5px; font-weight: 700; color: var(--ink-500); }
.lvlup-screen .elem-card .el.on { border-color: currentColor; }
.lvlup-screen .elem-card .el.elc-fire.on { color: var(--elem-fire); background: oklch(0.64 0.205 35 / 0.12); }
.lvlup-screen .elem-card .el.elc-water.on { color: var(--elem-water); background: oklch(0.66 0.150 245 / 0.12); }
.lvlup-screen .elem-card .el.elc-earth.on { color: var(--elem-earth); background: oklch(0.60 0.115 145 / 0.14); }
.lvlup-screen .elem-card .el.elc-air.on { color: var(--elem-air); background: oklch(0.86 0.075 215 / 0.14); }
.lvlup-screen .elem-card .el.on .msym, .lvlup-screen .elem-card .el.on .en { color: currentColor; }
.lvlup-screen .elem-card .annuler { background: none; border: none; color: var(--ink-500); font-family: var(--font-ui); font-weight: 700;
  font-size: 12.5px; cursor: pointer; padding: 2px; }

/* ---- erreur d'acquisition (422) + états de chargement ---- */
.lvlup-screen .err-band { display: flex; align-items: center; gap: 7px; margin: 0 0 14px; padding: 10px 13px; border-radius: var(--r-md);
  font-size: 12.5px; font-weight: 700; color: var(--danger, oklch(0.62 0.2 25));
  background: oklch(0.58 0.185 25 / 0.12); border: 1px solid oklch(0.58 0.185 25 / 0.45); animation: fadein .2s ease; }
.lvlup-screen .state-note { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 34px 16px; text-align: center; color: var(--ink-500); }
.lvlup-screen .state-note .msym { color: var(--torch); }
.lvlup-screen .state-note p { font-family: var(--font-narr); font-style: italic; font-size: 15px; margin: 0; }
.lvlup-screen .state-note.err .msym { color: var(--danger, oklch(0.62 0.2 25)); }

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
