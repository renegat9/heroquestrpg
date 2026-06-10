/* =========================================================================
   useApi — point d'intégration HTTP vers l'API Laravel (routes/api.php).

   Boucle de jeu prévue (CLAUDE.md) : la manette envoie un choix de menu
   → l'API valide via le moteur → résolution déterministe → la suite
   (narration, nouveau menu) arrive par Reverb (voir useEcho).

   Tant que l'API n'existe pas, les vues utilisent leurs données de démo ;
   ce composable centralise déjà les appels pour ne brancher qu'ici.
   ========================================================================= */

const BASE = '/api';

async function request(method, path, body = undefined) {
    const response = await fetch(`${BASE}${path}`, {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        credentials: 'same-origin',
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    if (!response.ok) {
        throw new Error(`API ${method} ${path} → ${response.status}`);
    }
    return response.json();
}

export function useApi() {
    return {
        get: (path) => request('GET', path),
        post: (path, body) => request('POST', path, body),

        // ---- intentions métier, à brancher sur routes/api.php ----

        /** Créer une campagne (écran /direction, onglet « Créer »). */
        creerGroupe: (payload) => request('POST', '/groupes', payload),

        /** Rejoindre une table avec un héros (onglet « Rejoindre »). */
        rejoindreGroupe: (code, payload) => request('POST', `/groupes/${code}/joueurs`, payload),

        /** État courant d'un groupe (reprise table / reconnexion). */
        etatGroupe: (groupe) => request('GET', `/groupes/${groupe}/etat`),

        /** Envoyer le choix de menu du joueur — le moteur valide et résout. */
        envoyerChoix: (groupe, payload) => request('POST', `/groupes/${groupe}/choix`, payload),

        /** Voter (kick, TPK, abandon, quête suivante…). */
        voter: (groupe, payload) => request('POST', `/groupes/${groupe}/votes`, payload),
    };
}
