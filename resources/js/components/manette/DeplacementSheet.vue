<script setup>
// Feuille de DÉPLACEMENT (manette) : montre l'allonce du tour (dé déjà lancé
// côté serveur : base + 1d6) et une mini-carte tappable. Le TERRAIN (cases /
// portes / pièges) est rendu par le socle PARTAGÉ DungeonGrid — le MÊME que
// l'écran table — pour un rendu identique ; cette feuille n'ajoute que la
// surbrillance des cases accessibles et le tap de destination.
//
// ⚠ Les cases accessibles ne sont PLUS calculées ici (René, 2026-10-10 : « ça
// serait plus efficace ainsi »). Cette feuille refaisait en JS un parcours pondéré
// — mobilier, bloc tombé, alliés traversés, monstres franchis, terrain, embrasures,
// roche — et a dérivé six fois en un mois (verdict Jungle : un bloc tombé enjambé à
// l'écran, « 6 points annoncés, 8 payés »). Le serveur publie la DÉCISION :
// `option.parametres.destinations` = [{x, y, cout}], calculée avec le code même de la
// résolution, et qui EST la liste blanche que le résolveur re-valide. Ici : on
// éclaire exactement cette liste, rien d'autre. Aucun parcours, aucun miroir.
import { computed, nextTick, onMounted, ref } from 'vue';
import { useApi } from '../../composables/useApi';
import DungeonGrid from '../carte/DungeonGrid.vue';
import LegendeCarte from '../carte/LegendeCarte.vue';
import MSym from '../ui/MSym.vue';

const props = defineProps({
    carte: { type: Object, required: true },   // { largeur, hauteur, cases, portes }
    entites: { type: Array, default: () => [] }, // [{type, id, x, y, ...}]
    depart: { type: Object, required: true },    // { x, y } du héros
    // Non requis dès que `casesEcart` est fourni (voir plus bas) : ce mode-là
    // n'a pas d'allonce à annoncer, juste au plus deux cases déjà décidées.
    portee: { type: Number, default: 0 },
    de: { type: [Number, null], default: null },
    base: { type: Number, default: 0 },
    // Le d6 de déplacement compte-t-il ce tour ? DÉCISION serveur
    // (`Equipement::deDeplacementAnnule()`/`sourceDeDeplacementAnnule()`,
    // contrat §« L'Armure de plates FAIT PERDRE LE DÉ », 2026-09-24).
    // `deAnnule` vaut `false` pour un Chevalier ou une armure Allégée : ce
    // composant ne recalcule aucune des deux exemptions, il lit seulement ce
    // que le serveur a décidé.
    deAnnule: { type: Boolean, default: false },
    deAnnulePar: { type: [String, null], default: null },
    // UNTHREATENED MOVEMENT (FL-Q p. 7, First Light, 2026-09-30) : DÉCISION
    // serveur (`Quete::monstreActifRevele()`, `Deplacement::calculer()`) —
    // sans monstre actif révélé sur le plateau, le dé COMPTE 4 au lieu
    // d'être lancé. Ce composant ne recalcule rien, il affiche ce que le
    // serveur a déjà décidé — même règle que `deAnnule` juste au-dessus.
    sansMenace: { type: Boolean, default: false },
    /**
     * LES CASES ATTEIGNABLES, DÉJÀ DÉCIDÉES par le serveur (`option.parametres.destinations`
     * de `se_deplacer`) — [{x, y, cout}], `cout` en POINTS. C'est la liste blanche : la
     * manette éclaire ces cases-là et aucune autre (terrain pondéré, bloc tombé, alliés,
     * monstres, roche d'un héros intangible, reliquat… tout y est déjà). Ne jamais
     * la compléter ni la filtrer ici.
     */
    destinations: { type: Array, default: () => [] },
    /** Code du groupe — sert UNIQUEMENT à demander l'aperçu de trajet au
     *  serveur (`POST deplacement/apercu`). */
    groupe: { type: String, default: '' },
    /**
     * CHUTE DE BLOCS (livret p. 14, 2026-09-24) : liste blanche DÉJÀ DÉCIDÉE
     * par le serveur, `option.parametres.cases` de `s_ecarter_du_bloc` —
     * [{x, y, sens: 'avancer'|'reculer'}], au plus deux entrées. `null` =
     * mode déplacement ORDINAIRE (allonce + `destinations` publiées).
     *
     * ⚠ Non `null` change TROIS choses, jamais plus : les cases proposées
     * (`accessibles` lit cette liste plutôt que `destinations`), l'en-tête (pas de dé à
     * annoncer, il n'y en a pas eu) et le bouton de confirmation ne demande
     * plus d'aperçu au serveur — un pas unique déjà validé n'a pas de trajet
     * à prévisualiser. Tout le reste (la mini-carte, le tap, le second tap
     * pour confirmer) est RÉUTILISÉ tel quel : c'est tout l'intérêt de ne pas
     * avoir créé une seconde feuille pour un même geste (« toucher une case
     * éclairée, confirmer »).
     */
    casesEcart: { type: Array, default: null },
});
const emit = defineEmits(['deplacer', 'close']);

