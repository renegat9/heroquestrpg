<script setup>
/*
 * Un JET : la volée de l'attaquant et celle du défenseur, chacune nommée,
 * chaque dé gagnant entouré de vert — OU, depuis le 2026-09-24, un jet
 * UNILATÉRAL (une seule volée, personne en face) : un dé rouge de résistance
 * (Boule de Feu, Trait de Feu), un jet de Mind (Sommeil, Terreur, un sort de
 * Dread) ou le dé d'un piège de sol. Les trois étaient calculés, publiés, et
 * dessinés NULLE PART (René : « pour les sorts d'attaque avec un lancer de
 * dés pour résister, on ne voit pas le lancer de dé ») — ce composant ne
 * connaissait que la volée à deux camps d'une attaque. Un jet unilatéral
 * n'utilise que la ligne `def` (rien à `atk`) : c'est TOUJOURS la cible qui
 * lance, jamais un round « attaque contre rien ».
 *
 * Rendu unique, TROIS emplois : l'overlay de révélation de la manette (grand,
 * ~3 s après SON action), le fil du combat (petit, permanent — l'historique) et,
 * depuis le 2026-09-14, la SCÈNE de l'écran de table. Des rendus séparés
 * auraient dérivé, et c'est justement une divergence de ce genre qui a produit
 * le défaut d'origine — d'où le déplacement de ce composant de `manette/` vers
 * `ui/` : il n'appartient plus à un seul écran.
 *
 * ⚠ Le succès n'est PAS une propriété du dé, mais du couple (dé, jet) : un
 * bouclier blanc pare pour un héros et ne pare rien pour un monstre ; un
 * crâne touche, sauf contre un éthéré où c'est le bouclier noir ; un dé rouge
 * de résistance réussit sur 5 OU 6, jamais un crâne. On ne redéduit RIEN ici —
 * le moteur envoie `touchante` / `defensive` avec le jet
 * (App\Engine\ResultatAttaque::pourJournal, App\Partie\JournalCombat::
 * desJetUnilateral) et on ne fait que comparer. `touchante`/`defensive` sont
 * soit une face unique (combat, Mind : `'crane'`, `'bouclier_blanc'`…), soit
 * un ENSEMBLE de faces gagnantes (dé rouge : `[5, 6]`) — la comparaison
 * s'adapte à la forme reçue, elle ne choisit jamais laquelle des deux gagne.
 */
import { computed } from 'vue';
import MSym from './MSym.vue';

const props = defineProps({
    /** {atk, def, touchante, defensive, attaquant, defenseur, touches, boucliers, libelle_atk?, libelle_def?} */
    jet: { type: Object, required: true },
    /** Version compacte pour le fil du combat. */
    compact: { type: Boolean, default: false },
});

/* Les points d'un d6 NUMÉRIQUE (dé rouge de résistance), même table que le dé
 * de déplacement de la scène de table (`SceneEvenement.vue`) — le même objet
 * du monde réel doit se dessiner pareil partout. Un pur fait d'affichage (un
 * d6 a six faces, point), pas une règle de jeu : la dupliquer ici ne fait
 * dériver aucune décision. */
const POINTS_D6 = {
    1: [5],
    2: [1, 9],
    3: [1, 5, 9],
    4: [1, 3, 7, 9],
    5: [1, 3, 5, 7, 9],
    6: [1, 3, 4, 6, 7, 9],
};

/** Icône d'une face SYMBOLIQUE du moteur (combat/Mind) — la couleur du bouclier
 *  est conservée. Un dé rouge (face NUMÉRIQUE) n'a pas d'icône : ses points se
 *  dessinent (voir `POINTS_D6`), jamais un pictogramme. */
function icone(face) {
    if (face === 'crane') return 'skull';
    if (face === 'bouclier_blanc' || face === 'bouclier_noir') return 'shield';
    return null;
}

function classeFace(face) {
    if (typeof face === 'number') return 'f-rouge';
    if (face === 'crane') return 'f-crane';
    return face === 'bouclier_noir' ? 'f-noir' : 'f-blanc';
}

