<script setup>
// LÉGENDE DE LA CARTE — bouton « i » + panneau (René, 2026-08-27).
//
// La carte porte maintenant six familles de symboles (figurines, portes et
// leurs quatre états, pièges, mobilier, épreuves, leviers), chacune avec son
// icône propre. Aucun écran ne disait ce qu'elles voulaient dire : un joueur
// devait deviner qu'un losange doré se tente et qu'un carré brun bloque.
//
// ⚠ Le panneau liste ce qui est SUR CETTE CARTE, pas un catalogue complet :
// une légende qui décrit sept pièges quand la salle en contient un se lit comme
// une documentation, et on cesse de l'ouvrir. Les familles vides disparaissent.
//
// ⚠ Les icônes viennent de `symboles.js`, la MÊME table que le rendu. C'est la
// seule garantie que la légende ne mente pas : un symbole ajouté apparaît aux
// deux endroits, ou à aucun.
//
// ⚠ La légende montre l'ILLUSTRATION quand elle existe, l'icône sinon (`Vignette`).
// Les pièges et les épreuves en portent une depuis toujours (`image_url` dans le
// payload) et elle n'était affichée NULLE PART ; le mobilier n'en avait aucune —
// ni gabarit, ni génération, ni champ. C'est ici qu'elles servent, et pas sur la
// carte : à 22 px sur la manette une illustration devient une bouillie, et elle
// ferait perdre la silhouette (rond / carré / losange / octogone) qui est ce que
// le joueur lit d'un coup d'œil. La forme dit la FAMILLE, l'image dit le CONTENU.
import { computed, ref } from 'vue';
import MSym from '../ui/MSym.vue';
import Vignette from '../ui/Vignette.vue';
import {
    EPREUVE_ICONES, EPREUVE_ICONE_DEFAUT, LEVIER_ICONE, MOBILIER_ICONES,
    MOBILIER_ICONE_DEFAUT, PIEGE_ICONES, PIEGE_ICONE_DEFAUT, icone,
} from './symboles.js';

const props = defineProps({
    /** Carte du contrat (EtatGroupe.carte) — sert à ne lister que le présent. */
    carte: { type: Object, default: () => ({}) },
});

const ouvert = ref(false);

/** Dédoublonne par nom en gardant la première entrée rencontrée. */
const parNom = (liste) => Object.values((liste ?? []).reduce((acc, e) => {
    const nom = e.nom ?? '';
    if (nom && !acc[nom]) { acc[nom] = e; }

    return acc;
}, {}));

const pieges = computed(() => parNom(props.carte?.pieges).map((p) => ({
    nom: p.nom,
    ic: icone(PIEGE_ICONES, p.nom, PIEGE_ICONE_DEFAUT),
    img: p.image_url ?? null,
})));

const epreuves = computed(() => parNom(props.carte?.epreuves).map((e) => ({
    nom: e.nom,
    ic: icone(EPREUVE_ICONES, e.nom, EPREUVE_ICONE_DEFAUT),
    img: e.image_url ?? null,
    detail: `jet de ${e.attribut === 'body' ? 'Body' : 'Mind'}, difficulté ${e.difficulte}`,
    description: e.description ?? null,
})));

const meubles = computed(() => parNom(props.carte?.mobilier).map((m) => ({
    nom: m.nom,
    ic: icone(MOBILIER_ICONES, m.nom, MOBILIER_ICONE_DEFAUT),
    img: m.image_url ?? null,
    detail: [
        m.bloque_mouvement !== false ? 'bloque le passage' : 'se franchit',
        m.bloque_vue === true ? 'bloque la vue' : 'on voit par-dessus',
    ].join(' · '),
})));

const leviers = computed(() => (props.carte?.leviers ?? []).length);

// États de piège réellement présents : « désamorcé » n'a rien à faire dans la
// légende d'une carte où aucun piège ne l'est.
const ETATS_PIEGE = { detecte: 'détecté — désamorçable au contact', desarme: 'désamorcé, inoffensif', declenche: 'déjà déclenché' };
// ⚠ Chaque état a son propre RENDU sur la carte (ambré clignotant, gris barré,
// cratère). Les décrire en simple texte laissait le joueur relier lui-même la
// phrase au dessin — c'est justement ce travail qu'une légende doit faire.
const etatsPiege = computed(() => Object.entries(ETATS_PIEGE)
    .filter(([cle]) => (props.carte?.pieges ?? []).some((p) => p.etat === cle)));

const PORTES = [
    ['ouverte', 'Ouverte — les montants seuls restent'],
    ['fermee', 'Fermée — l\'ouvrir révèle la salle et ses monstres'],
    ['verrouillee', 'Verrouillée — clé, gardien à vaincre ou levier'],
    ['secrete', 'Passage secret révélé par une fouille'],
];
const portes = computed(() => PORTES.filter(([etat]) => (props.carte?.portes ?? []).some((p) => p.etat === etat)));
</script>

