<script setup>
// PLAN DU HALL (guide de l'arène, 2026-11-12) — la carte FIXE du tournoi, peinte case par case à partir de `arene.hall`.
//
// ⚠ Rien n'est décidé ici. Les cases de sol et de roche, les tuiles de départ, les trophées, les coffres et les gravats
// viennent du serveur (`GuideController::arene()`, qui lit `HallArene` et `TropheesArene`). Le composant ne fait que peindre
// ce qu'il reçoit.
//
// Pourquoi pas `DungeonGrid` : ce socle attend des portes, du mobilier avec ses drapeaux de passage (`bloque_mouvement`) et des
// marqueurs de pièges. Rien de cela n'est publié pour le hall, et le deviner ici serait une règle de plus côté client. Un rendu
// en cases suffit à un plan qui n'a que cinq sortes de cases.
import { computed } from 'vue';
import MSym from '../ui/MSym.vue';

const props = defineProps({
    // `arene.hall` : {largeur, hauteur, cases, trophees, coffres, gravats, depart_challenger, depart_defender}.
    hall: { type: Object, required: true },
});

const cle = (x, y) => `${x},${y}`;
const ensemble = (liste) => new Set((liste ?? []).map((c) => cle(c.x, c.y)));

const trophees = computed(() => ensemble(props.hall.trophees));
const coffres = computed(() => ensemble(props.hall.coffres));
const gravats = computed(() => ensemble(props.hall.gravats));
const departsChallenger = computed(() => ensemble(props.hall.depart_challenger));
const departsDefender = computed(() => ensemble(props.hall.depart_defender));

// Une case du plan : sa famille (sol, roche, gravats, départ) et, s'il y en a un, son marqueur.
function decrire(x, y) {
    const k = cle(x, y);
    let classe = props.hall.cases[y]?.[x] === 's' ? 'sol' : 'roc';
    let titre = classe === 'sol' ? 'Sol' : 'Roche';
    let icone = null;
    let teinte = null;

    if (gravats.value.has(k)) {
        classe = 'gravats';
        titre = 'Gravats — roche : bloque le passage et la vue';
    } else if (departsChallenger.value.has(k)) {
        classe = 'depart-ch';
        titre = 'Tuile de départ du Challenger';
    } else if (departsDefender.value.has(k)) {
        classe = 'depart-df';
        titre = 'Tuile de départ du Defender';
    }
    if (trophees.value.has(k)) {
        icone = 'emoji_events';
        teinte = 'tropee';
        titre = 'Tuile trophée, face cachée — finir son activation dessus pour la ramasser';
    } else if (coffres.value.has(k)) {
        icone = 'inventory_2';
        teinte = 'coffre';
        titre = 'Coffre du tournoi — fouille au contact ou en diagonale';
    }
    return { k, classe, icone, teinte, titre };
}

// Une seule boucle, rangée par rangée, pour toute la carte.
const cases = computed(() => {
    const out = [];
    for (let y = 0; y < props.hall.hauteur; y++) {
        for (let x = 0; x < props.hall.largeur; x++) {
            out.push(decrire(x, y));
        }
    }
    return out;
});

// La légende compte ce que le plan porte : le même décompte que le texte, pris dans le même bloc.
const legende = computed(() => [
    { cle: 'trophee', classe: 'sol', icone: 'emoji_events', n: props.hall.trophees.length, texte: 'Tuile trophée, face cachée : elle se révèle en finissant son activation dessus' },
    { cle: 'coffre', classe: 'sol', icone: 'inventory_2', n: props.hall.coffres.length, texte: 'Coffre du tournoi : fouille au contact ou en diagonale' },
    { cle: 'gravats', classe: 'gravats', icone: null, n: props.hall.gravats.length, texte: 'Gravats : roche, ni franchissable ni transparente' },
    { cle: 'depart-ch', classe: 'depart-ch', icone: null, n: props.hall.depart_challenger.length, texte: 'Tuile de départ du Challenger (héros et alliés), à l’ouest' },
    { cle: 'depart-df', classe: 'depart-df', icone: null, n: props.hall.depart_defender.length, texte: 'Tuile de départ du Defender (figures), à l’est' },
]);
</script>

<template>
    <div class="ga-plan-zone">
        <div
            class="ga-plan"
            role="img"
            aria-label="Plan du hall de l'arène : salle centrale, deux tuiles de départ, trophées, coffres et gravats"
            :style="{ '--ga-w': hall.largeur }"
        >
            <div
                v-for="c in cases"
                :key="c.k"
                class="ga-case"
                :class="c.classe"
                :title="c.titre"
            >
                <MSym v-if="c.icone" :n="c.icone" fill class="ga-case-icone" :class="'ga-case-' + c.teinte" />
            </div>
        </div>

        <ul class="ga-legende">
            <li v-for="l in legende" :key="l.cle" class="ga-legende-ligne">
                <span class="ga-case ga-legende-case" :class="l.classe">
                    <MSym v-if="l.icone" :n="l.icone" fill class="ga-case-icone ga-legende-icone" />
                </span>
                <span class="ga-legende-texte">{{ l.texte }} <em>({{ l.n }})</em></span>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.ga-plan-zone { width: 100%; min-width: 0; }

/* La grille tient dans la largeur disponible : des cases carrées (aspect-ratio), et des colonnes en fraction, jamais en
   pixels, sinon le plan déborderait sur un téléphone de 412 px. */
.ga-plan { display: grid; gap: 2px; padding: 6px; border-radius: 10px; box-sizing: border-box; width: 100%;
  background: var(--stone-950); border: var(--line);
  grid-template-columns: repeat(var(--ga-w), minmax(0, 1fr)); }

/* Chaque case est un conteneur : son marqueur se dimensionne sur SA largeur (cqw), jamais sur la police héritée. */
.ga-case { position: relative; aspect-ratio: 1 / 1; min-width: 0; border-radius: 3px; display: grid; place-items: center;
  container-type: inline-size; }
.ga-case.roc { background: linear-gradient(150deg, oklch(0.125 0.011 255), oklch(0.108 0.010 255)); }
.ga-case.sol { background: linear-gradient(150deg, oklch(0.30 0.015 255), oklch(0.26 0.014 255));
  box-shadow: inset 0 0 0 1px oklch(0.40 0.016 255 / 0.40); }
/* Les gravats : de la roche, rayée en diagonale pour se distinguer du mur plein (la teinte seule se lit mal en gris). */
.ga-case.gravats { background: repeating-linear-gradient(135deg, oklch(0.36 0.02 60) 0 3px, oklch(0.19 0.012 60) 3px 6px); }
/* Les tuiles de départ reprennent les familles de la carte : héros en ardoise, monstres en rouge. */
.ga-case.depart-ch { background: linear-gradient(150deg, oklch(0.43 0.06 250), oklch(0.34 0.05 250)); }
.ga-case.depart-df { background: linear-gradient(150deg, oklch(0.40 0.13 25), oklch(0.31 0.10 25)); }

.ga-case-icone { font-size: 56cqw; line-height: 1; }
.ga-case-tropee { color: var(--gold, #d8a23a); }
.ga-case-coffre { color: var(--torch, #e0a060); }

.ga-legende { list-style: none; margin: 12px 0 0; padding: 0; display: grid; gap: 7px; }
.ga-legende-ligne { display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--ink-300, #cfc3ad); line-height: 1.4; }
.ga-legende-case { width: 22px; height: 22px; flex: none; aspect-ratio: auto; }
.ga-legende-icone { font-size: 13px; }
.ga-legende-texte em { font-style: normal; color: var(--ink-500, #8a7f6a); font-weight: 700; }
</style>
