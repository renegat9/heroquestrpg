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
};

const icone = computed(() => ICONE_GENRE[props.scene.genre] ?? 'bolt');
const acteurs = computed(() => props.scene.acteurs ?? []);
const objets = computed(() => props.scene.objets ?? []);
/** Deux acteurs = un affrontement : on les met face à face, avec le « vs ». */
const duel = computed(() => acteurs.value.length === 2);
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
    min-width: 380px; max-width: min(620px, 74%);
}

.scn-titre {
    font-family: var(--font-display); font-size: 18px; font-weight: 700;
    color: var(--parch-100); letter-spacing: .02em;
    margin: 0; display: flex; align-items: center; justify-content: center; gap: 9px;
}
.scn-titre .msym { color: var(--torch); }
.scn-sous { font-size: 12.5px; color: var(--ink-500); margin: 4px 0 0; }

.scn-acteurs { display: flex; align-items: flex-start; justify-content: center; gap: 22px; margin: 16px 0 4px; position: relative; }
.scn-acteur { margin: 0; }
.scn-acteur img {
    width: 132px; height: 132px; object-fit: cover; display: block;
    border-radius: 12px; border: var(--line-strong); background: var(--stone-950);
}
.scn-acteur figcaption { display: flex; flex-direction: column; gap: 1px; margin-top: 7px; }
.scn-nom { font-size: 13.5px; font-weight: 700; color: var(--ink-100); }
.scn-pv { font-size: 11.5px; color: var(--ink-500); font-variant-numeric: tabular-nums; }
.scn-detail { font-size: 11px; color: var(--ink-500); }

.scn-vs {
    position: absolute; top: 52px; left: 50%; transform: translateX(-50%);
    font-family: var(--font-display); font-size: 15px; color: var(--ember);
    background: var(--stone-900); border: var(--line); border-radius: 999px;
    padding: 2px 9px; letter-spacing: .04em;
}

.scn-objets { display: flex; justify-content: center; gap: 16px; margin: 14px 0 2px; flex-wrap: wrap; }
.scn-objet { margin: 0; }
.scn-objet img {
    width: 88px; height: 88px; object-fit: cover; display: block;
    border-radius: 10px; border: var(--line-strong); background: var(--stone-950);
}
.scn-objet figcaption { display: flex; flex-direction: column; gap: 1px; margin-top: 6px; max-width: 132px; }

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
