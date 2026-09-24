<script setup>
// Feuille de DÉTAIL générique — objets et capacités (René, 2026-09-04 : « il
// faudrait pouvoir voir le détail des items, capacité et sorts quand on les
// affiche »).
//
// ⚠ Volontairement SÉPARÉE de SpellInfoSheet, qui reste spécifique aux sorts :
// celle-ci ne connaît ni élément, ni disponibilité, ni bouton « Lancer ». Les
// fondre aurait donné un composant qui accepte tout et n'affiche rien de précis.
//
// ⚠ Elle n'invente aucun texte : `avantages` vient du SERVEUR
// (MotsClesEquipement::avantages), qui traduit l'`effet` de la pièce. Une table
// d'affichage côté client dérive de la donnée qu'elle décrit — les talents
// l'ont déjà payé, et leurs chiffres avaient disparu sans que personne ne le
// remarque.
//
// ---- Forge du Nain (René, 2026-09-18 : « ajoute la modification dans les
// détails d'un objet qui peut être amélioré [...] sans oublier d'afficher
// l'amélioration faite aux objets déjà forgés ») ----
// `ameliorations` (déjà posées) et `forgeable`/`forgeCatalogue` viennent
// TOUS les trois du serveur (`/moi`, `AuthController::detailForge()`) : ni
// « suis-je au hub », ni « ai-je un nain », ni « cet objet est un artefact »
// ne se recalculent ici — la fiche lit la décision, elle ne la rejuge pas.
// La confirmation reprend le patron déjà en place dans ce dossier (tir ami de
// CibleSheet, « jeter » de ChoixListeSheet) : un bandeau d'avertissement puis
// un bouton `btn-danger` de confirmation et un `btn-ghost` d'annulation.
import { computed, ref } from 'vue';
import MSym from '../ui/MSym.vue';

const props = defineProps({
    /** Titre : nom de l'objet ou de la capacité. */
    titre: { type: String, required: true },
    /** Ligne d'exergue : catégorie, rareté, emplacement… */
    sousTitre: { type: String, default: '' },
    /** Icône Material à afficher dans la pastille. */
    icone: { type: String, default: 'inventory_2' },
    /** Phrase libre (description écrite à la main — les talents en ont une). */
    description: { type: String, default: '' },
    /** Phrases DÉRIVÉES de l'effet, une par mécanique. */
    avantages: { type: Array, default: () => [] },
    /** Ligne d'inventaire concernée (nécessaire pour forger). */
    inventaireId: { type: [Number, String], default: null },
    /** Améliorations de Forge DÉJÀ posées : [{nom, avantages}]. */
    ameliorations: { type: Array, default: () => [] },
    /** DÉCISION serveur : cette pièce est-elle forgeable, maintenant, par le
     *  joueur connecté ? (hub, forgeron à lui, rareté, pas déjà améliorée). */
    forgeable: { type: Boolean, default: false },
    /** Liste blanche : améliorations compatibles ET câblées pour cette pièce
     *  — [{id, nom, prix, avantages}], exactement ce que `POST /forge`
     *  acceptera pour `amelioration_id`. */
    forgeCatalogue: { type: Array, default: () => [] },
    /** Bourse commune (EtatGroupe.groupe.or) — pour griser une option trop
     *  chère, comme `RecrutementHub` le fait déjà pour les mercenaires. */
    orCommun: { type: Number, default: null },
});

const emit = defineEmits(['close', 'forger']);

function onOverlayClick(e) {
    if (e.target.classList.contains('overlay')) emit('close');
}

/* Option choisie, en attente de confirmation — remplace le corps de la
   feuille par le bandeau d'avertissement, comme le tir ami de CibleSheet. */
const optionAConfirmer = ref(null);

function tropCher(opt) {
    return Number.isFinite(props.orCommun) && props.orCommun < opt.prix;
}

const toutesTropCheres = computed(() =>
    props.forgeCatalogue.length > 0 && props.forgeCatalogue.every(tropCher));

