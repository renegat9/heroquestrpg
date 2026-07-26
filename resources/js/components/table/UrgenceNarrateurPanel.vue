<script setup>
// MENU D'URGENCE DU NARRATEUR — overlay plein écran (écran de table
// uniquement, contrairement à ParametresPanel qui vit aussi sur /narrateur :
// ici il faut un groupe/une quête à agir dessus). Composant « idiot » (pas
// d'appel API ici) : TableView.vue porte la logique (mêmes conventions que
// rechargerQuete()/ouvrirCloture()) et passe enCours/erreur en props — même
// découpage que MarketPanel/PrologueOverlay.
//
// Deux actions destructrices, chacune derrière une confirmation inline
// (pattern CibleSheet.vue « tir ami ») :
//  - recommencer la quête actuelle : POST quete/redemarrer, utilisable à
//    tout moment (pas seulement après un TPK) ;
//  - arrêter la campagne : POST cloture/urgence, arrêt IMMÉDIAT sans
//    rituel de confirmation des autres joueurs — pensé pour « ça va très
//    mal, on sort tout de suite ».
import { ref } from 'vue';
import MSym from '../ui/MSym.vue';

defineProps({
    enCours: { type: Boolean, default: false },
    erreur: { type: String, default: '' },
});
const emit = defineEmits(['redemarrer', 'arreter', 'fermer']);

const etape = ref('menu'); // 'menu' | 'confirmer-redemarrage' | 'confirmer-arret'
</script>

<template>
    <div class="urgence-ov" @click.self="emit('fermer')">
        <div class="urgence-carte">
            <button class="urgence-fermer" type="button" title="Fermer" :disabled="enCours" @click="emit('fermer')">
                <MSym n="close" />
            </button>

            <div class="urgence-tete">
                <div class="urgence-orn"><MSym n="crisis_alert" fill /></div>
                <h2 class="urgence-titre">Actions du narrateur</h2>
                <p class="urgence-sous">Recommencer la quête si ça tourne mal, ou arrêter la campagne pour libérer les joueurs.</p>
            </div>

            <template v-if="etape === 'menu'">
                <button class="btn btn-block" type="button" @click="etape = 'confirmer-redemarrage'">
                    <MSym n="replay" /> Recommencer la quête actuelle
                </button>
                <button class="btn btn-danger btn-block" type="button" @click="etape = 'confirmer-arret'">
                    <MSym n="flag" /> Arrêter la campagne
                </button>
                <button class="btn btn-ghost btn-block" type="button" @click="emit('fermer')">
                    <MSym n="arrow_back" /> Revenir à la quête
                </button>
            </template>

            <template v-else-if="etape === 'confirmer-redemarrage'">
                <div class="urgence-warn">
                    <MSym n="warning" fill :size="22" />
                    <span>La quête va reprendre depuis son <b>DÉBUT</b> : positions, PV, sorts, inventaire et monstres redeviennent ce qu'ils étaient à son lancement. Action irréversible.</span>
                </div>
                <button class="btn btn-danger btn-block" type="button" :disabled="enCours" @click="emit('redemarrer')">
                    <MSym n="replay" /> {{ enCours ? 'Redémarrage…' : 'Confirmer — recommencer la quête' }}
                </button>
                <button class="btn btn-ghost btn-block" type="button" :disabled="enCours" @click="etape = 'menu'">
                    Annuler
                </button>
            </template>

            <template v-else-if="etape === 'confirmer-arret'">
                <div class="urgence-warn">
                    <MSym n="warning" fill :size="22" />
                    <span>La campagne va s'arrêter <b>IMMÉDIATEMENT</b> : les personnages sont libérés (retour au roster) pour une nouvelle campagne, sans attendre la confirmation des autres joueurs. Action irréversible.</span>
                </div>
                <button class="btn btn-danger btn-block" type="button" :disabled="enCours" @click="emit('arreter')">
                    <MSym n="flag" /> {{ enCours ? 'Arrêt en cours…' : 'Confirmer — arrêter la campagne' }}
                </button>
                <button class="btn btn-ghost btn-block" type="button" :disabled="enCours" @click="etape = 'menu'">
                    Annuler
                </button>
            </template>

            <p v-if="erreur" class="urgence-err"><MSym n="error" fill :size="16" /> {{ erreur }}</p>
        </div>
    </div>
</template>

<style scoped>
.urgence-ov {
    position: fixed; inset: 0; z-index: 90; display: grid; place-items: center;
    padding: 24px; background: oklch(0.12 0.02 60 / 0.82); backdrop-filter: blur(6px);
    animation: urgence-fade .25s ease;
}
@keyframes urgence-fade { from { opacity: 0; } to { opacity: 1; } }

.urgence-carte {
    position: relative; width: 100%; max-width: 440px;
    padding: 30px 30px 26px; border-radius: var(--r-xl); border: var(--line);
    background: linear-gradient(180deg, var(--stone-850), var(--stone-900));
    box-shadow: 0 0 60px oklch(0.60 0.200 25 / 0.14), var(--sh-3);
    display: flex; flex-direction: column; gap: 12px; color: var(--ink-100);
}

.urgence-fermer {
    position: absolute; top: 16px; right: 16px; width: 34px; height: 34px; border-radius: 999px;
    display: grid; place-items: center; border: var(--line); background: var(--stone-800); color: var(--ink-300);
    cursor: pointer; transition: color .15s, border-color .15s;
}
.urgence-fermer:hover { color: var(--parch-100); border-color: var(--torch); }
.urgence-fermer:disabled { opacity: 0.4; pointer-events: none; }

.urgence-tete { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 8px; margin-bottom: 6px; }
.urgence-orn {
    width: 56px; height: 56px; border-radius: 16px; display: grid; place-items: center;
    background: linear-gradient(150deg, oklch(0.62 0.2 25), oklch(0.5 0.19 25)); color: var(--parch-100);
    box-shadow: 0 0 28px oklch(0.60 0.200 25 / 0.25);
}
.urgence-orn .msym { font-size: 30px; }
.urgence-titre { font-family: var(--font-display); font-size: 21px; font-weight: 800; color: var(--parch-100);
    letter-spacing: 0.02em; margin: 0; }
.urgence-sous { font-size: 13px; color: var(--ink-500); margin: 0; max-width: 38ch; }

.urgence-warn {
    display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; border-radius: var(--r-md);
    background: oklch(0.78 0.150 75 / 0.12); border: 1px solid oklch(0.78 0.150 75 / 0.45);
    color: var(--warn); font-size: 13px; line-height: 1.5;
}
.urgence-warn .msym { flex: none; margin-top: 1px; }
.urgence-warn b { color: inherit; }

.urgence-err {
    display: flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 600; color: var(--danger); margin: 0;
}
</style>
