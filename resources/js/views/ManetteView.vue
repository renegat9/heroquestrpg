<script setup>
// MANETTE JOUEUR (portrait, téléphone) — port fidèle de
// reference/heroquest/manette-app.jsx (+ manette.css, importé globalement).
// Au montage : GET /moi + GET etat, abonnement aux canaux privés
// `groupe.{identifiant}` (état, narration, MJ) et `joueur.{id}`
// (.menu.propose → onglet Action) ; chaque tap envoie POST choix
// {option_id} et gèle les boutons jusqu'au prochain .groupe.etat.
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import MSym from '../components/ui/MSym.vue';
import Vignette from '../components/ui/Vignette.vue';
import ActionTab from '../components/manette/ActionTab.vue';
import FicheTab from '../components/manette/FicheTab.vue';
import SpellsTab from '../components/manette/SpellsTab.vue';
import SacTab from '../components/manette/SacTab.vue';
import RecrutementHub from '../components/manette/RecrutementHub.vue';
import MarketTab from '../components/manette/MarketTab.vue';
import DieFace from '../components/manette/DieFace.vue';
import CibleSheet from '../components/manette/CibleSheet.vue';
import DeplacementSheet from '../components/manette/DeplacementSheet.vue';
import VoteSheet from '../components/manette/VoteSheet.vue';
import { souscrireGroupe, souscrireJoueur } from '../composables/useEcho';
import { useApi } from '../composables/useApi';
import {
    acteurCourant, ciblesVersListe, CLASSES, conditionControle, conditionsVersBadges,
    initiativeVersMini, inventaireVendable, issueCloture, labelCourt, marcheVersEchoppe,
    montantPanier, niveauMonteVersListe, panierDuJoueur, pretsVersEtat, sortsEpuises,
    useGameStore, voteVersFeuille,
} from '../store/game';

const props = defineProps({
    groupe: { type: String, required: true },
});

const store = useGameStore();
store.setGroupe(props.groupe);
const api = useApi();

/* ---- Personnage piloté sur CE téléphone ----
   Transmis par JoueurView via ?perso=ID et mémorisé par groupe : un même
   joueur peut contrôler PLUSIEURS personnages (sur des appareils distincts),
   donc la manette ne peut pas deviner « le 1er engagé » sans se tromper. */
const route = useRoute();
const CLE_PERSO = `manette:perso:${props.groupe}`;
const persoIdActif = ref((() => {
    const q = Number(route.query.perso);
    if (q) { try { localStorage.setItem(CLE_PERSO, String(q)); } catch { /* stockage indispo */ } return q; }
    const s = Number(localStorage.getItem(CLE_PERSO));
    return s || null;
})());

/* ---- chargement de l'état + abonnements temps réel ---- */
const desabonnements = [];
const chargement = ref(true);
const erreurChargement = ref('');
onMounted(async () => {
    try {
        const { joueur, personnages } = await api.moi();
        store.setJoueur(joueur, personnages);
        store.appliquerEtat(await api.getEtatReprise(props.groupe));
        desabonnements.push(
            souscrireGroupe(props.groupe, {
                // /moi re-GET (contrat « Sorts des héros ») : la disponibilité
                // des sorts (1×/quête) suit chaque résolution du moteur.
                '.groupe.etat': (e) => { store.appliquerEtat(e); rafraichirMoi(); },
                '.narration.diffusee': (e) => store.setNarration(e.texte, e.sequence),
                '.mj.reflechit': (e) => store.setMjReflechit(e.actif),
                '.combat.journal': (e) => store.pousserJournalCombat(e),
                '.marche.ouvert': (e) => store.appliquerMarche(e),
                '.marche.maj': (e) => store.appliquerMarche(e),
                '.marche.finalise': (e) => store.fermerMarche(e?.applique ?? null),
                '.vote.lance': (e) => store.appliquerVote(e?.vote ?? e),
                '.vote.maj': (e) => store.setVoteDecompte(e),
                '.vote.resultat': (e) => store.setVoteResultat(e),
                '.niveau.monte': (e) => {
                    store.setNiveauMonte(e);
                    // /moi rafraîchi : niveau + points_competence à jour.
                    api.moi().then((r) => store.setJoueur(r.joueur, r.personnages)).catch(() => {});
                },
                '.cloture.ouverte': (e) => store.appliquerCloture(e),
                '.cloture.maj': (e) => store.appliquerCloture(e),
                '.cloture.terminee': (e) => store.setClotureTerminee(e),
                '.prets.maj': (e) => store.appliquerPrets(e),
            }),
            souscrireJoueur(joueur.id, {
                '.menu.propose': (e) => store.setMenu(e.menu),
            }),
        );
        // Rattrapage du menu courant : à la reconnexion, on a raté le
        // `.menu.propose` déjà émis — on le récupère (régénéré si c'est notre tour).
        api.getMenu(props.groupe).then((r) => { if (r?.menu) store.setMenu(r.menu); }).catch(() => {});

        // Rattrapage : phase marché, vote ou clôture déjà en cours (reconnexion).
        api.getMarche(props.groupe).then((m) => store.appliquerMarche(m)).catch(() => {});
        api.getVote(props.groupe).then((r) => {
            const v = r?.vote ?? r;
            if (v && (v.type || v.options)) store.appliquerVote(v);
        }).catch(() => {});
        api.getCloture(props.groupe).then((c) => store.appliquerCloture(c)).catch(() => {});
    } catch (e) {
        erreurChargement.value = e.message;
    } finally {
        chargement.value = false;
    }
});
onUnmounted(() => desabonnements.forEach((off) => off()));

/* re-GET /moi débouncé (un .groupe.etat peut arriver en rafale) */
let moiTimer = null;
function rafraichirMoi() {
    clearTimeout(moiTimer);
    moiTimer = setTimeout(() => {
        api.moi().then((r) => store.setJoueur(r.joueur, r.personnages)).catch(() => {});
    }, 300);
}
onUnmounted(() => clearTimeout(moiTimer));

