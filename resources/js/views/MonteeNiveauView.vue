<script setup>
// MONTÉE DE NIVEAU (joueur, portrait) — style porté de
// reference/heroquest/"Montee de niveau.html".
//
// Mode connecté (contrat « Montée de niveau ») : GET /api/moi (mon héros :
// niveau, points_competence, competences acquises) + GET /api/competences
// (catalogue) → GRILLE de la classe rendue via competencesVersGrille :
// 3 colonnes (les catégories de la classe) × 3 lignes, où acquérir une ligne
// exige celle du dessus DANS LA MÊME COLONNE (René, 2026-08-23).
//
// ⚠ Tuile compacte + feuille de détail, et non la grande carte d'avant : une
// colonne fait ~104 px dans le cadre téléphone, où l'ancienne carte (icône
// 44 px + description + ligne d'état) n'entrait pas. La feuille montre LES DEUX
// textes — `description`, la phrase de jeu, et `avantage`, le chiffre dérivé de
// l'effet — pour qu'on décide sans ouvrir le guide.
//
// Tap sur un nœud disponible → POST acquerirCompetence (maj optimiste, puis
// re-GET /moi ; 422 affiché et état rétabli). Quand le dernier point est
// dépensé : sceau « Progression scellée » (maquette).
import { computed, onMounted, ref } from 'vue';
import MSym from '../components/ui/MSym.vue';
import { useApi } from '../composables/useApi';
import { CLASSES, competencesVersGrille, ELEMENTS, TYPES_COMPETENCE, useGameStore } from '../store/game';

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
        erreurChargement.value = e.message;
    } finally {
        pret.value = true;
    }
}
onMounted(() => {
    store.fermerNiveauMonte(); // le toast de la manette a fait son office
    charger();
});

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

/* ---- grille + points (maj optimiste : points_competence est dérivé
   serveur = (niveau − 1) − nœuds acquis, donc −1 par acquisition) ---- */
const acquisOptimistes = ref([]);
const points = computed(() => Math.max(
    0,
    (monPerso.value?.points_competence ?? 0) - acquisOptimistes.value.length,
));
const grille = computed(() => {
    const p = monPerso.value;
    if (!p) return { colonnes: [], innees: [] };
    const acquis = [...(p.competences ?? []), ...acquisOptimistes.value];
    return competencesVersGrille(store.state.competences, p.classe, acquis, points.value);
});

/* ---- acquisition : optimiste, puis re-GET /moi ; 422 affiché ---- */
const enAttente = ref(null); // competence_id en cours d'acquisition
const erreurAction = ref('');
const aAcquis = ref(false); // au moins un nœud scellé sur cet écran

/* ---- feuille de détail : le tap ouvre, le bouton scelle ---- */
const detail = ref(null);

/* ---- nœuds à élément (contrat « Sorts des héros ») : Première magie /
   Second élément (Elfe) et Écoles (Magicien) exigent `element` dans le
   POST competences — mini-sélecteur, défaut eau.
   ⚠ Reconnu sur la MÉCANIQUE, plus sur une regex du nom : la regex ne
   connaissait que les trois libellés historiques et aurait manqué tout
   nœud d'élément ajouté depuis. ---- */
const exigeElement = (noeud) => noeud?.mecanique === 'emplacement_element';
const choixElement = ref(null); // { noeud, element } — sélecteur ouvert

function taper(noeud) {
    if (enAttente.value) return;
    detail.value = noeud;
}

function sceller(noeud) {
    if (noeud.etat !== 'dispo' || enAttente.value || !monPerso.value) return;
    detail.value = null;
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
        erreurAction.value = e.message; // 422 : prérequis, points, classe…
    } finally {
        enAttente.value = null;
    }
}

/* sceau final : plus de point après au moins une acquisition */
const scelle = computed(() => aAcquis.value && points.value === 0 && !enAttente.value);

