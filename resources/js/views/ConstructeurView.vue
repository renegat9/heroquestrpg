<script setup>
// CONSTRUCTEUR DE CAMPAGNES — mode classique (lots B1/B2, `docs/plan-mode-classique.md` §8 ; contrat « Constructeur
// de campagnes »). Un outil de BUREAU : on y dessine un plateau, on y pose les quêtes, leurs notes « quand → alors ».
//
// Ce que cet écran NE fait PAS, exprès :
//  - il ne valide rien lui-même. Chaque modification est envoyée à `POST /constructeur/validation` (débouncée) et les
//    refus affichés sont ceux du serveur, avec leur chemin — « le serveur publie la décision ».
//  - il ne connaît aucun vocabulaire : couches, rotations, côtés, types de porte, déclencheurs et effets des notes,
//    emprise des meubles viennent tous de `GET /constructeur/catalogues`.
//  - il n'écrit dans le document AUCUNE clé d'interface : la validation refuse toute clé inconnue. L'état de l'écran
//    (outil choisi, sélection) vit à côté du document, jamais dedans.
import { computed, onMounted, reactive, ref, watch } from 'vue';
import MSym from '../components/ui/MSym.vue';
import ProfilMonstre from '../components/constructeur/ProfilMonstre.vue';
import TableDeCases from '../components/constructeur/TableDeCases.vue';
import { useApi } from '../composables/useApi';

const api = useApi();
const CASE = 26;

// ---------------------------------------------------------------- chargement
const catalogues = ref(null);
const campagnes = ref([]);
const doc = ref(null);            // le document de la campagne, tel que PUT l'accepte
const slugCharge = ref(null);     // le slug sous lequel ce document a été LU (null = nouvelle campagne)
const erreur = ref('');
const message = ref('');

async function chargerListe() {
    campagnes.value = (await api.campagnesConstruites()).campagnes ?? [];
}

onMounted(async () => {
    try {
        catalogues.value = await api.constructeurCatalogues();
        await chargerListe();
    } catch (e) {
        erreur.value = e.message;
    }
});

const vocab = computed(() => catalogues.value?.vocabulaires ?? {});
const couches = computed(() => catalogues.value?.couches ?? {});
const nomDe = (couche, slug) => (couches.value[couche] ?? catalogues.value?.[couche] ?? []).find((l) => l.slug === slug)?.nom ?? slug;

async function ouvrir(slug) {
    erreur.value = '';
    message.value = '';
    try {
        doc.value = await api.constructeurLire(slug);
        slugCharge.value = slug;
        onglet.value = 'campagne';
        queteIndex.value = doc.value.quetes.length ? 0 : null;
    } catch (e) {
        erreur.value = e.message;
    }
}

function nouvelle() {
    doc.value = {
        format: (vocab.value.formats_acceptes ?? [1])[0],
        slug: 'nouvelle-campagne',
        titre: 'Nouvelle campagne',
        auteur: null,
        mention: null,
        prerequis_heros: [],
        plateau: { largeur: 26, hauteur: 19, salles: [] },
        quetes: [],
    };
    slugCharge.value = null;
    onglet.value = 'plateau';
    queteIndex.value = null;
    message.value = '';
}

// ---------------------------------------------------------------- validation en direct (le serveur décide)
const refus = ref([]);
const validationEnCours = ref(false);
let minuteur = null;

watch(doc, () => {
    if (!doc.value) return;
    clearTimeout(minuteur);
    minuteur = setTimeout(valider, 500);
}, { deep: true });

async function valider() {
    if (!doc.value) return;
    validationEnCours.value = true;
    try {
        refus.value = (await api.constructeurValider(doc.value)).refus ?? [];
    } catch (e) {
        refus.value = e.donnees?.refus ?? [{ chemin: '', code: 'erreur', message: e.message }];
    } finally {
        validationEnCours.value = false;
    }
}

const refusDeLaQuete = (i) => refus.value.filter((r) => r.chemin.startsWith(`quetes.${i}.`) || r.chemin === `quetes.${i}`);
const refusHorsQuetes = computed(() => refus.value.filter((r) => !r.chemin.startsWith('quetes.')));

// ---------------------------------------------------------------- enregistrement
const enregistrement = ref(false);
// Un slug déjà pris par une AUTRE campagne : l'enregistrer la remplacerait (PUT crée OU remplace).
const ecraserait = computed(() => doc.value && doc.value.slug !== slugCharge.value
    && campagnes.value.some((c) => c.slug === doc.value.slug));

async function enregistrer() {
    if (ecraserait.value && !window.confirm(`Une campagne « ${doc.value.slug} » existe déjà : l'enregistrer ici la REMPLACERA. Continuer ?`)) {
        return;
    }
    enregistrement.value = true;
    message.value = '';
    try {
        await api.constructeurEnregistrer(doc.value.slug, doc.value);
        slugCharge.value = doc.value.slug;
        message.value = 'Campagne enregistrée.';
        await chargerListe();
    } catch (e) {
        refus.value = e.donnees?.refus ?? refus.value;
        message.value = e.donnees?.refus ? 'Refusée : la campagne n\'est pas valide (voir les refus).' : e.message;
    } finally {
        enregistrement.value = false;
    }
}

// ---------------------------------------------------------------- onglets
const onglet = ref('campagne');
const queteIndex = ref(null);
const quete = computed(() => (queteIndex.value === null ? null : doc.value?.quetes[queteIndex.value] ?? null));

function basculerClasse(slug) {
    const liste = doc.value.prerequis_heros;
    const i = liste.indexOf(slug);
    if (i >= 0) liste.splice(i, 1); else liste.push(slug);
}

// ---------------------------------------------------------------- géométrie (dessin seulement)
const L = computed(() => Math.max(1, Number(doc.value?.plateau?.largeur) || 1));
const H = computed(() => Math.max(1, Number(doc.value?.plateau?.hauteur) || 1));
const salles = computed(() => doc.value?.plateau?.salles ?? []);
// `parties` : les rectangles de plus d'une salle en L (plateau officiel, René 2026-11-19) — dessinés, jamais tracés ici.
const rectsDe = (s) => [s, ...(s.parties ?? [])];
const salleEn = (x, y) => salles.value.find((s) => rectsDe(s).some((r) => x >= r.x && x < r.x + r.l && y >= r.y && y < r.y + r.h)) ?? null;
const DECALAGES = { n: [0, -1], e: [1, 0], s: [0, 1], o: [-1, 0] };
const OPPOSE = { n: 's', s: 'n', e: 'o', o: 'e' };

/** Les attributs `x1 y1 x2 y2` du trait d'une porte posée sur le bord `cote` de la case (x, y). */
function ligneBord(x, y, cote) {
    const [x0, y0, x1, y1] = [x * CASE, y * CASE, (x + 1) * CASE, (y + 1) * CASE];
    const [a, b, c, d] = { n: [x0, y0, x1, y0], s: [x0, y1, x1, y1], o: [x0, y0, x0, y1], e: [x1, y0, x1, y1] }[cote] ?? [x0, y0, x0, y0];
    return { x1: a, y1: b, x2: c, y2: d };
}

/** La case sous la souris, et le côté de la case le plus proche du pointeur. */
function caseDepuis(evt) {
    const r = evt.currentTarget.getBoundingClientRect();
    const px = (evt.clientX - r.left) / CASE;
    const py = (evt.clientY - r.top) / CASE;
    const x = Math.floor(px);
    const y = Math.floor(py);
    const fx = px - x;
    const fy = py - y;
    const distances = { n: fy, s: 1 - fy, o: fx, e: 1 - fx };
    const cote = Object.keys(distances).reduce((a, b) => (distances[a] <= distances[b] ? a : b));
    return { x, y, cote };
}

// ---------------------------------------------------------------- plateau : dessiner les salles
const sallesSel = ref(null);
const trace = reactive({ actif: false, x0: 0, y0: 0, x1: 0, y1: 0 });
const rectTrace = computed(() => ({
    x: Math.min(trace.x0, trace.x1), y: Math.min(trace.y0, trace.y1),
    l: Math.abs(trace.x1 - trace.x0) + 1, h: Math.abs(trace.y1 - trace.y0) + 1,
}));

function plateauDebut(evt) {
    const c = caseDepuis(evt);
    Object.assign(trace, { actif: true, x0: c.x, y0: c.y, x1: c.x, y1: c.y });
}
function plateauDeplace(evt) {
    if (!trace.actif) return;
    const c = caseDepuis(evt);
    trace.x1 = Math.max(0, Math.min(L.value - 1, c.x));
    trace.y1 = Math.max(0, Math.min(H.value - 1, c.y));
}
function plateauFin() {
    if (!trace.actif) return;
    trace.actif = false;
    const r = rectTrace.value;
    const existante = r.l === 1 && r.h === 1 ? salleEn(r.x, r.y) : null;
    if (existante) {
        sallesSel.value = existante;
        return;
    }
    let n = salles.value.length + 1;
    while (salles.value.some((s) => s.id === `salle-${n}`)) n++;
    const salle = { id: `salle-${n}`, x: r.x, y: r.y, l: r.l, h: r.h };
    doc.value.plateau.salles.push(salle);
    sallesSel.value = salle;
}
function retirerSalle(salle) {
    const id = salle.id;
    doc.value.plateau.salles.splice(doc.value.plateau.salles.indexOf(salle), 1);
    doc.value.quetes.forEach((q) => { q.salles_ouvertes = q.salles_ouvertes.filter((s) => s !== id); });
    if (sallesSel.value === salle) sallesSel.value = null;
}
function renommerSalle(salle, nouvel) {
    const ancien = salle.id;
    salle.id = nouvel;
    doc.value.quetes.forEach((q) => {
        q.salles_ouvertes = q.salles_ouvertes.map((s) => (s === ancien ? nouvel : s));
        (q.notes ?? []).forEach((note) => {
            if (note.quand?.cible?.salle === ancien) note.quand.cible.salle = nouvel;
        });
    });
}

// ---------------------------------------------------------------- quêtes : liste
function nouvelleQuete() {
    let n = doc.value.quetes.length + 1;
    while (doc.value.quetes.some((q) => q.slug === `quete-${n}`)) n++;
    doc.value.quetes.push({
        slug: `quete-${n}`, titre: `Quête ${n}`, ouverture: '', monstre_errant: null,
        salles_ouvertes: [], portes: [], blocs: [], mobilier: [], monstres: [], pieges: [], terrain: [],
        depart: { x: 0, y: 0 }, sortie: null, recompense: null,
        fin: { type: 'vaincre', cibles: [], libelle: '' }, notes: [],
    });
    queteIndex.value = doc.value.quetes.length - 1;
    onglet.value = 'quete';
}
function deplacerQuete(i, sens) {
    const j = i + sens;
    const q = doc.value.quetes;
    if (j < 0 || j >= q.length) return;
    [q[i], q[j]] = [q[j], q[i]];
    queteIndex.value = j;
}
function retirerQuete(i) {
    if (!window.confirm(`Retirer la quête « ${doc.value.quetes[i].titre} » de la campagne ?`)) return;
    doc.value.quetes.splice(i, 1);
    queteIndex.value = doc.value.quetes.length ? Math.min(i, doc.value.quetes.length - 1) : null;
}

// ---------------------------------------------------------------- quêtes : outils de la carte
const outil = ref('salle');
const choix = reactive({ terrainCache: false, piegeIntrouvable: false, piegeIndesarmable: false, typePorte: 'normale', verrouPorte: '', objetVerrou: '', conditionVerrou: '', mobilier: '', rotation: 0, monstre: '', piege: '', terrain: '', acces: 'escalier' });
watch(catalogues, (c) => {
    if (!c) return;
    choix.mobilier ||= c.couches.mobilier?.[0]?.slug ?? '';
    choix.monstre ||= c.couches.monstres?.[0]?.slug ?? '';
    choix.piege ||= c.couches.pieges?.[0]?.slug ?? '';
    choix.terrain ||= c.couches.terrain?.[0]?.slug ?? '';
    // Verrous de porte (lot K5a) : premier objet et première condition du catalogue, pour que la liste ne soit jamais vide.
    choix.objetVerrou ||= c.objets?.[0]?.slug ?? '';
    choix.conditionVerrou ||= c.vocabulaires?.conditions_de_porte?.[0]?.nom ?? '';
});
const OUTILS = [
    ['salle', 'meeting_room', 'Ouvrir / fermer une salle'],
    ['porte', 'door_front', 'Porte (sur le bord cliqué)'],
    ['bloc', 'block', 'Bloc de couloir'],
    ['mobilier', 'chair', 'Meuble'],
    ['monstre', 'skull', 'Monstre'],
    ['piege', 'warning', 'Piège'],
    ['terrain', 'water', 'Terrain'],
    ['cible', 'my_location', 'Cible de fin (un monstre)'],
    ['depart', 'login', 'Entrée'],
    ['sortie', 'logout', 'Sortie'],
    ['fausse', 'block', 'Fausse porte de sortie (bord cliqué)'],
    ['gomme', 'ink_eraser', 'Effacer'],
];

