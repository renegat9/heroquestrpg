<script setup>
/*
 * Réaction HORS TOUR — la seule feuille qui s'ouvre alors que ce n'est PAS
 * votre tour.
 *
 * *Dark Wings* (Warlock) et *Twisting Torrent* (Moine) s'activent quand leur
 * porteur encaisse, c'est-à-dire pendant le tour d'un monstre. Le moteur ne
 * pouvant pas suspendre sa résolution pour attendre un téléphone, le coup est
 * appliqué puis la question posée : c'est exactement l'ordre de la table, où
 * l'on annonce les dégâts avant que le joueur dise « j'annule ».
 *
 * D'où le ton du libellé : on ne demande pas « veux-tu te protéger ? » mais
 * « annuler ce qui vient d'arriver ? ». Le compte à rebours est affiché parce
 * que la fenêtre est courte et que la partie continue pendant ce temps —
 * laisser filer sans le dire serait perdre un pouvoir sans comprendre pourquoi.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import MSym from '../ui/MSym.vue';

const props = defineProps({
    /** {personnage_id, sort, description, source, degats, expire_dans} */
    reaction: { type: Object, required: true },
    /** Envoi en cours : on gèle les deux boutons. */
    pending: { type: Boolean, default: false },
});

const emit = defineEmits(['repondre']);

const restant = ref(props.reaction.expire_dans ?? 45);
let minuteur = null;

function relancer() {
    if (minuteur) clearInterval(minuteur);
    restant.value = props.reaction.expire_dans ?? 45;
    minuteur = setInterval(() => {
        restant.value -= 1;
        // Fenêtre écoulée : on répond « non » nous-mêmes plutôt que de laisser
        // une feuille morte à l'écran — le serveur refuserait de toute façon.
        if (restant.value <= 0) {
            clearInterval(minuteur);
            emit('repondre', false);
        }
    }, 1000);
}

watch(() => props.reaction, relancer, { immediate: true });
onBeforeUnmount(() => minuteur && clearInterval(minuteur));

const LIBELLE_SOURCE = {
    attaque_monstre: 'le coup du monstre',
    sort_dread: 'le sort du maître du donjon',
    tir_ami: 'le sort de ton compagnon',
};

const origine = computed(() => LIBELLE_SOURCE[props.reaction.source] ?? 'ce coup');
const degats = computed(() => Number(props.reaction.degats ?? 0));
</script>

<template>
    <div class="overlay">
        <div class="sheet reaction">
            <div class="grip" />
            <h3>
                <MSym n="bolt" :size="20" fill style="color: var(--torch); margin-right: 6px" />
                {{ reaction.sort }}
            </h3>

            <p class="rx-coup">
                Tu viens d'encaisser <b>{{ degats }} PV</b> — {{ origine }}.
            </p>
            <p v-if="reaction.description" class="rx-desc">{{ reaction.description }}</p>

            <div class="rx-chrono" :class="{ urgent: restant <= 10 }">
                <MSym n="timer" :size="15" />
                <span>{{ Math.max(0, restant) }} s pour décider</span>
            </div>

            <div class="rx-actions">
                <button class="rx-btn oui" :disabled="pending" @click="emit('repondre', true)">
                    <MSym n="shield" :size="18" fill />
                    Annuler les dégâts
                </button>
                <button class="rx-btn non" :disabled="pending" @click="emit('repondre', false)">
                    Laisser passer
                </button>
            </div>

            <p class="rx-note">Activer dépense le sort pour cette quête.</p>
        </div>
    </div>
</template>

<style scoped>
/* Préfixé `rx-` : beaucoup de blocs <style> du projet sont GLOBAUX et des
   noms génériques (.actions, .note) fuient d'une vue à l'autre. */
.sheet.reaction { text-align: center; }

.rx-coup {
    margin: 2px 0 6px;
    font-size: 15px;
    color: var(--ink-100, #f3e9d6);
}
.rx-coup b { color: #ff8f6b; }

.rx-desc {
    margin: 0 0 10px;
    font-size: 12.5px;
    line-height: 1.4;
    color: var(--ink-300, #b6a88a);
}

.rx-chrono {
    display: inline-flex; align-items: center; gap: 5px;
    margin-bottom: 12px;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 12px; font-weight: 700;
    color: var(--ink-300, #b6a88a);
    background: rgba(255, 255, 255, 0.06);
}
.rx-chrono.urgent { color: #ff8f6b; background: rgba(255, 143, 107, 0.14); }

.rx-actions { display: flex; flex-direction: column; gap: 8px; }
.rx-btn {
    display: flex; align-items: center; justify-content: center; gap: 7px;
    width: 100%;
    padding: 13px 14px;
    border-radius: 12px;
    border: 1px solid rgba(201, 162, 74, 0.35);
    font-size: 15px; font-weight: 700;
    cursor: pointer;
}
.rx-btn:disabled { opacity: 0.5; cursor: default; }
.rx-btn.oui { background: rgba(74, 222, 128, 0.16); border-color: #4ade80; color: #d7f7e2; }
.rx-btn.non { background: transparent; color: var(--ink-300, #b6a88a); }

.rx-note {
    margin: 10px 0 0;
    font-size: 11.5px;
    color: var(--ink-300, #b6a88a);
    opacity: 0.8;
}
</style>