const verrouLibelle = (n) => (n.verrou === 'prerequis'
    ? `Prérequis : ${n.prerequisNom ?? 'la ligne du dessus'}`
    : 'Aucun point de compétence disponible');
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
                        <p>Consultation des grilles de talents…</p>
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
                            <MSym n="grid_view" fill /> Grille de talents
                            <span class="sect-note">{{ heros?.classe ?? '' }}</span>
                        </div>

                        <div v-if="!grille.colonnes.length" class="state-note">
                            <MSym n="forest" :size="26" />
                            <p>Aucune grille publiée pour cette classe.</p>
                        </div>
                        <div v-else class="grille">
                            <div v-for="col in grille.colonnes" :key="col.colonne" class="col">
                                <div class="col-tete">
                                    <MSym :n="col.icone" fill :size="16" />
                                    <span>{{ col.categorie }}</span>
                                </div>
                                <template v-for="(n, i) in col.noeuds" :key="n.id">
                                    <!-- Chaînon : la ligne n exige la ligne n−1 de CETTE colonne.
                                         Doré quand le chemin est ouvert, gris pointillé sinon. -->
                                    <div
                                        v-if="i > 0"
                                        class="chainon"
                                        :class="{ ouvert: col.noeuds[i - 1].etat === 'acquis' }"
                                    />
                                    <button
                                        class="tuile"
                                        :class="['st-' + n.etat, { busy: enAttente === n.id }]"
                                        @click="taper(n)"
                                    >
                                        <span class="ti"><MSym :n="n.ic" fill /></span>
                                        <span class="tn">{{ n.nom }}</span>
                                        <span class="tb">
                                            <MSym
                                                :n="n.etat === 'acquis' ? 'check' : (n.etat === 'dispo' ? 'add' : 'lock')"
                                                :size="11"
                                                fill
                                            />
                                        </span>
                                    </button>
                                </template>
                            </div>
                        </div>

                        <!-- Capacités de CARTE : gratuites, hors grille. Les
                             mêler aux tuiles laisserait croire qu'elles coûtent
                             un point de compétence. -->
                        <template v-if="grille.innees.length">
                            <div class="sect">
                                <MSym n="style" fill /> Capacités de carte
                                <span class="sect-note">acquises d'emblée</span>
                            </div>
                            <div class="innees">
                                <button v-for="n in grille.innees" :key="n.id" class="innee" @click="taper(n)">
                                    <MSym :n="n.ic" fill :size="18" />
                                    <span>{{ n.nom }}</span>
                                </button>
                            </div>
                        </template>
                    </template>
                </div>

                <!-- pied : retour -->
                <div class="foot">
                    <p class="hint" :style="points > 0 ? 'color: var(--torch)' : ''">
                        {{ points > 0
                            ? 'Touche une case pour la lire, puis scelle-la.'
                            : 'Aucun point à dépenser — reviens après le prochain jalon.' }}
                    </p>
                    <RouterLink class="btn btn-gold" :to="{ name: 'manette', params: { groupe } }">
                        <MSym n="login" /> Reprendre la partie
                    </RouterLink>
                </div>

                <!-- feuille de détail d'un talent -->
                <div v-if="detail" class="talent-ov" @click.self="detail = null">
                    <div class="talent-card">
                        <h3>
                            <span class="ti"><MSym :n="detail.ic" fill /></span>
                            {{ detail.nom }}
                        </h3>
                        <div class="tags">
                            <span class="type-tag" :class="'tp-' + detail.type">
                                <MSym :n="TYPES_COMPETENCE[detail.type].ic" :size="11" />
                                {{ TYPES_COMPETENCE[detail.type].l }}
                            </span>
                            <span v-if="detail.avantage" class="fx-tag">
                                <MSym :n="detail.ic" :size="11" fill /> {{ detail.avantage }}
                            </span>
                        </div>
                        <p v-if="detail.description">{{ detail.description }}</p>
                        <div v-if="detail.etat === 'verrouille'" class="lockline">
                            <MSym n="lock" :size="13" /> {{ verrouLibelle(detail) }}
                        </div>
                        <button v-else-if="detail.etat === 'dispo'" class="btn btn-gold" @click="sceller(detail)">
                            <MSym n="verified" fill /> Sceller ce talent
                        </button>
                        <div v-else-if="detail.etat === 'acquis'" class="acquisline">
                            <MSym n="check_circle" :size="13" fill /> Déjà acquis
                        </div>
                        <button class="annuler" @click="detail = null">← Retour à la grille</button>
                    </div>
                </div>

                <!-- mini-sélecteur d'élément (nœuds `emplacement_element`) -->
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