<template>
    <div class="lg-wrap">
        <!-- ⚠ L'icône reste « i », ouverte comme fermée : passer à une croix
             plaçait DEUX croix côte à côte dans l'en-tête de la manette (l'une
             ferme la légende, l'autre la feuille de déplacement), sans rien pour
             dire laquelle fait quoi. L'état se dit par la couleur. -->
        <button class="lg-btn" :class="{ on: ouvert }" type="button" :aria-expanded="ouvert"
                title="Légende de la carte" @click="ouvert = !ouvert">
            <MSym n="info" fill />
        </button>

        <div v-if="ouvert" class="lg-panneau">
            <h4 class="lg-titre">Légende</h4>

            <section class="lg-sect">
                <div class="lg-sous">Figurines</div>
                <div class="lg-ligne"><span class="lg-chip lg-fig heros" /><span>Héros — cerclé d'or quand c'est son tour</span></div>
                <div class="lg-ligne"><span class="lg-chip lg-fig monstre" /><span>Monstre révélé</span></div>
                <div class="lg-ligne"><span class="lg-chip lg-fig allie" /><span>Allié (mercenaire, invocation)</span></div>
            </section>

            <section v-if="portes.length" class="lg-sect">
                <div class="lg-sous">Portes</div>
                <div v-for="[etat, texte] in portes" :key="etat" class="lg-ligne">
                    <span class="lg-chip lg-porte" :class="etat" /><span>{{ texte }}</span>
                </div>
            </section>

            <section v-if="pieges.length" class="lg-sect">
                <div class="lg-sous">Pièges</div>
                <div v-for="p in pieges" :key="p.nom" class="lg-ligne">
                    <span class="lg-chip lg-piege"><MSym :n="p.ic" fill /></span>
                    <Vignette class="lg-img" :src="p.img" :icon="p.ic" fill />
                    <span>{{ p.nom }}</span>
                </div>
                <div v-for="[cle, texte] in etatsPiege" :key="cle" class="lg-ligne">
                    <span class="lg-chip lg-piege" :class="cle"><MSym v-if="cle !== 'declenche'" n="warning" fill /></span>
                    <span class="lg-etat">{{ texte }}</span>
                </div>
            </section>

            <section v-if="epreuves.length" class="lg-sect">
                <div class="lg-sous">Épreuves</div>
                <div v-for="e in epreuves" :key="e.nom" class="lg-ligne">
                    <span class="lg-chip lg-epreuve"><MSym :n="e.ic" fill /></span>
                    <Vignette class="lg-img" :src="e.img" :icon="e.ic" fill />
                    <span>
                        {{ e.nom }} <em>— {{ e.detail }}</em>
                        <small v-if="e.description">{{ e.description }}</small>
                    </span>
                </div>
                <p class="lg-note">Grisée : ce héros y a déjà laissé sa tentative (une par héros).</p>
            </section>

            <section v-if="leviers" class="lg-sect">
                <div class="lg-sous">Leviers</div>
                <div class="lg-ligne">
                    <span class="lg-chip lg-levier"><MSym :n="LEVIER_ICONE" fill /></span>
                    <span>Ouvre une porte verrouillée — jet de Body, retentable sans limite</span>
                </div>
            </section>

            <section v-if="meubles.length" class="lg-sect">
                <div class="lg-sous">Mobilier</div>
                <div v-for="m in meubles" :key="m.nom" class="lg-ligne">
                    <span class="lg-chip lg-meuble"><MSym :n="m.ic" fill /></span>
                    <Vignette class="lg-img" :src="m.img" :icon="m.ic" fill />
                    <span>{{ m.nom }} <em>— {{ m.detail }}</em></span>
                </div>
            </section>
        </div>
    </div>
</template>

<style scoped>
/* ⚠ Les pastilles reprennent les valeurs de DungeonGrid.vue (mêmes teintes,
   mêmes silhouettes : rond pour une figurine, losange pour une épreuve,
   octogone pour un levier). C'est la seule duplication assumée du fichier —
   la forme et la couleur SONT le message, une légende qui les approxime ne
   sert à rien. Toucher l'une, toucher l'autre. */
.lg-wrap { position: relative; }

.lg-btn { width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center;
  background: oklch(0.16 0.012 255 / 0.9); border: var(--line); color: var(--ink-300);
  cursor: pointer; backdrop-filter: blur(6px); }
.lg-btn:hover, .lg-btn.on { color: var(--torch); border-color: var(--torch); }
.lg-btn.on { background: oklch(0.76 0.155 65 / 0.16); }
.lg-btn .msym { font-size: 19px; }

/* ⚠ `min(300px, …)` : sur un téléphone de 360 px le panneau fixe à 300 px
   dépassait du bord — mesuré à -44 px, donc une colonne de texte coupée. */
