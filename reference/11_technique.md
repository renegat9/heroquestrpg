# Conception — Architecture technique

> Document technique. Projet **interne**, auto-hébergé. Stack : **Laravel + Vue**, **MariaDB** (+ phpMyAdmin), **Qdrant** (bibliothèque RAG), le tout en **Docker**. Traduit les 10 documents de conception en architecture exécutable.

---

## 1. Vue d'ensemble

Un **monolithe modulaire Laravel** (moteur, agent IA, mémoire) + **temps réel via Reverb**, des clients **Vue** (rôles **hôte** et **joueur**, sur tout navigateur), **MariaDB** pour le relationnel et **Qdrant** pour la bibliothèque vectorielle. Tout tourne sur **un seul hôte** via docker-compose (base **Laravel Sail** étendue).

> Rappel du principe fondateur : le **moteur fait autorité** sur toute mécanique ; l'IA **narre et propose** en jobs asynchrones.

---

## 2. Conteneurs (docker-compose)

| Service | Rôle |
|---|---|
| **app** (Laravel + PHP-FPM) | API, moteur déterministe, orchestration IA, dispatch des jobs |
| **reverb** | Serveur WebSocket (process Laravel) — dans `app` ou dédié |
| **queue** | Worker de files d'attente (jobs IA) — process Laravel |
| **mariadb** | Base relationnelle (**volume**) |
| **phpmyadmin** | Admin DB (dev) |
| **qdrant** | Base vectorielle / bibliothèque (**volume**) |
| **web** (nginx) | Reverse proxy + service du build Vue |
| **vite** (dev) | Serveur front en dev (bind mount, hot reload) |