const grilleRef = ref(null);
const cle = (x, y) => `${x},${y}`;

// CASES ÉCLAIRÉES = exactement la liste publiée par le serveur. `casesEcart`
// (chute de blocs) est l'autre liste blanche déjà décidée, de même nature :
// au plus deux cases `{x, y, sens}`. Aucune des deux n'est recalculée ici.
const accessibles = computed(() => {
    if (props.casesEcart) {
        return new Set(props.casesEcart.map((c) => cle(c.x, c.y)));
    }

    return new Set(props.destinations.map((d) => cle(d.x, d.y)));
});

/** Rendu d'une figure présente sur une case : 'monstre' (icône dédiée) sauf
 *  pour un allié — hérité, mercenaire, ou monstre enrôlé par la Baguette d'Os
 *  (`controle_par`), qui n'est plus un ennemi ce tour-ci et se peint comme sur
 *  la table. Un monstre que ce héros peut franchir (mobilité de combat) reste un
 *  MONSTRE à l'écran : seule sa capacité à bloquer a changé, et le serveur l'a déjà
 *  tranchée dans `destinations`. */
function silhouetteDe(x, y) {
    const ent = occupantDe(x, y);
    return (ent?.type === 'monstre' && ! ent?.controle_par) ? 'monstre' : 'allie';
}

// Surcouche par case (au-dessus du terrain rendu par DungeonGrid) : départ,
// occupant (monstre/allié) ou case accessible ; null = terrain nu.
// Les occupants ne servent qu'au DESSIN : qui bloque, qui se traverse, se décide
// côté serveur et se lit dans `destinations` (une case occupée n'y figure jamais).
function surcouche(x, y) {
    if (x === props.depart.x && y === props.depart.y) return 'depart';
    if (occupantDe(x, y)) return silhouetteDe(x, y);
    // Trajet prévu : la case VISÉE d'abord (elle est aussi dans le chemin), puis
    // les cases traversées — elles restent accessibles, on ne fait que dire
    // « le héros passera par là ».
    if (viseeSur(x, y)) return 'visee';
    if (casesTrajet.value.has(cle(x, y))) return 'trajet';
    return accessibles.value.has(cle(x, y)) ? 'accessible' : null;
}

/** L'entité debout sur cette case, s'il y en a une. */
function occupantDe(x, y) {
    return props.entites.find((e) => e.x === x && e.y === y
        && ! (e.type === 'heros' && e.tombe)
        && ! (e.type === 'monstre' && ((e.etat ?? 'actif') !== 'actif' || (e.pv_body ?? 1) <= 0)));
}

/** TROIS PREMIÈRES LETTRES du compagnon (René, 2026-09-11 : « peux-tu mettre
 *  les 3 premières lettres des joueurs pour les identifier » — une initiale
 *  seule ne se rattachait pas assez vite à un nom autour de la table).
 *
 *  ⚠ Pas de portrait : à 38 px une illustration devient une tache. C'est la
 *  leçon des images de carte, qui vivent dans la LÉGENDE et jamais sur la
 *  grille, parce que la silhouette est ce qu'on lit d'un coup d'œil.
 *
 *  ⚠ Trois lettres ne tiennent pas dans un disque : la pastille est un
 *  RECTANGLE arrondi, et la graisse compense la petite taille. */
function initialeDe(x, y) {
    const nom = (occupantDe(x, y)?.nom ?? '').trim();
    return nom ? nom.slice(0, 3).toUpperCase() : '';
}