.lg-panneau { position: absolute; right: 0; top: 42px; z-index: 40;
  width: min(300px, calc(100vw - 28px)); max-height: 60vh;
  overflow-y: auto; padding: 14px 16px 16px; border-radius: var(--r-md);
  background: oklch(0.16 0.012 255 / 0.97); border: var(--line-strong); box-shadow: var(--sh-3);
  backdrop-filter: blur(8px); }
.lg-titre { font-family: var(--font-display); color: var(--parch-100); margin: 0 0 10px;
  font-size: 14px; letter-spacing: 0.06em; text-transform: uppercase; }

.lg-sect + .lg-sect { margin-top: 13px; border-top: var(--line); padding-top: 11px; }
.lg-sous { color: var(--torch); font-size: 10.5px; font-weight: 800; letter-spacing: 0.09em;
  text-transform: uppercase; margin-bottom: 7px; }
.lg-ligne { display: flex; align-items: flex-start; gap: 9px; margin-bottom: 6px;
  color: var(--ink-100); font-size: 12px; line-height: 1.35; }
.lg-ligne em { color: var(--ink-500); font-style: normal; }
.lg-ligne small { display: block; color: var(--ink-500); font-family: var(--font-narr);
  font-style: italic; font-size: 11px; margin-top: 2px; }
.lg-note { margin: 4px 0 0 27px; color: var(--ink-500); font-size: 11px; }

/* L'illustration, à côté de la pastille : la pastille dit la SILHOUETTE telle
   qu'elle est dessinée sur la carte, l'image dit ce que la chose est. Retirer
   la pastille au profit de l'image casserait le lien avec le plateau. */
.lg-img { flex: none; width: 42px; height: 42px; border-radius: 5px; overflow: hidden;
  background: var(--stone-850); box-shadow: inset 0 0 0 1px oklch(0.4 0.02 255 / 0.5); }

.lg-chip { flex: none; width: 18px; height: 18px; display: grid; place-items: center; margin-top: 1px; }
.lg-chip .msym { font-size: 12px; }

.lg-fig { border-radius: 50%; }
.lg-fig.heros { background: oklch(0.45 0.06 255); box-shadow: 0 0 0 2px var(--gold); }
.lg-fig.monstre { background: oklch(0.55 0.16 25 / 0.85); }
.lg-fig.allie { background: oklch(0.55 0.14 260 / 0.85); }

.lg-porte { height: 5px; margin-top: 8px; border-radius: 2px;
  background: linear-gradient(90deg, #d8a23a, #7a531d); }
.lg-porte.ouverte { background: none; box-shadow: inset 0 0 0 1px #f0d79a; }
.lg-porte.verrouillee { background: linear-gradient(90deg, #b98a3a, #6a4a1c); }
.lg-porte.secrete { background: linear-gradient(90deg, #b18ad8, #5b3f7a); }

.lg-piege { border-radius: 4px; color: var(--warn, oklch(0.82 0.16 75));
  background: oklch(0.78 0.15 75 / 0.14); box-shadow: inset 0 0 0 1.5px oklch(0.78 0.15 75 / 0.55); }
/* États : les mêmes teintes que `.dg-trap` dans DungeonGrid.vue. */
.lg-piege.desarme { color: var(--ink-600); background: oklch(0.3 0.01 255 / 0.4);
  box-shadow: inset 0 0 0 1px oklch(0.4 0.01 255 / 0.5); opacity: 0.75; position: relative; }
.lg-piege.desarme::after { content: ''; position: absolute; left: 12%; right: 12%; top: 50%; height: 2px;
  background: var(--ink-500); transform: rotate(-24deg); border-radius: 2px; }
.lg-piege.declenche { border-radius: 50%; box-shadow: inset 0 0 6px oklch(0 0 0 / 0.85);
  background: radial-gradient(circle at 50% 45%, oklch(0.08 0.01 255) 0 36%, oklch(0.24 0.045 40 / 0.85) 56%, transparent 74%); }
.lg-etat { color: var(--ink-500); }
.lg-epreuve { transform: rotate(45deg); border-radius: 12%; color: var(--stone-950); background: var(--gold); }
.lg-epreuve .msym { transform: rotate(-45deg); }
.lg-levier { color: var(--stone-950); background: oklch(0.72 0.13 235);
  clip-path: polygon(30% 0, 70% 0, 100% 30%, 100% 70%, 70% 100%, 30% 100%, 0 70%, 0 30%); }
.lg-meuble { border-radius: 3px; color: oklch(0.85 0.05 70);
  background: linear-gradient(150deg, oklch(0.32 0.05 55), oklch(0.22 0.045 50));
  box-shadow: inset 0 0 0 1px oklch(0.5 0.06 55 / 0.55); }
</style>