/* ---- quête échouée (TPK, contrat « Snapshots & reprise ») : la décision
   recharger/abandonner se prend à la table — la manette retire le menu et
   affiche une attente sobre. ---- */
const queteEchouee = computed(() => (store.state.etat?.quete?.etat ?? '') === 'echouee');

/* ---- condition de contrôle (Dread, doc 09 §4) : si mon héros est endormi
   (il saute son tour) ou commandé (le moteur le joue), la manette retire le
   menu et affiche un état sobre — le tour se résout sans moi. ---- */
const controleManette = computed(() => conditionControle(monEntite.value));
const ETATS_CONTROLE = {
    endormi: { ic: 'bedtime', titre: 'Endormi', detail: 'Tu sautes ton tour — une attaque subie te réveillera.' },
    commande: { ic: 'cyclone', titre: 'Commandé', detail: 'Ta volonté t\'échappe — le destin guide ta main ce tour.' },
};
const etatControle = computed(() => ETATS_CONTROLE[controleManette.value] ?? null);

/* ---- héros tombé (0 PV Body) : le moteur le saute dans l'initiative et
   rejette toutes ses actions — la manette retire le menu et l'annonce
   clairement (il ne rejouera que relevé par un allié). ---- */
const suisTombe = computed(() => monEntite.value?.tombe === true);

/* ---- mon héros : entité EtatGroupe ↔ personnage /moi. ---- */
const monEntite = computed(() => {
    const entites = store.state.etat?.entites ?? [];
    // Priorité au personnage explicitement piloté sur ce téléphone.
    if (persoIdActif.value) {
        const mien = entites.find((e) => e.type === 'heros' && e.id === persoIdActif.value);
        if (mien) return mien;
    }
    const ids = new Set((store.state.personnages ?? []).map((p) => p.id));
    return entites.find((e) => e.type === 'heros' && ids.has(e.id)) ?? null;
});

/* ---- Fin de mon tour → retire le menu périmé. Quand un .groupe.etat arrive
   avec mon héros `a_joue=true` (mes deux créneaux sont consommés / tour passé),
   le menu courant n'est plus jouable : on le vide pour afficher « tu reprendras
   la main » au lieu d'un menu qui renverrait 422. Le déclencheur sur a_joue=true
   évite toute course avec l'arrivée du prochain menu (a_joue repasse à false au
   début du tour suivant, AVANT le nouveau .menu.propose). ---- */
watch(() => store.state.etat, () => {
    if (!monEntite.value || !store.state.menu) return;
    const moi = (store.state.etat?.initiative ?? [])
        .find((o) => o.entite === 'heros' && o.id === monEntite.value.id);
    if (moi?.a_joue) store.viderMenu();
});

/* ---- Filet de sécurité du verrou anti-accumulation ----
   menuEnAttente ne tombe que sur MON prochain menu ou la fin de mon tour. Si un
   broadcast .menu.propose se perd (WS), on resterait gelé : au bout de 15 s de
   gel, on RATTRAPE le menu courant (getMenu) plutôt que rester bloqué. ---- */
let veilleVerrou = null;
watch(() => store.state.menuEnAttente, (gele) => {
    if (veilleVerrou) { clearTimeout(veilleVerrou); veilleVerrou = null; }
    if (!gele) return;
    veilleVerrou = setTimeout(() => {
        api.getMenu(props.groupe)
            .then((r) => { r?.menu ? store.setMenu(r.menu) : store.viderMenu(); })
            .catch(() => store.annulerChoixEnAttente());
    }, 15000);
});
onUnmounted(() => clearTimeout(veilleVerrou));

const tab = ref('action');

/** Habillage d'affichage (icône de classe + identité/stats réelles). Les
 *  attributs/dés (attribut_body/mind, des_attaque/defense) viennent de /moi
 *  (monPerso) — invariants hors quête, contrairement aux PV — le nom/niveau/
 *  conditions/portrait viennent de la source la plus à jour disponible
 *  (entité de quête en priorité, sinon le personnage). */
function habiller(perso, nom, niveau, conds, img = null) {
    const cls = CLASSES[(perso?.classe ?? '').toLowerCase()];
    return {
        name: nom,
        classe: (perso?.classe ?? '').toLowerCase(),
        cls: cls?.l ?? perso?.classe ?? '',
        crest: cls?.ic ?? 'person',
        icon: cls?.ic ?? 'person',
        lvl: niveau ?? perso?.niveau ?? 1,
        atkAttr: perso?.attribut_body ?? 0,
        mindAttr: perso?.attribut_mind ?? 0,
        atk: perso?.des_attaque ?? 0,
        def: perso?.des_defense ?? 0,
        conds,
        img, // portrait réel (image_url / portrait_url) si présent, sinon null → icône
    };
}
const hero = computed(() => {
    const p = monPerso.value;
    const e = monEntite.value;
    if (e) return habiller(p, e.nom, e.niveau, conditionsVersBadges(e.conditions), e.image_url ?? p?.portrait_url ?? null);
    if (p) return habiller(p, p.nom, p.niveau, [], p.portrait_url ?? null);
    return habiller(null, '…', 1, []); // avant chargement (bref, sous garde chargement/erreurChargement)
});

/* ---- mon personnage (/moi enrichi : niveau, points_competence, sorts).
   En quête : via mon entité d'EtatGroupe ; au hub (entites vides) : mon
   personnage rattaché au groupe, à défaut le premier. ---- */
const monPerso = computed(() => {
    const persos = store.state.personnages ?? [];
    if (monEntite.value) return persos.find((p) => p.id === monEntite.value.id) ?? null;
    // Au hub (entités vides) : le personnage piloté sur ce téléphone.
    if (persoIdActif.value) {
        const mien = persos.find((p) => p.id === persoIdActif.value);
        if (mien) return mien;
    }
    return persos.find((p) => p.groupe_actif_id != null || p.disponible === false)
        ?? persos[0]
        ?? null;
});
const pointsCompetence = computed(() => monPerso.value?.points_competence ?? 0);