function confirmerForge() {
    if (!optionAConfirmer.value) return;
    emit('forger', { inventaireId: props.inventaireId, ameliorationId: optionAConfirmer.value.id });
}
</script>

<template>
    <div class="overlay" @click="onOverlayClick">
        <div class="sheet info-sheet">
            <div class="grip" />

            <div class="info-head">
                <span class="info-ic"><MSym :n="icone" fill :size="26" /></span>
                <div class="info-titles">
                    <h3>{{ titre }}</h3>
                    <div v-if="sousTitre" class="info-sub">{{ sousTitre }}</div>
                </div>
            </div>

            <!-- ⚠ Le CORPS défile, le bouton non : une pièce d'artefact porte
                 jusqu'à huit phrases, et « Fermer » ne doit jamais quitter
                 l'écran. Même règle que le prologue, corrigée le même jour. -->
            <div class="info-corps">
                <!-- confirmation de Forge — même patron que le tir ami
                     (CibleSheet) et le « jeter » définitif (ChoixListeSheet) :
                     un bandeau d'avertissement, un bouton danger, un bouton
                     ghost pour revenir en arrière sans rien envoyer. -->
                <template v-if="optionAConfirmer">
                    <div class="info-forge-warn">
                        <MSym n="warning" fill :size="22" />
                        <span>
                            Forger <b>{{ titre }}</b> avec <b>{{ optionAConfirmer.nom }}</b> coûte
                            <b>{{ optionAConfirmer.prix }} or</b> à la bourse commune — définitif,
                            cette pièce ne pourra plus être améliorée ensuite. Confirmer ?
                        </span>
                    </div>
                    <button class="btn btn-danger btn-block" style="margin-top: 14px" @click="confirmerForge">
                        <MSym n="hardware" fill :size="18" /> Confirmer — forger {{ optionAConfirmer.nom }}
                    </button>
                    <button class="btn btn-ghost btn-block" style="margin-top: 8px" @click="optionAConfirmer = null">
                        Choisir une autre amélioration
                    </button>
                </template>

                <template v-else>
                    <p v-if="description" class="info-desc">{{ description }}</p>

                    <ul v-if="avantages.length" class="info-liste">
                        <li v-for="(a, i) in avantages" :key="i">
                            <MSym n="chevron_right" :size="16" /><span>{{ a }}</span>
                        </li>
                    </ul>

                    <p v-if="!description && !avantages.length && !ameliorations.length" class="info-vide">
                        Aucun détail connu pour cette pièce.
                    </p>

                    <!-- amélioration(s) de Forge DÉJÀ posées — visible même
                         pour un joueur qui n'a pas le nain, et même sur une
                         pièce du sac (René, 2026-09-18). -->
                    <template v-if="ameliorations.length">
                        <div class="info-forge-titre"><MSym n="hardware" fill :size="15" /> Amélioration de Forge</div>
                        <ul class="info-liste info-forge-faites">
                            <li v-for="(a, i) in ameliorations" :key="i">
                                <MSym n="check_circle" fill :size="16" />
                                <span><b>{{ a.nom }}</b><template v-if="a.avantages?.length"> — {{ a.avantages.join(', ') }}</template></span>
                            </li>
                        </ul>
                    </template>

                    <!-- Forger : la liste blanche QUE le serveur a déjà filtrée
                         (catégorie compatible ET mécanique câblée) — jamais une
                         option que `POST /forge` refuserait. -->
                    <template v-if="forgeable && forgeCatalogue.length">
                        <div class="info-forge-titre"><MSym n="hardware" fill :size="15" /> Forger une amélioration</div>
                        <button
                            v-for="opt in forgeCatalogue" :key="opt.id"
                            type="button" class="info-forge-opt"
                            :disabled="tropCher(opt)"
                            :title="tropCher(opt) ? 'Bourse commune insuffisante' : `Forger ${opt.nom}`"
                            @click="optionAConfirmer = opt"
                        >
                            <span class="info-forge-nom">{{ opt.nom }}</span>
                            <span v-if="opt.avantages?.length" class="info-forge-avantages">{{ opt.avantages.join(', ') }}</span>
                            <span class="info-forge-prix"><MSym n="paid" fill :size="13" /> {{ opt.prix }}</span>
                        </button>
                        <p v-if="toutesTropCheres" class="info-forge-note">
                            <MSym n="warning" :size="14" /> Bourse commune insuffisante ({{ orCommun }} or).
                        </p>
                    </template>
                </template>
            </div>

            <button v-if="!optionAConfirmer" class="btn btn-ghost btn-block" type="button" @click="emit('close')">Fermer</button>
        </div>
    </div>