/** Une face compte-t-elle ? `gagnante` est soit LA face qui gagne (combat,
 *  Mind), soit l'ENSEMBLE des faces qui gagnent (dé rouge : `[5, 6]`) — dans
 *  les deux cas c'est le moteur qui a décidé, jamais ce composant. */
function estSucces(face, gagnante) {
    return Array.isArray(gagnante) ? gagnante.includes(face) : face === gagnante;
}

const attaque = computed(() =>
    (props.jet.atk ?? []).map((face) => ({
        face,
        succes: estSucces(face, props.jet.touchante ?? 'crane'),
    })),
);

const defense = computed(() =>
    (props.jet.def ?? []).map((face) => ({
        face,
        succes: estSucces(face, props.jet.defensive ?? 'bouclier_blanc'),
    })),
);

/** « 2 crânes » / « 1 bouclier » — le compte des SUCCÈS, pas des dés. */
function compte(n, singulier) {
    return `${n} ${singulier}${n > 1 ? 's' : ''}`;
}

const reussitesAttaque = computed(() => attaque.value.filter((d) => d.succes).length);
const reussitesDefense = computed(() => defense.value.filter((d) => d.succes).length);

/** Nom de LA face gagnante — un ensemble (dé rouge) n'a pas de face unique à
 *  nommer, il se compte en « réussite(s) ». Même dictionnaire des deux côtés :
 *  la face décide le mot, pas la ligne (attaque/défense) où elle apparaît —
 *  un jet de Mind gagne sur un crâne côté DÉFENSE, et doit quand même dire
 *  « crâne », pas « bouclier blanc » par défaut de ligne. */
function nomFace(face) {
    if (Array.isArray(face)) return 'réussite';
    if (face === 'crane') return 'crâne';
    return face === 'bouclier_noir' ? 'bouclier noir' : 'bouclier blanc';
}
const motTouchante = computed(() => nomFace(props.jet.touchante ?? 'crane'));
const motDefensive = computed(() => nomFace(props.jet.defensive ?? 'bouclier_blanc'));

/* Le VERBE de chaque ligne : « attaque »/« défend » pour un affrontement,
 * mais « résiste » pour un jet unilatéral (dé rouge, Mind) — publié par le
 * formateur serveur (`libelle_atk`/`libelle_def`), jamais deviné ici : ce
 * composant ne sait pas, à la seule vue des faces, qu'un jet est une
 * résistance plutôt qu'une attaque. */
const libelleAtk = computed(() => props.jet.libelle_atk || 'attaque');
const libelleDef = computed(() => props.jet.libelle_def || 'défend');
</script>

<template>
    <div class="jet-des" :class="{ compact }">
        <div v-if="attaque.length" class="jd-ligne">
            <span class="jd-label">
                <MSym n="swords" :size="compact ? 12 : 14" fill />
                <b>{{ jet.attaquant || 'Attaque' }}</b>
                <i>{{ libelleAtk }}</i>
            </span>
            <span class="jd-des">
                <span
                    v-for="(d, i) in attaque"
                    :key="'a' + i"
                    class="jd-de"
                    :class="[classeFace(d.face), { succes: d.succes }]"
                >
                    <span v-if="typeof d.face === 'number'" class="jd-de-pips" aria-hidden="true">
                        <i v-for="p in POINTS_D6[d.face] ?? []" :key="p" :class="'jd-pt' + p" />
                    </span>
                    <MSym v-else :n="icone(d.face)" :size="compact ? 13 : 20" fill />
                </span>
            </span>
            <span class="jd-somme">{{ compte(reussitesAttaque, motTouchante) }}</span>
        </div>

        <div v-if="defense.length" class="jd-ligne jd-defense">
            <span class="jd-label">
                <MSym n="shield" :size="compact ? 12 : 14" fill />
                <b>{{ jet.defenseur || 'Défense' }}</b>
                <i>{{ libelleDef }}</i>
            </span>
            <span class="jd-des">
                <span
                    v-for="(d, i) in defense"
                    :key="'d' + i"
                    class="jd-de"
                    :class="[classeFace(d.face), { succes: d.succes }]"
                >
                    <span v-if="typeof d.face === 'number'" class="jd-de-pips" aria-hidden="true">
                        <i v-for="p in POINTS_D6[d.face] ?? []" :key="p" :class="'jd-pt' + p" />
                    </span>
                    <MSym v-else :n="icone(d.face)" :size="compact ? 13 : 20" fill />
                </span>
            </span>
            <span class="jd-somme">{{ compte(reussitesDefense, motDefensive) }}</span>
        </div>
    </div>