/* ---- LA GRILLE : 3 colonnes × 3 lignes ----------------------------------
   Le cadre téléphone laisse ~324 px utiles : chaque colonne fait donc ~100 px,
   d'où la tuile compacte (icône + nom) et le détail en feuille. */
.lvlup-screen .grille { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; align-items: start; margin-bottom: 6px; }
.lvlup-screen .col { display: flex; flex-direction: column; align-items: stretch; }
.lvlup-screen .col-tete { display: flex; flex-direction: column; align-items: center; gap: 3px; padding: 0 2px 8px;
  font-family: var(--font-display); font-size: 11px; font-weight: 700; letter-spacing: 0.04em; color: var(--gold); text-align: center; }
.lvlup-screen .col-tete .msym { color: var(--gold); }
.lvlup-screen .col-tete span { line-height: 1.15; }

/* le chaînon de PRÉREQUIS entre deux lignes d'une même colonne */
.lvlup-screen .chainon { width: 2px; height: 12px; margin: 0 auto; background: var(--stone-700); }
.lvlup-screen .chainon.ouvert { background: var(--gold); }

.lvlup-screen .tuile { position: relative; display: flex; flex-direction: column; align-items: center; gap: 5px;
  padding: 10px 5px 9px; border-radius: var(--r-md); cursor: pointer; width: 100%; text-align: center;
  background: var(--stone-850); border: 1px solid var(--stone-700); transition: all .15s;
  font-family: var(--font-ui); -webkit-tap-highlight-color: transparent; }
.lvlup-screen .tuile:active { transform: scale(0.97); }
.lvlup-screen .tuile .ti { width: 32px; height: 32px; border-radius: 9px; display: grid; place-items: center; flex: none;
  background: var(--stone-800); color: var(--torch); }
.lvlup-screen .tuile .ti .msym { font-size: 19px; }
.lvlup-screen .tuile .tn { font-size: 10.5px; font-weight: 700; color: var(--parch-100); line-height: 1.2; }
.lvlup-screen .tuile .tb { position: absolute; top: 5px; right: 5px; width: 16px; height: 16px; border-radius: 50%;
  border: 1.5px solid var(--stone-600); display: grid; place-items: center; }
.lvlup-screen .tuile .tb .msym { font-size: 10px; color: transparent; }

.lvlup-screen .tuile.st-acquis { border-color: oklch(0.62 0.08 80 / 0.55); background: linear-gradient(180deg, oklch(0.24 0.02 90 / 0.4), var(--stone-850)); }
.lvlup-screen .tuile.st-acquis .ti { background: linear-gradient(150deg, var(--gold), var(--ember-deep)); color: var(--stone-950); }
.lvlup-screen .tuile.st-acquis .tb { border-color: var(--gold); background: var(--gold); }
.lvlup-screen .tuile.st-acquis .tb .msym { color: var(--stone-950); }

.lvlup-screen .tuile.st-dispo { border-color: var(--torch); box-shadow: 0 0 0 1px oklch(0.76 0.155 65 / 0.25); }
.lvlup-screen .tuile.st-dispo .ti { background: oklch(0.76 0.155 65 / 0.16); }
.lvlup-screen .tuile.st-dispo .tb { border-color: var(--torch); }
.lvlup-screen .tuile.st-dispo .tb .msym { color: var(--torch); }
.lvlup-screen .tuile.st-dispo.busy { opacity: 0.7; }