/* ---- Talents acquis (fiche) : /moi ne porte que les IDS des nœuds ; on charge
   le catalogue une fois pour les nommer sur la fiche (« Garde tenace » etc.). ---- */
const catalogueCompetences = ref([]);
onMounted(async () => {
    try { catalogueCompetences.value = (await api.getCompetences()).competences ?? []; } catch { /* fiche sans noms de talents */ }
});
const mesCompetences = computed(() => {
    const parId = new Map(catalogueCompetences.value.map((c) => [c.id, c]));
    return (monPerso.value?.competences ?? []).map((id) => parId.get(id)).filter(Boolean);
});

/* ---- statut « Prêt » au hub (contrat « Statut prêt et démarrage de quête ») ----
   Phase hub uniquement. Mon personnage est le perso rattaché au groupe
   (monPerso). L'état des prêts vient de EtatGroupe.groupe.prets et du
   broadcast .prets.maj. Le narrateur doit être actif pour que la quête
   démarre (côté serveur). */
const auHub = computed(() => store.state.etat?.groupe?.phase === 'hub');
const narrateurActif = computed(() => store.state.etat?.groupe?.narrateur_actif ?? false);
const monPersonnageId = computed(() => monPerso.value?.id ?? null);
const preEnAttente = ref(false);
const erreurPret = ref('');

const monPret = computed(() => {
    if (!monPersonnageId.value) return false;
    const prets = store.state.prets ?? [];
    const mien = prets.find((r) => r.personnage_id === monPersonnageId.value);
    return mien?.pret ?? false;
});

const listePresence = computed(() =>
    pretsVersEtat(store.state.prets, store.state.personnages));

// Copier le code de groupe (clipboard si dispo — sinon repli execCommand,
// car en HTTP le navigateur peut bloquer navigator.clipboard).
const codeCopie = ref(false);
async function copierCode() {
    try {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(props.groupe);
        } else {
            const t = document.createElement('textarea');
            t.value = props.groupe; t.style.position = 'fixed'; t.style.opacity = '0';
            document.body.appendChild(t); t.select();
            document.execCommand('copy'); document.body.removeChild(t);
        }
        codeCopie.value = true;
        setTimeout(() => { codeCopie.value = false; }, 1500);
    } catch { /* best-effort : le code reste lisible/sélectionnable à l'écran */ }
}

async function basculerPret() {
    if (!monPersonnageId.value || preEnAttente.value) return;
    preEnAttente.value = true;
    erreurPret.value = '';
    const nouveau = !monPret.value;
    try {
        await api.marquerPret(props.groupe, monPersonnageId.value, nouveau);
        // Le serveur répond par .prets.maj (Reverb) ; pas besoin de re-GET.
    } catch (e) {
        erreurPret.value = e.message;
    } finally {
        preEnAttente.value = false;
    }
}

/* ---- sorts du héros (contrat « Sorts des héros ») : /moi expose
   sorts: [{sort_id, nom, element, type, disponible}] (toujours un tableau,
   vide si la classe ne lance pas de sorts). ---- */
const mesSorts = computed(() => monPerso.value?.sorts ?? []);

const lvlupToast = computed(() => {
    if (!store.state.niveauMonte) return null;
    const liste = niveauMonteVersListe(store.state.niveauMonte, store.state.etat?.entites);
    if (!liste.length) return null;
    const ids = new Set((store.state.personnages ?? []).map((p) => p.id));
    return liste.find((h) => ids.has(h.id)) ?? liste[0];
});
const body = computed(() => {
    const e = monEntite.value;
    if (e) return { cur: e.tombe ? 0 : e.pv_body, max: e.pv_body_max };
    // Hub : PV réels du personnage (/moi).
    const p = monPerso.value;
    if (p?.pv_body_max != null) return { cur: p.pv_body, max: p.pv_body_max };
    return { cur: 0, max: 0 };
});
const mind = computed(() => {
    const e = monEntite.value;
    if (e) return { cur: e.pv_mind, max: e.pv_mind_max };
    const p = monPerso.value;
    if (p?.pv_mind_max != null) return { cur: p.pv_mind, max: p.pv_mind_max };
    return { cur: 0, max: 0 };
});

const scene = computed(() => (store.state.etat?.groupe?.phase === 'hub' ? 'marche' : 'combat'));
const thinking = computed(() => store.state.mjReflechit);
const conn = computed(() => store.state.connexion); // 'ok' | 'warn'
const narration = computed(() => store.state.narration);
/* Journal de combat mécanique (.combat.journal) — les plus récentes en bas. */
const journalCombat = computed(() => store.state.journalCombat);

/* ---- menu réel (.menu.propose) + envoi du choix (POST choix) ---- */
const menuStore = computed(() => store.state.menu);

/* C'est mon tour ? Acteur courant de l'initiative = mon héros. Le moteur
   pré-génère un menu pour CHAQUE héros au démarrage (un .menu.propose chacun) ;
   sans ce verrou, toutes les manettes afficheraient « C'est ton tour » en même
   temps. On n'active donc le menu que quand l'initiative est sur mon héros
   (sinon : « tu reprendras la main »). */
const cestMonTour = computed(() => {
    if (!monEntite.value) return false;
    const cur = acteurCourant(store.state.etat?.initiative);
    return !!(cur && cur.entite === 'heros' && cur.id === monEntite.value.id);
});

/* Menu effectivement jouable : le menu courant SEULEMENT quand c'est mon tour. */
const menuCourant = computed(() => (cestMonTour.value ? menuStore.value : null));
const menuEnAttente = computed(() => store.state.menuEnAttente);
/* Boutons gelés : mon choix est en attente de résolution OU le MJ réfléchit
   (job LLM en cours pour TOUT le groupe) — dans les deux cas, le menu affiché
   est sur le point de changer : untaper évite un choix qui deviendrait
   illégal (422) ou s'accumulerait derrière la prochaine résolution. */
