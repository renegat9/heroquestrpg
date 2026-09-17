<script setup>
// GRILLE DE DONJON PARTAGÉE — socle commun à la carte TABLE (narrateur) et à la
// mini-carte MANETTE (déplacement), pour qu'elles rendent le TERRAIN de façon
// IDENTIQUE : cases (mur / sol / brouillard), portes et marqueurs de pièges.
// Chaque parent ajoute sa couche propre — figurines animées + caméra côté
// table ; surbrillance des cases accessibles + tap côté manette — via les
// slots/props ci-dessous.
//
// La porte VIT sur une arête (`{x,y,cote}`, identité et verrous côté serveur —
// voir `MoteurPortes`), mais elle BLOQUE et se DESSINE désormais sur une case,
// son embrasure (`p.embrasure`, René 2026-09-11 : « la porte doit être
// centrale à sa case ») : c'est là que son battant est dessiné, en POURCENTAGE
// de la case pour un rendu identique quelle que soit sa taille (grande table,
// petit 22px manette). C'était LA source de divergence historique (deux CSS de
// portes séparées).
import { computed } from 'vue';
import MSym from '../ui/MSym.vue';
import {
    EPREUVE_ICONES, EPREUVE_ICONE_DEFAUT, LEVIER_ICONE, MOBILIER_ICONES,
    MOBILIER_ICONE_DEFAUT, PIEGE_ICONES, PIEGE_ICONE_DEFAUT, TERRAIN_TEINTES,
    TERRAIN_TEINTE_DEFAUT, GLACE_ICONE, icone,
} from './symboles.js';

const props = defineProps({
    /** Carte du contrat : { largeur, hauteur, cases: [[..]], portes: [{x,y,cote,etat,verrou?}] }. */
    carte: { type: Object, required: true },
    /** Marqueurs de pièges connus : [{x, y, etat, nom?, titre?}] — voir piegesVersMarqueurs(). */
    traps: { type: Array, default: () => [] },
    /** Mobilier des salles découvertes : [{x, y, l, h, nom, bloque_mouvement,
     *  bloque_vue, ic?, titre?}] — voir mobilierVersDecor() (doc 17). Les deux
     *  drapeaux sont INDÉPENDANTS : une table bloque le passage mais on voit
     *  par-dessus, une bibliothèque bloque les deux. */
    furniture: { type: Array, default: () => [] },
    /** Épreuves des salles découvertes : [{x, y, nom, attribut, difficulte,
     *  tentee_par}] — les ancrages à JET D'ATTRIBUT (2026-08-24). Elles ne
     *  bloquent rien : un marqueur suffit, et il doit se distinguer du piège
     *  (danger) comme du meuble (obstacle) — ici une main tendue, dorée. */
    trials: { type: Array, default: () => [] },
    /** (x, y) → classe(s) CSS supplémentaire(s) par case (accessible / depart /
     *  occupant… — surcouche manette). Null = aucune (table). */
    cellClass: { type: Function, default: null },
    /** Style de la grille (le parent décide la taille : caméra 1fr côté table,
     *  22px + gap côté manette). Fusionné au `display:grid` du socle. */
    gridStyle: { type: Object, default: () => ({}) },
    /** Leviers des salles découvertes : [{x, y, difficulte}]. Ils
     *  n'étaient dessinés NULLE PART (2026-08-27) alors qu'un levier commande
     *  parfois l'unique porte d'une salle : un mécanisme invisible qui verrouille
     *  le donjon. */
    levers: { type: Array, default: () => [] },
    /** Terrain des salles découvertes (doc 18 §4) : [{x, y, nom, cout_deplacement,
     *  bloque_mouvement, bloque_vue, paire_id}]. ⚠ Rendu comme une TEINTE de la
     *  case elle-même (pas un marqueur posé dessus) — voir TERRAIN_TEINTES dans
     *  symboles.js pour la raison. C'était la couche « leviers » de 2026-08-27,
     *  publiée mais dessinée NULLE PART : gratuit tant qu'aucune case de glace
     *  n'était posée, un piège invisible le jour où il y en a une. */
    terrain: { type: Array, default: () => [] },
    /** Murs de glace posés par le sort du boss (doc 18 §4) : [{x, y, cranes}].
     *  ⚠ Rendus comme un BLOC PLEIN (cf. GLACE_ICONE dans symboles.js), pas
     *  comme une teinte de terrain : c'est un obstacle qui barre la case, et
     *  il était jusqu'ici dessiné NULLE PART alors qu'il bloque le mouvement. */
    ice: { type: Array, default: () => [] },
    /** Anime le déplacement des enfants (FLIP sur les figurines) — table. */
    animate: { type: Boolean, default: false },
});
const emit = defineEmits(['cell']);

