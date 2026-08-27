<script setup>
// GUIDE / COMPENDIUM (public, accessible depuis l'accueil) — données de
// référence en lecture seule : classes de héros + talents, bestiaire,
// équipements, sorts, pièges. Source : GET /api/guide (catalogues seedés).
// Les effets mécaniques sont traduits en libellés lisibles via compendium.js.
import { computed, onMounted, ref } from 'vue';
import MSym from '../components/ui/MSym.vue';
import { useApi } from '../composables/useApi';
import {
    accesEquipement,
    capacitesVersChips,
    CATEGORIE_OBJET,
    CLASSE,
    DESARMABLE,
    effetVersChips,
    ELEMENT,
    ORDRE_ELEMENTS,
    RACE,
    EMPLACEMENT,
    RARETE,
    TAG_EQUIPEMENT,
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
    ['cartes', 'style', 'Cartes sources'],
];

/* ---- Provenance : de quelle CARTE vient chaque pièce du catalogue ----
   Les armes, armures et artefacts sont la conversion de deux paquets de cartes
   (config/cartes.php, exposé par /api/guide). Sans cette table, la page listait
   un catalogue sans jamais dire d'où viennent ses prix et ses dés. */
const carteParObjet = computed(() => {
    const index = {};
    for (const paquet of guide.value?.cartes ?? []) {
        for (const c of paquet.cartes) {
            if (c.porte) index[c.nom] = { ...c, paquetLibelle: paquet.libelle };
        }
    }
    return index;
});

/** Cartes NON portées, par paquet — ce qui existe au plateau mais pas ici. */
const cartesAbsentes = computed(() => (guide.value?.cartes ?? [])
    .map((p) => ({ ...p, cartes: p.cartes.filter((c) => !c.porte) }))
    .filter((p) => p.cartes.length));

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
    for (const c of guide.value?.competences ?? []) {
        if (c.innee) continue; // les capacités de carte ont leur propre bloc
        (m[c.classe] ??= []).push(c);
    }
    return m;
});

/* La GRILLE de talents (2026-08-23) : 3 colonnes × 3 lignes par classe. Rendue
   à plat, la page ne disait pas à quoi un joueur RENONCE en descendant une
   colonne — or c'est tout le sujet de la refonte. */
const grilleParClasse = computed(() => {
    const m = {};
    for (const t of guide.value?.competences ?? []) {
        if (t.innee || t.colonne == null) continue;
        const cols = (m[t.classe] ??= []);
        let col = cols.find((c) => c.colonne === t.colonne);
        if (!col) {
            col = { colonne: t.colonne, categorie: t.categorie ?? '', icone: t.categorie_icone || 'hub', noeuds: [] };
            cols.push(col);
        }
        col.noeuds.push(t);
    }
    for (const cols of Object.values(m)) {
        cols.sort((a, b) => a.colonne - b.colonne);
        for (const c of cols) c.noeuds.sort((a, b) => (a.rang ?? 0) - (b.rang ?? 0));
    }
    return m;
});
/* Le mouvement s'explique par la RACE, plus un éventuel trait d'agilité
   (doc 01 §4bis-2). L'infobulle le dit, sinon le chiffre reste opaque. */
const SOCLE_RACE = { nain: 3, halfling: 3, humain: 4, elfe: 5 };

function detailDeplacement(c) {
    const socle = SOCLE_RACE[c.race];
    if (socle === undefined) return 'Déplacement de base';
    const agile = (c.deplacement_base ?? socle) - socle;
    const race = RACE[c.race]?.l ?? c.race;
    return agile > 0
        ? `Déplacement de base : ${socle} (${race}) +${agile} — classe agile`
        : `Déplacement de base : ${socle} (${race})`;
}

/* SORTS DE CLASSE — Barde, Druide et Warlock n'ont pas d'« arbre de cartes » :
   leurs cartes SONT des sorts, rangés dans l'onglet Sorts. Le Barde paraissait
   donc n'avoir qu'une seule capacité, alors qu'il en a quatre en tout.
   L'Elfe est le cas à part : son répertoire est un CHOIX (3 sur 6), pas une
   dotation — d'où la mention distincte. */
const REPERTOIRE_DE_CLASSE = { barde: 'barde', druide: 'druide', warlock: 'warlock', elfe: 'elfique' };

function sortsDeClasse(classe) {
    const el = REPERTOIRE_DE_CLASSE[classe];
    if (!el) return [];
    return (guide.value?.sorts ?? []).filter((s) => s.element === el);
}

