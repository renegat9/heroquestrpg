<script setup>
// FEUILLE DE SOUS-CHOIX (René, 2026-09-01) — le DEUXIÈME niveau du menu.
//
// Une option d'action ne porte plus un sort, un parchemin ou un objet : elle
// porte la LISTE de ceux qui sont jouables, et c'est ici qu'on en choisit un.
// Le menu d'action retrouve ainsi les « 2 à 5 options claires » du doc 13 §3.1,
// que neuf boutons « Lancer … » faisaient exploser.
//
// ⚠ Le bouton de retour NOMME sa destination (« Retour aux actions »). Un
// retour qui ne dit pas où il mène oblige à l'essayer pour le savoir.
//
// ⚠ Un tap sur le fond DÉPILE d'un cran, il ne ferme pas tout : le geste le
// plus facile ne doit pas être le plus destructeur.
import { computed } from 'vue';
import ChoiceCard from './ChoiceCard.vue';
import MSym from '../ui/MSym.vue';
import { elementInfo, TYPES_SORT } from '../../store/game.js';

const props = defineProps({
    /** Frame de la pile : { option, titre, entrees, grouper, retour }. */
    feuille: { type: Object, required: true },
});
const emit = defineEmits(['choisir', 'retour']);

/** Groupé par ÉLÉMENT pour les sorts — neuf lignes d'affilée sans repère se
 *  lisent mal, et le magicien retrouve le répertoire qu'il a choisi. */
const groupes = computed(() => {
    const entrees = props.feuille.entrees ?? [];

    if (! props.feuille.grouper) {
        return [{ cle: null, libelle: null, entrees }];
    }

    const par = new Map();

    for (const e of entrees) {
        const cle = e.element ?? 'autre';
        if (! par.has(cle)) { par.set(cle, []); }
        par.get(cle).push(e);
    }

    return [...par.entries()].map(([cle, liste]) => ({
        cle,
        libelle: elementInfo(cle)?.l ?? null,
        ic: elementInfo(cle)?.ic ?? null,
        entrees: liste,
    }));
});

/** Ligne d'information sous le nom : ce que l'entrée coûte ou ce qu'elle est. */
function meta(entree) {
    if (entree.disponible === false) {
        return 'Épuisé — redevient lançable à la prochaine quête';
    }

    // Le coût n'est affiché que pour les objets, dont la liste MÊLE le gratuit
    // et le payant : c'est la seule liste où il change d'une ligne à l'autre.
    if (entree.cout) {
        const cout = entree.cout === 'action' ? "coûte l'action" : 'gratuit';

        return entree.detail ? `${entree.detail} · ${cout}` : cout;
    }

    return entree.detail ?? TYPES_SORT[entree.sort_type]?.l ?? '';
}

function carte(entree) {
    const el = entree.element ? elementInfo(entree.element) : null;

    return {
        icon: el?.ic ?? (entree.cout ? 'backpack' : 'auto_awesome'),
        elClass: el ? `el-${el.cle ?? entree.element}` : '',
        badge: entree.quantite > 1 ? `×${entree.quantite}` : '',
        disabled: entree.disponible === false,
    };
}
</script>

<template>
    <div class="overlay" @click.self="emit('retour')">
        <div class="sheet">
            <div class="grip" />
            <h3>{{ feuille.titre }}</h3>
            <p class="sh-sub">{{ feuille.option.libelle }}</p>

            <template v-for="g in groupes" :key="g.cle ?? 'tout'">
                <div v-if="g.libelle" class="cl-groupe">
                    <MSym v-if="g.ic" :n="g.ic" :size="14" fill /> {{ g.libelle }}
                </div>
                <ChoiceCard
                    v-for="e in g.entrees"
                    :key="e.cle"
                    :icon="carte(e).icon"
                    :el-class="carte(e).elClass"
                    :badge="carte(e).badge"
                    :disabled="carte(e).disabled"
                    :title="e.nom"
                    :meta="meta(e)"
                    @click="emit('choisir', e)"
                />
            </template>

            <button class="btn btn-ghost btn-block cl-retour" type="button" @click="emit('retour')">
                <MSym n="arrow_back" :size="18" /> {{ feuille.retour }}
            </button>
        </div>
    </div>
</template>

<style scoped>
.cl-groupe {
    display: flex; align-items: center; gap: 6px;
    margin: 14px 0 8px; font-size: 11px; font-weight: 800;
    letter-spacing: 0.09em; text-transform: uppercase; color: var(--torch);
}
.cl-groupe:first-of-type { margin-top: 6px; }
.cl-retour { margin-top: 14px; }
</style>
