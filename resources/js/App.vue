<script setup>
// Coquille de la SPA : chaque écran (table, manette, hub…) gère son propre gabarit.
// Superpose un bandeau « session expirée » global : quand une requête API renvoie
// 401 en pleine partie (useApi émet `api:session-expiree`), on invite à se
// reconnecter plutôt que d'afficher « Unauthenticated. » brut dans la narration.
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useGameStore } from './store/game';

const router = useRouter();
const route = useRoute();
const store = useGameStore();
const sessionExpiree = ref(false);

// Écran NARRATEUR (table) : la « session » est celle de la TABLE, ouverte par
// CODE et sans compte — un 401 s'y règle en ROUVRANT la table, pas via le login
// joueur. Sans cette distinction, le narrateur était renvoyé sur le login manette.
const estTable = computed(() => ['table', 'narrateur'].includes(route.name));

function signaler() { sessionExpiree.value = true; }

function seReconnecter() {
    sessionExpiree.value = false;

    // §2.15 — le serveur ne nous connaît plus : il FAUT purger l'état client
    // d'authentification. Sans ça, /joueur se rendait depuis le cache
    // (« Salut X », le groupe, « Reprendre la partie ») alors que /api/moi
    // répondait 401 : on cliquait « Reprendre la partie », on retombait sur la
    // manette avec le même bandeau, et on tournait en rond indéfiniment. Le
    // seul moyen d'en sortir était de ressaisir son identifiant à la main.
    if (! estTable.value) {
        store.setJoueur(null, []);
    }

    router.push(estTable.value ? '/narrateur' : '/joueur');
}

onMounted(() => window.addEventListener('api:session-expiree', signaler));
onUnmounted(() => window.removeEventListener('api:session-expiree', signaler));
</script>

<template>
    <RouterView />

    <!-- §2.15 — non refermable : tant que la session n'est pas rétablie, TOUT
         est bloqué en dessous. L'ancien bouton « Plus tard » masquait le bandeau
         et laissait un menu d'action parfaitement lisible mais totalement inerte,
         dont les clics étaient avalés en silence par cet overlay. -->
    <div v-if="sessionExpiree" class="session-overlay">
        <div class="session-carte" role="alertdialog" aria-label="Session expirée">
            <div class="session-ic">🔒</div>
            <h2>Session expirée</h2>
            <p v-if="estTable">La session de la table a expiré. Rouvre la table
                avec le <b>code du groupe</b> pour reprendre la narration.</p>
            <p v-else>Ta session a expiré (longue pause). Il faut te reconnecter :
                l'écran derrière ce message n'est plus actif. Tu retrouveras
                ensuite la partie là où le groupe en est.</p>
            <div class="session-actions">
                <button class="session-btn gold" @click="seReconnecter">
                    {{ estTable ? 'Rouvrir la table' : 'Se reconnecter' }}
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.session-overlay {
    position: fixed; inset: 0; z-index: 9999;
    display: grid; place-items: center; padding: 20px;
    background: rgba(0, 0, 0, 0.72); backdrop-filter: blur(3px);
}
.session-carte {
    width: 100%; max-width: 380px; text-align: center;
    padding: 28px 24px; border-radius: 16px;
    background: #201a12; border: 1px solid rgba(201, 162, 74, 0.35);
    box-shadow: 0 18px 50px rgba(0, 0, 0, 0.6); color: #e7dcc6;
    font-family: system-ui, sans-serif;
}
.session-ic { font-size: 34px; margin-bottom: 8px; }
.session-carte h2 { margin: 0 0 10px; font-size: 20px; color: #c9a24a; }
.session-carte p { margin: 0 0 20px; font-size: 14px; line-height: 1.45; color: #cfc3ad; }
.session-actions { display: flex; gap: 10px; justify-content: center; }
.session-btn {
    padding: 10px 18px; border-radius: 10px; font-weight: 700; font-size: 14px;
    cursor: pointer; border: 0;
}
.session-btn.gold { background: #c9a24a; color: #1a1204; }
.session-btn.ghost { background: transparent; color: #cfc3ad; border: 1px solid rgba(207, 195, 173, 0.3); }
</style>
