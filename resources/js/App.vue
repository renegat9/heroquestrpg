<script setup>
// Coquille de la SPA : chaque écran (table, manette, hub…) gère son propre gabarit.
// Superpose un bandeau « session expirée » global : quand une requête API renvoie
// 401 en pleine partie (useApi émet `api:session-expiree`), on invite à se
// reconnecter plutôt que d'afficher « Unauthenticated. » brut dans la narration.
import { onMounted, onUnmounted, ref } from 'vue';
import { useRouter } from 'vue-router';

const router = useRouter();
const sessionExpiree = ref(false);

function signaler() { sessionExpiree.value = true; }
function seReconnecter() {
    sessionExpiree.value = false;
    router.push('/joueur');
}

onMounted(() => window.addEventListener('api:session-expiree', signaler));
onUnmounted(() => window.removeEventListener('api:session-expiree', signaler));
</script>

<template>
    <RouterView />

    <div v-if="sessionExpiree" class="session-overlay" @click.self="sessionExpiree = false">
        <div class="session-carte" role="alertdialog" aria-label="Session expirée">
            <div class="session-ic">🔒</div>
            <h2>Session expirée</h2>
            <p>Ta session a expiré (longue pause). Reconnecte-toi puis choisis
                « Reprendre la partie » — tu reviendras là où le groupe en était.</p>
            <div class="session-actions">
                <button class="session-btn ghost" @click="sessionExpiree = false">Plus tard</button>
                <button class="session-btn gold" @click="seReconnecter">Se reconnecter</button>
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