const boutonsGeles = computed(() => menuEnAttente.value || thinking.value);
const initMini = computed(() => initiativeVersMini(store.state.etat?.initiative));
const initCur = computed(() => {
    const cur = acteurCourant(store.state.etat?.initiative);
    return cur ? labelCourt(cur.nom) : null;
});

/* Feuille de ciblage / concentration (CibleSheet) ouverte par une option :
   { option, mode: 'cible'|'concentration', cibles?|sorts? }. Un nouveau
   menu (.menu.propose) la referme — l'option ne serait plus légale. */
const feuilleOption = ref(null);
watch(() => store.state.menu, () => { feuilleOption.value = null; });

function choisirOption(option) {
    if (boutonsGeles.value) return;

    // Option ciblée (sort / parchemin / attaque) : le moteur fournit les
    // cibles légales dans parametres.cibles (les héros y figurent — tir
    // ami S3) → choix de cible avant l'envoi.
    // Déplacement : ouvre la mini-carte tappable (l'allonce du tour, dé déjà
    // lancé côté serveur, est dans l'option) → le joueur choisit sa case.
    if (option.type === 'deplacement' && monEntite.value) {
        feuilleOption.value = { option, mode: 'deplacement' };
        return;
    }

    const cibles = option.parametres?.cibles;
    if (Array.isArray(cibles) && cibles.length) {
        feuilleOption.value = {
            option,
            mode: 'cible',
            cibles: ciblesVersListe(cibles, store.state.etat?.entites),
        };
        return;
    }

    // Concentration (nœud Magicien) : récupère UN sort épuisé au choix
    // (parametres: {sort_id}) — liste fournie par l'option ou déduite de /moi.
    if (option.type === 'concentration' && option.parametres?.sort_id == null) {
        const fournis = option.parametres?.sorts;
        const epuises = Array.isArray(fournis) && fournis.length
            ? fournis.map((s) => (typeof s === 'object' ? s : (
                (mesSorts.value ?? []).find((m) => String(m.sort_id) === String(s)) ?? { sort_id: s }
            )))
            : sortsEpuises(mesSorts.value);
        if (epuises.length) {
            feuilleOption.value = { option, mode: 'concentration', sorts: epuises };
            return;
        }
    }

    envoyerOption(option, option.parametres);
}

/** Case choisie sur la mini-carte → POST {option_id, parametres: {x, y}}. */
function deplacerVers(dest) {
    const { option } = feuilleOption.value;
    envoyerOption(option, { x: dest.x, y: dest.y });
}

/** Cible choisie dans la feuille → POST {option_id, parametres: {…,
 *  cible_id, cible_type}} À PLAT — seul format lu par le moteur (contrat) :
 *  l'objet `cible` imbriqué était ignoré → 422 « Cible requise » sur tous
 *  les sorts/parchemins ciblés depuis l'UI. */
function ciblerOption(cible) {
    const { option } = feuilleOption.value;
    const { cibles, ...reste } = option.parametres ?? {};
    envoyerOption(option, { ...reste, cible_id: cible.id, cible_type: cible.type });
}

/** Sort à récupérer choisi (concentration) → parametres: {sort_id}. */
function concentrerSort(sort) {
    const { option } = feuilleOption.value;
    const { sorts, ...reste } = option.parametres ?? {};
    envoyerOption(option, { ...reste, sort_id: sort.sort_id });
}

async function envoyerOption(option, parametres) {
    feuilleOption.value = null;
    store.choixEnvoye(); // optimiste : gelés jusqu'à mon prochain menu / fin de tour
    try {
        const rep = await api.envoyerChoix(props.groupe, { option_id: option.id, parametres });
        revelerDesResultat(rep?.resultat); // affiche le jet quelques secondes (#dés)
    } catch (e) {
        store.annulerChoixEnAttente(); // 422 option illégale, etc. : on rend la main
        store.setNarration(e.message);
    }
}

/* ---- Révélation des dés (mode connecté) ----
   Le POST choix renvoie EN ECHO les faces réelles du jet ; on les affiche
   quelques secondes pour qu'on VOIE le résultat (sinon seul .groupe.etat met à
   jour les PV — trop rapide, le joueur ne perçoit pas le lancer). ---- */
const desReveles = ref(null);
function faceVersDe(v, camp) {
    if (camp === 'atk') return v === 'crane' ? 'skull' : 'blank';
    return (v === 'bouclier_blanc' || v === 'bouclier_noir') ? 'shield' : 'blank';
}
let timerDes = null;
function revelerDesResultat(r) {
    if (!r || !Array.isArray(r.faces_attaque)) return; // seulement les jets d'attaque
    desReveles.value = {
        atk: r.faces_attaque.map((v) => faceVersDe(v, 'atk')),
        def: (r.faces_defense ?? []).map((v) => faceVersDe(v, 'def')),
        degats: r.degats ?? 0,
        cible: r.cible?.nom ?? r.cible_nom ?? null,
    };
    if (timerDes) clearTimeout(timerDes);
    timerDes = setTimeout(() => { desReveles.value = null; }, 3200);
}

/* ---- Potions : action GRATUITE jouable À TOUT MOMENT (canon), même hors de
   son tour / pendant le tour d'un monstre. Ne passe pas par le menu. ---- */
const consommablesActifs = computed(() => monPerso.value?.consommables ?? []);
const potionEnCours = ref(false);
async function boirePotion(inventaireId) {
    if (potionEnCours.value) return;
    potionEnCours.value = true;
    try {
        await api.boirePotion(props.groupe, inventaireId);
        rafraichirMoi(); // PV + inventaire rafraîchis
    } catch (e) {
        store.setNarration(e.message);
    } finally {
        potionEnCours.value = false;
    }
}

/* ---- Équipement (doc 01 §7) : au hub uniquement, monter/démonter une pièce
   du sac. Le serveur applique les deltas de combat aux colonnes du héros ; on
   recharge /moi pour rafraîchir dés (fiche) + sac (onglet). ---- */