const TUILES = { m: 'wall', s: 'floor', b: 'fog' };

// ⚠ Les icônes se résolvent ICI, pas chez les appelants : la table passe des
// marqueurs convertis (`mobilierVersDecor`), la manette passe le payload brut.
// Résoudre chez l'appelant obligerait à convertir des deux côtés, et l'un des
// deux finirait par ne pas suivre — c'est déjà ce qui rendait le mobilier
// illustré à la table et générique sur la manette.
const iconePiege = (t) => t.ic ?? icone(PIEGE_ICONES, t.nom, PIEGE_ICONE_DEFAUT);
const iconeEpreuve = (e) => e.ic ?? icone(EPREUVE_ICONES, e.nom, EPREUVE_ICONE_DEFAUT);
const iconeMeuble = (f) => f.ic ?? icone(MOBILIER_ICONES, f.nom, MOBILIER_ICONE_DEFAUT);

// Coordonnée → catégorie de terrain, pour une résolution en O(1) case par case
// (même raison que `cells` : un `.find()` par case sur une carte de 5000
// cellules aurait un coût quadratique au premier grand donjon).
const terrainParCase = computed(() => {
    const table = {};
    for (const t of props.terrain) {
        table[`${t.x},${t.y}`] = t;
    }
    return table;
});
const terrainDe = (x, y) => terrainParCase.value[`${x},${y}`] ?? null;
const classeTerrain = (x, y) => {
    const t = terrainDe(x, y);
    return t ? `terrain-${icone(TERRAIN_TEINTES, t.nom, TERRAIN_TEINTE_DEFAUT)}` : null;
};
// Titre de la case : nom du terrain, ET son surcoût s'il y en a un (Rivière
// gelée : 2 points pour l'atteindre au lieu de 1, doc 18 §4). Sans ce second
// membre, une case grisée sur la manette n'a QUE la teinte « danger » pour
// s'expliquer — un effet qui coûte plus cher doit pouvoir se vérifier au
// survol, pas seulement se déduire d'une couleur.
const titreTerrain = (x, y) => {
    const t = terrainDe(x, y);
    if (! t) return undefined;
    return t.cout_deplacement > 1 ? `${t.nom} (coûte ${t.cout_deplacement} points de déplacement)` : t.nom;
};

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