// Verrous de porte (lot K5a) : libellés des types que `vocab.verrous_de_porte` publie.
const LIBELLES_VERROU = { note: "s'ouvre par une note", cle: 'à clé', pierre: 'de pierre', condition: 'à condition' };
/** Le verrou choisi dans la barre d'outils. Rien du tout sans verrou : jamais de clé `verrou` vide. */
function verrouChoisi() {
    const v = choix.verrouPorte;
    if (choix.typePorte !== 'normale' || !v) return {};
    if (v === 'cle') return { verrou: { type: 'cle', objet: choix.objetVerrou } };
    if (v === 'condition') return { verrou: { type: 'condition', condition: choix.conditionVerrou } };
    return { verrou: { type: v } };
}

/** Une porte posée sur le bord du COULOIR se ramène au bord de la salle ouverte d'en face (la même arête). */
function bordDeSalle(q, x, y, cote) {
    const ouverte = (s) => s && q.salles_ouvertes.includes(s.id);
    if (ouverte(salleEn(x, y))) return { x, y, cote };
    const [dx, dy] = DECALAGES[cote];
    if (ouverte(salleEn(x + dx, y + dy))) return { x: x + dx, y: y + dy, cote: OPPOSE[cote] };
    return { x, y, cote };
}
const memeBord = (a, b) => (a.x === b.x && a.y === b.y && a.cote === b.cote)
    || (a.x + DECALAGES[a.cote][0] === b.x && a.y + DECALAGES[a.cote][1] === b.y && OPPOSE[a.cote] === b.cote);

const couvre = (m, x, y) => {
    const [l, h] = couches.value.mobilier?.find((t) => t.slug === m.slug)?.emprises?.[m.rotation] ?? [1, 1];
    return x >= m.x && x < m.x + l && y >= m.y && y < m.y + h;
};

/** Retire l'élément d'index `i` d'une liste désignée par des notes ou la fin, et recale ces désignations. */
function recaler(q, liste, i) {
    const cle = liste === 'monstres' ? 'monstre' : 'porte';
    if (liste === 'monstres' && Array.isArray(q.fin?.cibles)) {
        q.fin.cibles = q.fin.cibles.filter((c) => c !== i).map((c) => (c > i ? c - 1 : c));
    }
    (q.notes ?? []).forEach((note) => {
        const c = note.quand?.cible;
        if (!c || typeof c[cle] !== 'number') return;
        // La note visait l'élément retiré : elle ne désigne plus rien, et la validation le dira — plutôt que de viser
        // en silence l'élément suivant, qui a pris son index.
        if (c[cle] === i) c[cle] = -1; else if (c[cle] > i) c[cle] -= 1;
    });
    // Lot K5a : l'effet `ouvrir_porte` désigne une porte par son index, comme une cible : même recalage.
    if (liste === 'portes') {
        (q.notes ?? []).forEach((note) => {
            (note.alors ?? []).forEach((effet) => {
                if (effet.effet !== 'ouvrir_porte' || typeof effet.porte !== 'number') return;
                if (effet.porte === i) effet.porte = -1; else if (effet.porte > i) effet.porte -= 1;
            });
        });
    }
}

// Choisir une case sur la carte pour un champ de note (`case`, apparition) : le prochain clic la remplit.
const pointage = ref(null);  // { cible: objet, libelle }
function pointer(cible, libelle) {
    pointage.value = { cible, libelle };
}

function clicQuete(evt) {
    const q = quete.value;
    if (!q) return;
    const { x, y, cote } = caseDepuis(evt);
    if (x < 0 || y < 0 || x >= L.value || y >= H.value) return;

    if (pointage.value) {
        pointage.value.cible.x = x;
        pointage.value.cible.y = y;
        pointage.value = null;
        return;
    }

    const ici = (e) => e.x === x && e.y === y;
    switch (outil.value) {
        case 'salle': {
            const s = salleEn(x, y);
            if (!s) return;
            const i = q.salles_ouvertes.indexOf(s.id);
            if (i >= 0) q.salles_ouvertes.splice(i, 1); else q.salles_ouvertes.push(s.id);
            return;
        }
        case 'porte': {
            const b = bordDeSalle(q, x, y, cote);
            const i = q.portes.findIndex((p) => memeBord(p, b));
            if (i >= 0) {
                q.portes.splice(i, 1);
                recaler(q, 'portes', i);
            } else {
                q.portes.push({ ...b, type: choix.typePorte, ...verrouChoisi() });
            }
            return;
        }
        case 'bloc': {
            const i = q.blocs.findIndex(ici);
            if (i >= 0) q.blocs.splice(i, 1); else q.blocs.push({ x, y });
            return;
        }
        case 'mobilier': {
            const i = q.mobilier.findIndex((m) => couvre(m, x, y));
            if (i >= 0) q.mobilier.splice(i, 1); else q.mobilier.push({ slug: choix.mobilier, x, y, rotation: Number(choix.rotation) });
            return;
        }
        case 'monstre': {
            const i = q.monstres.findIndex(ici);
            if (i >= 0) {
                q.monstres.splice(i, 1);
                recaler(q, 'monstres', i);
            } else {
                q.monstres.push({ slug: choix.monstre, x, y });
            }
            return;
        }
        case 'piege':
        case 'terrain': {
            const liste = outil.value === 'piege' ? q.pieges : q.terrain;
            const i = liste.findIndex(ici);
            if (i >= 0) liste.splice(i, 1);
            else if (outil.value === 'piege') {
                // Lot K5d : les drapeaux du piège, posés seulement quand ils valent true.
                liste.push({
                    slug: choix.piege, x, y,
                    ...(choix.piegeIntrouvable ? { introuvable: true } : {}),
                    ...(choix.piegeIndesarmable ? { indesarmable: true } : {}),
                });
            } else liste.push({ slug: choix.terrain, x, y, ...(choix.terrainCache ? { cachee: true } : {}) });
            return;
        }
        case 'cible': {
            const i = q.monstres.findIndex(ici);
            if (i < 0 || q.fin?.type !== 'vaincre') return;
            const j = q.fin.cibles.indexOf(i);
            if (j >= 0) q.fin.cibles.splice(j, 1); else q.fin.cibles.push(i);
            return;
        }
        case 'fausse': {
            // Une fausse porte de sortie : posée comme une porte de sortie, sur le bord d'une salle ouverte. Clé
            // FACULTATIVE du format : absente quand la quête n'en a aucune.
            const b = bordDeSalle(q, x, y, cote);
            const liste = q.fausses_sorties ?? [];
            const i = liste.findIndex((f) => memeBord(f, b));
            if (i >= 0) liste.splice(i, 1); else liste.push(b);
            if (liste.length) q.fausses_sorties = liste; else delete q.fausses_sorties;
            return;
        }
        case 'depart':
        case 'sortie': {
            const acces = choix.acces === 'porte' ? { type: 'porte', ...bordDeSalle(q, x, y, cote) } : { x, y };
            q[outil.value] = acces;
            return;
        }
        case 'gomme': {
            for (const [liste, test] of [['monstres', ici], ['pieges', ici], ['terrain', ici], ['blocs', ici], ['mobilier', (m) => couvre(m, x, y)]]) {
                const i = q[liste].findIndex(test);
                if (i >= 0) {
                    q[liste].splice(i, 1);
                    if (liste === 'monstres') recaler(q, 'monstres', i);
                    return;
                }
            }
        }
    }
}

const sigle = (slug) => (nomDe('monstres', slug) ?? slug).slice(0, 2);

// ---------------------------------------------------------------- fin, récompense
function changerTypeFin(type) {
    const libelle = quete.value.fin?.libelle ?? '';
    // Les clés de chaque type sont celles de la validation (`ValidationCampagne::CLES_FIN`) : « sortir » n'en a pas d'autre.
    quete.value.fin = type === 'drapeau' ? { type, drapeau: '', libelle }
        : type === 'sortir' || type === 'ramener' ? { type, libelle }
        : { type, cibles: [], libelle };
    // « ramener » (lot K5k) exige une charge : on en pose une à compléter, plutôt que de laisser un refus muet.
    if (type === 'ramener' && !quete.value.charge) quete.value.charge = { nom: '', x: 0, y: 0 };
}
const LIBELLES_FIN = {
    vaincre: 'vaincre des monstres désignés',
    drapeau: 'un drapeau posé par une note',
    sortir: 'trouver la sortie et quitter le donjon',
    ramener: 'ramener une charge hors du donjon',
};
// Lot K5k (R5) : `quete.charge` — l'objet encombrant à porter ; la clé disparaît quand on la retire.
function basculerCharge(avec) {
    if (avec) quete.value.charge = { nom: '', x: 0, y: 0 };
    else delete quete.value.charge;
}
// QUÊTE SOLO (lot S1) : `regles.solo` — clé FACULTATIVE du format, absente quand la quête se joue à plusieurs. La
// classe proposée est l'une de celles que la campagne EXIGE : la validation refuse les autres (`solo_sans_prerequis`).
function changerSolo(classe) {
    if (classe) quete.value.regles = { ...(quete.value.regles ?? {}), solo: classe };
    else if (quete.value.regles) {
        delete quete.value.regles.solo;
        if (!Object.keys(quete.value.regles).length) delete quete.value.regles;
    }
}
// Lot K5c (R8) : `regles.marche_ferme` — présent seulement quand il vaut true, jamais une clé `false` décorative.
function changerMarcheFerme(ferme) {
    if (ferme) quete.value.regles = { ...(quete.value.regles ?? {}), marche_ferme: true };
    else if (quete.value.regles) {
        delete quete.value.regles.marche_ferme;
        if (!Object.keys(quete.value.regles).length) delete quete.value.regles;
    }
}
// Lot K5e (R6) : `regles.sans_fouille` — les salles ouvertes qu'on ne fouille pas ; la clé disparaît quand la liste est vide.
function basculerSansFouille(id, coche) {
    const liste = new Set(quete.value.regles?.sans_fouille ?? []);
    if (coche) liste.add(id); else liste.delete(id);
    if (liste.size) quete.value.regles = { ...(quete.value.regles ?? {}), sans_fouille: [...liste] };
    else if (quete.value.regles) {
        delete quete.value.regles.sans_fouille;
        if (!Object.keys(quete.value.regles).length) delete quete.value.regles;
    }
}
// Lots K5n/K5o : les autres `regles` des livrets — une clé n'existe que remplie ; `regles` disparaît quand il est vide.
function poserRegle(cle, valeur) {
    if (valeur === undefined) {
        if (quete.value.regles) {
            delete quete.value.regles[cle];
            if (!Object.keys(quete.value.regles).length) delete quete.value.regles;
        }
    } else quete.value.regles = { ...(quete.value.regles ?? {}), [cle]: valeur };
}
// `primes_monstres {slug: or}` et `monstres_pacifies_par_drapeau {slug: drapeau}` : une table par monstre du catalogue.
function ajouterAuMonstre(cle, slug, defaut) {
    if (!slug) return;
    poserRegle(cle, { ...(quete.value.regles?.[cle] ?? {}), [slug]: defaut });
}
function retirerDuMonstre(cle, slug) {
    const table = { ...(quete.value.regles?.[cle] ?? {}) };
    delete table[slug];
    poserRegle(cle, Object.keys(table).length ? table : undefined);
}
// `quete.dangers` (K5o) : rocher roulant (un trajet de 2 cases au moins) ou brume de la mort, en couloir.
function ajouterDanger(type) {
    if (!Array.isArray(quete.value.dangers)) quete.value.dangers = [];
    quete.value.dangers.push(type === 'rocher' ? { type, run: [{ x: 0, y: 0 }, { x: 1, y: 0 }] } : { type, x: 0, y: 0 });
}
function retirerDanger(i) {
    quete.value.dangers.splice(i, 1);
    if (!quete.value.dangers.length) delete quete.value.dangers;
}
// Profil d'auteur ouvert (R9) — un état d'écran, pas une donnée de la quête.
const profilsOuverts = reactive(new Set());
const basculerProfil = (cle) => (profilsOuverts.has(cle) ? profilsOuverts.delete(cle) : profilsOuverts.add(cle));
const aUnProfil = (m) => ['nom', 'deplacement', 'attaque', 'defense', 'pv_body', 'pv_mind', 'vulnerable_uniquement_a', 'invulnerable_casse_arme', 'fuite'].some((k) => k in m);
// Le bloc des livrets s'ouvre de lui-même quand la quête en utilise déjà une clé.
const aDesReglesDeLivret = computed(() => {
    const q = quete.value;
    if (!q) return false;
    const r = q.regles ?? {};
    return ['primes_monstres', 'portes_teleportation', 'fouille_piege_un_par_un', 'monstres_pacifies_par_drapeau', 'degats_en_mind', 'ordre_libre', 'obscurite_magique', 'deguisement'].some((k) => k in r)
        || (q.dangers ?? []).length > 0 || (q.glyphes ?? []).length > 0 || (q.salles_speciales ?? []).length > 0 || (q.monstres ?? []).some(aUnProfil) || (q.terrain ?? []).some((t) => 'paire_id' in t);
});
// `paire_id` (K5o) : seulement sur un terrain qui téléporte vers sa jumelle — décidé par le catalogue (`paire`).
const terrainsAPaire = computed(() => (quete.value?.terrain ?? []).map((t, i) => ({ t, i })).filter(({ t }) => couches.value.terrain?.find((c) => c.slug === t.slug)?.paire));
function changerPaire(t, v) {
    if (v.trim() === '') delete t.paire_id; else t.paire_id = v.trim();
}