</template>

<style scoped>
.jet-des { display: flex; flex-direction: column; gap: 8px; }
.jet-des.compact { gap: 3px; }

.jd-ligne { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.jet-des.compact .jd-ligne { gap: 6px; }

/* Le LABEL est la moitié du correctif : les deux rangées ne se distinguaient
   que par une opacité de 0.85, et rien ne disait laquelle appartenait à qui. */
.jd-label {
    display: inline-flex; align-items: center; gap: 4px;
    min-width: 104px;
    font-size: 11.5px; letter-spacing: 0.02em;
    color: var(--ink-300, #b6a88a);
}
.jet-des.compact .jd-label { min-width: 88px; font-size: 10.5px; }
.jd-label b { color: var(--ink-100, #f3e9d6); font-weight: 700; }
.jd-label i { font-style: normal; opacity: 0.62; }

.jd-des { display: inline-flex; gap: 5px; flex-wrap: wrap; }
.jet-des.compact .jd-des { gap: 3px; }

.jd-de {
    display: grid; place-items: center;
    width: 30px; height: 30px;
    border-radius: 7px;
    border: 2px solid transparent;
    background: rgba(255, 255, 255, 0.06);
}
.jet-des.compact .jd-de { width: 20px; height: 20px; border-radius: 5px; border-width: 1.5px; }

/* Couleur de la FACE (ce que le dé montre) — indépendante du succès. */
.jd-de.f-crane { color: #e8ddc8; }
.jd-de.f-blanc { color: #dfe6ee; }
.jd-de.f-noir { color: #8e93a3; background: rgba(0, 0, 0, 0.34); }

/* Dé ROUGE de résistance : même rouge que le dé de déplacement de la scène de
 * table (`SceneEvenement.vue` .scn-d6) — un dé physique se reconnaît partout
 * dans l'appli, il n'a pas deux couleurs selon l'écran. */
.jd-de.f-rouge {
    background: linear-gradient(155deg, var(--body-bright, #c9524a), var(--body, #a83f3f));
    color: var(--parch-100, #f3e9d6);
    border-color: rgba(0, 0, 0, 0.25);
}
.jd-de-pips {
    display: grid; grid-template-columns: repeat(3, 1fr); grid-template-rows: repeat(3, 1fr);
    width: 100%; height: 100%; padding: 5px; box-sizing: border-box;
}
.jet-des.compact .jd-de-pips { padding: 3px; }
.jd-de-pips i {
    width: 4px; height: 4px; border-radius: 50%; place-self: center;
    background: currentColor;
}
.jet-des.compact .jd-de-pips i { width: 3px; height: 3px; }
.jd-pt1 { grid-area: 1 / 1; } .jd-pt3 { grid-area: 1 / 3; }
.jd-pt4 { grid-area: 2 / 1; } .jd-pt5 { grid-area: 2 / 2; } .jd-pt6 { grid-area: 2 / 3; }
.jd-pt7 { grid-area: 3 / 1; } .jd-pt9 { grid-area: 3 / 3; }

/* Le VERT dit « ce dé a compté ». Un anneau, pas un remplissage : la face
   reste lisible, et un dé raté reste visible plutôt que d'être effacé. */
.jd-de.succes {
    border-color: #4ade80;
    background: rgba(74, 222, 128, 0.16);
    box-shadow: 0 0 0 1px rgba(74, 222, 128, 0.35), 0 0 10px rgba(74, 222, 128, 0.25);
}
.jd-de.succes.f-noir { color: #d7f7e2; }

.jd-somme {
    font-size: 11.5px; font-weight: 700;
    color: #86efac;
    margin-left: auto;
}
.jet-des.compact .jd-somme { font-size: 10.5px; }
</style>
