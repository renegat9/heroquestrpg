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
import MSym from '../ui/MSym.vue';

defineProps({
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
});

const emit = defineEmits(['close']);

function onOverlayClick(e) {
    if (e.target.classList.contains('overlay')) emit('close');
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
                <p v-if="description" class="info-desc">{{ description }}</p>

                <ul v-if="avantages.length" class="info-liste">
                    <li v-for="(a, i) in avantages" :key="i">
                        <MSym n="chevron_right" :size="16" /><span>{{ a }}</span>
                    </li>
                </ul>

                <p v-if="!description && !avantages.length" class="info-vide">
                    Aucun détail connu pour cette pièce.
                </p>
            </div>

            <button class="btn btn-ghost btn-block" type="button" @click="emit('close')">Fermer</button>
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
</style>
