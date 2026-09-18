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
import { computed, ref, watch } from 'vue';
import ChoiceCard from './ChoiceCard.vue';
import MSym from '../ui/MSym.vue';
import { elementInfo, TYPES_SORT } from '../../store/game.js';

const props = defineProps({
    /** Frame de la pile : { option, titre, entrees, grouper, confirmer?, retour }. */
    feuille: { type: Object, required: true },
});
const emit = defineEmits(['choisir', 'retour']);

/* `jeter` DÉTRUIT l'objet sans rien rendre (aucune couche d'objets posés au
   sol, précédent : l'arme lancée est supprimée) — le seul geste du jeu qui
   détruit de la valeur, donc la manette confirme avant d'envoyer. Repris au
   plus près du patron de confirmation « tir ami » de CibleSheet (bandeau
   d'avertissement, bouton danger, bouton d'annulation) plutôt qu'un second
   patron : ce niveau-ci, et non le ciblage, puisque `jeter` n'a pas de cibles. */
const entreeAConfirmer = ref(null);
/* Palier de QUANTITÉ (révision R2, 2026-09-17) — s'intercale AVANT la
   confirmation quand la pile compte plus d'un exemplaire. `max` vient
   TOUJOURS de `entree.quantite`, publié par le serveur : jamais recalculé
   ici, et re-validé de toute façon à la résolution contre la ligne en base
   (un champ numérique est la plus facile des whitelists à contourner).
   `etape` distingue les deux paliers : une pile à l'unité saute directement
   à la confirmation, comme avant ce correctif. */
const etape = ref(null); // null | 'quantite' | 'confirmer'
const quantiteChoisie = ref(1);
watch(() => props.feuille, () => { entreeAConfirmer.value = null; etape.value = null; quantiteChoisie.value = 1; });

function choisir(entree) {
    if (!props.feuille.confirmer) {
        emit('choisir', entree);
        return;
    }
    entreeAConfirmer.value = entree;
    quantiteChoisie.value = 1;
    etape.value = (entree.quantite ?? 1) > 1 ? 'quantite' : 'confirmer';
}

/** Borne le champ (min 1, max la pile) avant de passer à la confirmation —
 *  la frappe libre peut dépasser `max`, la saisie ne doit pas. */
function validerQuantite() {
    const max = entreeAConfirmer.value.quantite ?? 1;
    quantiteChoisie.value = Math.max(1, Math.min(max, Math.round(Number(quantiteChoisie.value)) || 1));
    etape.value = 'confirmer';
}

function annulerConfirmation() {
    entreeAConfirmer.value = null;
    etape.value = null;
}

/** Envoi final : la quantité choisie voyage AVEC l'entrée — c'est elle qui dit
 *  combien part, pas seulement quoi (le payload qui mentait, corrigé ici). */
