/* =========================================================================
   useEcho — temps réel Laravel Echo + Reverb (docs/contrat-api.md).

   Canaux (préfixe d'événement = broadcastAs, d'où le `.` initial) :
   - private `groupe.{identifiant}` : .groupe.etat, .narration.diffusee,
     .mj.reflechit — écran de table + manettes ;
   - private `joueur.{id}`         : .menu.propose — la manette du joueur.

   L'auth des canaux privés passe par POST /broadcasting/auth avec la
   session (cookie same-origin) + X-CSRF-TOKEN de la balise meta, comme
   useApi. Config Reverb via les variables VITE_REVERB_* (.env).

   Si la clé manque ou que la connexion échoue, tout reste non bloquant :
   la SPA continue (mode démo, voir store/game.js).
   ========================================================================= */

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { onMounted, onUnmounted } from 'vue';
import { useGameStore } from '../store/game';

/** Jeton CSRF : cookie XSRF-TOKEN (frais), sinon meta du blade (premier rendu). */
function jetonXsrf() {
    const cookie = document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='));

    if (cookie) {
        return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) };
    }

    const meta = document.querySelector('meta[name="csrf-token"]')?.content;

    return meta ? { 'X-CSRF-TOKEN': meta } : {};
}

let echoInstance = null;

/** Instance Echo partagée, créée à la première souscription. */
export function getEcho() {
    if (echoInstance) return echoInstance;

    const key = import.meta.env.VITE_REVERB_APP_KEY;
    if (!key) {
        console.info('[useEcho] VITE_REVERB_APP_KEY absent — temps réel désactivé.');
        return null;
    }

    try {
        window.Pusher = Pusher;
        echoInstance = new Echo({
            broadcaster: 'reverb',
            key,
            wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
            wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
            wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
            forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
            enabledTransports: ['ws', 'wss'],
            // Auth des canaux privés : session + jeton XSRF du cookie (comme
            // useApi — la meta du blade est périmée après le login).
            authEndpoint: '/broadcasting/auth',
            auth: {
                headers: {
                    ...jetonXsrf(),
                    Accept: 'application/json',
                },
            },
        });

        // Indicateur de connexion (manette : « Connecté / Reconnexion… »).
        const store = useGameStore();
        echoInstance.connector.pusher.connection.bind('state_change', ({ current }) => {
            store.setConnexion(current === 'connected' ? 'ok' : 'warn');
        });
    } catch (e) {
        console.warn('[useEcho] connexion Reverb impossible.', e);
        echoInstance = null;
    }
    return echoInstance;
}

function souscrire(nomCanal, events) {
    const echo = getEcho();
    if (!echo) return () => {};

    const channel = echo.private(nomCanal);
    for (const [event, handler] of Object.entries(events)) {
        channel.listen(event, handler);
    }
    return () => echo.leave(nomCanal);
}

/**
 * Souscription impérative au canal privé `groupe.{identifiant}` —
 * utilisable après un appel API asynchrone (hors setup). Retourne la
 * fonction de désabonnement (echo.leave), à appeler au démontage.
 *
 *   const off = souscrireGroupe(id, { '.groupe.etat': (e) => { … } });
 */
export function souscrireGroupe(identifiant, events = {}) {
    return souscrire(`groupe.${identifiant}`, events);
}

/**
 * Souscription impérative au canal privé `joueur.{id}` (menu personnel).
 *
 *   const off = souscrireJoueur(joueur.id, { '.menu.propose': (e) => { … } });
 */
export function souscrireJoueur(joueurId, events = {}) {
    return souscrire(`joueur.${joueurId}`, events);
}

function souscrireAuMontage(nomCanal, events) {
    let off = null;
    onMounted(() => { off = souscrire(nomCanal, events); });
    onUnmounted(() => { off?.(); off = null; });
}

/** Variante composable (id connu au setup) du canal de groupe. */
export function useGroupChannel(groupeId, events = {}) {
    souscrireAuMontage(`groupe.${groupeId}`, events);
}

/** Variante composable (id connu au setup) du canal joueur. */
export function usePlayerChannel(joueurId, events = {}) {
    souscrireAuMontage(`joueur.${joueurId}`, events);
}