// Lot K5j (R4) : `regles.confiscation` — tout est pris au départ, repris en fouillant la salle choisie ; « non » retire
// la clé (jamais une clé vide).
function changerConfiscation(salle) {
    if (salle) quete.value.regles = { ...(quete.value.regles ?? {}), confiscation: { salle } };
    else if (quete.value.regles) {
        delete quete.value.regles.confiscation;
        if (!Object.keys(quete.value.regles).length) delete quete.value.regles;
    }
}
function basculerRecompense(avec) {
    quete.value.recompense = avec ? { or: 0, partage: (vocab.value.partages ?? ['groupe'])[0] } : null;
}

// ---------------------------------------------------------------- mercenaires de la campagne (lot K6)
// `mercenaires {regime, offerts}` — clé de CAMPAGNE ; disparaît quand on choisit « aucun mercenaire ».
function changerRegimeMercenaires(regime) {
    if (!regime) delete doc.value.mercenaires;
    else doc.value.mercenaires = { regime, offerts: doc.value.mercenaires?.offerts ?? [] };
}
function basculerMercenaire(slug) {
    const liste = doc.value.mercenaires.offerts;
    const i = liste.indexOf(slug);
    if (i >= 0) liste.splice(i, 1); else liste.push(slug);
}

// ---------------------------------------------------------------- quête double (lot K5p, R10)
// `quete.double` : la PREMIÈRE moitié dans l'ordre est la principale (`{quete, passage}`), la seconde `{quete}` — et sa fin,
// sa sortie, sa récompense valent `null` (ce sont celles de la principale). Choisir l'autre moitié règle les DEUX quêtes :
// un lien à sens unique serait refusé (`double_invalide`).
const moitieDe = (q) => doc.value?.quetes.findIndex((x) => x.slug === q?.double?.quete) ?? -1;
const estPrincipale = computed(() => !!quete.value?.double && moitieDe(quete.value) > queteIndex.value);
function delierDouble(q) {
    if (!q?.double) return;
    const autre = doc.value.quetes[moitieDe(q)];
    delete q.double;
    if (autre?.double?.quete === q.slug) delete autre.double;
}
function changerDouble(slug) {
    delierDouble(quete.value);
    if (!slug) return;
    const j = doc.value.quetes.findIndex((x) => x.slug === slug);
    const autre = doc.value.quetes[j];
    delierDouble(autre);
    const [principale, seconde] = j > queteIndex.value ? [quete.value, autre] : [autre, quete.value];
    principale.double = { quete: seconde.slug, passage: { x: 0, y: 0 } };
    seconde.double = { quete: principale.slug };
    seconde.fin = null;
    seconde.sortie = null;
    seconde.recompense = null;
    delete seconde.suite;
}
function changerTypePassage(type) {
    const p = quete.value.double.passage;
    quete.value.double.passage = type === 'porte' ? { type, x: p.x, y: p.y, cote: 'n' } : { x: p.x, y: p.y };
}

// ---------------------------------------------------------------- suite (campagne ramifiée)
// Lot K5i (C2) : `quete.suite` — embranchements lus À LA VICTOIRE, dans l'ordre : le premier dont le drapeau est posé (ou
// qui n'en porte pas) décide ; sans embranchement retenu, la quête suivante dans l'ordre. Une entrée est `{quete: slug}`
// ou `{fin: true}`, plus `si_drapeau` facultatif. La clé disparaît quand la liste est vide : le serveur ne tolère ni
// `suite: []` ni `si_drapeau: ''`. Un embranchement sans drapeau doit être le dernier — l'aide le dit, le serveur le refuse.
// Une quête retirée ou renommée laisse ses embranchements pointer dans le vide : le serveur le nomme, on ne corrige pas.
const autresQuetes = computed(() => (doc.value?.quetes ?? []).filter((q) => q !== quete.value));
function ajouterEmbranchement() {
    if (!Array.isArray(quete.value.suite)) quete.value.suite = [];
    quete.value.suite.push(autresQuetes.value.length ? { quete: autresQuetes.value[0].slug } : { fin: true });
}
function retirerEmbranchement(i) {
    quete.value.suite.splice(i, 1);
    if (!quete.value.suite.length) delete quete.value.suite;
}
// `__fin__` = fin de la campagne ; sinon le slug d'une autre quête. Le drapeau de l'embranchement est conservé.
function changerDestination(i, valeur) {
    const entree = quete.value.suite[i];
    const nouvelle = valeur === '__fin__' ? { fin: true } : { quete: valeur };
    if (entree.si_drapeau !== undefined) nouvelle.si_drapeau = entree.si_drapeau;
    quete.value.suite.splice(i, 1, nouvelle);
}
// Un drapeau vide n'est jamais gardé : la clé disparaît.
function changerSiDrapeauSuite(i, valeur) {
    const entree = quete.value.suite[i];
    if (valeur.trim() === '') delete entree.si_drapeau; else entree.si_drapeau = valeur.trim();
}

// ---------------------------------------------------------------- notes « quand → alors »
const declencheurs = computed(() => vocab.value.declencheurs_de_note ?? {});
const effetsNote = computed(() => vocab.value.effets_de_note ?? {});
const LIBELLES_PARAM = { texte: 'Texte lu', or: 'Or', body: 'Points de Corps', slug: 'Objet', quantite: 'Quantité', nom: 'Nom du drapeau', monstres: 'Monstres', porte: 'Porte',
    sur_jet: 'Esquivé sous (1d6)', avec_casque: 'avec un Casque, sous' };
// Lots K5n/K5o : les paramètres optionnels BOOLÉENS d'un effet — posés seulement quand ils valent true (`ValidationCampagne`
// exige un booléen : un champ numérique les rendait refusés).
const BOOLEENS_EFFET = {
    toute_la_salle: 'tous les héros de sa salle',
    nombre_d6: 'seuls les 1d6 premiers paraissent',
    si_absent_du_groupe: 'seulement si personne ne l\'a déjà',
};
function basculerBooleen(objet, cle, avec) {
    if (avec) objet[cle] = true; else delete objet[cle];
}
// Une table de cases indexée par un dé, créée PLEINE (le serveur exige chaque face) à partir d'une case d'origine.
const FACES_D6 = ['1', '2', '3', '4', '5', '6'];
const SOMMES_2D6 = ['2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12'];
const tablePleine = (faces, x = 0, y = 0) => Object.fromEntries(faces.map((f) => [f, { x, y }]));
function basculerDestinationsD6(effet, avec) {
    if (avec) effet.destinations_d6 = tablePleine(FACES_D6, effet.x ?? 0, effet.y ?? 0); else delete effet.destinations_d6;
}

function notes() {
    if (!Array.isArray(quete.value.notes)) quete.value.notes = [];
    return quete.value.notes;
}
function nouvelleNote() {
    const lettres = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    const prises = new Set(notes().map((n) => n.repere));
    const repere = [...lettres].find((l) => !prises.has(l)) ?? String(notes().length + 1);
    const premier = quete.value.salles_ouvertes[0] ?? '';
    notes().push({ repere, quand: { declencheur: 'fouille_tresor', cible: { salle: premier } }, alors: [{ effet: 'texte', texte: '' }] });
}
function cibleParDefaut(genre) {
    return {
        salle: { salle: quete.value.salles_ouvertes[0] ?? '' },
        case: { case: { x: 0, y: 0 } },
        porte: { porte: 0 },
        monstre: { monstre: 0 },
        tour: { tour: 1 },
    }[genre];
}
const genreDeCible = (note) => Object.keys(note.quand?.cible ?? {})[0] ?? '';
function changerDeclencheur(note, d) {
    note.quand.declencheur = d;
    const genres = declencheurs.value[d]?.cibles ?? [];
    if (!genres.includes(genreDeCible(note))) note.quand.cible = cibleParDefaut(genres[0]);
}
function changerGenreCible(note, genre) {
    note.quand.cible = cibleParDefaut(genre);
}
function changerSiDrapeau(note, valeur) {
    if (valeur.trim() === '') delete note.quand.si_drapeau; else note.quand.si_drapeau = valeur.trim();
}
// Lot K6 : `sauf_drapeau` — la note ne joue que si ce drapeau N'est PAS posé (le glyphe resté actif).
function changerSaufDrapeau(note, valeur) {
    if (valeur.trim() === '') delete note.quand.sauf_drapeau; else note.quand.sauf_drapeau = valeur.trim();
}
// Lot K6 (R11) : `quete.salles_speciales` — une salle propre à la quête qui remplace les salles du plateau qu'elle touche.
function ajouterSalleSpeciale() {
    if (!Array.isArray(quete.value.salles_speciales)) quete.value.salles_speciales = [];
    quete.value.salles_speciales.push({ id: `speciale-${quete.value.salles_speciales.length + 1}`, x: 0, y: 0, l: 3, h: 3 });
}
function retirerSalleSpeciale(i) {
    quete.value.salles_speciales.splice(i, 1);
    if (!quete.value.salles_speciales.length) delete quete.value.salles_speciales;
}
// Lot K6 (First Light) : `quete.glyphes` — la clé disparaît quand la liste est vide.
function ajouterGlyphe() {
    if (!Array.isArray(quete.value.glyphes)) quete.value.glyphes = [];
    const n = quete.value.glyphes.length + 1;
    quete.value.glyphes.push({ id: `glyphe-${n}`, salle: quete.value.salles_ouvertes[0] ?? '', detruit_par: 'parchemin', drapeau: `glyphe_${n}_detruit` });
}
function retirerGlyphe(i) {
    quete.value.glyphes.splice(i, 1);
    if (!quete.value.glyphes.length) delete quete.value.glyphes;
}
function changerUneFois(note, valeur) {
    if (valeur) delete note.une_fois; else note.une_fois = false;
}
function effetParDefaut(nom) {
    const effet = { effet: nom };
    for (const p of effetsNote.value[nom]?.parametres ?? []) {
        effet[p] = { texte: '', or: 10, body: 1, slug: nom === 'debloquer_mercenaire' ? (doc.value.mercenaires?.offerts?.[0] ?? '') : (catalogues.value?.objets?.[0]?.slug ?? ''),
            nom: 'drapeau', monstres: [], porte: 0, x: 0, y: 0, jetons: 1, libelle: '', alors: [{ effet: 'texte', texte: '' }] }[p] ?? '';
    }
    return effet;
}
function changerEffet(note, j, nom) {
    note.alors.splice(j, 1, effetParDefaut(nom));
}
// Lot K6 : l'offre — ses effets en JSON (refusé s'il ne se lit pas), son coût (une clé vide disparaît).
function changerAlorsOffre(effet, texte) {
    try { effet.alors = JSON.parse(texte); } catch { erreur.value = 'Les effets de l\'offre ne sont pas du JSON lisible.'; }
}
function changerCoutOffre(effet, cle, valeur) {
    const cout = { ...(effet.cout ?? {}) };
    if (valeur === '' || valeur === null) delete cout[cle]; else cout[cle] = Number(valeur);
    if (Object.keys(cout).length) effet.cout = cout; else delete effet.cout;
}
function changerOptionnel(effet, cle, valeur) {
    if (valeur === '' || valeur === null) delete effet[cle]; else effet[cle] = Number(valeur);
}
const libellePorte = (p, i) => `porte ${i} — (${p.x}, ${p.y}) ${p.cote}${p.type === 'secrete' ? ', secrète' : ''}${p.verrou ? ` — verrou ${p.verrou.type}` : ''}`;
const libelleMonstre = (m, i) => `monstre ${i} — ${nomDe('monstres', m.slug)} (${m.x}, ${m.y})`;
</script>