const equipEnCours = ref(false);
async function equiper(inventaireId) {
    if (equipEnCours.value || !monPersonnageId.value) return;
    equipEnCours.value = true;
    try {
        await api.equiper(props.groupe, monPersonnageId.value, inventaireId);
        rafraichirMoi();
    } catch (e) {
        store.setNarration(e.message);
    } finally {
        equipEnCours.value = false;
    }
}
async function desequiper(inventaireId) {
    if (equipEnCours.value || !monPersonnageId.value) return;
    equipEnCours.value = true;
    try {
        await api.desequiper(props.groupe, monPersonnageId.value, inventaireId);
        rafraichirMoi();
    } catch (e) {
        store.setNarration(e.message);
    } finally {
        equipEnCours.value = false;
    }
}

/* ---- Alliés / mercenaires (doc 14 §3.5) : au hub, recruter un renfort scripté
   contre la bourse COMMUNE. Le catalogue (statique) est chargé une fois ; les
   recrues et l'or vivent dans EtatGroupe (mis à jour en direct par le POST, qui
   rediffuse .groupe.etat). ---- */
const catalogueMercs = ref([]);
const recrutEnCours = ref(false);
const recruesHub = computed(() => store.state.etat?.groupe?.mercenaires ?? []);
const orCommun = computed(() => store.state.etat?.groupe?.or ?? 0);
async function chargerMercenaires() {
    if (catalogueMercs.value.length) return;
    try {
        catalogueMercs.value = (await api.getMercenaires()).mercenaires ?? [];
    } catch { /* catalogue indisponible : le panneau reste vide */ }
}
// Charge le catalogue dès qu'on est au hub (le recrutement n'y est possible que là).
watch(auHub, (v) => { if (v) chargerMercenaires(); }, { immediate: true });
async function recruter(mercenaireId) {
    if (recrutEnCours.value) return;
    recrutEnCours.value = true;
    try {
        await api.recruterMercenaire(props.groupe, mercenaireId);
        // recrues + or arrivent par .groupe.etat (EtatGroupeDiffuse) — rien à recharger.
    } catch (e) {
        store.setNarration(e.message);
    } finally {
        recrutEnCours.value = false;
    }
}

/* ---- vote (.vote.lance ouvre la feuille, bulletin POSTé, .vote.maj fait
   vivre le décompte, .vote.resultat ferme avec le résultat ; la cible d'un
   retrait_joueur ne vote pas (lecture seule)). ---- */
const monBulletin = ref(null);
watch(() => store.state.vote, () => { monBulletin.value = null; });

const voteAffiche = computed(() => voteVersFeuille(
    store.state.vote, store.state.voteDecompte, store.state.voteResultat,
    monBulletin.value, store.state.joueur?.id, store.state.etat,
));

async function castVote(k) {
    monBulletin.value = k; // optimiste — le décompte arrive par .vote.maj
    try {
        await api.voterBulletin(props.groupe, k);
    } catch (e) {
        monBulletin.value = null;
        store.setNarration(e.message);
    }
}
function fermerVote() {
    store.fermerVote();
    monBulletin.value = null;
}

/* ---- marché (doc 04 §5 — saisie individuelle) : le
   panier local du joueur est la source de vérité de SON panier ; chaque
   modification est PUT (débouncée) et annule sa confirmation. Le total
   projeté du groupe = total serveur corrigé du delta local. ---- */
const panierLocal = ref(null); // { achats: [{objet_id, quantite}], ventes: [inventaire_id…] }
const confirmEnvoyee = ref(false);
const marcheErreur = ref('');
let panierTimer = null;
let panierDirty = false;

watch(() => store.state.marche, (m) => {
    if (!m) {
        panierLocal.value = null;
        confirmEnvoyee.value = false;
        marcheErreur.value = '';
        panierDirty = false;
        clearTimeout(panierTimer);
        return;
    }
    if (panierLocal.value === null) {
        const mien = panierDuJoueur(m, store.state.joueur?.id);
        panierLocal.value = {
            achats: (mien?.achats ?? []).map((a) => ({ objet_id: a.objet_id, quantite: a.quantite ?? 1 })),
            ventes: (mien?.ventes ?? []).map((v) => (typeof v === 'object' ? v.inventaire_id : v)),
        };
    }
}, { immediate: true });
onUnmounted(() => clearTimeout(panierTimer));

const marcheLive = computed(() => {
    if (!store.state.marche || !panierLocal.value) return null;
    const m = store.state.marche;
    const joueurId = store.state.joueur?.id;
    const inventaire = inventaireVendable(m, joueurId, store.state.etat, store.state.personnages);
    const mienServeur = panierDuJoueur(m, joueurId);
    const local = montantPanier(panierLocal.value, m, inventaire);
    const serveur = montantPanier(mienServeur, m, inventaire);
    const paniers = m.paniers ?? [];
    return {
        profil: m.profil,
        pseudo: mienServeur?.pseudo ?? store.state.joueur?.pseudo,
        or: m.or_courant ?? 0,
        items: marcheVersEchoppe(m),
        inventaire,
        achats: panierLocal.value.achats,
        ventes: panierLocal.value.ventes,
        confirme: !!mienServeur?.confirme || confirmEnvoyee.value,
        confirmes: paniers.filter((p) => p.confirme).length,
        membres: paniers.length,
        totalAchats: local.achats,
        totalVentes: local.ventes,
        totalProjete: (m.total_projete ?? m.or_courant ?? 0) + (local.net - serveur.net),
        erreur: marcheErreur.value,
    };
});