function envoyerConfirmation() {
    emit('choisir', { ...entreeAConfirmer.value, quantite_choisie: quantiteChoisie.value });
}

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
        const base = entree.detail ? `${entree.detail} · ${cout}` : cout;

        // ⚠ CE QUE LA PIÈCE FAIT, à l'endroit où on en a le plus besoin : cette
        // liste se consulte EN PLEIN TOUR. On n'y met que la première phrase —
        // un artefact en porte jusqu'à huit, et une carte de choix qui déborde
        // n'aide plus personne ; le sac reste l'endroit où tout lire.
        const quoi = (entree.avantages ?? [])[0];

        return quoi ? `${base} · ${quoi}` : base;
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

            <!-- palier de QUANTITÉ (R2) — avant la confirmation, seulement si
                 la pile compte plus d'un exemplaire. -->
            <template v-if="etape === 'quantite'">
                <h3><MSym n="inventory_2" :size="20" style="vertical-align: -3px" /> Combien en jeter ?</h3>
                <p class="sh-sub">{{ entreeAConfirmer.nom }} — tu en portes {{ entreeAConfirmer.quantite }}.</p>
                <div class="cl-qte">
                    <button
                        type="button" class="btn btn-ghost btn-sm"
                        :disabled="quantiteChoisie <= 1"
                        @click="quantiteChoisie = Math.max(1, quantiteChoisie - 1)"
                    ><MSym n="remove" /></button>
                    <input
                        v-model.number="quantiteChoisie"
                        class="cl-qte-champ"
                        type="number" inputmode="numeric"
                        min="1" :max="entreeAConfirmer.quantite"
                    >
                    <button
                        type="button" class="btn btn-ghost btn-sm"
                        :disabled="quantiteChoisie >= entreeAConfirmer.quantite"
                        @click="quantiteChoisie = Math.min(entreeAConfirmer.quantite, quantiteChoisie + 1)"
                    ><MSym n="add" /></button>
                </div>
                <button class="btn btn-torch btn-block" style="margin-top: 14px" @click="validerQuantite">
                    Suivant
                </button>
                <button class="btn btn-ghost btn-block" style="margin-top: 8px" @click="annulerConfirmation">
                    Choisir un autre objet
                </button>
            </template>

            <!-- confirmation « jeter » (destruction définitive) — même patron
                 que le tir ami de CibleSheet, remonté d'un niveau puisque
                 `jeter` n'ouvre pas de troisième niveau (pas de cibles). -->
            <template v-else-if="etape === 'confirmer'">
                <h3><MSym n="warning" fill :size="20" style="color: var(--danger); vertical-align: -3px" /> Jeter — définitif</h3>
                <p class="sh-sub">{{ feuille.option.libelle }} — cette pièce est détruite, pas posée au sol.</p>
                <div class="cl-detruit-warn">
                    <MSym n="warning" fill :size="22" />
                    <!-- Pas d'accord de genre sur le nom de l'objet (masculin ou
                         féminin selon la pièce, jamais connu ici) : la phrase
                         tourne autour, comme le tir ami tourne autour de « subira »
                         plutôt que d'accorder un participe sur la cible.
                         ⚠ Le NOMBRE qui part est dit ici (R2) : le payload mentait
                         avant ce correctif (`×3` affiché, un seul exemplaire
                         détruit) — la confirmation doit dire COMBIEN, pas
                         seulement quoi. -->
                    <span>
                        Jeter <b v-if="quantiteChoisie > 1">{{ quantiteChoisie }} ×</b>
                        <b>{{ entreeAConfirmer.nom }}</b> ne rend rien : ça disparaît du sac pour toujours. Confirmer ?
                    </span>
                </div>
                <button class="btn btn-danger btn-block" style="margin-top: 14px" @click="envoyerConfirmation">
                    <MSym n="warning" fill :size="18" />
                    Confirmer — jeter <template v-if="quantiteChoisie > 1">{{ quantiteChoisie }} × </template>{{ entreeAConfirmer.nom }}
                </button>
                <button class="btn btn-ghost btn-block" style="margin-top: 8px" @click="annulerConfirmation">
                    Choisir un autre objet
                </button>
            </template>

            <!-- liste des entrées -->
            <template v-else>
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
                        @click="choisir(e)"
                    />
                </template>

                <button class="btn btn-ghost btn-block cl-retour" type="button" @click="emit('retour')">
                    <MSym n="arrow_back" :size="18" /> {{ feuille.retour }}
                </button>
            </template>
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
/* Bandeau de confirmation « jeter » — mêmes propriétés que `.ami-warn` de
   CibleSheet (mais scopé ici, préfixe `cl-` de ce fichier) : un seul patron
   visuel de confirmation dans toute la manette. */
.cl-detruit-warn { display: flex; align-items: center; gap: 10px; padding: 12px 14px; border-radius: var(--r-md);
  font-size: 13px; color: var(--parch-100); background: oklch(0.6 0.2 25 / 0.14); border: 1px solid oklch(0.6 0.2 25 / 0.5); }
.cl-detruit-warn .msym { color: var(--danger); flex: none; }
/* Palier de quantité (R2) — stepper + champ numérique centré, même famille
   visuelle que les boutons `.btn-ghost` déjà globaux. Préfixé `cl-` (fichier
   scoped, mais le nom générique `.qte` traînait déjà ailleurs dans le projet). */
.cl-qte { display: flex; align-items: center; justify-content: center; gap: 14px; margin: 10px 0 4px; }
.cl-qte-champ {
    width: 84px; padding: 10px 6px; text-align: center;
    font-size: 22px; font-weight: 800; font-variant-numeric: tabular-nums;
    color: var(--ink-100); background: var(--stone-850); border: var(--line-strong);
    border-radius: var(--r-md);
}
/* Masque les flèches natives du <input type=number> : le stepper +/- fait
   déjà ce travail, deux contrôles pour un seul geste prêteraient à confusion. */
.cl-qte-champ::-webkit-outer-spin-button, .cl-qte-champ::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.cl-qte-champ { appearance: textfield; }
</style>