/** ⚠ Deux héros peuvent porter le MÊME nom (constaté en partie : deux
 *  « Thrakor »), donc l'initiale seule ne suffit pas à les distinguer. La
 *  teinte se dérive de l'`id`, stable d'un tour à l'autre — deux compagnons
 *  homonymes restent alors deux figurines différentes à l'œil. */
function teinteDe(x, y) {
    const id = occupantDe(x, y)?.id;
    return id == null ? null : { '--dep-allie-h': `${(Number(id) * 47) % 360}` };
}

// APERÇU DU TRAJET (René, 2026-09-17 : « que la figure utilise le vrai
// chemin »). Un tap ne part plus tout droit : il demande au SERVEUR la route
// exacte, la dessine, et c'est le second tap (ou le bouton) qui l'engage.
//
// ⚠ Le chemin vient du résolveur, il n'est PAS refait ici. Ce composant sait
// déjà calculer des cases atteignables — et c'est précisément le piège : deux
// routes de même coût n'exposent pas aux mêmes pièges (`controlerChemin()`
// contrôle CASE PAR CASE), donc un chemin re-dérivé en JS pourrait annoncer un
// trajet que le héros ne prendra pas. Le serveur publie la DÉCISION.
const api = useApi();
const apercu = ref(null);        // { x, y, chemin, cout, restant_apres, pieges, atteignable, raison, indisponible }
const apercuEnCours = ref(false);

/** Cases du trajet prévu, pour la surcouche (O(1) par case). */
const casesTrajet = computed(() => {
    const s = new Set();
    for (const c of apercu.value?.chemin ?? []) s.add(cle(c.x, c.y));
    return s;
});

// ⚠ L'état arrive en VOCABULAIRE MOTEUR (`detecte`/`desarme`/`declenche`) : le
// dire tel quel à l'écran (« Fosse (detecte) ») met un identifiant de code sous
// les yeux du joueur. Même table que celle de la légende, en plus court — une
// ligne d'aperçu n'a pas la place d'une phrase.
const ETATS_PIEGE = { detecte: 'détecté', fosse_ouverte: 'fosse ouverte', desarme: 'désamorcé', declenche: 'déjà déclenché' };
const trajetPieges = computed(() => (apercu.value?.pieges ?? []).map((p) => ({
    ...p,
    libelle: ETATS_PIEGE[p.etat] ?? p.etat,
})));

function viseeSur(x, y) {
    return apercu.value !== null && apercu.value.x === x && apercu.value.y === y;
}

async function toucher(x, y) {
    if (! accessibles.value.has(cle(x, y))) return;

    // Second tap sur la MÊME case : c'est la confirmation.
    if (viseeSur(x, y)) {
        emit('deplacer', { x, y });
        return;
    }

    // CHUTE DE BLOCS : un pas, déjà validé par le serveur
    // (`option.parametres.cases` EST la liste blanche que le résolveur
    // revalide) — aucun trajet à demander, ce n'est pas un déplacement BFS.
    if (props.casesEcart) {
        apercu.value = { x, y, chemin: [{ x, y }], cout: 1, restant_apres: 0, pieges: [], atteignable: true };
        return;
    }

    apercu.value = { x, y, chemin: [], pieges: [], atteignable: true };
    apercuEnCours.value = true;

    try {
        const rep = await api.apercuDeplacement(props.groupe, x, y);
        // Une réponse tardive ne doit pas écraser un tap plus récent.
        if (viseeSur(x, y)) apercu.value = { x, y, ...rep };
    } catch {
        // ⚠ L'aperçu ne doit JAMAIS empêcher de jouer : réseau coupé, serveur
        // qui répond 422, peu importe — on garde la visée et le second tap
        // part comme avant. Le moteur revalide de toute façon.
        if (viseeSur(x, y)) apercu.value = { x, y, chemin: [], pieges: [], atteignable: true, indisponible: true };
    } finally {
        apercuEnCours.value = false;
    }
}

/** Bouton « Y aller » : même chemin que le second tap. */
function confirmer() {
    if (apercu.value) emit('deplacer', { x: apercu.value.x, y: apercu.value.y });
}

// ZOOM (René, 2026-08-28). Les cases étaient à 22 px : sous la cible tactile
// recommandée, on visait la voisine — et les marqueurs (piège, épreuve, meuble)
// s'y réduisaient à une tache. 38 px rend le tap fiable ET les symboles lisibles,
// au prix d'une carte qui ne tient plus à l'écran : d'où la croix de direction
// ci-dessous, qui est la contrepartie du zoom et non un ajout séparé.
const CASE = 38;
const ECART = 2;
// Un appui = trois cases. Une seule serait fastidieuse sur un donjon de 40 de
// large ; un écran entier ferait perdre le fil du chemin qu'on suit des yeux.
const PAS = 3 * (CASE + ECART);

