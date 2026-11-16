<script setup>
// MANETTE À PLUSIEURS HÉROS (reste du lot M2) — CONFIER un héros à un autre joueur de la table, depuis le hub.
//
// Un héros a un propriétaire ; son pilote est le joueur qui le joue. Un joueur absent laisse son héros à un présent.
//
// ⚠ Rien ici n'est décidé. Le serveur publie, dans `groupe.prets[]`, le propriétaire de chaque héros
// (`proprietaire_joueur_id`) et s'il est confié (`confie`), et dans `groupe.pilotes_possibles` la liste des joueurs à
// qui un héros peut être confié — c'est la règle même que `PUT /pilote` applique. Ce composant rend ce qu'il reçoit :
// il ne calcule ni l'appartenance ni le pilote. Son seul filtre, « pas le joueur de cette manette », est une identité.
import { computed, ref } from 'vue';
import MSym from '../ui/MSym.vue';

const props = defineProps({
    // Les héros dont CE joueur est le propriétaire : {personnage_id, nom, pilote_joueur_id, confie}.
    herosPossedes: { type: Array, default: () => [] },
    // `groupe.pilotes_possibles` : [{joueur_id, pseudo}].
    pilotesPossibles: { type: Array, default: () => [] },
    // Le joueur de cette manette : on ne se confie pas un héros à soi-même.
    monJoueurId: { type: Number, default: null },
    // Un envoi est en cours (gèle les boutons).
    enCours: { type: Boolean, default: false },
});
const emit = defineEmits(['confier']);

// Un seul déroulé ouvert à la fois : l'id du héros dont on choisit le pilote.
const ouvertPour = ref(null);

const candidats = computed(() => props.pilotesPossibles.filter((j) => j.joueur_id !== props.monJoueurId));

function pseudoDe(joueurId) {
    return props.pilotesPossibles.find((j) => j.joueur_id === joueurId)?.pseudo ?? 'un autre joueur';
}

function basculer(h) {
    ouvertPour.value = ouvertPour.value === h.personnage_id ? null : h.personnage_id;
}

function choisir(h, joueur) {
    ouvertPour.value = null;
    emit('confier', { personnageId: h.personnage_id, piloteJoueurId: joueur.joueur_id });
}

function reprendre(h) {
    ouvertPour.value = null;
    emit('confier', { personnageId: h.personnage_id, piloteJoueurId: null });
}
</script>

<template>
    <div v-if="herosPossedes.length" class="confier-bloc">
        <p class="confier-aide">
            Absent ? Confiez votre héros à un joueur présent : il le jouera à votre place.
        </p>
        <div v-for="h in herosPossedes" :key="h.personnage_id" class="confier-ligne">
            <div class="confier-tete">
                <span class="confier-nom">{{ h.nom }}</span>
                <span v-if="h.confie" class="confier-etat">
                    <MSym n="swap_horiz" :size="14" /> Confié à {{ pseudoDe(h.pilote_joueur_id) }}
                </span>
                <button
                    v-if="h.confie"
                    type="button"
                    class="confier-btn"
                    :disabled="enCours"
                    @click="reprendre(h)"
                >
                    Reprendre
                </button>
                <button
                    v-else-if="candidats.length"
                    type="button"
                    class="confier-btn"
                    :disabled="enCours"
                    :aria-expanded="ouvertPour === h.personnage_id ? 'true' : 'false'"
                    @click="basculer(h)"
                >
                    Confier
                </button>
            </div>
            <div v-if="!h.confie && ouvertPour === h.personnage_id" class="confier-choix">
                <span class="confier-lbl">Confier {{ h.nom }} à</span>
                <button
                    v-for="j in candidats"
                    :key="j.joueur_id"
                    type="button"
                    class="confier-btn"
                    :disabled="enCours"
                    @click="choisir(h, j)"
                >
                    {{ j.pseudo }}
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Classes préfixées `confier-` : les blocs <style> du projet sont souvent GLOBAUX. Le style des boutons reprend
   celui de `sac-btn ghost` (SacTab), qui est SCOPÉ à son composant et ne s'applique donc pas ici. */
.confier-bloc { display: grid; gap: 8px; margin: 8px 0 4px; }
.confier-aide { margin: 0; font-size: 12px; color: var(--ink-500, #8d8574); }
.confier-ligne { padding: 8px 10px; border-radius: 9px; background: var(--panel-2, oklch(0.28 0.02 70 / 0.5)); }
.confier-tete { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.confier-nom { font-weight: 700; font-size: 14px; color: var(--parch-100, #f1e6cf); }
.confier-etat { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: var(--gold, #c9a24a); }
.confier-choix { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 8px; }
.confier-lbl { font-size: 12px; color: var(--ink-300, #cfc3ad); }
.confier-btn {
    margin-left: auto;
    padding: 6px 12px;
    border-radius: 9px;
    font: inherit;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    background: transparent;
    color: var(--ink-300, #cfc3ad);
    border: 1px solid var(--line-soft, oklch(0.4 0.02 70 / 0.5));
}
.confier-btn:disabled { opacity: 0.5; cursor: default; }
.confier-choix .confier-btn { margin-left: 0; }
</style>