// Portes (René, 2026-09-11 : « la porte doit être centrale à sa case,
// bloquant l'entrée dans sa case tant qu'elle n'est pas ouverte »). Chaque
// porte est désormais rendue sur SA CASE D'EMBRASURE (`p.embrasure`, publiée
// par `EtatGroupe::portes()` — la case parmi les deux que sépare l'arête qui
// appartient réellement au mur d'une salle, `Grille::caseEmbrasure()`), plus
// sur l'arête `(x,y,cote)` elle-même : c'est là que le moteur la bloque, et
// c'est donc là qu'il faut la dessiner pour que la carte ne mente pas.
//
// ⚠ Une porte SECRÈTE non révélée n'apparaît plus du tout ici : elle n'a
// PLUS d'entrée dans `carte.portes` (2026-09-11, remplace le déguisement
// `etat: 'mur'` du 2026-09-10) — sa case d'embrasure est peinte `m` dans
// `carte.cases` par le serveur, donc `.dg-cell.wall` la rend déjà, sans rien
// de spécial à faire ici. Aucun cas particulier à traiter dans ce composant.
//
// L'ancienne FUSION des deux voies d'un seuil large (une porte de chaque
// côté d'un couloir à 2 cases) n'a plus lieu d'être : `AssembleurCarte` ne
// pose plus qu'UNE porte par bout de jonction depuis la décision « un seuil
// fait une case » (2026-08-08), et chaque porte occupe maintenant sa PROPRE
// case, jamais une arête partagée avec sa voisine — il n'y a donc plus deux
// battants à confondre en un seul.
const doors = computed(() => (props.carte.portes ?? [])
    .filter((p) => PORTE_ETATS[p.etat] && p.embrasure)
    .map((p) => {
        const verrou = p.verrou ? (PORTE_VERROUS[p.verrou] ?? p.verrou) : null;

        return {
            x: p.embrasure.x,
            y: p.embrasure.y,
            cote: p.cote === 's' ? 's' : 'e', // arête EST ('e') ou SUD ('s') — oriente le glyphe
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
            :class="[c.t, classeTerrain(c.x, c.y), cellClass ? cellClass(c.x, c.y) : null]"
            :style="{ gridColumn: c.x + 1, gridRow: c.y + 1 }"
            :title="titreTerrain(c.x, c.y)"
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
                <MSym v-if="t.etat !== 'declenche'" :n="iconePiege(t)" fill />
            </div>
        </div>

        <!-- épreuves : marqueur au-dessus de la case, comme les pièges, mais
             d'une autre couleur ET d'une autre icône. Confondre les deux serait
             coûteux : un piège se contourne, une épreuve se tente. -->
        <div
            v-for="(e, i) in trials"
            :key="`e-${e.x}-${e.y}-${i}`"
            class="dg-trial-holder"
            :style="{ gridColumn: e.x + 1, gridRow: e.y + 1 }"
        >
            <div
                class="dg-trial"
                :class="{ tentee: (e.tentee_par ?? []).length > 0 }"
                :title="`${e.nom} — jet de ${e.attribut === 'body' ? 'Body' : 'Mind'} (difficulté ${e.difficulte})`"
            >
                <MSym :n="iconeEpreuve(e)" fill />
            </div>
        </div>

        <!-- mobilier (doc 17) : bloc plein sur toute son emprise l×h — un simple
             marqueur discret serait invisible à 22px (leçon des portes, cf. plus
             bas) alors qu'un meuble bloquant le passage doit se lire au premier
             coup d'œil. `bloque_mouvement`/`bloque_vue` sont INDÉPENDANTS : seul
             le premier pilote ce rendu (le second n'a pas d'équivalent visuel
             sur une carte en vue de dessus — voir Grille::ligneDeVue côté serveur). -->
        <div
            v-for="(f, i) in furniture"
            :key="`f-${f.x}-${f.y}-${i}`"
            class="dg-furn-holder"
            :style="{ gridColumn: `${f.x + 1} / span ${f.l}`, gridRow: `${f.y + 1} / span ${f.h}` }"
        >
            <div class="dg-furn" :class="{ 'non-bloquant': !f.bloque_mouvement }" :title="f.titre ?? f.nom">
                <MSym :n="iconeMeuble(f)" fill />
            </div>
        </div>

        <!-- murs de glace (doc 18 §4) : bloc plein, comme un meuble bloquant —
             ils barrent la case et se brisent à 5 crânes, que le titre annonce. -->
        <div
            v-for="(g, i) in ice"
            :key="`g-${g.x}-${g.y}-${i}`"
            class="dg-ice-holder"
            :style="{ gridColumn: g.x + 1, gridRow: g.y + 1 }"
        >
            <div class="dg-ice" :title="`Mur de glace — ${g.cranes ?? 0}/5 crânes`">
                <MSym :n="GLACE_ICONE" fill />
            </div>
        </div>

        <!-- leviers : mécanisme d'ouverture, au sol. Rendu en OCTOGONE bleu —
             ni un disque (réservé aux figurines), ni le losange doré de
             l'épreuve : les trois se côtoient dans une même salle et un joueur
             doit pouvoir dire de loin lequel il vise. -->
        <div
            v-for="(l, i) in levers"
            :key="`l-${l.x}-${l.y}-${i}`"
            class="dg-lever-holder"
            :style="{ gridColumn: l.x + 1, gridRow: l.y + 1 }"
        >
            <div
                class="dg-lever"
                :title="`Levier — jet de Body${l.difficulte ? ` (difficulté ${l.difficulte})` : ''}`"
            >
                <MSym :n="LEVIER_ICONE" fill />
            </div>
        </div>

        <!-- portes : battant CENTRÉ dans sa case d'embrasure (René, 2026-09-11 —
             plus sur une arête, voir le commentaire de `doors` ci-dessus) -->
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

/* ⚠ TAILLE DES GLYPHES — `--dg-icone`, posée par le PARENT avec la taille de
   case (`gridStyle`). Les marqueurs étaient dimensionnés de trois façons qui ne
   suivaient AUCUNE d'elles la case : `62%`/`64%` se calculent sur la police
   héritée (~16 px), donc ~10 px quelle que soit la case ; le meuble était figé
   à 14 px ; le piège sur `1.3vw`, c'est-à-dire sur la largeur de l'ÉCRAN. Rien
   ne se voyait tant que la manette était à 22 px — mais au premier zoom on
   obtenait de grandes cases avec de minuscules symboles au milieu.
   Le repli reprend exactement les valeurs d'avant, pour la table qui dimensionne
   ses cases en `1fr` et ne peut donc pas annoncer de pixels. */
.dg { --dg-icone: clamp(11px, 1.3vw, 20px); }


/* ---- cases (mêmes teintes table & manette) ---- */
/* ⚠ TROIS PLANS DE LUMINOSITÉ, et ils doivent le RESTER (René, 2026-09-11 :
   « on trouve difficile de distinguer les salles avec le fond de la page »).

   Le défaut mesuré : le mur était `transparent`, donc littéralement le fond de
   page (`--stone-950`, L 0.16) ; le brouillard valait 0.16 lui aussi ; et le
   sol ne montait qu'à 0.20-0.235. Un rapport de contraste sol/fond d'environ
   1.3:1 là où 3:1 est le minimum pour qu'une forme se distingue — et sur la
   MANETTE, le sol tombait pile sur `--stone-900` (0.20), la couleur du panneau
   qui le porte. Le donjon n'avait donc aucune silhouette : pas de roche, du
   vide. La seule chose qui disait « ceci est une salle » était un liseré
   intérieur d'1 px à 35 % d'opacité, invisible à 22 px sur un téléphone.

   L'ordre à préserver, du plus sombre au plus clair :
     brouillard (0.10) < ROCHE (0.115) < fond de page (0.16) < SOL (0.26-0.30)
   La roche est plus sombre que la page EXPRÈS : c'est ce qui donne au donjon
   une silhouette découpée, au lieu de le laisser se fondre dans l'écran. */
.dg-cell { position: relative; border-radius: 3px; }
/* `void` = hors carte, et reste transparent : c'est la seule case qui doit
   disparaître dans la page. La confondre avec `wall` redessinerait un cadre
   rectangulaire autour de donjons qui n'en ont pas. */
.dg-cell.void { background: transparent; }
.dg-cell.wall { background: linear-gradient(150deg, oklch(0.125 0.011 255), oklch(0.108 0.010 255)); }
.dg-cell.floor { background: linear-gradient(150deg, oklch(0.30 0.015 255), oklch(0.26 0.014 255));
  box-shadow: inset 0 0 0 1px oklch(0.40 0.016 255 / 0.40); }
.dg-cell.fog { background: oklch(0.10 0.008 255); }
.dg-cell.fog::after { content: ""; position: absolute; inset: 0; border-radius: 3px;
  background: radial-gradient(circle at 50% 40%, oklch(0.17 0.012 255 / 0.6), oklch(0.07 0.006 255 / 0.95)); }


/* ---- terrain (doc 18 §4) : la CASE elle-même change de teinte, ce n'est pas
   un marqueur posé dessus — voir le commentaire de TERRAIN_TEINTES dans
   symboles.js pour la raison (un héros se TIENT sur la glace). Trois
   catégories : `danger` porte une HACHURE en plus de la teinte — la couleur
   seule se lit mal sous le voile de brouillard ou en accessibilité réduite,
   la texture porte l'information même en niveaux de gris ; `passage` (tunnel)
   et `decor` (sans effet à ce jour) restent une simple teinte. Placées AVANT
   la surcouche manette qui suit : sur la manette, « case accessible »/« case
   occupée » doivent rester lisibles par-dessus un sol givré, jamais l'inverse
   — la cascade CSS (règle déclarée plus bas = priorité) fait ce travail sans
   qu'aucune des deux couches n'ait à connaître l'autre. */
.dg-cell.terrain-danger {
  background:
    repeating-linear-gradient(135deg, oklch(0.78 0.15 220 / 0.24) 0 4px, transparent 4px 9px),
    linear-gradient(150deg, oklch(0.30 0.05 220), oklch(0.22 0.045 225));
  box-shadow: inset 0 0 0 1px oklch(0.78 0.15 220 / 0.4);
}
.dg-cell.terrain-passage {
  background: linear-gradient(150deg, oklch(0.32 0.09 290), oklch(0.21 0.06 290));
  box-shadow: inset 0 0 0 1px oklch(0.72 0.13 290 / 0.45);
}
.dg-cell.terrain-decor {
  background: linear-gradient(150deg, oklch(0.27 0.03 220), oklch(0.20 0.02 225));
  box-shadow: inset 0 0 0 1px oklch(0.6 0.04 220 / 0.3);
}

/* ---- surcouche manette (accessibilité / départ / occupants) ---- */
.dg-cell.accessible { background: oklch(0.6 0.15 145 / 0.32); cursor: pointer; outline: 1px solid oklch(0.6 0.15 145 / 0.5); }
.dg-cell.accessible:hover { background: oklch(0.6 0.15 145 / 0.55); }
/* ---- trajet prévu (manette, aperçu avant validation) : les cases que le héros
   VA traverser, et la case visée. Teinte AMBRÉE, pas une nuance de plus du vert
   des cases accessibles : le joueur doit voir d'un coup d'œil ce qui est
   « atteignable » et ce qui est « le chemin choisi », y compris quand les deux
   se superposent. */
.dg-cell.trajet { background: oklch(0.72 0.15 75 / 0.38); cursor: pointer;
  outline: 1px solid oklch(0.78 0.14 75 / 0.6); }
.dg-cell.visee { background: oklch(0.78 0.16 75 / 0.65); cursor: pointer;
  outline: 2px solid oklch(0.88 0.14 80 / 0.9); }
.dg-cell.depart { background: linear-gradient(150deg, var(--ember), var(--ember-deep)); color: var(--parch-100); display: grid; place-items: center; }
.dg-cell.allie { background: oklch(0.55 0.14 260 / 0.5); display: grid; place-items: center; }
.dg-cell.monstre { background: oklch(0.55 0.16 25 / 0.45); color: var(--danger, #e66); display: grid; place-items: center; }

/* ---- pièges (detecte / desarme / declenche — contrat « Pièges ») ---- */
.dg-trap-holder { position: relative; pointer-events: none; z-index: 2; }
/* Épreuves : un LOSANGE doré, et surtout PAS un disque.
   ⚠ Au premier jet c'était un disque plein de 72 %, exactement la silhouette
   d'un jeton de figurine : sur la table, un disque doré entre deux héros se
   lisait comme une quatrième figurine (constaté en capture, 2026-08-27). Les
   figures sont RONDES ; ce qui n'est pas une figure ne doit donc pas l'être.
   Le losange est plus petit, laisse voir le sol autour, et se distingue aussi
   du piège (carré rouge, danger) — l'épreuve est une occasion, pas une menace.
   Estompé dès qu'un héros y a laissé sa tentative : la table voit d'un coup
   d'œil qu'elle a déjà servi (une tentative par héros). */
.dg-trial-holder { position: relative; display: grid; place-items: center; pointer-events: none; z-index: 2; }
.dg-trial { width: 54%; height: 54%; display: grid; place-items: center; transform: rotate(45deg);
  border-radius: 12%; color: var(--stone-950); background: var(--gold);
  box-shadow: 0 0 5px oklch(0.80 0.135 88 / 0.5); }
/* Le glyphe se redresse : seul le cadre tourne, sinon la main penche. */
.dg-trial .msym { transform: rotate(-45deg); font-size: var(--dg-icone); }
.dg-trial.tentee { background: var(--stone-700); color: var(--ink-400); box-shadow: none; opacity: 0.75; }

.dg-trap { position: absolute; inset: 12%; border-radius: 5px; display: grid; place-items: center; }
.dg-trap .msym { font-size: var(--dg-icone); filter: drop-shadow(0 1px 2px oklch(0 0 0 / 0.6)); }
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

/* ---- leviers : octogone bleu, une troisième silhouette. Les figurines sont
   RONDES, l'épreuve est un LOSANGE doré ; le levier ne doit donc être ni l'un
   ni l'autre. Il ne s'estompe jamais : contrairement à l'épreuve, le forcer est
   retentable sans limite, donc un levier déjà tenté reste une action valable. */
.dg-lever-holder { position: relative; display: grid; place-items: center; pointer-events: none; z-index: 2; }
.dg-lever { width: 56%; height: 56%; display: grid; place-items: center;
  clip-path: polygon(30% 0, 70% 0, 100% 30%, 100% 70%, 70% 100%, 30% 100%, 0 70%, 0 30%);
  color: var(--stone-950); background: oklch(0.72 0.13 235);
  box-shadow: 0 0 5px oklch(0.72 0.13 235 / 0.5); }
.dg-lever .msym { font-size: var(--dg-icone); }

/* ---- mobilier (doc 17) : bloc PLEIN sur toute l'emprise, pas un marqueur —
   la leçon des portes (test de jeu 2026-07-31, cf. plus bas) est qu'un
   habillage discret disparaît sur les 22px de la manette. Un meuble bloquant
   doit se lire comme un obstacle, pas comme une décoration. */
.dg-furn-holder { position: relative; pointer-events: none; z-index: 2; }
.dg-furn { position: absolute; inset: 5%; border-radius: 4px; display: grid; place-items: center;
  background: linear-gradient(150deg, oklch(0.32 0.05 55), oklch(0.22 0.045 50));
  box-shadow: inset 0 0 0 1px oklch(0.5 0.06 55 / 0.55), 0 1px 3px oklch(0 0 0 / 0.5);
  color: oklch(0.85 0.05 70); }
.dg-furn .msym { font-size: var(--dg-icone); filter: drop-shadow(0 1px 2px oklch(0 0 0 / 0.6)); }
.dg-furn.non-bloquant { opacity: 0.6; box-shadow: inset 0 0 0 1px oklch(0.5 0.06 55 / 0.3); }

/* ---- murs de glace (doc 18 §4) : MÊME silhouette que le mobilier bloquant
   (bloc plein occupant la case) et une palette glacée — le joueur doit lire
   « on ne passe pas » au premier coup d'œil, et comprendre du même geste que
   ça se brise. */
.dg-ice-holder { position: relative; pointer-events: none; z-index: 2; }
.dg-ice { position: absolute; inset: 5%; border-radius: 4px; display: grid; place-items: center;
  background: linear-gradient(150deg, oklch(0.62 0.09 220 / 0.85), oklch(0.44 0.08 235 / 0.9));
  box-shadow: inset 0 0 0 1px oklch(0.88 0.06 220 / 0.7), 0 1px 3px oklch(0 0 0 / 0.5);
  color: oklch(0.96 0.02 220); }
.dg-ice .msym { font-size: var(--dg-icone); filter: drop-shadow(0 1px 2px oklch(0 0 0 / 0.6)); }

/* ---- portes : battant CENTRÉ DANS SA CASE D'EMBRASURE, plus sur une arête
   (René, 2026-09-11 : « la porte doit être centrale à sa case, bloquant
   l'entrée dans sa case tant qu'elle n'est pas ouverte » — le moteur bloque
   désormais cette case exactement comme il bloquait déjà l'arête, voir
   `Grille::caseEmbrasure()`. La carte doit donc montrer la même chose : un
   obstacle qui occupe une case, pas un trait sur son bord).
   Test de jeu 2026-07-31 : les joueurs ne repéraient PAS les portes — un
   pointillé à 50 % d'opacité sur une arête étroite était invisible à 22 px.
   Le battant occupe maintenant le gros de sa case, comme le bloc plein du
   mobilier (§mobilier plus haut) : un obstacle doit se lire comme un
   obstacle, pas comme une décoration. Il reste ORIENTÉ selon `cote` — une
   embrasure EST perce un mur vertical (battant vertical, plus étroit en
   largeur), une embrasure SUD perce un mur horizontal (battant horizontal,
   plus étroit en hauteur) — pour que l'œil retrouve, même sans lire le
   libellé, LEQUEL des quatre murs de la case est percé. */
.dg-door-holder { position: relative; pointer-events: none; z-index: 3; display: grid; place-items: center; }
.dg-door { position: absolute; border-radius: 3px;
  background: linear-gradient(150deg, #d8a23a, #7a531d);
  box-shadow: inset 0 0 0 1px oklch(0 0 0 / 0.6), 0 1px 3px oklch(0 0 0 / 0.55);
  display: grid; place-items: center; }
.dg-door.cote-e { inset: 8% 24%; }
.dg-door.cote-s { inset: 24% 8%; }

.dg-door.verrouillee { background: linear-gradient(150deg, #b98a3a, #6a4a1c); }

/* Ouverte : le battant s'efface, un simple cadre marque encore l'embrasure —
   la case elle-même redevient un sol ordinaire, praticable et transparente
   (`Grille::estTraversable()`/`ligneDeVue()`), donc rien ne doit plus la
   faire lire comme un obstacle. */
.dg-door.ouverte { background: none;
  box-shadow: inset 0 0 0 1.5px oklch(0.75 0.12 88 / 0.55); }

/* Secrète RÉVÉLÉE (le serveur ne publie plus du tout les autres — leur case
   se peint en roche côté serveur, `EtatGroupe::carte()` — et `.dg-cell.wall`
   les rend déjà sans rien de spécial ici) : violet, pour qu'un raccourci
   trouvé se distingue d'une porte ordinaire. */
.dg-door.secrete { background: linear-gradient(150deg, #b18ad8, #5b3f7a); }

.dg-door-lock { color: #f0d79a; font-size: 0.6em; filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.8)); }

/* ---- FLIP figurines (table, animate) : glissement d'une case ----
   ⚠ Viser `.fig` EXPLICITEMENT, et par `:deep()`. Le TransitionGroup pose ses
   classes sur TOUS ses enfants — les 5 040 `.dg-cell` de la grille comme les
   quelques figurines. Un simple `.dg-fig-move` dans un style SCOPÉ faisait donc
   exactement l'inverse de son intention : il tombait sur toutes les cases (qui
   portent le data-v du composant) et sur aucune figurine (qui arrivent par le
   `<slot />`, sans attribut de scope — le style de TableView n'est pas scopé).
   Coût maximal, effet nul, et une figurine qui n'a jamais glissé.

   `:slotted()` ne suffit PAS : il compile en `[data-v-x-s]`, un attribut que le
   contenu du slot ne porte pas ici (vérifié au navigateur). `:deep()` compile en
   `[data-v-x] …`, et le conteneur `.dg` porte bien le data-v : la règle atteint
   ses enfants — d'où la classe pour exclure les cases.

   ⚠ Et cette classe DOIT être celle de l'ENFANT DIRECT du TransitionGroup —
   `.ent-holder`, pas `.fig` qui est à l'intérieur. Vue SONDE l'enfant déplacé
   (il lui applique la move-class sur un clone et lit sa transition `transform`) :
   sans transition sur l'enfant direct, il conclut que l'animation est impossible
   et ne pose JAMAIS la classe. Mesuré le 2026-08-23 avec un MutationObserver sur
   6 tours joués : 0 pose de classe, 0 transition démarrée — les figurines se
   téléportaient.

   Mesuré à la table le 2026-08-23 : 5 040 transitions CSS simultanées, une par
   case, redémarrées à chaque re-rendu — donc à chaque déplacement et à chaque
   diffusion d'état. `page.screenshot()` expirait à 90 s ; après correctif, 4
   animations en tout et une capture en 0,5 s. C'est très probablement la
   « table à 100 % CPU » du verdict de jeu du 2026-07-27, restée sans cause. */
:deep(.ent-holder.dg-fig-move) { transition: transform 0.14s linear; }
:deep(.ent-holder.dg-fig-leave-active) { transition: opacity 0.28s ease, transform 0.28s ease; }
:deep(.ent-holder.dg-fig-leave-to) { opacity: 0; transform: scale(0.5); }
:deep(.ent-holder.dg-fig-enter-active) { transition: opacity 0.3s ease; }
:deep(.ent-holder.dg-fig-enter-from) { opacity: 0; }
</style>