const gridStyle = computed(() => ({
    gap: `${ECART}px`,
    width: 'max-content',
    gridTemplateColumns: `repeat(${props.carte.largeur}, ${CASE}px)`,
    gridTemplateRows: `repeat(${props.carte.hauteur}, ${CASE}px)`,
    // Les glyphes des marqueurs se dimensionnent là-dessus : sans cette
    // variable, agrandir la case laissait les symboles à ~10 px (voir
    // DungeonGrid.vue).
    '--dg-icone': `${Math.round(CASE * 0.46)}px`,
    // Taille RÉELLE de la case en px (ici une constante, la manette ne zoome
    // pas) — même rôle que `--dg-icone` juste au-dessus, pour le CONTOUR de
    // salle cette fois (DungeonGrid.vue, `.dg-room-outline`) : un trait
    // calculé en `%` se serait résolu sur la police héritée, en `vw` sur la
    // largeur d'écran — jamais sur la case, dans les deux cas.
}));

// Bornes de défilement, relues à chaque scroll : une flèche qui ne peut plus
// rien faire est GRISÉE plutôt que morte au toucher — sinon le joueur appuie
// trois fois en croyant que la carte est figée.
const bornes = ref({ gauche: false, droite: false, haut: false, bas: false });

function mesurer() {
    const el = grilleRef.value;
    if (! el) { return; }

    // Marge d'un pixel : les navigateurs rendent parfois un scrollLeft
    // fractionnaire, et une comparaison stricte laissait une flèche active à
    // l'arrivée en butée.
    bornes.value = {
        gauche: el.scrollLeft > 1,
        droite: el.scrollLeft < el.scrollWidth - el.clientWidth - 1,
        haut: el.scrollTop > 1,
        bas: el.scrollTop < el.scrollHeight - el.clientHeight - 1,
    };
}

// Rien à faire défiler = pas de croix. Sur une petite carte entièrement visible,
// quatre flèches grisées ne seraient que du décor recouvrant des cases jouables ;
// et le bouton de recentrage n'a rien à recentrer.
const padUtile = computed(() => Object.values(bornes.value).some(Boolean));

function deplacerVue(dx, dy) {
    grilleRef.value?.scrollBy({ left: dx * PAS, top: dy * PAS, behavior: 'smooth' });
}

function centrerSurHeros() {
    // ⚠ `scrollIntoView` sur la case de départ, et non un calcul de coordonnées :
    // c'est le DOM qui connaît la taille réelle du cadre, laquelle dépend du
    // clavier, de la barre d'adresse et de l'orientation.
    grilleRef.value?.querySelector('.dg-cell.depart')
        ?.scrollIntoView({ block: 'center', inline: 'center', behavior: 'smooth' });
}

onMounted(async () => {
    grilleRef.value?.querySelector('.dg-cell.depart')
        ?.scrollIntoView({ block: 'center', inline: 'center', behavior: 'instant' });
    await nextTick();
    mesurer();
});
</script>

