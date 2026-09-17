<script setup>
/**
 * SCÈNE ILLUSTRÉE d'un événement, sur l'écran de table (`.table.scene`).
 *
 * C'est ici que les illustrations du catalogue — générées en 1024×1024, servies
 * jusqu'ici dans des vignettes de 40 px — sont enfin montrées à une échelle où
 * le travail se voit : les portraits de l'attaquant et du défendeur face à face,
 * la volée de dés réellement tombée, l'objet trouvé, le piège déclenché.
 *
 * ⚠ TOUT ARRIVE DÉCIDÉ. Titre écrit, issue nommée, URL d'images résolues côté
 * serveur (`App\Partie\SceneDeTable`) avec leur chaîne de repli jusqu'à
 * l'emblème SVG. Ce composant n'interprète rien, ne joint aucun identifiant et
 * ne connaît aucune règle de jeu — c'est la classe de défaut la plus répétée du
 * projet côté front, et elle ne revient pas par ici.
 *
 * ⚠ LA CARTE RESTE LISIBLE. Un popup a déjà été retiré de cet écran
 * (2026-09-05) : il couvrait le donjon une à deux minutes pendant que la partie
 * était jouable — « on est capable de jouer alors qu'il y a un popup ». La scène
 * se pose DANS la zone carte, centrée, sans la recouvrir entièrement, et
 * disparaît d'elle-même.
 *
 * ⚠ LA FERMETURE NE DÉPEND D'AUCUNE VOIX (décision de René, 2026-09-14) : un
 * clic n'importe où sur l'écran du narrateur, ou le délai réglé dans ses
 * paramètres (défaut 5 s). La carte d'ouverture de quête a passé des semaines
 * invisible parce que sa fermeture était accrochée à une fin de lecture qui,
 * sans son activé, survient dans le même tick.
 */
import { computed } from 'vue';
import JetDes from '../ui/JetDes.vue';
import MSym from '../ui/MSym.vue';

const props = defineProps({
    /** Payload `.table.scene` — voir docs/contrat-api.md. */
    scene: { type: Object, required: true },
});

defineEmits(['fermer']);

const ICONE_GENRE = {
    attaque: 'swords',
    piege: 'dangerous',
    fouille: 'search',
    jet: 'casino',
    sort: 'auto_awesome',
    salle: 'door_open',
    chute: 'heart_broken',
    objet: 'science',
    deplacement: 'directions_walk',
};

/* Les points d'un d6, dans une grille 3×3 lue ligne à ligne. Pure mise en
 * forme d'un NOMBRE publié par le serveur : aucune règle ici. */
const POINTS_D6 = {
    1: [5],
    2: [1, 9],
    3: [1, 5, 9],
    4: [1, 3, 7, 9],
    5: [1, 3, 5, 7, 9],
    6: [1, 3, 4, 6, 7, 9],
};

const icone = computed(() => ICONE_GENRE[props.scene.genre] ?? 'bolt');
const acteurs = computed(() => props.scene.acteurs ?? []);
const objets = computed(() => props.scene.objets ?? []);
/* ⚠ Le « vs » ne se déduit PAS du nombre d'acteurs : un sort de soin en a deux
 * lui aussi, et « Sylvaine vs Borin » raconterait le contraire de ce qui vient
 * de se passer. C'est le RÔLE publié par le serveur qui dit s'il y a
 * affrontement. */
const duel = computed(() => acteurs.value.some((a) => a.role === 'defenseur'));
</script>