</template>

<style>
.info-sheet { display: flex; flex-direction: column; gap: 14px; max-height: calc(100dvh - 48px); }

.info-head { display: flex; align-items: center; gap: 12px; }
.info-ic { width: 46px; height: 46px; border-radius: 13px; display: grid; place-items: center;
  background: linear-gradient(150deg, var(--ember), var(--ember-deep)); color: var(--parch-100);
  flex-shrink: 0; }
.info-titles h3 { margin: 0; font-family: var(--font-display); font-size: 19px; }
.info-sub { font-size: 12.5px; color: var(--ink-500); margin-top: 2px; text-transform: capitalize; }

/* `min-height: 0` : sans lui l'enfant de flex refuse de rétrécir, la feuille
   repousse son propre plafond et rien ne défile. */
.info-corps { min-height: 0; overflow-y: auto; overscroll-behavior: contain; }

.info-desc { margin: 0 0 10px; font-size: 14px; line-height: 1.55; color: var(--ink-200); }
.info-liste { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 7px; }
.info-liste li { display: flex; align-items: flex-start; gap: 6px; font-size: 13.5px;
  line-height: 1.45; color: var(--ink-200); }
.info-liste .msym { color: var(--torch); flex-shrink: 0; margin-top: 1px; }
.info-vide { margin: 0; font-size: 13px; color: var(--ink-500); font-style: italic; }

/* ---- Forge du Nain : amélioration déjà posée + catalogue à forger ---- */
.info-forge-titre { display: flex; align-items: center; gap: 6px; margin: 16px 0 8px;
  font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
  color: var(--gold, #c9a24a); }
.info-forge-titre:first-child { margin-top: 0; }
.info-forge-faites .msym { color: var(--gold, #c9a24a); }
.info-forge-opt {
    width: 100%; display: flex; align-items: center; gap: 8px; text-align: left;
    margin-bottom: 8px; padding: 10px 12px; border-radius: 10px; cursor: pointer;
    border: 1px solid var(--line-soft, oklch(0.4 0.02 70 / 0.4));
    background: var(--panel-2, oklch(0.24 0.02 70 / 0.45)); color: var(--ink-100);
    font: inherit;
}
.info-forge-opt:disabled { opacity: 0.5; cursor: default; }
.info-forge-nom { font-weight: 700; font-size: 13.5px; }
.info-forge-avantages { flex: 1; min-width: 0; font-size: 12px; color: var(--ink-400); }
.info-forge-prix { flex-shrink: 0; display: flex; align-items: center; gap: 4px;
  font-weight: 800; font-size: 13px; color: var(--gold, #c9a24a); }
.info-forge-note { display: flex; align-items: center; gap: 6px; margin: 2px 0 0;
  font-size: 12px; color: var(--ink-500); }
.info-forge-warn { display: flex; align-items: center; gap: 10px; padding: 12px 14px;
  border-radius: var(--r-md); font-size: 13px; color: var(--parch-100);
  background: oklch(0.6 0.2 25 / 0.14); border: 1px solid oklch(0.6 0.2 25 / 0.5); }
.info-forge-warn .msym { color: var(--danger); flex: none; }
</style>