<template>
    <div class="dep-ov" @click.self="$emit('close')">
        <div class="dep-sheet">
            <header class="dep-head">
                <!-- CHUTE DE BLOCS : pas de dé à annoncer, il n'y en a pas eu
                     ici — l'allonce du tour normal n'a pas sa place dans cette
                     feuille-là (voir `casesEcart`). -->
                <div class="dep-roll" v-if="casesEcart">
                    <MSym n="square" fill />
                    <span class="dep-portee-lbl">S'écarter du bloc de pierre</span>
                </div>
                <div class="dep-roll" v-else>
                    <MSym n="casino" fill />
                    <span class="dep-portee">{{ portee }}</span>
                    <span class="dep-portee-lbl">cases</span>
                </div>
                <div class="dep-detail" v-if="de != null">
                    {{ base }}
                    <span v-if="!deAnnule">+ dé {{ de }}<template v-if="sansMenace"> (sans menace)</template></span>
                    <!-- Dé ANNULÉ (Armure de plates) : montré, rayé, jamais
                         caché — le joueur voit ce qu'il aurait eu. Discret :
                         une INFORMATION, pas une alerte (même ton que le
                         reste de la feuille). -->
                    <span v-else class="dep-de-annule">
                        <span class="dep-de-barre">dé {{ de }}</span><b class="dep-de-x" aria-hidden="true">✕</b>
                    </span>
                </div>
                <LegendeCarte class="dep-legende" :carte="carte" />
                <button class="dep-close" type="button" @click="$emit('close')"><MSym n="close" /></button>
            </header>

            <!-- ⚠ La note vit HORS de la rangée d'en-tête (René, 2026-09-24).
                 Glissée dans `.dep-detail`, elle l'élargissait jusqu'à pousser
                 le calcul AU-DESSUS du chiffre et se coller sur « cases », son
                 icône posée par-dessus le libellé : quatre éléments pour une
                 rangée de 412 px. La rangée garde le calcul (« 4 dé ~~2~~ ✕ »),
                 la RAISON prend sa propre ligne, au rang de l'indication qui
                 suit. -->
            <p v-if="deAnnule" class="dep-de-note">
                <MSym n="shield" :size="14" /> {{ deAnnulePar }} — le dé ne compte pas
            </p>
            <!-- UNTHREATENED MOVEMENT (FL-Q p. 7) : même emplacement que la
                 note ci-dessus, pour la même raison — un effet automatique
                 que rien n'annonce est injouable. Les deux ne cohabitent
                 jamais (un dé annulé n'a plus de valeur à commenter). -->
            <p v-else-if="sansMenace" class="dep-de-note">
                <MSym n="casino" :size="14" /> Aucun monstre actif — le dé compte 4 au lieu d'être lancé
            </p>

            <!-- CHUTE DE BLOCS — avertissement du livret p. 14 : « the hero
                 then decides to move ahead or move back ». Une décision
                 assumée, pas empêchée : la manette PRÉVIENT, elle ne retire
                 pas l'option d'avancer. -->
            <p
                v-if="casesEcart && casesEcart.some((c) => c.sens === 'avancer') && !apercu"
                class="dep-hint dep-hint-piege"
            >
                <MSym n="warning" :size="14" /> Avancer peut t'isoler du reste du groupe — reculer te ramène à ta case de départ.
            </p>

            <p v-if="accessibles.size && ! apercu" class="dep-hint">
                <MSym n="touch_app" :size="14" />
                {{ casesEcart ? 'Touche une case éclairée pour t\'écarter' : 'Touche une case éclairée pour voir le trajet' }}
            </p>

            <!-- APERÇU : le trajet EXACT rendu par le serveur, à confirmer. Les
                 pièges annoncés sont ceux que la carte montre DÉJÀ (détectés,
                 désamorcés, déclenchés) — l'aperçu ne révèle rien. -->
            <div v-else-if="apercu" class="dep-apercu">
                <p class="dep-hint">
                    <MSym n="route" :size="14" />
                    <span v-if="casesEcart">Confirme pour t'écarter là</span>
                    <span v-else-if="apercuEnCours">Calcul du trajet…</span>
                    <span v-else-if="apercu.indisponible">Trajet indisponible — touche encore pour y aller quand même</span>
                    <span v-else-if="apercu.atteignable === false">{{ apercu.raison }}</span>
                    <span v-else>{{ apercu.cout }} point{{ apercu.cout > 1 ? 's' : '' }} — il en restera {{ apercu.restant_apres }}</span>
                </p>
                <p v-for="p in trajetPieges" :key="`${p.x}-${p.y}`" class="dep-hint dep-hint-piege">
                    <MSym n="warning" :size="14" /> Le trajet passe sur : {{ p.nom }} ({{ p.libelle }})
                </p>
                <!-- TRAVERSER LA PIERRE : décision de l'aperçu (`traverse_roche`), annoncée
                     AVANT le second tap. Le moteur tient exactement ceci : l'intangible vit
                     pendant le déplacement ; une fois celui-ci fini, plus de roche ce tour. -->
                <p v-if="apercu.traverse_roche && apercu.atteignable !== false" class="dep-hint dep-hint-piege">
                    <MSym n="warning" :size="14" /> Ce trajet traverse la roche : une fois ton déplacement fini, tu ne pourras plus y revenir. Et tu tombes si ton tour finit dans la roche.
                </p>
                <button
                    v-if="apercu.atteignable !== false"
                    class="dep-aller"
                    type="button"
                    @click="confirmer"
                ><MSym n="directions_walk" :size="16" fill /> {{ casesEcart ? "S'écarter" : 'Y aller' }}</button>
            </div>
            <p v-else class="dep-hint dep-hint-bloque"><MSym n="block" :size="14" /> Aucune case accessible — tu es bloqué. Ferme et termine ton tour.</p>

            <div class="dep-carte">
                <div ref="grilleRef" class="dep-scroll" @scroll.passive="mesurer">
                <DungeonGrid :carte="carte" :traps="carte.pieges ?? []" :furniture="carte.mobilier ?? []" :trials="carte.epreuves ?? []" :levers="carte.leviers ?? []" :terrain="carte.terrain ?? []" :ice="carte.glace ?? []" :shadow="carte.ombre ?? []" :stairs="carte.escalier ?? null" :cell-class="surcouche" :grid-style="gridStyle" @cell="toucher">
                    <template #cell="{ x, y }">
                        <MSym v-if="surcouche(x, y) === 'depart'" n="person" :size="14" fill />
                        <MSym v-else-if="surcouche(x, y) === 'monstre'" n="pets" :size="13" fill />
                        <span
                            v-else-if="surcouche(x, y) === 'allie' && initialeDe(x, y)"
                            class="dep-initiale"
                            :style="teinteDe(x, y)"
                            :title="occupantDe(x, y)?.nom"
                        >{{ initialeDe(x, y) }}</span>
                    </template>
                    </DungeonGrid>
                </div>

                <!-- Croix de direction : le pendant du zoom. Le doigt sert à
                     CHOISIR une case ; le faire aussi servir à faire glisser la
                     carte rend les deux gestes ambigus (un glissement un peu
                     court se lit comme un tap, et on part se déplacer où on ne
                     voulait pas). Le défilement natif reste possible, ces
                     boutons ne font que le rendre explicite.
                     Posée en surimpression d'un COIN : hors de `.dep-scroll`,
                     qui défile — dedans, elle s'en irait avec le donjon. -->
                <div v-if="padUtile" class="dep-pad">
                    <button type="button" class="pad-h" :disabled="! bornes.haut" aria-label="Vers le haut" @click="deplacerVue(0, -1)"><MSym n="keyboard_arrow_up" /></button>
                    <button type="button" class="pad-g" :disabled="! bornes.gauche" aria-label="Vers la gauche" @click="deplacerVue(-1, 0)"><MSym n="keyboard_arrow_left" /></button>
                    <!-- Au centre : revenir à son héros. C'est le seul repère
                         qui ne se perd jamais, donc la sortie de secours quand
                         on s'est égaré à l'autre bout du donjon. -->
                    <button type="button" class="pad-c" aria-label="Centrer sur mon héros" @click="centrerSurHeros"><MSym n="my_location" fill /></button>
                    <button type="button" class="pad-d" :disabled="! bornes.droite" aria-label="Vers la droite" @click="deplacerVue(1, 0)"><MSym n="keyboard_arrow_right" /></button>
                    <button type="button" class="pad-b" :disabled="! bornes.bas" aria-label="Vers le bas" @click="deplacerVue(0, 1)"><MSym n="keyboard_arrow_down" /></button>
                </div>
            </div>

            <!-- Fermeture toujours atteignable au bas de la feuille. -->
            <button class="dep-fermer" type="button" @click="$emit('close')">
                <MSym n="close" :size="16" /> Fermer
            </button>
        </div>
    </div>
</template>

<style>
/* La légende vit dans l'EN-TÊTE, pas en surimpression de la grille : celle-ci
   défile (`.dep-scroll`), un bouton flottant posé dessus s'en irait avec elle.
   `flex: none` pour la même raison que la croix, juste à côté — l'en-tête se
   compresse sur un écran étroit et éjecterait le bouton hors du viewport. */
.dep-legende { flex: none; margin-left: auto; }

/* ⚠ ANCRAGE DU PANNEAU DE LÉGENDE. Il se positionne sur son bouton (`right: 0`
   de `.lg-wrap`), or ce bouton n'est PAS au bord : la croix de fermeture le
   suit. Sur 360 px, le panneau débordait donc de 44 px à GAUCHE de l'écran,
   texte coupé. En rendant l'en-tête positionné et le bouton statique, le
   panneau s'aligne sur le bord de l'EN-TÊTE — c'est-à-dire sur le bord de la
   feuille, qui est le seul repère juste. */
.dep-head { position: relative; }
.dep-head .dep-legende { position: static; }

/* ⚠ `minmax(0, 1fr)` : sans lui la piste de grille se dimensionne sur le
   CONTENU de la feuille, et celle-ci atteignait son `max-width` de 520 px sur un
   écran de 412 — mesuré. La carte étant large de plusieurs milliers de pixels,
   tout ce qui suit sortait de l'écran : la croix de fermeture de l'en-tête et la
   moitié droite de la croix de direction, donc inatteignables au doigt (c'est le
   défaut de la §2.2, ressorti par une autre porte). Le `overflow: auto` de
   `.dep-scroll` le masquait tant qu'il était l'enfant DIRECT de la feuille : un
   conteneur de défilement a une taille minimale nulle, pas un `div` ordinaire. */
.dep-ov { position: fixed; inset: 0; z-index: 70; display: grid; place-items: end center;
  grid-template-columns: minmax(0, 1fr);
  background: oklch(0.12 0.02 60 / 0.6); backdrop-filter: blur(3px); }
.dep-sheet { width: 100%; max-width: 520px; max-height: 82vh; display: flex; flex-direction: column;
  background: var(--stone-900); border-top-left-radius: 18px; border-top-right-radius: 18px;
  border: var(--line); border-bottom: none; padding: 14px 14px 20px; box-shadow: var(--sh-3); }

/* `min-width: 0` + `flex: none` sur la croix : sans ça, le contenu de l'en-tête
   (portée + détail du dé) refusait de se compresser sur un écran étroit et
   poussait le bouton de fermeture HORS du viewport — mesuré à x≈471 px sur un
   écran de 420 px, donc totalement inatteignable (verdict §2.2). */
.dep-head { display: flex; align-items: center; gap: 12px; min-width: 0; }
.dep-head > * { min-width: 0; }
.dep-roll { display: inline-flex; align-items: baseline; gap: 6px; color: var(--torch); font-weight: 800; }
.dep-roll .msym { font-size: 26px; align-self: center; }
.dep-portee { font-size: 26px; font-family: var(--font-display); }
.dep-portee-lbl { font-size: 12px; color: var(--ink-400); font-weight: 700; }
.dep-detail { font-size: 13px; color: var(--ink-400); font-weight: 700; display: flex; align-items: baseline; gap: 6px; flex-wrap: wrap; }
.dep-detail span { color: var(--ink-600); }
/* Dé de mouvement ANNULÉ par une armure lourde (contrat §« L'Armure de
   plates FAIT PERDRE LE DÉ ») : la face reste LISIBLE (barrée, pas cachée —
   le joueur doit voir ce qu'il aurait eu), le ✕ vient EN PLUS. Discret —
   c'est une information, pas une alerte. */
.dep-de-annule { display: inline-flex; align-items: baseline; gap: 3px; }
.dep-de-barre { text-decoration: line-through; text-decoration-thickness: 1.5px; }
.dep-de-x { color: var(--danger, #e66); font-weight: 800; }
/* Même gabarit que `.dep-hint` juste dessous : une ligne pleine, pas un
   élément de plus dans la rangée d'en-tête. `margin-bottom: 0` parce que
   l'indication qui suit porte déjà son propre espacement haut. */
.dep-de-note {
    display: flex; align-items: center; gap: 6px; margin: 8px 0 0;
    font-size: 12.5px; color: var(--ink-400); font-weight: 600;
}
.dep-de-note .msym { color: var(--danger, #e66); }
.dep-close { margin-left: auto; flex: none; display: grid; place-items: center; width: 34px; height: 34px;
  border-radius: 999px; border: var(--line); background: var(--stone-850); color: var(--ink-300); cursor: pointer; }

.dep-hint { font-size: 12.5px; color: var(--ink-400); display: flex; align-items: center; gap: 6px; margin: 8px 0 10px; }
.dep-hint .msym { color: var(--torch); }
.dep-hint-bloque { color: var(--danger, #e66); }
.dep-hint-bloque .msym { color: var(--danger, #e66); }

/* Aperçu du trajet : bloc COMPACT (la manette n'a qu'un écran de téléphone, et
   la carte doit rester la plus grande chose dessus) — une ligne de coût, une
   ligne par piège connu traversé, un bouton pleine largeur pour engager.
   ⚠ Classes préfixées `dep-` : les styles de SFC sont globaux ici. */
.dep-apercu { display: flex; flex-direction: column; gap: 2px; }
.dep-apercu .dep-hint { margin: 8px 0 6px; }
.dep-hint-piege { color: var(--torch, #d9a441); margin: 0 0 6px; }
.dep-hint-piege .msym { color: var(--torch, #d9a441); }
.dep-aller { margin: 2px 0 10px; padding: 10px; border-radius: 11px; border: var(--line);
  background: linear-gradient(150deg, var(--ember, #8c3b1b), var(--ember-deep, #5e2410));
  color: var(--parch-100, #f3e7cf); font-weight: 700; font-size: 14px; cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: 6px; }
.dep-fermer { margin-top: 12px; flex: none; width: 100%; padding: 11px; border-radius: 11px; border: var(--line);
  background: var(--stone-850); color: var(--ink-200, #e7dcc6); font-weight: 700; font-size: 14px; cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: 6px; }

/* Cadre de la carte : c'est LUI qui porte la croix de direction, pas la zone
   défilante — un bouton posé dans `.dep-scroll` s'en irait avec le donjon. */
.dep-carte { position: relative; flex: 1; min-height: 0; min-width: 0; display: flex; }

/* Croix de direction, en bas à droite. Compacte et translucide : elle recouvre
   quelques cases, et c'est le compromis assumé — la carte peut toujours être
   décalée pour dégager la case visée, alors que la placer SOUS la carte
   coûterait de la hauteur sur un écran déjà à 82 vh. */
.dep-pad { position: absolute; right: 10px; bottom: 10px; z-index: 5;
  display: grid; grid-template-columns: repeat(3, 34px); grid-template-rows: repeat(3, 34px);
  gap: 2px; padding: 4px; border-radius: 14px;
  background: oklch(0.16 0.012 255 / 0.82); border: var(--line); backdrop-filter: blur(6px);
  box-shadow: var(--sh-2); }
.dep-pad button { display: grid; place-items: center; border-radius: 9px; cursor: pointer;
  border: none; background: var(--stone-800); color: var(--ink-200, #e7dcc6);
  -webkit-tap-highlight-color: transparent; }
.dep-pad button:active { transform: scale(0.94); }
.dep-pad button:disabled { opacity: 0.28; pointer-events: none; }
.dep-pad .msym { font-size: 21px; }
.dep-pad .pad-h { grid-area: 1 / 2; }
.dep-pad .pad-g { grid-area: 2 / 1; }
.dep-pad .pad-c { grid-area: 2 / 2; background: var(--stone-850); color: var(--torch); }
.dep-pad .pad-d { grid-area: 2 / 3; }
.dep-pad .pad-b { grid-area: 3 / 2; }

/* `safe center` : la grille est CENTRÉE quand elle tient dans la vue, mais
   revient au bord quand elle DÉPASSE (scroll jusqu'à la salle la plus à droite). */
.dep-scroll { overflow: auto; flex: 1; min-width: 0; border-radius: var(--r-md); background: var(--stone-950); padding: 8px;
  display: flex; justify-content: safe center; align-items: safe center; }
/* Départ/occupants : centrer l'icône dans la case (DungeonGrid gère le reste). */
.dep-scroll .dg-cell { display: grid; place-items: center; }

/* Initiale d'un compagnon (René, 2026-09-11). Pleine case, fond teinté par
   `--dep-allie-h` (dérivé de l'id) pour séparer deux homonymes, et un contour
   sombre pour que la lettre tienne sur n'importe quelle teinte. */
.dep-initiale {
  display: grid; place-items: center; width: 100%; height: 100%;
  /* Rectangle arrondi, pas un disque : trois lettres dans un cercle de 38 px
     obligeraient à descendre sous 8 px pour tenir dans la corde. */
  border-radius: 6px;
  font-weight: 800; font-size: 11px; line-height: 1; letter-spacing: -0.04em;
  color: oklch(0.98 0 0);
  background: oklch(0.52 0.15 var(--dep-allie-h, 260));
  box-shadow: inset 0 0 0 1.5px oklch(0.16 0.012 255 / 0.85);
  text-shadow: 0 1px 2px oklch(0.16 0.012 255 / 0.9);
}
</style>
