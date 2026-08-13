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

/* Le libellé du bouton dit ce que la réaction FAIT : « annuler » serait faux
   pour un plancher de PV, qui ne rend pas le coup mais empêche la chute. */
const LIBELLE_ACTION = {
    annule_degats: 'Annuler les dégâts',
    annule_degats_voisin: 'Le couvrir de ton bouclier',
    plancher_pv: 'Tenir debout (1 PV)',
    riposte: 'Riposter aussitôt',
    defi_errant: 'Le défier — qu\'il vienne à toi',
    soin_urgence: 'Rester debout',
};

/* Un bouclier sur un bouton qui REND le coup serait un contresens : la
   Représailles du Berserker n'encaisse rien, elle frappe. */
const ICONE_ACTION = { riposte: 'swords', defi_errant: 'swords', soin_urgence: 'healing' };

const origine = computed(() => LIBELLE_SOURCE[props.reaction.source] ?? 'ce coup');

/** La réaction protège quelqu'un d'AUTRE (Parade au bouclier du Chevalier). */
const pourAutrui = computed(() => props.reaction.action === 'annule_degats_voisin');
const degats = computed(() => Number(props.reaction.degats ?? 0));

/* Défi du chevalier : rien n'a encore été encaissé, une bête vient de surgir.
   Le texte du coup serait un mensonge (« tu viens d'encaisser 0 PV »). */
const defi = computed(() => props.reaction.action === 'defi_errant');
const monstre = computed(() => props.reaction.contexte?.monstre ?? props.reaction.monstre ?? 'Un monstre errant');

/* SOIN D'URGENCE : le héros vient de tomber et il lui reste de quoi tenir. Un
   bouton PAR remède — potion ou sort —, parce que le choix compte : une potion
   se consomme pour de bon, un sort revient à la quête suivante. La liste vient
   du serveur, qui la revalide à la résolution. */
const soins = computed(() => props.reaction.soins ?? []);
const soinUrgence = computed(() => props.reaction.action === 'soin_urgence' && soins.value.length > 0);
</script>

<template>
    <div class="overlay">
        <div class="sheet reaction">
            <div class="grip" />
            <h3>
                <MSym n="bolt" :size="20" fill style="color: var(--torch); margin-right: 6px" />
                {{ reaction.sort }}
            </h3>

            <p v-if="soinUrgence" class="rx-coup">
                Tu tombes sous <b>{{ degats }} PV</b> de dégâts — {{ origine }}.
                Il te reste de quoi tenir.
            </p>
            <p v-else-if="defi" class="rx-coup">
                <b>{{ monstre }}</b> surgit dans ta salle, attiré par
                <b>{{ reaction.victime }}</b>. Le prendre sur toi : il se place à ton
                contact et frappe aussitôt.
            </p>
            <p v-else-if="pourAutrui" class="rx-coup">
                <b>{{ reaction.victime }}</b> vient d'encaisser <b>{{ degats }} PV</b> —
                {{ origine }}. Tu es à son contact.
            </p>
            <p v-else class="rx-coup">
                Tu viens d'encaisser <b>{{ degats }} PV</b> — {{ origine }}.
            </p>
            <p v-if="reaction.description" class="rx-desc">{{ reaction.description }}</p>

            <div class="rx-chrono" :class="{ urgent: restant <= 10 }">
                <MSym n="timer" :size="15" />
                <span>{{ Math.max(0, restant) }} s pour décider</span>
            </div>

            <div class="rx-actions">
                <!-- Un bouton par remède : le joueur choisit CE qu'il dépense. -->
                <template v-if="soinUrgence">
                    <button
                        v-for="s in soins"
                        :key="s.cle"
                        class="rx-btn oui"
                        :disabled="pending"
                        @click="emit('repondre', true, s.cle)"
                    >
                        <MSym :n="s.type === 'potion' ? 'science' : 'auto_awesome'" :size="18" fill />
                        {{ s.type === 'potion' ? 'Boire' : 'Lancer' }} {{ s.nom }} (+{{ s.soin }} PV)
                    </button>
                </template>
                <button v-else class="rx-btn oui" :disabled="pending" @click="emit('repondre', true)">
                    <MSym :n="ICONE_ACTION[reaction.action] || 'shield'" :size="18" fill />
                    {{ LIBELLE_ACTION[reaction.action] || 'Annuler les dégâts' }}
                </button>
                <button class="rx-btn non" :disabled="pending" @click="emit('repondre', false)">
                    {{ soinUrgence ? 'Tomber' : 'Laisser passer' }}
                </button>
            </div>

            <p class="rx-note">
                {{ soinUrgence
                    ? 'La potion est consommée ; un sort se recharge à la quête suivante.'
                    : 'Activer dépense cette capacité pour la quête.' }}
            </p>
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
