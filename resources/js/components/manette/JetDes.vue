<script setup>
/*
 * Un JET COMPLET : la volée de l'attaquant et celle du défenseur, chacune
 * nommée, chaque dé gagnant entouré de vert.
 *
 * Rendu unique, deux emplois : l'overlay de révélation (grand, ~3 s après SON
 * action) et le fil du combat (petit, permanent — l'historique). Deux rendus
 * séparés auraient dérivé, et c'est justement une divergence de ce genre qui a
 * produit le défaut d'origine.
 *
 * ⚠ Le succès n'est PAS une propriété du dé, mais du couple (dé, lanceur) :
 * un bouclier blanc pare pour un héros et ne pare rien pour un monstre ; un
 * crâne touche, sauf contre un éthéré où c'est le bouclier noir. On ne redéduit
 * donc rien ici — le moteur envoie `touchante` / `defensive` avec le jet
 * (App\Engine\ResultatAttaque::pourJournal) et on ne fait que comparer.
 */
import { computed } from 'vue';
import MSym from '../ui/MSym.vue';

const props = defineProps({
    /** {atk, def, touchante, defensive, attaquant, defenseur, touches, boucliers} */
    jet: { type: Object, required: true },
    /** Version compacte pour le fil du combat. */
    compact: { type: Boolean, default: false },
});

/** Icône d'une face brute du moteur — la couleur du bouclier est conservée. */
function icone(face) {
    if (face === 'crane') return 'skull';
    return 'shield';
}

function classeFace(face) {
    if (face === 'crane') return 'f-crane';
    return face === 'bouclier_noir' ? 'f-noir' : 'f-blanc';
}

const attaque = computed(() =>
    (props.jet.atk ?? []).map((face) => ({
        face,
        succes: face === (props.jet.touchante ?? 'crane'),
    })),
);

const defense = computed(() =>
    (props.jet.def ?? []).map((face) => ({
        face,
        succes: face === (props.jet.defensive ?? 'bouclier_blanc'),
    })),
);

/** « 2 crânes » / « 1 bouclier » — le compte des SUCCÈS, pas des dés. */
function compte(n, singulier) {
    return `${n} ${singulier}${n > 1 ? 's' : ''}`;
}

const reussitesAttaque = computed(() => attaque.value.filter((d) => d.succes).length);
const reussitesDefense = computed(() => defense.value.filter((d) => d.succes).length);

/** Nom de la face gagnante, pour que le joueur sache ce qu'il cherche. */
const motTouchante = computed(() => (props.jet.touchante === 'bouclier_noir' ? 'bouclier noir' : 'crâne'));
const motDefensive = computed(() => (props.jet.defensive === 'bouclier_noir' ? 'bouclier noir' : 'bouclier blanc'));
</script>

<template>
    <div class="jet-des" :class="{ compact }">
        <div v-if="attaque.length" class="jd-ligne">
            <span class="jd-label">
                <MSym n="swords" :size="compact ? 12 : 14" fill />
                <b>{{ jet.attaquant || 'Attaque' }}</b>
                <i>attaque</i>
            </span>
            <span class="jd-des">
                <span
                    v-for="(d, i) in attaque"
                    :key="'a' + i"
                    class="jd-de"
                    :class="[classeFace(d.face), { succes: d.succes }]"
                >
                    <MSym :n="icone(d.face)" :size="compact ? 13 : 20" fill />
                </span>
            </span>
            <span class="jd-somme">{{ compte(reussitesAttaque, motTouchante) }}</span>
        </div>

        <div v-if="defense.length" class="jd-ligne jd-defense">
            <span class="jd-label">
                <MSym n="shield" :size="compact ? 12 : 14" fill />
                <b>{{ jet.defenseur || 'Défense' }}</b>
                <i>défend</i>
            </span>
            <span class="jd-des">
                <span
                    v-for="(d, i) in defense"
                    :key="'d' + i"
                    class="jd-de"
                    :class="[classeFace(d.face), { succes: d.succes }]"
                >
                    <MSym :n="icone(d.face)" :size="compact ? 13 : 20" fill />
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