.lvlup-screen .tuile.st-verrouille { opacity: 0.5; }
.lvlup-screen .tuile.st-verrouille .ti { color: var(--ink-500); }
.lvlup-screen .tuile.st-verrouille .tb .msym { color: var(--ink-600); }

/* capacités de carte : gratuites, donc hors grille et sans état */
.lvlup-screen .innees { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 6px; }
.lvlup-screen .innee { display: inline-flex; align-items: center; gap: 5px; padding: 7px 10px; border-radius: 99px; cursor: pointer;
  background: var(--stone-850); border: 1px solid oklch(0.62 0.08 80 / 0.4); color: var(--parch-100);
  font-family: var(--font-ui); font-size: 11px; font-weight: 700; -webkit-tap-highlight-color: transparent; }
.lvlup-screen .innee .msym { color: var(--gold); }

/* ---- feuille de détail d'un talent ---- */
.lvlup-screen .talent-ov, .lvlup-screen .elem-ov { position: absolute; inset: 0; z-index: 55; background: oklch(0.16 0.012 255 / 0.88);
  backdrop-filter: blur(3px); display: flex; align-items: center; justify-content: center; padding: 24px; animation: lvlup-fadein .2s ease; }
.lvlup-screen .talent-card, .lvlup-screen .elem-card { width: 100%; max-width: 320px; background: var(--stone-900); border: var(--line-gold);
  border-radius: var(--r-xl); padding: 20px; display: flex; flex-direction: column; gap: 12px; box-shadow: var(--sh-3); }
.lvlup-screen .talent-card h3, .lvlup-screen .elem-card h3 { font-family: var(--font-display); font-size: 18px; color: var(--parch-100); margin: 0;
  display: flex; align-items: center; gap: 9px; letter-spacing: 0.02em; }
.lvlup-screen .talent-card h3 .ti { width: 34px; height: 34px; border-radius: 10px; display: grid; place-items: center; flex: none;
  background: linear-gradient(150deg, var(--gold), var(--ember-deep)); color: var(--stone-950); }
.lvlup-screen .talent-card h3 .ti .msym { font-size: 20px; }
.lvlup-screen .talent-card p, .lvlup-screen .elem-card p { font-family: var(--font-narr); font-style: italic; font-size: 13.5px;
  color: var(--ink-300); margin: 0; line-height: 1.45; }
.lvlup-screen .talent-card .tags { display: flex; flex-wrap: wrap; gap: 6px; }
.lvlup-screen .type-tag { display: inline-flex; align-items: center; gap: 3px; font-size: 9.5px; font-weight: 800; text-transform: uppercase;
  letter-spacing: 0.05em; padding: 2px 7px; border-radius: 99px; border: 1px solid currentColor; }
.lvlup-screen .type-tag.tp-passif { color: var(--ok); background: oklch(0.7 0.14 150 / 0.1); }
.lvlup-screen .type-tag.tp-actif { color: var(--torch); background: oklch(0.76 0.155 65 / 0.1); }
.lvlup-screen .type-tag.tp-deblocage { color: var(--gold); background: oklch(0.80 0.135 88 / 0.1); }
.lvlup-screen .fx-tag { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 700; color: var(--gold);
  padding: 2px 8px; border-radius: 99px; background: oklch(0.80 0.135 88 / 0.12); }
.lvlup-screen .talent-card .lockline { display: flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 700; color: var(--ink-600); }
.lvlup-screen .talent-card .acquisline { display: flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 700; color: var(--gold); }
.lvlup-screen .talent-card .annuler, .lvlup-screen .elem-card .annuler { background: none; border: none; color: var(--ink-500);
  font-family: var(--font-ui); font-weight: 700; font-size: 12.5px; cursor: pointer; padding: 2px; }

/* ---- mini-sélecteur d'élément (nœuds `emplacement_element`) ---- */
.lvlup-screen .elem-card h3 .msym { color: var(--gold); }
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