async function envoyerPanier() {
    clearTimeout(panierTimer);
    if (!panierDirty || !panierLocal.value) return;
    panierDirty = false;
    try {
        const r = await api.majPanier(props.groupe, {
            achats: panierLocal.value.achats,
            ventes: panierLocal.value.ventes.map((id) => ({ inventaire_id: id })),
        });
        if (r) store.appliquerMarche(r);
    } catch (e) {
        marcheErreur.value = e.message;
    }
}
function planifierEnvoiPanier() {
    confirmEnvoyee.value = false; // toute modification annule la confirmation
    marcheErreur.value = '';
    panierDirty = true;
    clearTimeout(panierTimer);
    panierTimer = setTimeout(envoyerPanier, 400);
}
function changerQuantite(objetId, delta) {
    const achats = [...panierLocal.value.achats];
    const i = achats.findIndex((a) => a.objet_id === objetId);
    // Contrat : stock null = illimité (commun) — ne pas le coercer à 0.
    const stock = store.state.marche?.inventaire?.find((it) => it.objet_id === objetId)?.stock ?? null;
    const plafond = stock === null ? Number.MAX_SAFE_INTEGER : stock;
    const q = Math.max(0, Math.min(plafond, (i >= 0 ? achats[i].quantite : 0) + delta));
    if (q === 0) {
        if (i >= 0) achats.splice(i, 1);
    } else if (i >= 0) {
        achats[i] = { ...achats[i], quantite: q };
    } else {
        achats.push({ objet_id: objetId, quantite: q });
    }
    panierLocal.value = { ...panierLocal.value, achats };
    planifierEnvoiPanier();
}
function basculerVente(inventaireId) {
    const ventes = panierLocal.value.ventes.includes(inventaireId)
        ? panierLocal.value.ventes.filter((id) => id !== inventaireId)
        : [...panierLocal.value.ventes, inventaireId];
    panierLocal.value = { ...panierLocal.value, ventes };
    planifierEnvoiPanier();
}
async function confirmerMonPanier() {
    await envoyerPanier(); // pousse le panier en attente avant de confirmer
    confirmEnvoyee.value = true;
    try {
        const r = await api.confirmerPanier(props.groupe);
        if (r) store.appliquerMarche(r);
    } catch (e) {
        confirmEnvoyee.value = false;
        marcheErreur.value = e.message;
    }
}

/* ---- clôture de campagne : .cloture.ouverte → toast routant vers l'écran
   de clôture (/cloture/:groupe) ; .cloture.terminee → même toast vers
   l'épilogue (le groupe n'existe plus). ---- */
const clotureToastFermee = ref(false);
watch(() => !!store.state.cloture, (ouverte) => { if (ouverte) clotureToastFermee.value = false; });
watch(() => !!store.state.clotureTerminee, (fin) => { if (fin) clotureToastFermee.value = false; });

const clotureToast = computed(() => {
    if (clotureToastFermee.value) return null;
    if (store.state.clotureTerminee) {
        return { titre: 'Campagne terminée', texte: "L'épilogue du MJ vous attend.", lien: "Voir l'épilogue" };
    }
    if (store.state.cloture) {
        return { titre: 'Clôture de campagne', texte: issueCloture(store.state.cloture.issue).sous, lien: 'Ouvrir' };
    }
    return null;
});

/* scène → onglet par défaut */
watch(scene, (s) => { if (s === 'marche') tab.value = 'action'; });

const navItems = computed(() => (scene.value === 'marche'
    ? [['action', 'storefront', 'Marché'], ['fiche', 'person', 'Fiche'], ['sorts', 'auto_awesome', 'Sorts'], ['sac', 'backpack', 'Sac']]
    : [['action', 'swords', 'Action'], ['fiche', 'person', 'Fiche'], ['sorts', 'auto_awesome', 'Sorts'], ['sac', 'backpack', 'Sac']]));
</script>

