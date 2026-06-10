/* =========================================================================
   useEcho — point d'intégration temps réel (Laravel Echo + Reverb).

   Conformément à reference/11_technique.md §7 :
   - canal de groupe `groupe.{id}`  → écran de table (narration, état partagé) ;
   - canal privé   `joueur.{id}`    → manette (menu de choix personnel).

   Les vues appellent useGroupChannel()/usePlayerChannel() avec une map
   { '.nom.evenement': handler }. Tant que Reverb / l'API ne sont pas
   branchés, l'instance Echo est créée paresseusement et toute erreur de
   connexion est non bloquante : la SPA continue sur ses données de démo.
   ========================================================================= */

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { onMounted, onUnmounted } from 'vue';

let echoInstance = null;

/** Instance Echo partagée, créée à la première souscription. */
export function getEcho() {
    if (echoInstance) return echoInstance;

    const key = import.meta.env.VITE_REVERB_APP_KEY;
    if (!key) {
        console.info('[useEcho] VITE_REVERB_APP_KEY absent — temps réel désactivé (mode démo).');
        return null;
    }

    try {
        window.Pusher = Pusher;
        echoInstance = new Echo({
            broadcaster: 'reverb',
            key,
            wsHost: import.meta.env.VITE_REVERB_HOST,
            wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
            wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
            forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
            enabledTransports: ['ws', 'wss'],
        });
    } catch (e) {
        console.warn('[useEcho] connexion Reverb impossible — mode démo.', e);
        echoInstance = null;
    }
    return echoInstance;
}

function subscribe(channelFactory, events) {
    let channel = null;

    onMounted(() => {
        const echo = getEcho();
        if (!echo) return;
        channel = channelFactory(echo);
        for (const [event, handler] of Object.entries(events)) {
            channel.listen(event, handler);
        }
    });

    onUnmounted(() => {
        if (channel) channel.unsubscribe?.();
        channel = null;
    });
}

/**
 * Canal de groupe `groupe.{id}` — écran de table (hôte).
 * Événements attendus côté serveur (à brancher) : narration, état du
 * groupe, déplacement, résolution de combat, changement de tour…
 *
 *   useGroupChannel(groupeId, { '.narration.diffusee': (e) => { … } })
 */
export function useGroupChannel(groupeId, events = {}) {
    subscribe((echo) => echo.channel(`groupe.${groupeId}`), events);
}

/**
 * Canal privé par joueur — la manette y reçoit SON menu de choix.
 * Nécessitera l'auth de canal (routes/channels.php) côté Laravel.
 *
 *   usePlayerChannel(joueurId, { '.menu.propose': (e) => { … } })
 */
export function usePlayerChannel(joueurId, events = {}) {
    subscribe((echo) => echo.private(`joueur.${joueurId}`), events);
}
