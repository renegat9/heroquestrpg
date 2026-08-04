<script setup>
// GRILLE DE DONJON PARTAGÉE — socle commun à la carte TABLE (narrateur) et à la
// mini-carte MANETTE (déplacement), pour qu'elles rendent le TERRAIN de façon
// IDENTIQUE : cases (mur / sol / brouillard), portes en ARÊTE (battant sur la
// cloison, jamais une case) et marqueurs de pièges. Chaque parent ajoute sa
// couche propre — figurines animées + caméra côté table ; surbrillance des
// cases accessibles + tap côté manette — via les slots/props ci-dessous.
//
// La porte étant une arête, son battant est dimensionné en POURCENTAGE de la
// case : même rendu quelle que soit la taille de case (grande table, petit
// 22px manette). C'était LA source de divergence (deux CSS de portes séparées).
import { computed } from 'vue';
import MSym from '../ui/MSym.vue';

const props = defineProps({
    /** Carte du contrat : { largeur, hauteur, cases: [[..]], portes: [{x,y,cote,etat,verrou?}] }. */
    carte: { type: Object, required: true },
    /** Marqueurs de pièges connus : [{x, y, etat, nom?, titre?}] — voir piegesVersMarqueurs(). */
    traps: { type: Array, default: () => [] },
    /** Mobilier des salles découvertes : [{x, y, l, h, nom, bloquant, ic?, titre?}]
     *  — voir mobilierVersDecor() (doc 17). */
    furniture: { type: Array, default: () => [] },
    /** (x, y) → classe(s) CSS supplémentaire(s) par case (accessible / depart /
     *  occupant… — surcouche manette). Null = aucune (table). */
    cellClass: { type: Function, default: null },
    /** Style de la grille (le parent décide la taille : caméra 1fr côté table,
     *  22px + gap côté manette). Fusionné au `display:grid` du socle. */
    gridStyle: { type: Object, default: () => ({}) },
    /** Anime le déplacement des enfants (FLIP sur les figurines) — table. */
    animate: { type: Boolean, default: false },
});
const emit = defineEmits(['cell']);

const TUILES = { m: 'wall', s: 'floor', b: 'fog' };

const cells = computed(() => {
    const out = [];
    const w = props.carte.largeur ?? 0;
    const h = props.carte.hauteur ?? 0;
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            out.push({ x, y, t: TUILES[props.carte.cases?.[y]?.[x]] ?? 'void' });
        }
    }
    return out;
});

const PORTE_ETATS = { ouverte: 'ouverte', fermee: 'fermée', verrouillee: 'verrouillée', secrete: 'secrète' };
const PORTE_VERROUS = { cle: 'clé requise', monstres_vaincus: 'gardien à vaincre', levier: 'levier à actionner' };

const doors = computed(() => (props.carte.portes ?? [])
    .filter((p) => PORTE_ETATS[p.etat])
    .map((p) => {
        const verrou = p.verrou ? (PORTE_VERROUS[p.verrou] ?? p.verrou) : null;
        return {
            x: p.x,
            y: p.y,
            cote: p.cote === 's' ? 's' : 'e', // arête EST ('e') ou SUD ('s')
            etat: p.etat,
            cadenas: p.etat === 'verrouillee',
            titre: `Porte ${PORTE_ETATS[p.etat]}${verrou ? ` — ${verrou}` : ''}`,
        };
    }));
</script>