<template>
    <div class="cst">
        <header class="cst-tete">
            <RouterLink to="/" class="cst-retour"><MSym n="arrow_back" :size="16" /> Accueil</RouterLink>
            <h1 class="cst-titre"><MSym n="architecture" fill :size="22" /> Constructeur de campagnes</h1>
            <span class="cst-sous">Mode classique — le serveur valide chaque modification ; rien n'est enregistré sans « Enregistrer ».</span>
        </header>

        <p v-if="erreur" class="cst-erreur">{{ erreur }}</p>
        <p v-else-if="!catalogues" class="cst-sous">Chargement des catalogues…</p>

        <div v-else class="cst-corps">
            <!-- ======================= liste des campagnes ======================= -->
            <aside class="cst-liste">
                <button class="cst-btn torch" type="button" @click="nouvelle"><MSym n="add" :size="16" /> Nouvelle campagne</button>
                <button
                    v-for="c in campagnes"
                    :key="c.slug"
                    type="button"
                    class="cst-campagne"
                    :class="{ on: slugCharge === c.slug }"
                    @click="ouvrir(c.slug)"
                >
                    <b>{{ c.titre }}</b>
                    <em>{{ c.slug }} · {{ c.nb_quetes }} quête{{ c.nb_quetes > 1 ? 's' : '' }}{{ c.jouable ? '' : ' · brouillon' }}</em>
                </button>
                <p v-if="!campagnes.length" class="cst-sous">Aucune campagne enregistrée.</p>
            </aside>

            <section v-if="doc" class="cst-edition">
                <!-- barre : onglets, état de validation, enregistrement -->
                <div class="cst-barre">
                    <nav class="cst-onglets">
                        <button type="button" :class="{ on: onglet === 'campagne' }" @click="onglet = 'campagne'">Campagne</button>
                        <button type="button" :class="{ on: onglet === 'plateau' }" @click="onglet = 'plateau'">Plateau</button>
                        <button type="button" :class="{ on: onglet === 'quete' }" @click="onglet = 'quete'">Quêtes ({{ doc.quetes.length }})</button>
                    </nav>
                    <span class="cst-etat" :class="refus.length ? 'ko' : 'ok'">
                        <MSym :n="validationEnCours ? 'hourglass_top' : (refus.length ? 'error' : 'check_circle')" :size="16" />
                        {{ refus.length ? `${refus.length} refus` : 'Valide' }}
                    </span>
                    <a v-if="slugCharge" class="cst-btn ghost" :href="`/api/constructeur/campagnes/${slugCharge}/export`">
                        <MSym n="download" :size="16" /> Exporter
                    </a>
                    <button class="cst-btn torch" type="button" :disabled="enregistrement" @click="enregistrer">
                        <MSym n="save" :size="16" /> Enregistrer
                    </button>
                </div>
                <p v-if="ecraserait" class="cst-erreur">Le slug « {{ doc.slug }} » est celui d'une AUTRE campagne : l'enregistrer la remplacerait.</p>
                <p v-if="message" class="cst-message">{{ message }}</p>

                <!-- ======================= campagne ======================= -->
                <div v-if="onglet === 'campagne'" class="cst-form">
                    <label>Titre <input v-model="doc.titre" class="cst-input" /></label>
                    <label>Slug <input v-model="doc.slug" class="cst-input" /></label>
                    <label>Auteur <input :value="doc.auteur ?? ''" class="cst-input" @input="doc.auteur = $event.target.value || null" /></label>
                    <label>Mention (crédit, droits) <textarea :value="doc.mention ?? ''" class="cst-input" rows="2" @input="doc.mention = $event.target.value || null" /></label>
                    <div>
                        <span class="cst-lbl">Classes exigées</span>
                        <div class="cst-puces">
                            <button
                                v-for="c in catalogues.classes"
                                :key="c.slug"
                                type="button"
                                class="cst-puce"
                                :class="{ on: doc.prerequis_heros.includes(c.slug) }"
                                @click="basculerClasse(c.slug)"
                            >{{ c.nom }}</button>
                        </div>
                    </div>
                    <!-- Lot K6 : les mercenaires de la campagne — sa règle d'embauche et ceux qu'elle offre. -->
                    <div>
                        <span class="cst-lbl">Mercenaires</span>
                        <div class="cst-ligne">
                            <select :value="doc.mercenaires?.regime ?? ''" class="cst-input" @change="changerRegimeMercenaires($event.target.value)">
                                <option value="">aucun mercenaire</option>
                                <option value="par_quete">payés pour une quête à la fois (Frozen Horror)</option>
                                <option value="retenu_moitie">gardés pour la moitié du prix (Ogre Horde)</option>
                                <option value="debloques">débloqués par les notes (Dread Moon)</option>
                            </select>
                        </div>
                        <div v-if="doc.mercenaires" class="cst-puces">
                            <button
                                v-for="m in (catalogues.mercenaires ?? [])" :key="m.slug" type="button" class="cst-puce"
                                :class="{ on: doc.mercenaires.offerts.includes(m.slug) }"
                                @click="basculerMercenaire(m.slug)"
                            >{{ m.nom }}</button>
                        </div>
                    </div>
                    <ul v-if="refusHorsQuetes.length" class="cst-refus">
                        <li v-for="(r, i) in refusHorsQuetes" :key="i"><code>{{ r.chemin || '(document)' }}</code> {{ r.message }}</li>
                    </ul>
                </div>

                <!-- ======================= plateau ======================= -->
                <div v-else-if="onglet === 'plateau'" class="cst-deux">
                    <div>
                        <p class="cst-sous">Glisser sur la grille pour tracer une salle ; cliquer une salle pour la choisir. Tout le reste est couloir.</p>
                        <div class="cst-grille-cadre">
                            <svg
                                class="cst-grille"
                                :width="L * CASE"
                                :height="H * CASE"
                                @mousedown.prevent="plateauDebut"
                                @mousemove="plateauDeplace"
                                @mouseup="plateauFin"
                                @mouseleave="plateauFin"
                            >
                                <rect :width="L * CASE" :height="H * CASE" class="g-couloir" />
                                <template v-for="s in salles" :key="s.id">
                                    <rect v-for="(r, ri) in rectsDe(s)" :key="ri" :x="r.x * CASE" :y="r.y * CASE" :width="r.l * CASE" :height="r.h * CASE" class="g-salle" :class="{ sel: sallesSel === s }" />
                                    <text :x="s.x * CASE + 4" :y="s.y * CASE + 13" class="g-id">{{ s.id }}</text>
                                </template>
                                <rect v-if="trace.actif" :x="rectTrace.x * CASE" :y="rectTrace.y * CASE" :width="rectTrace.l * CASE" :height="rectTrace.h * CASE" class="g-trace" />
                                <g class="g-quadrillage">
                                    <line v-for="i in L + 1" :key="`v${i}`" :x1="(i - 1) * CASE" y1="0" :x2="(i - 1) * CASE" :y2="H * CASE" />
                                    <line v-for="j in H + 1" :key="`h${j}`" x1="0" :y1="(j - 1) * CASE" :x2="L * CASE" :y2="(j - 1) * CASE" />
                                </g>
                            </svg>
                        </div>
                    </div>
                    <div class="cst-form">
                        <div class="cst-ligne">
                            <label>Largeur <input v-model.number="doc.plateau.largeur" type="number" min="1" class="cst-input cst-num" /></label>
                            <label>Hauteur <input v-model.number="doc.plateau.hauteur" type="number" min="1" class="cst-input cst-num" /></label>
                        </div>
                        <div v-for="s in salles" :key="s.id" class="cst-salle" :class="{ on: sallesSel === s }" @click="sallesSel = s">
                            <input :value="s.id" class="cst-input" @change="renommerSalle(s, $event.target.value)" />
                            <label>x <input v-model.number="s.x" type="number" class="cst-input cst-num" /></label>
                            <label>y <input v-model.number="s.y" type="number" class="cst-input cst-num" /></label>
                            <label>l <input v-model.number="s.l" type="number" class="cst-input cst-num" /></label>
                            <label>h <input v-model.number="s.h" type="number" class="cst-input cst-num" /></label>
                            <span v-if="(s.parties ?? []).length" class="cst-aide" :title="JSON.stringify(s.parties)">+ {{ s.parties.length }} partie(s)</span>
                            <button type="button" class="cst-icone" title="Retirer la salle" @click.stop="retirerSalle(s)"><MSym n="delete" :size="16" /></button>
                        </div>
                        <ul v-if="refusHorsQuetes.length" class="cst-refus">
                            <li v-for="(r, i) in refusHorsQuetes" :key="i"><code>{{ r.chemin || '(document)' }}</code> {{ r.message }}</li>
                        </ul>
                    </div>
                </div>

                <!-- ======================= quêtes ======================= -->
                <div v-else class="cst-quetes">
                    <div class="cst-quetes-liste">
                        <button
                            v-for="(q, i) in doc.quetes"
                            :key="i"
                            type="button"
                            class="cst-campagne"
                            :class="{ on: queteIndex === i }"
                            @click="queteIndex = i"
                        >
                            <b>{{ i + 1 }}. {{ q.titre }}</b>
                            <em :class="{ 'cst-ko': refusDeLaQuete(i).length }">{{ refusDeLaQuete(i).length ? `${refusDeLaQuete(i).length} refus` : 'valide' }}</em>
                        </button>
                        <button class="cst-btn ghost" type="button" @click="nouvelleQuete"><MSym n="add" :size="16" /> Quête</button>
                    </div>

                    <div v-if="quete" class="cst-quete">
                        <div class="cst-ligne">
                            <button class="cst-icone" type="button" title="Monter" @click="deplacerQuete(queteIndex, -1)"><MSym n="arrow_upward" :size="16" /></button>
                            <button class="cst-icone" type="button" title="Descendre" @click="deplacerQuete(queteIndex, 1)"><MSym n="arrow_downward" :size="16" /></button>
                            <button class="cst-icone" type="button" title="Retirer la quête" @click="retirerQuete(queteIndex)"><MSym n="delete" :size="16" /></button>
                        </div>

                        <!-- outils de la carte -->
                        <div class="cst-outils">
                            <button
                                v-for="[id, icone, libelle] in OUTILS"
                                :key="id"
                                type="button"
                                class="cst-outil"
                                :class="{ on: outil === id }"
                                :title="libelle"
                                @click="outil = id; pointage = null"
                            ><MSym :n="icone" :size="18" /></button>
                            <span class="cst-sous">{{ OUTILS.find((o) => o[0] === outil)?.[2] }}</span>
                            <select v-if="outil === 'porte'" v-model="choix.typePorte" class="cst-input">
                                <option v-for="t in vocab.types_porte" :key="t" :value="t">{{ t === 'secrete' ? 'secrète' : t }}</option>
                            </select>
                            <template v-if="outil === 'porte' && choix.typePorte === 'normale'">
                                <select v-model="choix.verrouPorte" class="cst-input" title="Verrou de la porte">
                                    <option value="">sans verrou</option>
                                    <option v-for="v in vocab.verrous_de_porte" :key="v" :value="v">{{ LIBELLES_VERROU[v] ?? v }}</option>
                                </select>
                                <select v-if="choix.verrouPorte === 'cle'" v-model="choix.objetVerrou" class="cst-input">
                                    <option v-for="o in catalogues.objets" :key="o.slug" :value="o.slug">{{ o.nom }}</option>
                                </select>
                                <select v-if="choix.verrouPorte === 'condition'" v-model="choix.conditionVerrou" class="cst-input">
                                    <option v-for="c in vocab.conditions_de_porte" :key="c.nom" :value="c.nom">{{ c.libelle }}</option>
                                </select>
                            </template>
                            <template v-if="outil === 'mobilier'">
                                <select v-model="choix.mobilier" class="cst-input">
                                    <option v-for="m in couches.mobilier" :key="m.slug" :value="m.slug">{{ m.nom }}</option>
                                </select>
                                <select v-model.number="choix.rotation" class="cst-input">
                                    <option v-for="r in vocab.rotations" :key="r" :value="r">{{ r }}°</option>
                                </select>
                            </template>
                            <select v-if="outil === 'monstre'" v-model="choix.monstre" class="cst-input">
                                <option v-for="m in couches.monstres" :key="m.slug" :value="m.slug">{{ m.nom }}</option>
                            </select>
                            <select v-if="outil === 'piege'" v-model="choix.piege" class="cst-input">
                                <option v-for="m in couches.pieges" :key="m.slug" :value="m.slug">{{ m.nom }}</option>
                            </select>
                            <template v-if="outil === 'piege'">
                                <label class="cst-case"><input v-model="choix.piegeIntrouvable" type="checkbox" /> introuvable</label>
                                <label class="cst-case"><input v-model="choix.piegeIndesarmable" type="checkbox" /> indésarmable</label>
                            </template>
                            <select v-if="outil === 'terrain'" v-model="choix.terrain" class="cst-input">
                                <option v-for="m in couches.terrain" :key="m.slug" :value="m.slug">{{ m.nom }}</option>
                            </select>
                            <!-- E5 : la tuile n'est posée qu'au premier pas d'un héros (glace glissante, glissière). -->
                            <label v-if="outil === 'terrain'" class="cst-case"><input v-model="choix.terrainCache" type="checkbox" /> posée au premier pas</label>
                            <template v-if="outil === 'depart' || outil === 'sortie'">
                                <select v-model="choix.acces" class="cst-input">
                                    <option v-for="t in vocab.types_acces" :key="t" :value="t">{{ t === 'escalier' ? 'escalier (2×2, coin haut-gauche)' : 'porte (bord cliqué)' }}</option>
                                </select>
                                <button v-if="outil === 'sortie'" type="button" class="cst-btn ghost" @click="quete.sortie = null">Sortie = entrée</button>
                            </template>
                        </div>
                        <p v-if="pointage" class="cst-message">Cliquer une case pour : {{ pointage.libelle }} <button type="button" class="cst-btn ghost" @click="pointage = null">Annuler</button></p>

                        <div class="cst-grille-cadre">
                            <svg class="cst-grille" :width="L * CASE" :height="H * CASE" @click="clicQuete">
                                <rect :width="L * CASE" :height="H * CASE" class="g-couloir" />
                                <template v-for="s in salles" :key="s.id">
                                    <rect
                                        v-for="(r, ri) in rectsDe(s)" :key="ri"
                                        :x="r.x * CASE" :y="r.y * CASE" :width="r.l * CASE" :height="r.h * CASE"
                                        :class="quete.salles_ouvertes.includes(s.id) ? 'g-salle' : 'g-roche'"
                                    />
                                    <text :x="s.x * CASE + 4" :y="s.y * CASE + 13" class="g-id">{{ s.id }}</text>
                                </template>
                                <!-- Lot K6 (R11) : les salles spéciales de la quête, par-dessus les salles du plateau. -->
                                <template v-for="sp in (quete.salles_speciales ?? [])" :key="`gsp-${sp.id}`">
                                    <rect :x="sp.x * CASE" :y="sp.y * CASE" :width="sp.l * CASE" :height="sp.h * CASE" class="g-salle g-speciale" />
                                    <text :x="sp.x * CASE + 4" :y="sp.y * CASE + 13" class="g-id">{{ sp.id }}</text>
                                </template>
                                <g class="g-quadrillage">
                                    <line v-for="i in L + 1" :key="`v${i}`" :x1="(i - 1) * CASE" y1="0" :x2="(i - 1) * CASE" :y2="H * CASE" />
                                    <line v-for="j in H + 1" :key="`h${j}`" x1="0" :y1="(j - 1) * CASE" :x2="L * CASE" :y2="(j - 1) * CASE" />
                                </g>
                                <rect v-for="(b, i) in quete.blocs" :key="`b${i}`" :x="b.x * CASE + 2" :y="b.y * CASE + 2" :width="CASE - 4" :height="CASE - 4" class="g-bloc" />
                                <template v-for="(m, i) in quete.mobilier" :key="`m${i}`">
                                    <rect
                                        :x="m.x * CASE + 2" :y="m.y * CASE + 2"
                                        :width="(couches.mobilier.find((t) => t.slug === m.slug)?.emprises?.[m.rotation]?.[0] ?? 1) * CASE - 4"
                                        :height="(couches.mobilier.find((t) => t.slug === m.slug)?.emprises?.[m.rotation]?.[1] ?? 1) * CASE - 4"
                                        class="g-meuble"
                                    />
                                    <text :x="m.x * CASE + 5" :y="m.y * CASE + 16" class="g-txt">{{ nomDe('mobilier', m.slug).slice(0, 3) }}</text>
                                </template>
                                <text v-for="(p, i) in quete.pieges" :key="`p${i}`" :x="p.x * CASE + CASE / 2" :y="p.y * CASE + 18" class="g-piege">▲</text>
                                <text v-for="(t, i) in quete.terrain" :key="`t${i}`" :x="t.x * CASE + CASE / 2" :y="t.y * CASE + 18" class="g-terrain" :class="{ cachee: t.cachee }">≈</text>
                                <template v-if="quete.depart">
                                    <rect v-if="quete.depart.type !== 'porte'" :x="quete.depart.x * CASE + 1" :y="quete.depart.y * CASE + 1" :width="2 * CASE - 2" :height="2 * CASE - 2" class="g-entree" />
                                    <line v-else v-bind="ligneBord(quete.depart.x, quete.depart.y, quete.depart.cote)" class="g-porte-entree" />
                                </template>
                                <template v-if="quete.sortie">
                                    <rect v-if="quete.sortie.type !== 'porte'" :x="quete.sortie.x * CASE + 3" :y="quete.sortie.y * CASE + 3" :width="2 * CASE - 6" :height="2 * CASE - 6" class="g-sortie" />
                                    <line v-else v-bind="ligneBord(quete.sortie.x, quete.sortie.y, quete.sortie.cote)" class="g-porte-sortie" />
                                </template>
                                <!-- Lot K5p : le passage de la principale vers l'autre moitié d'une quête double. -->
                                <template v-if="quete.double?.passage">
                                    <rect v-if="quete.double.passage.type !== 'porte'" :x="quete.double.passage.x * CASE + 3" :y="quete.double.passage.y * CASE + 3" :width="2 * CASE - 6" :height="2 * CASE - 6" class="g-passage" />
                                    <line v-else v-bind="ligneBord(quete.double.passage.x, quete.double.passage.y, quete.double.passage.cote)" class="g-passage" />
                                </template>
                                <line
                                    v-for="(f, i) in (quete.fausses_sorties ?? [])"
                                    :key="`f${i}`"
                                    v-bind="ligneBord(f.x, f.y, f.cote)"
                                    class="g-porte-fausse"
                                />
                                <line
                                    v-for="(p, i) in quete.portes"
                                    :key="`d${i}`"
                                    v-bind="ligneBord(p.x, p.y, p.cote)"
                                    :class="p.type === 'secrete' ? 'g-porte-secrete' : 'g-porte'"
                                />
                                <template v-for="(m, i) in quete.monstres" :key="`k${i}`">
                                    <circle
                                        :cx="m.x * CASE + CASE / 2" :cy="m.y * CASE + CASE / 2" :r="CASE / 2 - 3"
                                        class="g-monstre" :class="{ cible: quete.fin?.cibles?.includes(i) }"
                                    />
                                    <text :x="m.x * CASE + CASE / 2" :y="m.y * CASE + CASE / 2 + 4" class="g-sigle">{{ sigle(m.slug) }}</text>
                                    <text :x="m.x * CASE + CASE - 3" :y="m.y * CASE + 9" class="g-index">{{ i }}</text>
                                </template>
                            </svg>
                        </div>

                        <!-- fiche de la quête -->
                        <div class="cst-form">
                            <div class="cst-ligne">
                                <label>Titre <input v-model="quete.titre" class="cst-input" /></label>
                                <label>Slug <input v-model="quete.slug" class="cst-input" /></label>
                            </div>
                            <label>Texte d'ouverture (lu à la table) <textarea v-model="quete.ouverture" rows="3" class="cst-input" /></label>
                            <div class="cst-ligne">
                                <label>Monstre errant
                                    <select :value="quete.monstre_errant ?? ''" class="cst-input" @change="quete.monstre_errant = $event.target.value || null">
                                        <option value="">aucun</option>
                                        <option v-for="m in couches.monstres" :key="m.slug" :value="m.slug">{{ m.nom }}</option>
                                    </select>
                                </label>
                                <label>Quête solo
                                    <select :value="quete.regles?.solo ?? ''" class="cst-input" @change="changerSolo($event.target.value)">
                                        <option value="">non — tout le groupe</option>
                                        <option v-for="c in catalogues.classes.filter((k) => doc.prerequis_heros.includes(k.slug))" :key="c.slug" :value="c.slug">{{ c.nom }} seul</option>
                                    </select>
                                </label>
                                <label class="cst-case">
                                    <input type="checkbox" :checked="quete.regles?.marche_ferme === true" @change="changerMarcheFerme($event.target.checked)" /> Marché fermé avant la quête
                                </label>
                                <span v-if="quete.salles_ouvertes.length" class="cst-lbl">Ne se fouille pas :</span>
                                <label v-for="s in quete.salles_ouvertes" :key="`sf-${s}`" class="cst-case">
                                    <input type="checkbox" :checked="(quete.regles?.sans_fouille ?? []).includes(s)" @change="basculerSansFouille(s, $event.target.checked)" /> {{ s }}
                                </label>
                                <label v-if="quete.salles_ouvertes.length">Équipement confisqué
                                    <select :value="quete.regles?.confiscation?.salle ?? ''" class="cst-input" @change="changerConfiscation($event.target.value)">
                                        <option value="">non</option>
                                        <option v-for="s in quete.salles_ouvertes" :key="`cf-${s}`" :value="s">repris dans {{ s }}</option>
                                    </select>
                                </label>
                                <label class="cst-case">
                                    <input type="checkbox" :checked="quete.recompense !== null" @change="basculerRecompense($event.target.checked)" /> Récompense
                                </label>
                                <template v-if="quete.recompense">
                                    <label>Or <input v-model.number="quete.recompense.or" type="number" min="0" class="cst-input cst-num" /></label>
                                    <!-- Lot K6 (Dread Moon) : des jetons de réputation pour le groupe. -->
                                    <label>Réputation <input :value="quete.recompense.reputation ?? ''" type="number" min="1" class="cst-input cst-num" placeholder="—"
                                        @change="$event.target.value ? (quete.recompense.reputation = Number($event.target.value)) : delete quete.recompense.reputation" /></label>
                                    <select v-model="quete.recompense.partage" class="cst-input">
                                        <option v-for="p in vocab.partages" :key="p" :value="p">{{ p === 'par_heros' ? 'par héros' : 'à partager' }}</option>
                                    </select>
                                </template>
                            </div>
                            <!-- ============ Lots K5l à K5o : ce qu'il fallait aux livrets ============ -->
                            <details class="cst-livrets" :open="aDesReglesDeLivret">
                                <summary class="cst-lbl">Règles des livrets — monstres d'auteur, primes, téléportations, dangers</summary>

                                <div v-if="quete.monstres.length" class="cst-bloc">
                                    <span class="cst-sous">Monstres posés — profil d'auteur (R9) : sous-boss, boss ou créature nouvelle sur une base du catalogue.</span>
                                    <div class="cst-puces">
                                        <button
                                            v-for="(m, i) in quete.monstres" :key="`pm${i}`" type="button" class="cst-puce"
                                            :class="{ on: aUnProfil(m), ouvert: profilsOuverts.has(m) }" :title="libelleMonstre(m, i)"
                                            @click="basculerProfil(m)"
                                        >{{ i }} · {{ m.nom ?? nomDe('monstres', m.slug) }}</button>
                                    </div>
                                    <template v-for="(m, i) in quete.monstres" :key="`pf${i}`">
                                        <div v-if="profilsOuverts.has(m)">
                                            <span class="cst-lbl">{{ libelleMonstre(m, i) }}</span>
                                            <ProfilMonstre :monstre="m" :objets="catalogues.objets" :pointer="pointer" :libelle="`monstre ${i}`" />
                                        </div>
                                    </template>
                                </div>

                                <!-- Lot K6 (R11) : les salles spéciales (place publique, salle tournante, salle du trône…) — elles
                                     remplacent, pour cette quête, les salles du plateau qu'elles touchent. -->
                                <div class="cst-bloc">
                                    <div class="cst-ligne">
                                        <span class="cst-sous">Salles spéciales (remplacent les salles du plateau en dessous)</span>
                                        <button type="button" class="cst-btn ghost" @click="ajouterSalleSpeciale"><MSym n="add" :size="14" /> salle spéciale</button>
                                    </div>
                                    <div v-for="(sp, i) in (quete.salles_speciales ?? [])" :key="`sp${i}`" class="cst-ligne">
                                        <input v-model="sp.id" class="cst-input" title="identifiant" />
                                        <label>x <input v-model.number="sp.x" type="number" min="0" class="cst-input cst-num" /></label>
                                        <label>y <input v-model.number="sp.y" type="number" min="0" class="cst-input cst-num" /></label>
                                        <label>largeur <input v-model.number="sp.l" type="number" min="1" class="cst-input cst-num" /></label>
                                        <label>hauteur <input v-model.number="sp.h" type="number" min="1" class="cst-input cst-num" /></label>
                                        <label class="cst-case"><input type="checkbox" :checked="sp.place === true" @change="$event.target.checked ? (sp.place = true) : delete sp.place" /> place publique</label>
                                        <button type="button" class="cst-icone" title="Coin haut-gauche sur la carte" @click="pointer(sp, `le coin de ${sp.id}`)"><MSym n="pin_drop" :size="16" /></button>
                                        <button type="button" class="cst-icone" title="Retirer" @click="retirerSalleSpeciale(i)"><MSym n="close" :size="16" /></button>
                                    </div>
                                </div>

                                <!-- Lot K6 (First Light) : les glyphes magiques — découverts par une fouille de la zone, détruits par le
                                     parchemin-clé ou au feu de la cheminée de leur salle ; détruire pose le drapeau que les notes lisent. -->
                                <div class="cst-bloc">
                                    <div class="cst-ligne">
                                        <span class="cst-sous">Glyphes magiques</span>
                                        <button type="button" class="cst-btn ghost" @click="ajouterGlyphe"><MSym n="add" :size="14" /> glyphe</button>
                                    </div>
                                    <div v-for="(g, i) in (quete.glyphes ?? [])" :key="`gl${i}`" class="cst-ligne">
                                        <input v-model="g.id" class="cst-input" title="identifiant" />
                                        <select v-model="g.salle" class="cst-input">
                                            <option v-for="s in quete.salles_ouvertes" :key="`gs-${s}`" :value="s">{{ s }}</option>
                                        </select>
                                        <select v-model="g.detruit_par" class="cst-input">
                                            <option value="parchemin">détruit par le parchemin-clé</option>
                                            <option value="cheminee">détruit au feu de la cheminée</option>
                                        </select>
                                        <label>pose le drapeau <input v-model="g.drapeau" class="cst-input" /></label>
                                        <button type="button" class="cst-icone" title="Retirer" @click="retirerGlyphe(i)"><MSym n="close" :size="16" /></button>
                                    </div>
                                </div>

                                <!-- Lot K6 (The Crypt of Perpetual Darkness) : la salle de l'obscurité magique. -->
                                <div class="cst-bloc">
                                    <label>Obscurité magique dans
                                        <select :value="quete.regles?.obscurite_magique?.salle ?? ''" class="cst-input" @change="poserRegle('obscurite_magique', $event.target.value ? { salle: $event.target.value } : undefined)">
                                            <option value="">— aucune salle —</option>
                                            <option v-for="s in quete.salles_ouvertes" :key="`ob-${s}`" :value="s">{{ s }}</option>
                                        </select>
                                    </label>
                                    <span class="cst-sous">−2 dés aux deux premières attaques d'un héros qui ne voit pas dans le noir (la Couronne des Ténèbres le lève).</span>
                                </div>

                                <!-- Lot K6 (Dread Moon) : le déguisement — chaque héros sans armure voyante le prend au départ. -->
                                <div class="cst-bloc">
                                    <label class="cst-case"><input type="checkbox" :checked="quete.regles?.deguisement === true" @change="poserRegle('deguisement', $event.target.checked ? true : undefined)" /> Déguisement permis (Dread Moon) — les monstres d'une place publique ignorent les héros déguisés</label>
                                </div>

                                <!-- Lot K6 (Jungles of Delthrak, « Order of Play ») : les héros choisissent l'ordre à chaque round. -->
                                <div class="cst-bloc">
                                    <label class="cst-case"><input type="checkbox" :checked="quete.regles?.ordre_libre === true" @change="poserRegle('ordre_libre', $event.target.checked ? true : undefined)" /> Ordre libre : à chaque round, les héros jouent dans l'ordre qu'ils veulent</label>
                                </div>

                                <!-- Lot K6 (Spirit Queen's Torment) : les dégâts convertis en Esprit. -->
                                <div class="cst-bloc">
                                    <div class="cst-ligne">
                                        <label>Dégâts en Esprit
                                            <select :value="quete.regles?.degats_en_mind === true ? 'tous' : (Array.isArray(quete.regles?.degats_en_mind) ? 'monstres' : '')" class="cst-input"
                                                @change="poserRegle('degats_en_mind', $event.target.value === 'tous' ? true : ($event.target.value === 'monstres' ? [choix.monstre] : undefined))">
                                                <option value="">non</option>
                                                <option value="tous">tout dégât subi entame l'Esprit</option>
                                                <option value="monstres">les coups de certains monstres</option>
                                            </select>
                                        </label>
                                        <template v-if="Array.isArray(quete.regles?.degats_en_mind)">
                                            <span v-for="(slug, k) in quete.regles.degats_en_mind" :key="`em${slug}`" class="cst-puce on">{{ nomDe('monstres', slug) }}
                                                <button v-if="quete.regles.degats_en_mind.length > 1" type="button" class="cst-icone" title="Retirer" @click="quete.regles.degats_en_mind.splice(k, 1)"><MSym n="close" :size="12" /></button>
                                            </span>
                                            <select class="cst-input" @change="$event.target.value && quete.regles.degats_en_mind.push($event.target.value); $event.target.value = ''">
                                                <option value="">+ monstre…</option>
                                                <option v-for="m in couches.monstres.filter((c) => !quete.regles.degats_en_mind.includes(c.slug))" :key="m.slug" :value="m.slug">{{ m.nom }}</option>
                                            </select>
                                        </template>
                                    </div>
                                </div>

                                <div class="cst-bloc">
                                    <label class="cst-case"><input type="checkbox" :checked="quete.regles?.fouille_piege_un_par_un === true" @change="poserRegle('fouille_piege_un_par_un', $event.target.checked ? true : undefined)" /> Une fouille ne révèle qu'un piège à la fois</label>
                                </div>

                                <div class="cst-bloc">
                                    <span class="cst-sous">Prime en or par monstre abattu (versée à qui l'abat, errants compris)</span>
                                    <div v-for="(or, slug) in (quete.regles?.primes_monstres ?? {})" :key="`pr${slug}`" class="cst-ligne">
                                        <span>{{ nomDe('monstres', slug) }}</span>
                                        <input v-model.number="quete.regles.primes_monstres[slug]" type="number" min="1" class="cst-input cst-num" />
                                        <button type="button" class="cst-icone" title="Retirer" @click="retirerDuMonstre('primes_monstres', slug)"><MSym n="close" :size="16" /></button>
                                    </div>
                                    <select class="cst-input" @change="ajouterAuMonstre('primes_monstres', $event.target.value, 10); $event.target.value = ''">
                                        <option value="">+ prime pour…</option>
                                        <option v-for="m in couches.monstres.filter((c) => !(c.slug in (quete.regles?.primes_monstres ?? {})))" :key="m.slug" :value="m.slug">{{ m.nom }}</option>
                                    </select>
                                </div>

                                <div class="cst-bloc">
                                    <span class="cst-sous">Monstres pacifiés par un drapeau (ils ne bougent plus ni n'attaquent une fois le drapeau posé)</span>
                                    <div v-for="(drapeau, slug) in (quete.regles?.monstres_pacifies_par_drapeau ?? {})" :key="`pa${slug}`" class="cst-ligne">
                                        <span>{{ nomDe('monstres', slug) }}</span>
                                        <label>drapeau <input v-model="quete.regles.monstres_pacifies_par_drapeau[slug]" class="cst-input" /></label>
                                        <button type="button" class="cst-icone" title="Retirer" @click="retirerDuMonstre('monstres_pacifies_par_drapeau', slug)"><MSym n="close" :size="16" /></button>
                                    </div>
                                    <select class="cst-input" @change="ajouterAuMonstre('monstres_pacifies_par_drapeau', $event.target.value, ''); $event.target.value = ''">
                                        <option value="">+ pacifier…</option>
                                        <option v-for="m in couches.monstres.filter((c) => !(c.slug in (quete.regles?.monstres_pacifies_par_drapeau ?? {})))" :key="m.slug" :value="m.slug">{{ m.nom }}</option>
                                    </select>
                                </div>

                                <div class="cst-bloc">
                                    <label class="cst-case"><input type="checkbox" :checked="!!quete.regles?.portes_teleportation" @change="poserRegle('portes_teleportation', $event.target.checked ? tablePleine(SOMMES_2D6) : undefined)" /> Portes à téléportation : franchir une porte téléporte sur la case tirée au 2d6</label>
                                    <TableDeCases v-if="quete.regles?.portes_teleportation" :table="quete.regles.portes_teleportation" :faces="SOMMES_2D6" libelle="2d6 =" :pointer="pointer" />
                                </div>

                                <div class="cst-bloc">
                                    <div class="cst-ligne">
                                        <span class="cst-sous">Dangers (en couloir)</span>
                                        <button type="button" class="cst-btn ghost" @click="ajouterDanger('rocher')"><MSym n="add" :size="14" /> rocher roulant</button>
                                        <button type="button" class="cst-btn ghost" @click="ajouterDanger('brume')"><MSym n="add" :size="14" /> brume de la mort</button>
                                    </div>
                                    <div v-for="(d, i) in (quete.dangers ?? [])" :key="`dg${i}`" class="cst-ligne">
                                        <template v-if="d.type === 'brume'">
                                            <span>Brume de la mort</span>
                                            <input v-model.number="d.x" type="number" class="cst-input cst-num" title="x" />
                                            <input v-model.number="d.y" type="number" class="cst-input cst-num" title="y" />
                                            <button type="button" class="cst-icone" title="Choisir sur la carte" @click="pointer(d, 'la brume de la mort')"><MSym n="pin_drop" :size="16" /></button>
                                        </template>
                                        <template v-else>
                                            <span>Rocher roulant, trajet :</span>
                                            <span v-for="(c, k) in d.run" :key="k" class="cst-ligne">
                                                <input v-model.number="c.x" type="number" class="cst-input cst-num" title="x" />
                                                <input v-model.number="c.y" type="number" class="cst-input cst-num" title="y" />
                                                <button type="button" class="cst-icone" title="Choisir sur la carte" @click="pointer(c, `la case ${k + 1} du rocher`)"><MSym n="pin_drop" :size="16" /></button>
                                                <button v-if="d.run.length > 2" type="button" class="cst-icone" title="Retirer la case" @click="d.run.splice(k, 1)"><MSym n="close" :size="14" /></button>
                                            </span>
                                            <button type="button" class="cst-btn ghost" @click="d.run.push({ ...d.run[d.run.length - 1] })"><MSym n="add" :size="14" /> case</button>
                                        </template>
                                        <button type="button" class="cst-icone" title="Retirer le danger" @click="retirerDanger(i)"><MSym n="delete" :size="16" /></button>
                                    </div>
                                </div>

                                <div v-if="terrainsAPaire.length" class="cst-bloc">
                                    <span class="cst-sous">Terrains jumelés — deux extrémités d'une même paire portent le même identifiant</span>
                                    <div v-for="{ t, i } in terrainsAPaire" :key="`tp${i}`" class="cst-ligne">
                                        <span>{{ nomDe('terrain', t.slug) }} ({{ t.x }}, {{ t.y }})</span>
                                        <input :value="t.paire_id ?? ''" class="cst-input" placeholder="paire" @change="changerPaire(t, $event.target.value)" />
                                    </div>
                                </div>
                            </details>

                            <!-- Lot K5p : la quête double (The Frozen Horror q. 9-10) — deux cartes jouées l'une après l'autre. -->
                            <div class="cst-ligne">
                                <label>Quête double avec
                                    <select :value="quete.double?.quete ?? ''" class="cst-input" @change="changerDouble($event.target.value)">
                                        <option value="">— aucune —</option>
                                        <option v-for="(q, k) in doc.quetes.filter((x, k) => k !== queteIndex)" :key="q.slug" :value="q.slug">{{ q.titre || q.slug }}</option>
                                    </select>
                                </label>
                                <template v-if="estPrincipale">
                                    <span class="cst-sous">Passage vers l'autre moitié :</span>
                                    <select :value="quete.double.passage.type ?? 'escalier'" class="cst-input" @change="changerTypePassage($event.target.value)">
                                        <option value="escalier">escalier (2×2)</option>
                                        <option value="porte">porte</option>
                                    </select>
                                    <input v-model.number="quete.double.passage.x" type="number" class="cst-input cst-num" title="x" />
                                    <input v-model.number="quete.double.passage.y" type="number" class="cst-input cst-num" title="y" />
                                    <select v-if="quete.double.passage.type === 'porte'" v-model="quete.double.passage.cote" class="cst-input">
                                        <option v-for="c in vocab.cotes" :key="c" :value="c">{{ c }}</option>
                                    </select>
                                    <button type="button" class="cst-icone" title="Choisir sur la carte" @click="pointer(quete.double.passage, 'le passage')"><MSym n="pin_drop" :size="16" /></button>
                                </template>
                                <span v-else-if="quete.double" class="cst-sous">Seconde moitié : on y arrive par son départ, qui est aussi le passage du retour ; sa fin, sa sortie et sa récompense sont celles de la principale.</span>
                            </div>

                            <div class="cst-suite">
                                <div class="cst-ligne">
                                    <span class="cst-lbl">Suite (campagne ramifiée)</span>
                                    <button type="button" class="cst-btn ghost" @click="ajouterEmbranchement"><MSym n="add" :size="14" /> embranchement</button>
                                </div>
                                <span class="cst-sous">À la victoire : le premier embranchement dont le drapeau est posé décide. Sans embranchement retenu, la quête suivante dans l'ordre. Un embranchement sans drapeau doit être le dernier.</span>
                                <div v-for="(embranchement, i) in (quete.suite ?? [])" :key="i" class="cst-ligne">
                                    <select :value="embranchement.fin ? '__fin__' : embranchement.quete" class="cst-input" @change="changerDestination(i, $event.target.value)">
                                        <option v-for="autre in autresQuetes" :key="autre.slug" :value="autre.slug">vers « {{ autre.titre }} »</option>
                                        <option value="__fin__">fin de la campagne</option>
                                    </select>
                                    <input :value="embranchement.si_drapeau ?? ''" class="cst-input" placeholder="si le drapeau (toujours)" @change="changerSiDrapeauSuite(i, $event.target.value)" />
                                    <button type="button" class="cst-icone" title="Retirer l'embranchement" @click="retirerEmbranchement(i)"><MSym n="close" :size="16" /></button>
                                </div>
                            </div>
                            <!-- Une seconde moitié de quête double n'a pas de fin à elle (`fin: null`) : c'est celle de la principale. -->
                            <template v-if="quete.fin">
                            <div class="cst-ligne">
                                <label>Fin
                                    <select :value="quete.fin?.type" class="cst-input" @change="changerTypeFin($event.target.value)">
                                        <option v-for="t in vocab.types_fin" :key="t" :value="t">{{ LIBELLES_FIN[t] ?? t }}</option>
                                    </select>
                                </label>
                                <label v-if="quete.fin?.type === 'drapeau'">Drapeau <input v-model="quete.fin.drapeau" class="cst-input" /></label>
                                <span v-else-if="quete.fin?.type === 'sortir'" class="cst-sous">La sortie de la quête est ouverte dès le départ ; la quête est gagnée quand le dernier héros debout l'a franchie.</span>
                                <span v-else-if="quete.fin?.type === 'ramener'" class="cst-sous">La sortie est ouverte dès le départ ; la quête est gagnée si la charge est sortie avec le dernier héros debout, perdue sinon.</span>
                                <span v-else class="cst-sous">Cibles : {{ (quete.fin?.cibles ?? []).map((c) => quete.monstres[c] ? libelleMonstre(quete.monstres[c], c) : `monstre ${c} (absent)`).join(' ; ') || 'aucune — outil « Cible » sur un monstre' }}</span>
                            </div>
                            <label>Consigne dite aux joueurs <input v-model="quete.fin.libelle" class="cst-input" /></label>
                            </template>
                            <!-- Lot K5k (R5) : la charge à porter — son porteur lance un dé de déplacement de moins. -->
                            <div class="cst-ligne">
                                <label class="cst-case"><input type="checkbox" :checked="!!quete.charge" @change="basculerCharge($event.target.checked)" /> Charge à porter</label>
                                <template v-if="quete.charge">
                                    <input v-model="quete.charge.nom" class="cst-input" maxlength="40" placeholder="nom (ex. le coffre du trésorier)" />
                                    <input v-model.number="quete.charge.x" type="number" class="cst-input cst-num" title="x" />
                                    <input v-model.number="quete.charge.y" type="number" class="cst-input cst-num" title="y" />
                                    <button type="button" class="cst-icone" title="Choisir sur la carte" @click="pointer(quete.charge, 'la charge')"><MSym n="pin_drop" :size="16" /></button>
                                </template>
                            </div>
                        </div>

                        <!-- notes -->
                        <div class="cst-notes">
                            <div class="cst-ligne">
                                <span class="cst-lbl">Notes « quand → alors »</span>
                                <button type="button" class="cst-btn ghost" @click="nouvelleNote"><MSym n="add" :size="16" /> Note</button>
                            </div>
                            <div v-for="(note, i) in (quete.notes ?? [])" :key="i" class="cst-note">
                                <div class="cst-ligne">
                                    <input v-model="note.repere" class="cst-input cst-num" title="Repère" />
                                    <select :value="note.quand.declencheur" class="cst-input" @change="changerDeclencheur(note, $event.target.value)">
                                        <option v-for="(d, cle) in declencheurs" :key="cle" :value="cle">{{ d.libelle }}</option>
                                    </select>
                                    <select
                                        v-if="(declencheurs[note.quand.declencheur]?.cibles ?? []).length > 1"
                                        :value="genreDeCible(note)" class="cst-input"
                                        @change="changerGenreCible(note, $event.target.value)"
                                    >
                                        <option v-for="g in declencheurs[note.quand.declencheur].cibles" :key="g" :value="g">{{ g }}</option>
                                    </select>
                                    <select v-if="genreDeCible(note) === 'salle'" v-model="note.quand.cible.salle" class="cst-input">
                                        <option v-for="s in quete.salles_ouvertes" :key="s" :value="s">{{ s }}</option>
                                    </select>
                                    <template v-else-if="genreDeCible(note) === 'case'">
                                        <input v-model.number="note.quand.cible.case.x" type="number" class="cst-input cst-num" />
                                        <input v-model.number="note.quand.cible.case.y" type="number" class="cst-input cst-num" />
                                        <button type="button" class="cst-icone" title="Choisir sur la carte" @click="pointer(note.quand.cible.case, `la case de la note ${note.repere}`)"><MSym n="pin_drop" :size="16" /></button>
                                    </template>
                                    <select v-else-if="genreDeCible(note) === 'porte'" v-model.number="note.quand.cible.porte" class="cst-input">
                                        <option v-for="(p, k) in quete.portes" :key="k" :value="k">{{ libellePorte(p, k) }}</option>
                                    </select>
                                    <select v-else-if="genreDeCible(note) === 'monstre'" v-model.number="note.quand.cible.monstre" class="cst-input">
                                        <option v-for="(m, k) in quete.monstres" :key="k" :value="k">{{ libelleMonstre(m, k) }}</option>
                                    </select>
                                    <label v-else-if="genreDeCible(note) === 'tour'">à partir du tour <input v-model.number="note.quand.cible.tour" type="number" min="1" class="cst-input cst-num" /></label>
                                    <button type="button" class="cst-icone" title="Retirer la note" @click="quete.notes.splice(i, 1)"><MSym n="delete" :size="16" /></button>
                                </div>
                                <div class="cst-ligne">
                                    <label>si le drapeau <input :value="note.quand.si_drapeau ?? ''" class="cst-input" placeholder="(toujours)" @change="changerSiDrapeau(note, $event.target.value)" /></label>
                                    <label>sauf si le drapeau <input :value="note.quand.sauf_drapeau ?? ''" class="cst-input" placeholder="(aucun)" @change="changerSaufDrapeau(note, $event.target.value)" /></label>
                                    <!-- Lot K6 (Dread Moon) : le héros qui déclenche est déguisé, ou non. -->
                                    <label>héros
                                        <select :value="note.quand.si_deguise === true ? 'oui' : (note.quand.si_deguise === false ? 'non' : '')" class="cst-input"
                                            @change="$event.target.value === '' ? delete note.quand.si_deguise : (note.quand.si_deguise = $event.target.value === 'oui')">
                                            <option value="">déguisé ou non</option>
                                            <option value="oui">déguisé seulement</option>
                                            <option value="non">à découvert seulement</option>
                                        </select>
                                    </label>
                                    <label class="cst-case"><input type="checkbox" :checked="note.une_fois !== false" @change="changerUneFois(note, $event.target.checked)" /> une seule fois</label>
                                </div>
                                <div v-for="(effet, j) in note.alors" :key="j" class="cst-effet">
                                    <select :value="effet.effet" class="cst-input" @change="changerEffet(note, j, $event.target.value)">
                                        <option v-for="(e, cle) in effetsNote" :key="cle" :value="cle">{{ e.libelle }}</option>
                                    </select>
                                    <template v-for="p in (effetsNote[effet.effet]?.parametres ?? [])" :key="p">
                                        <textarea v-if="p === 'texte'" v-model="effet.texte" rows="2" class="cst-input cst-large" :placeholder="LIBELLES_PARAM[p]" />
                                        <label v-else-if="p === 'or' || p === 'body'">{{ LIBELLES_PARAM[p] }} <input v-model.number="effet[p]" type="number" min="1" class="cst-input cst-num" /></label>
                                        <!-- Lot K6 : débloquer un mercenaire — parmi ceux que la CAMPAGNE offre. -->
                                        <select v-else-if="p === 'slug' && effet.effet === 'debloquer_mercenaire'" v-model="effet.slug" class="cst-input">
                                            <option v-for="sl in (doc.mercenaires?.offerts ?? [])" :key="sl" :value="sl">{{ (catalogues.mercenaires ?? []).find((m) => m.slug === sl)?.nom ?? sl }}</option>
                                        </select>
                                        <select v-else-if="p === 'slug'" v-model="effet.slug" class="cst-input">
                                            <option v-for="o in catalogues.objets" :key="o.slug" :value="o.slug">{{ o.nom }}</option>
                                        </select>
                                        <!-- Lot K6 (Dread Moon) : la réputation et l'offre. -->
                                        <label v-else-if="p === 'jetons'">Jetons (négatif : en moins) <input v-model.number="effet.jetons" type="number" class="cst-input cst-num" /></label>
                                        <label v-else-if="p === 'libelle'">Libellé de l'offre <input v-model="effet.libelle" class="cst-input" maxlength="120" /></label>
                                        <label v-else-if="p === 'alors'" class="cst-large">Effets si un héros accepte (JSON, validé par le serveur)
                                            <textarea :value="JSON.stringify(effet.alors, null, 1)" rows="3" class="cst-input cst-large" @change="changerAlorsOffre(effet, $event.target.value)" />
                                        </label>
                                        <!-- Lot K5b : la case d'un bloc qui tombe — x et y ensemble, choisis aussi sur la carte. -->
                                        <template v-else-if="p === 'x'">
                                            <input v-model.number="effet.x" type="number" class="cst-input cst-num" title="x" />
                                            <input v-model.number="effet.y" type="number" class="cst-input cst-num" title="y" />
                                            <button type="button" class="cst-icone" title="Choisir sur la carte" @click="pointer(effet, `le bloc de la note ${note.repere}`)"><MSym n="pin_drop" :size="16" /></button>
                                        </template>
                                        <template v-else-if="p === 'y'" />
                                        <select v-else-if="p === 'porte'" v-model.number="effet.porte" class="cst-input">
                                            <option v-for="(d, k) in quete.portes" :key="k" :value="k">{{ libellePorte(d, k) }}</option>
                                        </select>
                                        <label v-else-if="p === 'nom'">{{ LIBELLES_PARAM[p] }} <input v-model="effet.nom" class="cst-input" /></label>
                                        <div v-else-if="p === 'monstres'" class="cst-apparitions">
                                            <div v-for="(a, k) in effet.monstres" :key="k" class="cst-ligne">
                                                <select v-model="a.slug" class="cst-input">
                                                    <option v-for="m in couches.monstres" :key="m.slug" :value="m.slug">{{ m.nom }}</option>
                                                </select>
                                                <input v-model.number="a.x" type="number" class="cst-input cst-num" />
                                                <input v-model.number="a.y" type="number" class="cst-input cst-num" />
                                                <button type="button" class="cst-icone" title="Choisir sur la carte" @click="pointer(a, `l'apparition de la note ${note.repere}`)"><MSym n="pin_drop" :size="16" /></button>
                                                <button type="button" class="cst-icone" :class="{ on: aUnProfil(a) }" title="Profil d'auteur (nom, stats, armes)" @click="basculerProfil(a)"><MSym n="tune" :size="16" /></button>
                                                <button type="button" class="cst-icone" title="Retirer" @click="effet.monstres.splice(k, 1)"><MSym n="close" :size="16" /></button>
                                                <ProfilMonstre v-if="profilsOuverts.has(a)" :monstre="a" :objets="catalogues.objets" :pointer="pointer" :libelle="`l'apparition de la note ${note.repere}`" />
                                            </div>
                                            <button type="button" class="cst-btn ghost" @click="effet.monstres.push({ slug: choix.monstre, x: 0, y: 0 })"><MSym n="add" :size="14" /> monstre</button>
                                        </div>
                                    </template>
                                    <template v-for="p in (effetsNote[effet.effet]?.optionnels ?? [])" :key="`o${p}`">
                                        <!-- Lot K6 : le coût d'une offre (or de la bourse du héros, jetons du groupe), le groupe d'offres exclusives. -->
                                        <template v-if="p === 'cout'">
                                            <label>coût : po <input :value="effet.cout?.or ?? ''" type="number" min="0" class="cst-input cst-num" @change="changerCoutOffre(effet, 'or', $event.target.value)" /></label>
                                            <label>jetons <input :value="effet.cout?.reputation ?? ''" type="number" min="0" class="cst-input cst-num" @change="changerCoutOffre(effet, 'reputation', $event.target.value)" /></label>
                                        </template>
                                        <label v-else-if="p === 'groupe'">exclusive avec le groupe <input :value="effet.groupe ?? ''" class="cst-input" placeholder="(seule)" @change="$event.target.value.trim() ? (effet.groupe = $event.target.value.trim()) : delete effet.groupe" /></label>
                                        <!-- Lot K5h : `campagne` est un booléen — posé seulement quand il vaut true. -->
                                        <label v-else-if="p === 'campagne'" class="cst-case">
                                            <input type="checkbox" :checked="effet.campagne === true" @change="$event.target.checked ? (effet.campagne = true) : delete effet.campagne" /> de campagne (reste d'une quête à l'autre)
                                        </label>
                                        <label v-else-if="BOOLEENS_EFFET[p]" class="cst-case">
                                            <input type="checkbox" :checked="effet[p] === true" @change="basculerBooleen(effet, p, $event.target.checked)" /> {{ BOOLEENS_EFFET[p] }}
                                        </label>
                                        <!-- Lot K5o : la case d'arrivée tirée au d6 (salle tournante, Nuage de la Terreur). -->
                                        <div v-else-if="p === 'destinations_d6'" class="cst-apparitions">
                                            <label class="cst-case"><input type="checkbox" :checked="!!effet.destinations_d6" @change="basculerDestinationsD6(effet, $event.target.checked)" /> case d'arrivée tirée au d6</label>
                                            <TableDeCases v-if="effet.destinations_d6" :table="effet.destinations_d6" :faces="FACES_D6" libelle="d6 =" :pointer="pointer" />
                                        </div>
                                        <label v-else>{{ LIBELLES_PARAM[p] ?? p }}
                                            <input :value="effet[p] ?? ''" type="number" min="1" :max="p === 'sur_jet' || p === 'avec_casque' ? 6 : undefined" class="cst-input cst-num" @change="changerOptionnel(effet, p, $event.target.value)" />
                                        </label>
                                    </template>
                                    <button type="button" class="cst-icone" title="Retirer l'effet" @click="note.alors.splice(j, 1)"><MSym n="close" :size="16" /></button>
                                </div>
                                <button type="button" class="cst-btn ghost" @click="note.alors.push(effetParDefaut('texte'))"><MSym n="add" :size="14" /> effet</button>
                            </div>
                        </div>

                        <ul v-if="refusDeLaQuete(queteIndex).length" class="cst-refus">
                            <li v-for="(r, i) in refusDeLaQuete(queteIndex)" :key="i"><code>{{ r.chemin.replace(`quetes.${queteIndex}.`, '') }}</code> {{ r.message }}</li>
                        </ul>
                    </div>
                    <p v-else class="cst-sous">Aucune quête : « + Quête » pour en ajouter une.</p>
                </div>
            </section>
            <p v-else class="cst-sous">Choisir une campagne à gauche, ou en commencer une nouvelle.</p>
        </div>
    </div>
</template>

<style scoped>
.cst { min-height: 100vh; background: var(--stone-950); color: var(--ink-100); padding: 18px 16px 48px; box-sizing: border-box; }
.cst-tete { display: flex; align-items: baseline; flex-wrap: wrap; gap: 14px; margin-bottom: 14px; }
.cst-retour { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 700; color: var(--ink-300); text-decoration: none; }
.cst-retour:hover { color: var(--torch); }
.cst-titre { display: inline-flex; align-items: center; gap: 8px; margin: 0; font-family: var(--font-display); font-size: 22px; color: var(--parch-100); }
.cst-titre .msym { color: var(--torch); }
.cst-sous { font-size: 13px; color: var(--ink-500); margin: 0; }
.cst-erreur { color: var(--danger); font-size: 13.5px; margin: 6px 0; }
.cst-message { color: var(--ok); font-size: 13.5px; margin: 6px 0; display: flex; align-items: center; gap: 10px; }
.cst-corps { display: grid; grid-template-columns: 240px 1fr; gap: 16px; align-items: start; }
.cst-liste, .cst-quetes-liste { display: flex; flex-direction: column; gap: 8px; }
.cst-campagne { text-align: left; display: flex; flex-direction: column; gap: 2px; padding: 9px 11px; border-radius: var(--r-md);
  border: var(--line); background: var(--stone-850); color: var(--ink-100); cursor: pointer; font: inherit; }
.cst-campagne b { font-size: 13.5px; }
.cst-campagne em { font-style: normal; font-size: 11.5px; color: var(--ink-500); }
.cst-campagne.on { border-color: var(--torch); }
.cst-ko { color: var(--danger) !important; }
.cst-edition { display: flex; flex-direction: column; gap: 12px; min-width: 0; }
.cst-barre { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.cst-onglets { display: flex; gap: 4px; margin-right: auto; }
.cst-onglets button { padding: 7px 14px; border-radius: 99px; border: var(--line); background: var(--stone-850); color: var(--ink-300); cursor: pointer; font: inherit; font-weight: 700; font-size: 13px; }
.cst-onglets button.on { color: var(--parch-100); border-color: var(--torch); }
.cst-etat { display: inline-flex; align-items: center; gap: 5px; font-size: 13px; font-weight: 700; }
.cst-etat.ok { color: var(--ok); }
.cst-etat.ko { color: var(--danger); }
.cst-btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: var(--r-md); border: var(--line);
  background: var(--stone-850); color: var(--ink-100); cursor: pointer; font: inherit; font-size: 13px; font-weight: 700; text-decoration: none; }
.cst-btn.torch { background: var(--torch); color: var(--stone-950); border-color: var(--torch); }
.cst-btn:disabled { opacity: .5; cursor: not-allowed; }
.cst-icone { display: inline-grid; place-items: center; width: 28px; height: 28px; border-radius: 8px; border: var(--line); background: var(--stone-850); color: var(--ink-300); cursor: pointer; }
.cst-icone:hover { color: var(--parch-100); border-color: var(--torch); }
.cst-form { display: flex; flex-direction: column; gap: 10px; }
.cst-form label, .cst-ligne label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: var(--ink-300); }
.cst-ligne { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.cst-ligne label { flex-direction: row; align-items: center; }
.cst-input { background: var(--stone-900); color: var(--parch-100); border: var(--line); border-radius: 8px; padding: 6px 8px; font: inherit; font-size: 13px; }
.cst-num { width: 64px; }
.cst-large { flex: 1 1 260px; min-width: 200px; }
.cst-lbl { font-size: 12px; font-weight: 700; color: var(--ink-300); text-transform: uppercase; letter-spacing: .06em; }
.cst-case { flex-direction: row !important; align-items: center; }
/* Les sous-éditeurs (lots K5l à K5o : `ProfilMonstre`, `TableDeCases`) reprennent les mêmes contrôles — le style
   `scoped` de cette vue ne descend pas dans un composant enfant sans `:deep()`. */
.cst-livrets { border: var(--line); border-radius: var(--r-md); padding: 8px 10px; }
.cst-livrets summary { cursor: pointer; }
.cst-bloc { display: flex; flex-direction: column; gap: 4px; margin-top: 8px; }
.cst-icone.on { color: var(--torch); border-color: var(--torch); }
.g-speciale { stroke: oklch(0.78 0.14 80); stroke-width: 2; stroke-dasharray: 6 3; }
.g-passage { fill: oklch(0.7 0.12 250 / 0.18); stroke: oklch(0.75 0.13 250); stroke-width: 3; stroke-dasharray: 5 3; }
.g-terrain.cachee { opacity: .45; font-style: italic; }
.cst-puces { display: flex; flex-wrap: wrap; gap: 4px; }
.cst-puce { font: inherit; font-size: 12px; padding: 3px 8px; border-radius: 12px; border: var(--line); background: var(--stone-850); color: var(--ink-300); cursor: pointer; }
.cst-puce.on { color: var(--torch); border-color: var(--torch); }
.cst-puce.ouvert { background: var(--stone-700); color: var(--parch-100); }
.cst-edition :deep(:is(.pm, .tdc) .cst-ligne) { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.cst-edition :deep(:is(.pm, .tdc) label) { display: inline-flex; flex-direction: row; align-items: center; gap: 6px; font-size: 12.5px; color: var(--ink-300); }
.cst-edition :deep(:is(.pm, .tdc) .cst-input) { background: var(--stone-900); color: var(--parch-100); border: var(--line); border-radius: 8px; padding: 6px 8px; font: inherit; font-size: 13px; }
.cst-edition :deep(:is(.pm, .tdc) .cst-num) { width: 64px; }
.cst-edition :deep(:is(.pm, .tdc) .cst-icone) { display: inline-grid; place-items: center; width: 28px; height: 28px; border-radius: 8px; border: var(--line); background: var(--stone-850); color: var(--ink-300); cursor: pointer; }
.cst-puces { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.cst-puce { padding: 5px 10px; border-radius: 99px; border: var(--line); background: var(--stone-850); color: var(--ink-300); cursor: pointer; font: inherit; font-size: 12.5px; }
.cst-puce.on { color: var(--stone-950); background: var(--gold); border-color: var(--gold); }
.cst-deux { display: grid; grid-template-columns: minmax(0, auto) minmax(280px, 1fr); gap: 16px; align-items: start; }
.cst-salle { display: flex; align-items: center; gap: 6px; padding: 6px; border-radius: var(--r-md); border: var(--line); }
.cst-salle.on { border-color: var(--torch); }
.cst-quetes { display: grid; grid-template-columns: 200px 1fr; gap: 14px; align-items: start; }
.cst-quete { display: flex; flex-direction: column; gap: 10px; min-width: 0; }
.cst-outils { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.cst-outil { display: inline-grid; place-items: center; width: 34px; height: 34px; border-radius: 9px; border: var(--line); background: var(--stone-850); color: var(--ink-300); cursor: pointer; }
.cst-outil.on { color: var(--stone-950); background: var(--torch); border-color: var(--torch); }
.cst-grille-cadre { overflow: auto; max-width: 100%; border: var(--line); border-radius: var(--r-md); background: var(--stone-900); }
.cst-grille { display: block; cursor: crosshair; user-select: none; }
.cst-notes { display: flex; flex-direction: column; gap: 10px; }
.cst-suite { display: flex; flex-direction: column; gap: 8px; }
.cst-note { display: flex; flex-direction: column; gap: 6px; padding: 10px; border-radius: var(--r-md); border: var(--line); background: var(--stone-900); }
.cst-effet { display: flex; align-items: flex-start; gap: 6px; flex-wrap: wrap; padding-left: 14px; border-left: 2px solid var(--stone-700); }
.cst-apparitions { display: flex; flex-direction: column; gap: 4px; }
.cst-refus { margin: 0; padding: 10px 12px 10px 28px; border-radius: var(--r-md); border: 1px solid var(--danger); font-size: 12.5px; color: var(--ink-300); display: flex; flex-direction: column; gap: 4px; }
.cst-refus code { color: var(--danger); margin-right: 6px; }

/* la grille */
.g-couloir { fill: var(--stone-800); }
.g-salle { fill: var(--stone-600); stroke: var(--parch-300); stroke-width: 2; }
.g-salle.sel { stroke: var(--torch); stroke-width: 3; }
.g-roche { fill: var(--stone-950); stroke: var(--stone-600); stroke-width: 1; }
.g-trace { fill: oklch(0.76 0.155 65 / 0.25); stroke: var(--torch); stroke-width: 2; }
.g-quadrillage line { stroke: oklch(0.5 0.01 255 / 0.25); stroke-width: 1; pointer-events: none; }
.g-id { font-size: 10px; fill: var(--parch-200); pointer-events: none; }
.g-bloc { fill: var(--stone-950); stroke: var(--ink-500); }
.g-meuble { fill: oklch(0.45 0.06 60); stroke: var(--parch-400); }
.g-txt { font-size: 9px; fill: var(--parch-100); pointer-events: none; }
.g-piege { font-size: 13px; fill: var(--warn); text-anchor: middle; pointer-events: none; }
.g-terrain { font-size: 16px; fill: var(--elem-water); text-anchor: middle; pointer-events: none; }
.g-entree { fill: oklch(0.6 0.12 150 / 0.35); stroke: var(--ok); stroke-width: 2; }
.g-sortie { fill: none; stroke: var(--torch); stroke-width: 2; stroke-dasharray: 4 3; }
.g-porte { stroke: var(--gold); stroke-width: 6; stroke-linecap: round; }
.g-porte-secrete { stroke: var(--mind-bright); stroke-width: 5; stroke-dasharray: 4 3; }
.g-porte-entree { stroke: var(--ok); stroke-width: 7; }
.g-porte-sortie { stroke: var(--torch); stroke-width: 7; stroke-dasharray: 5 3; }
.g-porte-fausse { stroke: var(--danger); stroke-width: 7; stroke-dasharray: 2 3; }
.g-monstre { fill: var(--ember-deep); stroke: var(--ember); stroke-width: 1.5; }
.g-monstre.cible { stroke: var(--gold); stroke-width: 3.5; }
.g-sigle { font-size: 10px; font-weight: 800; fill: var(--parch-100); text-anchor: middle; pointer-events: none; }
.g-index { font-size: 8px; fill: var(--ink-300); text-anchor: end; pointer-events: none; }
</style>