/* Les DEUX faces d'une carte de style (Moine) : `effet.techniques` porte deux
   techniques par carte, et leur nom n'apparaissait nulle part — la description
   les mentionne en prose, mais on ne pouvait pas les LISTER. Le résumé se tire
   de la description, découpée sur le « — ou — » qui sépare les deux faces. */
function techniquesDe(noeud) {
    const techs = noeud?.effet?.techniques;
    if (!Array.isArray(techs)) return [];
    const morceaux = (noeud.description ?? '').split(/\s+—\s+ou\s+—\s+/);
    return techs.map((t, i) => ({
        nom: t.nom,
        // « Nom : effet » → on ne garde que l'effet, le nom est déjà à gauche.
        resume: (morceaux[i] ?? '').replace(new RegExp(`^\\s*${t.nom}\\s*:\\s*`), '').trim(),
    }));
}

/* Capacités de CARTE (classes d'extension) : acquises d'emblée et gratuitement,
   là où un nœud d'arbre se paie un point. Les mélanger faisait croire qu'on
   devait acheter « Furie » ou les Styles du Moine. */
const inneesParClasse = computed(() => {
    const m = {};
    for (const c of guide.value?.competences ?? []) {
        if (c.innee) (m[c.classe] ??= []).push(c);
    }
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
/* ---- maîtrises d'équipement (doc 01 §7) ----
   La restriction n'apparaissait NULLE PART dans le guide : le joueur ne
   découvrait qu'un magicien ne porte pas d'armure qu'au refus, en essayant de
   l'équiper. On l'expose des deux côtés : ce que chaque classe maîtrise, et
   qui peut porter chaque pièce. */
/* Règles imprimées au DOS DES CARTES de classe (voir ReglesDeClasseTest) :
   elles ne se déduisent d'aucune donnée envoyée par l'API, il faut donc les
   nommer ici pour que le joueur les lise ailleurs qu'au moment d'un refus. */
const SANS_METAL = ['druide', 'rogue'];
const DESAMORCE_SANS_OUTILS = ['nain', 'explorateur'];

const acces = computed(() => accesEquipement(classes.value, guide.value?.competences ?? []));

/** Tags de départ d'une classe + ceux que son arbre débloque. */
function maitrises(classe) {
    const base = Array.isArray(classe.tags_equipement) ? classe.tags_equipement : [];
    const debloquables = [];
    for (const t of talentsParClasse.value[classe.nom] ?? []) {
        const e = t.effet;
        if (!e || e.mecanique !== 'acces_equipement' || !Array.isArray(e.tags)) continue;
        for (const tag of e.tags) debloquables.push({ tag, talent: t.nom });
    }
    return { base, debloquables };
}

const sortsParElement = computed(() => {
    const m = {};
    for (const x of guide.value?.sorts ?? []) (m[x.element] ??= []).push(x);
    // ⚠ Tout élément inconnu de l'ordre déclaré est ajouté À LA FIN plutôt que
    // jeté : c'est ce filtrage muet qui faisait disparaître les 15 sorts des
    // répertoires de classe. Un catalogue incomplet doit se voir, pas se taire.
    const connus = ORDRE_ELEMENTS.filter((e) => m[e]);
    const autres = Object.keys(m).filter((e) => !ORDRE_ELEMENTS.includes(e)).sort();
    return [...connus, ...autres].map((e) => [e, m[e]]);
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
                    <p class="guide-sub">Bestiaire, talents, équipements, sorts, pièges — et les cartes dont tout cela est tiré.</p>
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
                        <!-- La RACE porte le socle de mouvement (doc 01 §4bis-2) :
                             sans elle, un Explorateur plus lent qu'un Rogue
                             paraissait arbitraire. -->
                        <span v-if="c.race" class="hero-race" :title="`Race : ${RACE[c.race]?.l ?? c.race}`">
                            <MSym :n="RACE[c.race]?.ic ?? 'person'" :size="13" />
                            {{ RACE[c.race]?.l ?? c.race }}
                        </span>
                    </div>
                    <div class="stat-row">
                        <span class="stat" title="PV de Body"><MSym n="favorite" fill :size="15" class="c-body" /> {{ c.pv_body }} <em>Body</em></span>
                        <span class="stat" title="PV de Mind"><MSym n="psychology" fill :size="15" class="c-mind" /> {{ c.pv_mind }} <em>Mind</em></span>
                        <span class="stat" title="Attribut Body"><MSym n="fitness_center" :size="15" /> {{ c.attr_body }} <em>attr. Body</em></span>
                        <span class="stat" title="Attribut Mind"><MSym n="neurology" :size="15" /> {{ c.attr_mind }} <em>attr. Mind</em></span>
                        <span class="stat" title="Dés d'attaque"><MSym n="swords" :size="15" class="c-atk" /> {{ c.des_attaque }} <em>attaque</em></span>
                        <span class="stat" title="Dés de défense"><MSym n="shield" :size="15" class="c-def" /> {{ c.des_defense }} <em>défense</em></span>
                        <span class="stat" :title="detailDeplacement(c)"><MSym n="directions_walk" :size="15" /> {{ c.deplacement_base }} <em>dépl.</em></span>
                        <span v-if="c.bonus_sac" class="stat" title="Bonus de sac"><MSym n="backpack" :size="15" /> +{{ c.bonus_sac }} <em>sac</em></span>
                    </div>
                    <div class="hero-talents-t"><MSym n="shield_with_heart" :size="14" /> Équipement maîtrisé</div>
                    <div v-if="maitrises(c).base.length" class="chips">
                        <span v-for="t in maitrises(c).base" :key="t" class="chip maitrise">
                            <MSym n="check" :size="11" /> {{ TAG_EQUIPEMENT[t] ?? t }}
                        </span>
                        <span
                            v-for="d in maitrises(c).debloquables"
                            :key="d.tag"
                            class="chip maitrise-off"
                            :title="`Débloqué par le talent « ${d.talent} »`"
                        >
                            <MSym n="lock_open" :size="11" /> {{ TAG_EQUIPEMENT[d.tag] ?? d.tag }} — via {{ d.talent }}
                        </span>
                    </div>
                    <p v-else-if="!c.objets_autorises" class="maitrise-libre">
                        <MSym n="info" :size="13" /> Aucune restriction déclarée : cette classe peut porter tout l'équipement.
                    </p>

                    <!-- Liste NOMINATIVE (Moine) : elle remplace les maîtrises,
                         elle ne s'y ajoute pas. Sans elle la fiche montrait un
                         Moine réduit à son talisman, donc sans aucune arme. -->
                    <p v-if="c.objets_autorises?.length" class="regle-carte">
                        <MSym n="playlist_add_check" :size="13" />
                        Ne manie que : <strong>{{ c.objets_autorises.join(', ') }}</strong>
                    </p>

                    <!-- Le Moine ne porte RIEN : sa liste nominative le refuse
                         mécaniquement, mais elle ne l'énonce pas — un joueur ne
                         doit pas avoir à déduire une interdiction d'une absence. -->
                    <p v-if="c.nom === 'moine'" class="regle-carte">
                        <MSym n="block" :size="13" />
                        Ne porte <strong>ni armure ni bouclier</strong>
                    </p>
                    <p v-if="SANS_METAL.includes(c.nom)" class="regle-carte">
                        <MSym n="block" :size="13" />
                        Ne porte aucune <strong>armure métallique</strong>{{ c.nom === 'rogue' ? ' ni bouclier' : '' }}
                    </p>
                    <!-- Le Barde n'a PAS de ligne ici, et c'est voulu : son
                         « +1 dé de défense sans métal » EST sa capacité de carte
                         « Léger sur ses pieds », listée plus bas et lue depuis le
                         catalogue. Énoncée aux deux endroits, la même règle se
                         lisait comme deux bonus distincts. -->
                    <p v-if="c.nom === 'chevalier'" class="regle-carte">
                        <MSym n="directions_walk" :size="13" />
                        Les armures ne <strong>ralentissent pas</strong> son mouvement
                    </p>
                    <p v-if="DESAMORCE_SANS_OUTILS.includes(c.nom)" class="regle-carte">
                        <MSym n="handyman" :size="13" />
                        Désamorce les pièges <strong>sans outils</strong> — seul un bouclier noir le fait échouer
                    </p>
                    <p v-if="c.nom === 'berserker'" class="regle-carte">
                        <MSym n="block" :size="13" />
                        N’utilise <strong>aucune arme à distance</strong>
                    </p>

                    <template v-if="sortsDeClasse(c.nom).length">
                        <div class="hero-talents-t">
                            <MSym n="auto_awesome" :size="14" /> Sorts de classe
                            <span class="tl-gratuit">{{ c.nom === 'elfe'
                                ? '3 au choix parmi ceux-ci, rechoisissables au hub'
                                : 'acquis d\'emblée, comme ses capacités de carte' }}</span>
                        </div>
                        <ul class="talent-ul">
                            <li v-for="s in sortsDeClasse(c.nom)" :key="s.id" class="talent-li">
                                <div class="tl-head">
                                    <span class="tl-nom">{{ s.nom }}</span>
                                    <span class="tl-type tt-sort">{{ TYPE_SORT[s.type] ?? s.type }}</span>
                                </div>
                            </li>
                        </ul>
                    </template>

                    <template v-if="(inneesParClasse[c.nom] ?? []).length">
                        <div class="hero-talents-t">
                            <MSym n="badge" :size="14" /> Capacités de carte
                            <span class="tl-gratuit">acquises d'emblée, sans coûter de point</span>
                        </div>
                        <ul class="talent-ul">
                            <li v-for="t in inneesParClasse[c.nom]" :key="t.id" class="talent-li">
                                <div class="tl-head">
                                    <span class="tl-nom">{{ t.nom }}</span>
                                    <span class="tl-type tt-innee">innée</span>
                                    <span v-if="techniquesDe(t).length" class="tl-prereq">
                                        <MSym n="style" :size="11" /> {{ techniquesDe(t).length }} techniques
                                    </span>
                                </div>
                                <div v-if="t.description" class="tl-desc">{{ t.description }}</div>
                                <!-- Les 4 cartes du Moine sont RECTO-VERSO : chacune
                                     porte deux techniques, listées ici parce qu'elles
                                     n'existent nulle part ailleurs dans le guide. -->
                                <ul v-if="techniquesDe(t).length" class="tech-ul">
                                    <li v-for="(tech, i) in techniquesDe(t)" :key="i" class="tech-li">
                                        <span class="tech-nom">{{ tech.nom }}</span>
                                        <span v-if="tech.resume" class="tech-res">{{ tech.resume }}</span>
                                    </li>
                                </ul>
                            </li>
                        </ul>
                    </template>

                    <div class="hero-talents-t"><MSym n="grid_view" :size="14" /> Grille de talents</div>
                    <template v-for="col in grilleParClasse[c.nom] ?? []" :key="col.colonne">
                        <div class="tl-cat"><MSym :n="col.icone" :size="13" fill /> {{ col.categorie }}</div>
                        <ul class="talent-ul">
                            <li v-for="t in col.noeuds" :key="t.id" class="talent-li">
                                <div class="tl-head">
                                    <span class="tl-rang">{{ t.rang }}</span>
                                    <span class="tl-nom">{{ t.nom }}</span>
                                    <span class="tl-type" :class="'tt-' + t.type">{{ TYPE_TALENT[t.type] ?? t.type }}</span>
                                </div>
                                <div v-if="t.description" class="tl-desc">{{ t.description }}</div>
                                <!-- L'avantage chiffré, dérivé de l'effet côté serveur : la
                                     page disait ce que le talent RACONTE, jamais ce qu'il VAUT. -->
                                <div v-if="t.avantage" class="tl-fx">
                                    <MSym :n="t.avantage_icone || 'hub'" :size="12" fill /> {{ t.avantage }}
                                </div>
                            </li>
                        </ul>
                    </template>
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
                                <span v-if="o.tag_equipement" class="tag maitrise">
                                    <MSym n="shield_with_heart" :size="11" /> {{ TAG_EQUIPEMENT[o.tag_equipement] ?? o.tag_equipement }}
                                </span>
                            </div>
                            <div v-if="effetVersChips(o.effet).length" class="chips">
                                <span v-for="(ch, i) in effetVersChips(o.effet)" :key="i" class="chip">{{ ch.texte }}</span>
                            </div>
                            <!-- D'où vient cette pièce : nom de la carte du
                                 plateau dont ses prix et ses dés sont tirés. -->
                            <p v-if="carteParObjet[o.nom]" class="provenance">
                                <MSym n="style" :size="12" /> Carte « {{ carteParObjet[o.nom].carte }} »
                                <span class="via">{{ carteParObjet[o.nom].paquet ?? carteParObjet[o.nom].paquetLibelle }}</span>
                            </p>
                            <!-- Qui peut la porter : la restriction ne se lisait
                                 qu'au refus, au moment d'équiper. -->
                            <p v-if="o.tag_equipement" class="porteurs">
                                <MSym n="group" :size="12" />
                                <span v-if="acces(o.tag_equipement).base.length">
                                    {{ acces(o.tag_equipement).base.map((n) => CLASSE[n]?.l ?? n).join(', ') }}
                                </span>
                                <em v-else>Aucune classe de départ</em>
                                <span v-for="d in acces(o.tag_equipement).debloque" :key="d.classe" class="porteur-deb">
                                    · {{ CLASSE[d.classe]?.l ?? d.classe }} <span class="via">via {{ d.talent }}</span>
                                </span>
                            </p>
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

            <!-- ===================== CARTES SOURCES ===================== -->
            <section v-show="tab === 'cartes'" class="guide-sec">
                <p class="cartes-intro">
                    Les armes, les armures et les artefacts ne sont pas des valeurs inventées :
                    chacun est la conversion d'une <strong>carte du jeu de plateau</strong>, dont le
                    texte devient des statistiques (prix, dés) et des mots-clés lus par le moteur.
                    Les potions, les parchemins et la trousse à outils viennent d'ailleurs
                    (deck de trésor, sorts, livret de règles).
                </p>

                <template v-for="paquet in guide.cartes ?? []" :key="paquet.cle">
                    <h3 class="grp-title">
                        <MSym n="style" :size="16" /> {{ paquet.libelle }}
                        <span class="grp-n">{{ paquet.cartes.length }}</span>
                    </h3>
                    <p class="cartes-src">
                        <MSym n="picture_as_pdf" :size="13" />
                        <a v-if="paquet.url" :href="paquet.url" target="_blank" rel="noopener">{{ paquet.source }}</a>
                        <span v-else>{{ paquet.source }}</span>
                    </p>

                    <div class="cartes-grille">
                        <div
                            v-for="c in paquet.cartes"
                            :key="c.carte"
                            class="carte-ligne"
                            :class="{ absente: !c.porte }"
                        >
                            <MSym :n="c.porte ? 'check_circle' : 'schedule'" :size="15" class="carte-ic" />
                            <div class="carte-corps">
                                <div class="carte-nom">
                                    {{ c.nom }}
                                    <span class="carte-vo">{{ c.carte }}</span>
                                    <span v-if="c.paquet" class="tag ghost">{{ c.paquet }}</span>
                                </div>
                                <!-- Une carte non portée dit CE QU'ELLE FAIT au
                                     plateau, et ce qui manque pour l'appliquer :
                                     une dette nommée se retrouve. -->
                                <p v-if="!c.porte && c.texte" class="carte-txt">{{ c.texte }}</p>
                                <p v-if="!c.porte && c.manque" class="carte-manque">
                                    <MSym n="construction" :size="12" /> {{ c.manque }}
                                </p>
                            </div>
                        </div>
                    </div>
                </template>

                <p v-if="cartesAbsentes.length" class="cartes-bilan">
                    <MSym n="info" :size="14" />
                    {{ cartesAbsentes.reduce((n, p) => n + p.cartes.length, 0) }} cartes existent au
                    plateau et ne sont pas encore jouables ici — chacune attend une mécanique
                    précise, listée ci-dessus. Elles ne sont pas semées : une carte dont le moteur
                    n'applique pas l'effet serait une règle promise et jamais tenue.
                </p>
            </section>
        </main>
    </div>
</template>

<style scoped>
/* ---- onglet « Cartes sources » ---- */
.cartes-intro { max-width: 900px; margin: 0 0 18px; color: var(--ink-300); font-size: 14px; line-height: 1.6; }
.cartes-src { margin: -6px 0 12px; font-size: 12px; color: var(--ink-400); display: flex; align-items: center; gap: 6px; }
.cartes-src a { color: var(--torch); }
.cartes-grille { display: grid; gap: 8px; margin-bottom: 22px; }
.carte-ligne { display: flex; gap: 10px; padding: 10px 12px; border-radius: 10px;
  background: color-mix(in srgb, var(--stone-900) 80%, transparent); border: 1px solid var(--stone-800); }
.carte-ligne.absente { opacity: 0.82; border-style: dashed; }
.carte-ic { flex: none; margin-top: 2px; color: var(--moss, #7fae6a); }
.carte-ligne.absente .carte-ic { color: var(--ink-500); }
.carte-corps { min-width: 0; }
.carte-nom { font-weight: 700; font-size: 14px; display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.carte-vo { font-weight: 500; font-size: 12px; color: var(--ink-500); font-style: italic; }
.carte-txt { margin: 4px 0 0; font-size: 13px; color: var(--ink-300); line-height: 1.5; }
.carte-manque { margin: 5px 0 0; font-size: 12px; color: var(--ink-400); display: flex; align-items: flex-start; gap: 5px; }
.cartes-bilan { max-width: 900px; margin: 4px 0 0; font-size: 13px; color: var(--ink-400);
  display: flex; align-items: flex-start; gap: 6px; line-height: 1.55; }
.provenance { margin: 6px 0 0; font-size: 12px; color: var(--ink-500); display: flex; align-items: center; gap: 5px; }

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
/* Capacité de CARTE : gratuite, donc distinguée d'un nœud qu'on achète. */
.tt-innee { color: var(--torch); background: rgba(255, 143, 107, 0.12); }
.tt-sort { color: var(--elem-water, #6fb6ff); }
/* Race : une identité, affichée près du nom et non parmi les statistiques. */
.hero-race { display: inline-flex; align-items: center; gap: 4px; margin-left: auto;
  padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700;
  color: var(--ink-300); background: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(255, 255, 255, 0.08); }
.tl-gratuit { font-size: 10px; font-weight: 600; text-transform: none; letter-spacing: 0;
  color: var(--ink-500); margin-left: 6px; }
/* Les deux faces d'une carte de style du Moine. */
.tech-ul { list-style: none; margin: 6px 0 0; padding: 0 0 0 10px; border-left: 2px solid rgba(201, 162, 74, 0.25); }
.tech-li { margin-top: 4px; font-size: 12px; }
.tech-nom { font-weight: 700; color: var(--torch); margin-right: 6px; }
.tech-res { color: var(--ink-400, #9c8f76); }
.tl-prereq { display: inline-flex; align-items: center; gap: 3px; font-size: 10px; font-weight: 700; color: var(--ink-600); }
.tl-desc { font-size: 12.5px; color: var(--ink-300); margin-top: 4px; line-height: 1.45; }
/* La GRILLE : un intertitre par colonne, un numéro de ligne par nœud, et
   l'avantage chiffré sous la phrase de jeu. */
.tl-cat { display: flex; align-items: center; gap: 6px; margin: 12px 0 6px; font-family: var(--font-display);
  font-size: 12px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--gold); }
.tl-rang { flex: none; width: 18px; height: 18px; border-radius: 50%; display: grid; place-items: center;
  font-size: 10px; font-weight: 800; color: var(--stone-950); background: var(--gold); }
.tl-fx { display: inline-flex; align-items: center; gap: 4px; margin-top: 5px; padding: 2px 8px; border-radius: 99px;
  font-size: 11.5px; font-weight: 700; color: var(--gold); background: oklch(0.80 0.135 88 / 0.12); }

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

/* ---- maîtrises d'équipement (doc 01 §7) ----
   Acquise = pleine ; débloquable par un talent = atténuée et pointillée, pour
   qu'on distingue « je peux » de « je pourrai ». */
.chip.maitrise { color: var(--gold, #d8a23a); border-color: oklch(0.80 0.135 88 / 0.4); }
.chip.maitrise .msym { color: var(--gold, #d8a23a); }
.chip.maitrise-off { color: var(--ink-500); border-style: dashed; }
.tag.maitrise { color: var(--gold, #d8a23a); border-color: oklch(0.80 0.135 88 / 0.4);
  display: inline-flex; align-items: center; gap: 4px; }
.maitrise-libre { display: flex; align-items: center; gap: 6px; margin: 0 0 4px;
  font-size: 12px; color: var(--ink-500); font-style: italic; }
/* Règles du dos de carte : lisibles d'un coup d'œil, sous les maîtrises.
   Un joueur ne doit pas découvrir la règle au moment d'un refus d'équiper. */
.regle-carte { display: flex; align-items: flex-start; gap: 6px; margin: 6px 0 0;
  font-size: 12px; line-height: 1.45; color: var(--ink-400, #b9ab8f); }
.regle-carte .msym { flex: none; margin-top: 1px; color: var(--gold, #c9a24a); }
.regle-carte strong { color: var(--ink-200, #e8dcc6); font-weight: 600; }
.porteurs { display: flex; flex-wrap: wrap; align-items: center; gap: 5px; margin: 8px 0 0;
  font-size: 11.5px; color: var(--ink-400, #b9ab8f); }
.porteurs .msym { color: var(--ink-500); }
.porteurs em { font-style: italic; color: var(--ink-500); }
.porteur-deb { color: var(--ink-500); }
.porteur-deb .via { font-style: italic; opacity: 0.8; }
</style>