- **Volumes** sur `mariadb` *et* `qdrant` (les deux portent l'état des campagnes).
- Un seul **réseau** compose ; les services se parlent par nom.
- **Clé LLM** en variable d'environnement / secret, jamais dans l'image.

---

## 3. Découpage applicatif (Laravel)

- **Moteur (`engine`)** : classes PHP **pures** (règles, dés, combat, jets, application d'événements). Hors plomberie HTTP. **Testé à fond (Pest)**. Cœur autoritaire.
- **Agent (`agent`)** : un **seul agent MJ** doté de **skills par tâche** (squelette, détail de quête, menu, narration marché, narration combat/Dread). Chaque sortie est contrainte par un **schéma JSON** (structured outputs / strict tool use) puis **validée par le moteur** contre le catalogue (rejet/retry si invalide). Assemblage de prompt, récupération RAG, appels LLM par API ; s'exécute en **jobs**.
- **Mémoire (`memory`)** : état vivant (chargé en contexte), journal d'événements, snapshots (MariaDB) ; bible (Qdrant).
- **Temps réel** : Reverb diffuse l'état et les résultats d'IA aux clients.
- **API / Auth** : login simple (utilisateurs connus), gestion des groupes.

---

## 4. Le flux d'un tour (boucle MJ IA)

1. Le joueur tape un choix sur son téléphone (Vue) → requête API.
2. Laravel **valide via le moteur** (l'option est-elle légale ?).
3. Si jet / combat : le moteur **résout** (déterministe) → met à jour l'**état vivant** + ajoute au **journal** (MariaDB).
4. Pour la narration et le menu suivant : **dispatch d'un job** → l'agent assemble le prompt (état + RAG Qdrant filtré `group_id`) → appelle le LLM **avec un schéma de sortie** → le moteur **valide le JSON** (forme + références au catalogue ; rejet/retry si invalide).
5. Le résultat est **diffusé via Reverb** : narration/TTS sur la tablette, menus sur les téléphones.

> Pendant le job, l'interface affiche « le MJ réfléchit… » ; rien ne bloque l'API.

---

## 5. Données — MariaDB (relationnel)

Modèles Eloquent principaux :
- **joueurs**, **personnages** (roster ; `joueur_id`, `groupe_actif_id`) — doc 01.
- **groupes / parties** (identifiant, thème, longueur, état) — doc 05.
- **quetes** (gabarit, branche active, jalons de boss) — doc 06.
- **evenements** (journal rejouable) et **snapshots** (sauvegardes) — doc 07.
- catalogues : **bestiaire**, **objets**, **sorts**, **tuiles**, **pieges** (données de référence à *seeder*).

---

## 6. Données — Qdrant (bibliothèque RAG)

- Une **collection** avec **payload `group_id`** : la bible de chaque groupe est isolée par **filtrage** (Q7), plus simple qu'une collection par groupe.
- Vecteurs = **embeddings** des entrées de bible (PNJ, lieux, événements, branches prises, réputation).
- Récupération : recherche sémantique **filtrée `group_id`** à chaque scène, injectée dans le prompt.
- Embeddings via API au MVP (service local possible en phase 2).

---

## 7. Temps réel — Reverb + Echo

- **Reverb** (WebSocket) côté serveur ; **Echo** côté Vue.
- **Canal de groupe** (`groupe.{id}`) : la tablette y écoute narration et état partagé.
- **Canaux privés par joueur** : chaque téléphone reçoit **son** menu de choix.
- **Rôle ≠ matériel** : un client est une vue dans un navigateur ; tout appareil (tablette, ordinateur, téléphone) peut tenir le rôle **hôte** (écran de table) ou **joueur** (manette). Plusieurs appareils par campagne, sans limite au-delà du raisonnable.
- **Multi-écrans hôtes** : tous les hôtes peuvent **piloter** les interactions, mais les **décisions passent de préférence par le vote** sur l'interface joueur.
- **Une session active par joueur** : une **nouvelle connexion déconnecte l'ancienne** (pas de double-saisie).
- **Jeu à distance** : **multi-écrans sans partage** — chaque client rend la **vue de table localement** (synchronisée par Reverb), sans partage d'écran vidéo.

---

## 8. Files d'attente — jobs IA

- Toute génération IA passe par un **job** (worker `queue`).
- Absorbe la **latence** du LLM, gère les **reprises sur échec** (repli, doc Garde-fous §5), et évite de bloquer l'API.

---

## 9. Gestion du contexte (Q8)

- Surveillance du **remplissage** du contexte de la session d'agent.
- Au **seuil**, un job **verse** les anciens événements en entrées Qdrant, puis **compacte** (résumé) le contexte restant.

---

## 10. Persistance & sauvegardes

- État vivant + journal + snapshots en **MariaDB** ; bible en **Qdrant**.
- **Sauvegarder les deux volumes** : une campagne complète = relationnel **+** vectoriel.
- Multi-groupes / plusieurs parties = `group_id` partout (lignes distinctes, payload Qdrant).

---

## 11. Sécurité (cadre interne)

- **Auth simple** (utilisateurs connus, réseau local / NAS).
- Clé LLM et identifiants en **secrets / env**, hors image.
- **Pas** de durcissement grand public (scaling horizontal, rate-limiting, multi-tenant) — hors périmètre.
- ⚠️ **Exposition WAN** : l'auth simple convient en LAN / VPN, **pas** sur des ports publics ouverts → voir **§13** (proxy TLS, auth renforcée, ne jamais exposer la BD).

---

## 12. Périmètre technique

- **MVP** : app Laravel (engine + agent + memory), Reverb, MariaDB + phpMyAdmin, Qdrant, clients Vue (tablette + téléphone), un hôte docker-compose, LLM par API.
- **Phase 2** : sidecar **Python** (modèle local / embeddings), conteneurs séparés si besoin de scale, monitoring/observabilité.

---

## 13. Exécution & déploiement (local / LAN / WAN)

### Local (même machine)
- `docker compose up` → tout démarre (app, MariaDB, Qdrant, Reverb, worker). Accès via `http://localhost`.
- Dev : serveur Vite + `php artisan` dans le conteneur `app` (base Sail).

### LAN (jeu entre proches, même réseau)
- Héberger sur **une machine** ; tablette + téléphones sur le **même Wi-Fi**.
- Pointer `APP_URL` et l'hôte **Reverb/Echo** sur l'**IP LAN** de l'hôte (pas `localhost`), sinon les autres appareils ne se connectent pas au WebSocket.
- **Exposer les ports** HTTP **et** WebSocket (Reverb) ; autoriser ces ports dans le **pare-feu** de l'hôte.
- IP locale fixe (réservation DHCP) recommandée pour qu'elle ne change pas.

### À distance (WAN) — du plus sûr au moins sûr
1. **VPN (recommandé)** — Tailscale / WireGuard : les joueurs rejoignent un réseau privé, **aucun port public ouvert**. Le plus simple **et** le plus sûr ; rien n'est exposé à Internet.
2. **Tunnel inverse** — Cloudflare Tunnel / ngrok : publie l'app **sans ouvrir de port** sur la box, avec TLS et éventuellement une auth au niveau du tunnel.
3. **Ouverture de ports WAN (port forwarding)** — possible, mais **à durcir** :
   - **Reverse proxy + HTTPS/TLS** (Caddy / Traefik) en façade ; n'exposer **que** le port du proxy (443), jamais l'app en clair.
   - **Ne JAMAIS exposer** MariaDB, phpMyAdmin ni Qdrant au WAN — uniquement web + WebSocket (`wss://`), via le proxy.
   - **Renforcer l'auth** : le login simple « interne » est trop faible sur le WAN → mots de passe forts, **rate-limiting**, voire basic-auth au proxy / fail2ban.
   - **Nom de domaine + certificat** (Let's Encrypt via Caddy/Traefik) ; **DNS dynamique** si pas d'IP fixe.
   - Garder l'hôte à jour ; sauvegardes régulières (§10).

> Pour « jouer à distance », un **VPN** couvre l'essentiel des besoins sans risque : on garde le confort du LAN sans rien publier. L'ouverture de ports n'est à envisager que si un VPN est impossible — et alors **toujours** derrière un proxy TLS avec auth renforcée.

---

## 14. Questions ouvertes (technique)

1. **Front** : Vue **SPA + API + Echo** (recommandé pour le temps réel tablette/téléphone) ou **Inertia.js** ?
2. **Embeddings** : API distante ou modèle local ?
3. **Seed des catalogues** : migrations + seeders, ou import de fichiers de référence (JSON) ?
4. **TTS & ambiance** : service externe ou rendu local sur la tablette ?
5. **Reverb** : intégré au conteneur `app` ou conteneur dédié selon la charge ?