<template>
    <TransitionGroup tag="div" name="dg-fig" :css="animate" class="dg" :style="gridStyle">
        <!-- cases : mur / sol / brouillard (+ surcouche parent via cellClass) -->
        <div
            v-for="c in cells"
            :key="`c-${c.x}-${c.y}`"
            class="dg-cell"
            :class="[c.t, cellClass ? cellClass(c.x, c.y) : null]"
            :style="{ gridColumn: c.x + 1, gridRow: c.y + 1 }"
            @click="emit('cell', c.x, c.y)"
        >
            <slot name="cell" :x="c.x" :y="c.y" />
        </div>

        <!-- pièges : marqueur au-dessus des cases, sous les figurines -->
        <div
            v-for="(t, i) in traps"
            :key="`t-${t.x}-${t.y}-${i}`"
            class="dg-trap-holder"
            :style="{ gridColumn: t.x + 1, gridRow: t.y + 1 }"
        >
            <div class="dg-trap" :class="t.etat" :title="t.titre ?? t.nom">
                <MSym v-if="t.etat !== 'declenche'" n="warning" fill />
            </div>
        </div>

        <!-- mobilier (doc 17) : bloc plein sur toute son emprise l×h — un simple
             marqueur discret serait invisible à 22px (leçon des portes, cf. plus
             bas) alors qu'un meuble bloquant doit se lire au premier coup d'œil. -->
        <div
            v-for="(f, i) in furniture"
            :key="`f-${f.x}-${f.y}-${i}`"
            class="dg-furn-holder"
            :style="{ gridColumn: `${f.x + 1} / span ${f.l}`, gridRow: `${f.y + 1} / span ${f.h}` }"
        >
            <div class="dg-furn" :class="{ 'non-bloquant': !f.bloquant }" :title="f.titre ?? f.nom">
                <MSym :n="f.ic ?? 'category'" :size="14" fill />
            </div>
        </div>

        <!-- portes : battant sur la CLOISON (arête), en % de la case -->
        <div
            v-for="(d, i) in doors"
            :key="`d-${d.x}-${d.y}-${d.cote}-${i}`"
            class="dg-door-holder"
            :style="{ gridColumn: d.x + 1, gridRow: d.y + 1 }"
        >
            <div class="dg-door" :class="[`cote-${d.cote}`, d.etat]" :title="d.titre">
                <MSym v-if="d.cadenas" n="lock" fill class="dg-door-lock" />
            </div>
        </div>

        <!-- couche propre au parent (figurines animées de la table) -->
        <slot />
    </TransitionGroup>
</template>

<style scoped>
.dg { display: grid; }

/* ---- cases (mêmes teintes table & manette) ---- */
.dg-cell { position: relative; border-radius: 3px; }
.dg-cell.void { background: transparent; }
.dg-cell.wall { background: transparent; }
.dg-cell.floor { background: linear-gradient(150deg, oklch(0.235 0.013 255), oklch(0.20 0.012 255));
  box-shadow: inset 0 0 0 1px oklch(0.3 0.014 255 / 0.35); }
.dg-cell.fog { background: oklch(0.16 0.01 255); }
.dg-cell.fog::after { content: ""; position: absolute; inset: 0; border-radius: 3px;
  background: radial-gradient(circle at 50% 40%, oklch(0.26 0.015 255 / 0.6), oklch(0.1 0.008 255 / 0.95)); }