<template>
    <div class="scn" role="dialog" aria-live="polite" @click="$emit('fermer')">
        <div class="scn-carte">
            <p class="scn-titre">
                <MSym :n="icone" fill :size="18" /> {{ scene.titre }}
            </p>
            <p v-if="scene.sous_titre" class="scn-sous">{{ scene.sous_titre }}</p>

            <div class="scn-acteurs" :class="{ duel }">
                <figure v-for="(a, i) in acteurs" :key="a.role + i" class="scn-acteur">
                    <img :src="a.image_url" :alt="a.nom" />
                    <figcaption>
                        <span class="scn-nom">{{ a.nom }}</span>
                        <span v-if="a.pv" class="scn-pv">{{ a.pv.courant }}/{{ a.pv.max }} PV</span>
                    </figcaption>
                </figure>
                <span v-if="duel" class="scn-vs" aria-hidden="true">vs</span>
            </div>

            <div v-if="objets.length" class="scn-objets">
                <figure v-for="(o, i) in objets" :key="o.nom + i" class="scn-objet">
                    <img :src="o.image_url" :alt="o.nom" />
                    <figcaption>
                        <span class="scn-nom">{{ o.nom }}</span>
                        <span v-if="o.detail" class="scn-detail">{{ o.detail }}</span>
                    </figcaption>
                </figure>
            </div>

            <JetDes v-if="scene.jet" :jet="scene.jet" />

            <!-- Début de tour : le dé ROUGE du plateau, et le calcul tel que le
                 serveur l'a écrit (base, dés, armure, bonus — rien n'est refait ici). -->
            <div v-if="scene.deplacement" class="scn-depl">
                <div class="scn-des" role="img" :aria-label="'Dés : ' + scene.deplacement.des.join(', ')">
                    <span v-for="(valeur, i) in scene.deplacement.des" :key="i" class="scn-d6">
                        <i v-for="point in POINTS_D6[valeur] ?? []" :key="point" :class="'scn-pt' + point" />
                    </span>
                </div>
                <p class="scn-calcul">{{ scene.deplacement.calcul }}</p>
            </div>

            <p class="scn-issue" :class="'t-' + scene.issue.ton">{{ scene.issue.libelle }}</p>
        </div>
    </div>
</template>

<style>
/* ⚠ Classes préfixées `scn-` : les blocs <style> des SFC de ce projet sont
   GLOBAUX, et une classe générique fuit d'une vue à l'autre. */
.scn {
    position: absolute; inset: 0; z-index: 28;
    display: grid; place-items: center;
    /* Le clic ferme, donc on capture — mais le fond reste transparent : la
       carte, les figurines et les PV restent lisibles autour. */
    cursor: pointer;
    animation: scn-entree .22s ease-out;
}
@keyframes scn-entree { from { opacity: 0; transform: translateY(10px) } to { opacity: 1; transform: none } }
@media (prefers-reduced-motion: reduce) { .scn { animation: none } }

.scn-carte {
    background: linear-gradient(180deg, var(--stone-850), var(--stone-900));
    border: var(--line-strong); border-radius: var(--r-xl, 18px);
    box-shadow: 0 18px 60px rgba(0, 0, 0, .55), var(--sh-3);
    padding: 20px 26px 18px; text-align: center;
    /* ⚠ LARGEUR FIXE, hauteur libre (René, 2026-09-14). Avant, la carte se
       dimensionnait sur son contenu : elle sautait d'une scène à l'autre — une
       chute étroite, une salle à six créatures deux fois plus large — et l'œil
       du narrateur devait la rechercher à chaque fois. Elle ne prend PAS toute
       la carte pour autant : la carte doit rester lisible autour, c'est
       l'arbitrage du 2026-09-05 qui avait fait retirer le popup précédent. */
    /* 600 px = quatre tuiles par rangée (4×118 + gaps + marges). Plus large,
       une scène à deux figures flottait dans le vide. */
    width: min(600px, 86%);
}

/* Une seule taille de tuile pour TOUTES les images de la carte — portraits,
   objets, créatures. Elles avaient 132 px d'un côté et 88 de l'autre, ce qui
   faisait lire une hiérarchie qui n'existe pas. */
.scn-carte { --scn-tuile: 118px; }

.scn-titre {
    font-family: var(--font-display); font-size: 18px; font-weight: 700;
    color: var(--parch-100); letter-spacing: .02em;
    margin: 0; display: flex; align-items: center; justify-content: center; gap: 9px;
    text-wrap: balance;
}
.scn-titre .msym { color: var(--torch); flex: none; }
.scn-sous { font-size: 12.5px; color: var(--ink-500); margin: 4px 0 0; }