<template>
    <div class="stage">
        <div>
            <div class="phone">
                <div class="screen tex-vignette" style="position: relative">
                    <!-- connexion / erreur de chargement initial -->
                    <div v-if="chargement || erreurChargement" class="chargement-overlay">
                        <template v-if="chargement">
                            <MSym n="hourglass_top" :size="30" />
                            <p>Connexion…</p>
                        </template>
                        <template v-else>
                            <MSym n="error" fill :size="30" />
                            <p>{{ erreurChargement }}</p>
                        </template>
                    </div>
                    <!-- barre de statut -->
                    <div class="topbar">
                        <div class="hero-chip">
                            <span class="crest"><Vignette :src="hero.img" :icon="hero.crest" fill :size="22" /></span>
                            <div>
                                <div class="nm">{{ hero.name }}</div>
                                <div class="cls">{{ hero.cls }} · Niv. {{ hero.lvl }}</div>
                            </div>
                        </div>
                        <div v-if="thinking" class="think" style="margin-left: auto">
                            <span class="dots"><i /><i /><i /></span>MJ réfléchit…
                        </div>
                        <div v-else class="conn" :class="conn" style="margin-left: auto">
                            <span class="dot" />{{ conn === 'ok' ? 'Connecté' : 'Reconnexion…' }}
                        </div>
                    </div>

                    <!-- mini jauges PV -->
                    <div class="mini-pv">
                        <div class="g">
                            <div class="lab" style="color: var(--body-bright)">
                                <span><MSym n="favorite" fill :size="12" /> BODY</span><span>{{ body.cur }}/{{ body.max }}</span>
                            </div>
                            <div class="pips"><div v-for="i in body.max" :key="i" class="pip" :class="{ b: i <= body.cur }" /></div>
                        </div>
                        <div class="g">
                            <div class="lab" style="color: var(--mind-bright)">
                                <span><MSym n="psychology" fill :size="12" /> MIND</span><span>{{ mind.cur }}/{{ mind.max }}</span>
                            </div>
                            <div class="pips"><div v-for="i in mind.max" :key="i" class="pip" :class="{ m: i <= mind.cur }" /></div>
                        </div>
                    </div>

                    <!-- narration compacte -->
                    <div class="narr-peek">
                        <div class="hd">
                            <span class="who">LE MAÎTRE DE JEU</span>
                            <span class="bars"><i /><i /><i /></span>
                        </div>
                        <p>{{ narration }}</p>
                    </div>

                    <!-- zone principale -->
                    <div class="body">
                        <!-- quête échouée (TPK) : pas de menu, le sort du
                             groupe se joue sur l'écran de table -->
                        <div v-if="tab === 'action' && queteEchouee" style="text-align: center; padding: 30px 14px; color: var(--ink-500)">
                            <MSym n="skull" :size="34" />
                            <p style="font-family: var(--font-narr); font-style: italic; font-size: 15px; margin: 10px 0 0">
                                Le destin du groupe se décide à la table…
                            </p>
                        </div>
                        <!-- héros tombé : le moteur le saute, aucun menu n'est jouable -->
                        <div v-else-if="tab === 'action' && suisTombe" style="text-align: center; padding: 30px 14px; color: var(--ink-500)">
                            <MSym n="personal_injury" :size="34" />
                            <p style="font-family: var(--font-narr); font-weight: 700; font-size: 16px; margin: 10px 0 4px">À terre</p>
                            <p style="font-family: var(--font-narr); font-style: italic; font-size: 14px; margin: 0">
                                Tu es tombé au combat — seul un allié peut te relever.
                            </p>
                        </div>
                        <!-- contrôle Dread (endormi / commandé) : le tour se résout sans moi -->
                        <div v-else-if="tab === 'action' && etatControle" style="text-align: center; padding: 30px 14px; color: var(--ink-500)">
                            <MSym :n="etatControle.ic" :size="34" />
                            <p style="font-family: var(--font-narr); font-weight: 700; font-size: 16px; margin: 10px 0 4px">{{ etatControle.titre }}</p>
                            <p style="font-family: var(--font-narr); font-style: italic; font-size: 14px; margin: 0">
                                {{ etatControle.detail }}
                            </p>
                        </div>
                        <ActionTab
                            v-else-if="tab === 'action' && scene === 'combat'"
                            :hero="hero"
                            :menu="menuCourant"
                            :pending="boutonsGeles"
                            :thinking="thinking"
                            :init-order="initMini"
                            :init-cur="initCur"
                            :sorts="mesSorts"
                            :journal="journalCombat"
                            @choose="choisirOption"
                            @open-spells="tab = 'sorts'"
                        />
                        <MarketTab
                            v-else-if="tab === 'action' && scene === 'marche' && marcheLive"
                            :hero="hero"
                            :live="marcheLive"
                            @qty="changerQuantite"
                            @vendre="basculerVente"
                            @confirmer="confirmerMonPanier"
                        />
                        <!-- hub connecté, marché pas (ou plus) ouvert -->
                        <div v-else-if="tab === 'action' && scene === 'marche'" style="padding: 14px">
                            <!-- ---- statut marché ---- -->
                            <p style="font-family: var(--font-narr); font-style: italic; font-size: 14px; color: var(--ink-500); margin: 0 0 16px; text-align: center">
                                {{ store.state.marcheFinalise
                                    ? (store.state.marcheFinalise.applique
                                        ? 'Marché conclu — les paniers ont été appliqués.'
                                        : 'La phase de marché a été annulée.')
                                    : "Le marché n'est pas encore ouvert. Le groupe se repose au hub…" }}
                            </p>

                            <!-- ---- bouton Prêt (phase hub, mode connecté) ---- -->
                            <div v-if="auHub" class="pret-hub">
                                <div class="pret-hub-titre">
                                    <MSym n="flag" fill :size="15" /> Prêt pour la quête
                                </div>
                                <!-- liste des prêts des autres joueurs -->
                                <div v-if="listePresence.length" class="pret-liste">
                                    <div
                                        v-for="r in listePresence"
                                        :key="r.personnage_id"
                                        class="pret-ligne"
                                        :class="{ pret: r.pret }"
                                    >
                                        <MSym :n="r.pret ? 'check_circle' : 'radio_button_unchecked'" fill :size="15" />
                                        {{ r.nom }}
                                    </div>
                                </div>
                                <!-- narrateur actif / en attente -->
                                <div class="pret-narrateur" :class="{ actif: narrateurActif }">
                                    <MSym :n="narrateurActif ? 'cast_connected' : 'cast'" :size="14" />
                                    {{ narrateurActif ? 'Narrateur actif' : 'En attente d\'un narrateur' }}
                                </div>
                                <!-- code du groupe (à donner au narrateur tant qu'il n'est pas actif) -->
                                <div v-if="!narrateurActif" class="pret-code">
                                    <span class="pret-code-lbl">Code du groupe</span>
                                    <button
                                        class="pret-code-val"
                                        type="button"
                                        :title="codeCopie ? 'Copié !' : 'Copier le code'"
                                        @click="copierCode"
                                    >
                                        <code>{{ groupe }}</code>
                                        <MSym :n="codeCopie ? 'check' : 'content_copy'" :size="13" />
                                    </button>
                                </div>
                                <!-- bouton Prêt / Annuler -->
                                <button
                                    class="pret-btn"
                                    :class="{ pret: monPret }"
                                    :disabled="preEnAttente || !monPersonnageId"
                                    @click="basculerPret"
                                >
                                    <MSym :n="monPret ? 'cancel' : 'check_circle'" fill :size="18" />
                                    {{
                                        preEnAttente
                                            ? 'Envoi…'
                                            : (monPret ? 'Annuler ma présence' : 'Prêt pour la quête !')
                                    }}
                                </button>
                                <p v-if="erreurPret" class="pret-err">
                                    <MSym n="error" :size="13" /> {{ erreurPret }}
                                </p>
                            </div>

                            <!-- ---- recrutement d'alliés (hub, bourse commune) ---- -->
                            <RecrutementHub
                                v-if="auHub"
                                :catalogue="catalogueMercs"
                                :recrues="recruesHub"
                                :or="orCommun"
                                :en-cours="recrutEnCours"
                                @recruter="recruter"
                            />
                        </div>
                        <FicheTab
                            v-else-if="tab === 'fiche'"
                            :hero="hero"
                            :body="body"
                            :mind="mind"
                            :niveau="monEntite?.niveau ?? null"
                            :points="pointsCompetence"
                            :groupe="groupe"
                            :competences="mesCompetences"
                        />
                        <SpellsTab
                            v-else-if="tab === 'sorts'"
                            :hero="hero"
                            :sorts="mesSorts"
                            :menu="menuCourant"
                            :pending="boutonsGeles"
                            @choose="choisirOption"
                        />
                        <SacTab
                            v-else-if="tab === 'sac'"
                            :equipement="monPerso?.equipement ?? { armes: [], armure: null, sac: [] }"
                            :potions="consommablesActifs"
                            :potion-en-cours="potionEnCours"
                            :au-hub="auHub"
                            :equip-en-cours="equipEnCours"
                            @boire="boirePotion"
                            @equiper="equiper"
                            @desequiper="desequiper"
                        />
                    </div>

                    <!-- navigation basse -->
                    <div class="botnav">
                        <button v-for="[k, ic, l] in navItems" :key="k" :class="{ on: tab === k }" @click="tab = k">
                            <MSym :n="ic" /><span class="bl">{{ l }}</span>
                        </button>
                    </div>

                    <!-- toast montée de niveau (.niveau.monte sur groupe.{id}) -->
                    <div v-if="lvlupToast" class="lvlup-toast">
                        <span class="seal"><MSym n="military_tech" fill /></span>
                        <div class="tx">
                            <b>Niveau {{ lvlupToast.niveau }} — {{ lvlupToast.nom }}</b>
                            <span>{{ lvlupToast.gains.length ? lvlupToast.gains.join(' · ') : 'Le jalon est franchi !' }}</span>
                        </div>
                        <RouterLink
                            v-if="lvlupToast.points > 0 || pointsCompetence > 0"
                            class="go"
                            :to="{ name: 'montee-niveau', params: { groupe } }"
                        >
                            <MSym n="hub" fill :size="15" /> Dépenser mes points
                        </RouterLink>
                        <button class="x" aria-label="Fermer" @click="store.fermerNiveauMonte()">
                            <MSym n="close" :size="18" />
                        </button>
                    </div>

                    <!-- toast clôture de campagne (.cloture.ouverte / .cloture.terminee) -->
                    <div v-if="clotureToast" class="lvlup-toast">
                        <span class="seal"><MSym n="workspace_premium" fill /></span>
                        <div class="tx">
                            <b>{{ clotureToast.titre }}</b>
                            <span>{{ clotureToast.texte }}</span>
                        </div>
                        <RouterLink class="go" :to="{ name: 'cloture', params: { groupe } }">
                            <MSym n="arrow_forward" fill :size="15" /> {{ clotureToast.lien }}
                        </RouterLink>
                        <button class="x" aria-label="Fermer" @click="clotureToastFermee = true">
                            <MSym n="close" :size="18" />
                        </button>
                    </div>

                    <!-- overlays -->
                    <DeplacementSheet
                        v-if="feuilleOption && feuilleOption.mode === 'deplacement' && monEntite"
                        :carte="store.state.etat.carte"
                        :entites="store.state.etat.entites ?? []"
                        :depart="{ x: monEntite.x, y: monEntite.y }"
                        :portee="feuilleOption.option.parametres?.portee ?? feuilleOption.option.parametres?.portee_base ?? 0"
                        :de="feuilleOption.option.parametres?.de ?? null"
                        :base="feuilleOption.option.parametres?.base ?? 0"
                        @deplacer="deplacerVers"
                        @close="feuilleOption = null"
                    />
                    <CibleSheet
                        v-else-if="feuilleOption"
                        :feuille="feuilleOption"
                        @cible="ciblerOption"
                        @sort="concentrerSort"
                        @close="feuilleOption = null"
                    />
                    <VoteSheet v-if="voteAffiche" :vote="voteAffiche" @cast="castVote" @close="fermerVote" />

                    <!-- Révélation du jet de dés (mode connecté) : tap pour fermer -->
                    <div v-if="desReveles" class="des-reveal" @click="desReveles = null">
                        <div class="des-reveal-card">
                            <div class="dr-row"><DieFace v-for="(d, i) in desReveles.atk" :key="'a' + i" :face="d" reveal /></div>
                            <div v-if="desReveles.def.length" class="dr-row dr-def">
                                <DieFace v-for="(d, i) in desReveles.def" :key="'d' + i" :face="d" reveal />
                            </div>
                            <div class="dr-txt">
                                <template v-if="desReveles.degats > 0">
                                    {{ desReveles.degats }} blessure{{ desReveles.degats > 1 ? 's' : '' }}<template v-if="desReveles.cible"> · {{ desReveles.cible }}</template>
                                </template>
                                <template v-else>Coup paré !</template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="scene-ctrl">
                <RouterLink
                    to="/"
                    title="Hub"
                    style="display: grid; place-items: center; width: 30px; height: 30px; border-radius: 50%; color: var(--ink-300); text-decoration: none"
                >
                    <MSym n="home" :size="18" />
                </RouterLink>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Révélation du jet de dés (mode connecté) — overlay lisible ~3 s */
.des-reveal {
    position: fixed;
    inset: 0;
    z-index: 60;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(6, 4, 2, 0.55);
    backdrop-filter: blur(2px);
}
.des-reveal-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    padding: 22px 26px;
    border-radius: 16px;
    background: var(--panel, #17120b);
    border: 1px solid rgba(201, 162, 74, 0.35);
    box-shadow: 0 18px 50px rgba(0, 0, 0, 0.6);
    animation: dr-pop 0.18s ease-out;
}
.des-reveal .dr-row { display: flex; gap: 8px; }
.des-reveal .dr-def { opacity: 0.85; }
.des-reveal .dr-txt {
    margin-top: 4px;
    font-weight: 800;
    font-size: 15px;
    color: var(--ink-100, #f3e9d6);
    text-align: center;
}
@keyframes dr-pop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
</style>