/* ---- surcouche manette (accessibilité / départ / occupants) ---- */
.dg-cell.accessible { background: oklch(0.6 0.15 145 / 0.32); cursor: pointer; outline: 1px solid oklch(0.6 0.15 145 / 0.5); }
.dg-cell.accessible:hover { background: oklch(0.6 0.15 145 / 0.55); }
.dg-cell.depart { background: linear-gradient(150deg, var(--ember), var(--ember-deep)); color: var(--parch-100); display: grid; place-items: center; }
.dg-cell.allie { background: oklch(0.55 0.14 260 / 0.5); display: grid; place-items: center; }
.dg-cell.monstre { background: oklch(0.55 0.16 25 / 0.45); color: var(--danger, #e66); display: grid; place-items: center; }

/* ---- pièges (detecte / desarme / declenche — contrat « Pièges ») ---- */
.dg-trap-holder { position: relative; pointer-events: none; z-index: 2; }
.dg-trap { position: absolute; inset: 12%; border-radius: 5px; display: grid; place-items: center; }
.dg-trap .msym { font-size: clamp(11px, 1.3vw, 20px); filter: drop-shadow(0 1px 2px oklch(0 0 0 / 0.6)); }
.dg-trap.detecte { color: var(--warn, oklch(0.82 0.16 75)); background: oklch(0.78 0.15 75 / 0.13);
  box-shadow: inset 0 0 0 1.5px oklch(0.78 0.15 75 / 0.55); animation: dg-trappulse 2.2s ease-in-out infinite; }
@keyframes dg-trappulse { 50% { box-shadow: inset 0 0 0 1.5px oklch(0.78 0.15 75 / 0.95); } }
.dg-trap.desarme { color: var(--ink-600); background: oklch(0.3 0.01 255 / 0.4);
  box-shadow: inset 0 0 0 1px oklch(0.4 0.01 255 / 0.5); opacity: 0.75; }
.dg-trap.desarme::after { content: ""; position: absolute; left: 14%; right: 14%; top: 50%; height: 2px;
  background: var(--ink-500); transform: rotate(-24deg); border-radius: 2px; }
.dg-trap.declenche { inset: 6%; border-radius: 50%;
  background: radial-gradient(circle at 50% 45%, oklch(0.08 0.01 255) 0 36%, oklch(0.24 0.045 40 / 0.85) 56%, transparent 74%);
  box-shadow: inset 0 0 10px oklch(0 0 0 / 0.85); }

/* ---- mobilier (doc 17) : bloc PLEIN sur toute l'emprise, pas un marqueur —
   la leçon des portes (test de jeu 2026-07-31, cf. plus bas) est qu'un
   habillage discret disparaît sur les 22px de la manette. Un meuble bloquant
   doit se lire comme un obstacle, pas comme une décoration. */
.dg-furn-holder { position: relative; pointer-events: none; z-index: 2; }
.dg-furn { position: absolute; inset: 5%; border-radius: 4px; display: grid; place-items: center;
  background: linear-gradient(150deg, oklch(0.32 0.05 55), oklch(0.22 0.045 50));
  box-shadow: inset 0 0 0 1px oklch(0.5 0.06 55 / 0.55), 0 1px 3px oklch(0 0 0 / 0.5);
  color: oklch(0.85 0.05 70); }
.dg-furn .msym { filter: drop-shadow(0 1px 2px oklch(0 0 0 / 0.6)); }
.dg-furn.non-bloquant { opacity: 0.6; box-shadow: inset 0 0 0 1px oklch(0.5 0.06 55 / 0.3); }

/* ---- portes : battant en % de la case, sur l'arête est/sud ----
   Test de jeu 2026-07-31 : les joueurs ne repéraient PAS les portes. Une porte
   ouverte n'était qu'un pointillé de 2 px à 50 % d'opacité — invisible sur les
   cases de 22 px de la manette —, et un joueur a pris deux fois la case d'un
   allié pour un seuil. On dessine donc systématiquement les DEUX JAMBAGES
   (montants) de l'ouverture : c'est eux qui font lire « passage » même quand le
   battant est effacé. Le battant, lui, ne dit plus que l'ÉTAT. */
.dg-door-holder { position: relative; pointer-events: none; z-index: 3; }
.dg-door { position: absolute; border-radius: 2px;
  background: linear-gradient(var(--deg, 90deg), #d8a23a, #7a531d);
  box-shadow: 0 0 0 1px oklch(0 0 0 / 0.7), 0 1px 3px oklch(0 0 0 / 0.6);
  display: grid; place-items: center; }
.dg-door.cote-e { --deg: 90deg; top: 10%; bottom: 10%; right: 0; width: 30%; transform: translateX(50%); }
.dg-door.cote-s { --deg: 180deg; left: 10%; right: 10%; bottom: 0; height: 30%; transform: translateY(50%); }

/* Jambages : deux tenons clairs aux extrémités de l'arête, toujours visibles. */
.dg-door::before, .dg-door::after {
  content: ''; position: absolute; background: #f0d79a;
  box-shadow: 0 0 0 1px oklch(0 0 0 / 0.75); border-radius: 1px;
}
.dg-door.cote-e::before, .dg-door.cote-e::after { left: -10%; right: -10%; height: 22%; min-height: 3px; }
.dg-door.cote-e::before { top: -14%; }
.dg-door.cote-e::after { bottom: -14%; }
.dg-door.cote-s::before, .dg-door.cote-s::after { top: -10%; bottom: -10%; width: 22%; min-width: 3px; }
.dg-door.cote-s::before { left: -14%; }
.dg-door.cote-s::after { right: -14%; }

.dg-door.verrouillee { background: linear-gradient(var(--deg, 90deg), #b98a3a, #6a4a1c); }

/* Ouverte : le battant s'efface, les jambages restent — l'ouverture se voit. */
.dg-door.ouverte { background: none; box-shadow: none; }

/* Secrète RÉVÉLÉE (le serveur masque les autres) : violet, pour qu'un raccourci
   trouvé se distingue d'une porte ordinaire. */
.dg-door.secrete { background: linear-gradient(var(--deg, 90deg), #b18ad8, #5b3f7a); }
.dg-door.secrete::before, .dg-door.secrete::after { background: #e0cdf5; }

.dg-door-lock { color: #f0d79a; font-size: 0.6em; filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.8)); }

/* ---- FLIP figurines (table, animate) : glissement d'une case ---- */
.dg-fig-move { transition: transform 0.14s linear; }
.dg-fig-leave-active { transition: opacity 0.28s ease, transform 0.28s ease; }
.dg-fig-leave-to { opacity: 0; transform: scale(0.5); }
.dg-fig-enter-active { transition: opacity 0.3s ease; }
.dg-fig-enter-from { opacity: 0; }
</style>