.scn-acteurs, .scn-objets {
    display: flex; flex-wrap: wrap; align-items: flex-start;
    justify-content: center; gap: 18px 20px;
}
.scn-acteurs { margin: 16px 0 4px; position: relative; }
.scn-objets { margin: 16px 0 2px; }

.scn-acteur, .scn-objet { margin: 0; width: var(--scn-tuile); }
.scn-acteur img, .scn-objet img {
    width: var(--scn-tuile); height: var(--scn-tuile); object-fit: cover; display: block;
    border-radius: 12px; border: var(--line-strong); background: var(--stone-950);
}
/* ⚠ La légende ne dépasse JAMAIS la largeur de son image, et revient à la ligne
   plutôt que de pousser les tuiles voisines : « Chacal des Sables Éternels »
   débordait et décalait toute la rangée. */
.scn-acteur figcaption, .scn-objet figcaption {
    display: flex; flex-direction: column; gap: 2px; margin-top: 7px;
    width: var(--scn-tuile); overflow-wrap: anywhere; hyphens: auto;
}
.scn-nom { font-size: 12.5px; font-weight: 700; color: var(--ink-100); line-height: 1.25; }
.scn-pv { font-size: 11.5px; color: var(--ink-500); font-variant-numeric: tabular-nums; }
.scn-detail { font-size: 11px; color: var(--ink-400, #9aa4b2); line-height: 1.3;
    font-variant-numeric: tabular-nums; }

.scn-vs {
    position: absolute; top: calc(var(--scn-tuile) / 2 - 13px); left: 50%; transform: translateX(-50%);
    font-family: var(--font-display); font-size: 15px; color: var(--ember);
    background: var(--stone-900); border: var(--line); border-radius: 999px;
    padding: 2px 9px; letter-spacing: .04em;
}

/* ---- début de tour : le dé de déplacement ---- */
.scn-depl { margin: 14px 0 2px; display: flex; flex-direction: column; align-items: center; gap: 10px; }
.scn-des { display: flex; gap: 14px; }
.scn-d6 {
    width: 64px; height: 64px; padding: 10px; box-sizing: border-box;
    display: grid; grid-template-columns: repeat(3, 1fr); grid-template-rows: repeat(3, 1fr);
    border-radius: 13px;
    /* Rouge comme le dé de déplacement du plateau — pas l'or du thème. */
    background: linear-gradient(155deg, var(--body-bright), var(--body));
    box-shadow: inset 0 -3px 0 oklch(0 0 0 / .28), 0 6px 16px oklch(0 0 0 / .45);
    animation: scn-lancer .5s cubic-bezier(.2, .8, .25, 1.2);
}
.scn-d6 i {
    width: 11px; height: 11px; border-radius: 50%; place-self: center;
    background: var(--parch-100); box-shadow: inset 0 1px 1px oklch(0 0 0 / .35);
}
.scn-pt1 { grid-area: 1 / 1; } .scn-pt3 { grid-area: 1 / 3; }
.scn-pt4 { grid-area: 2 / 1; } .scn-pt5 { grid-area: 2 / 2; } .scn-pt6 { grid-area: 2 / 3; }
.scn-pt7 { grid-area: 3 / 1; } .scn-pt9 { grid-area: 3 / 3; }
@keyframes scn-lancer { from { transform: rotate(-120deg) scale(.4); opacity: 0 } to { transform: none; opacity: 1 } }
@media (prefers-reduced-motion: reduce) { .scn-d6 { animation: none } }
.scn-calcul {
    margin: 0; font-size: 14px; color: var(--ink-300);
    font-variant-numeric: tabular-nums; letter-spacing: .01em;
}

.scn-issue {
    font-family: var(--font-display); font-size: 16px; font-weight: 700;
    margin: 14px 0 0; letter-spacing: .02em;
}
.scn-issue.t-degats { color: var(--body-bright); }
.scn-issue.t-mort { color: var(--danger); }
.scn-issue.t-tresor { color: var(--gold); }
.scn-issue.t-echec { color: var(--ink-500); }
.scn-issue.t-info { color: var(--ink-300); }
</style>
