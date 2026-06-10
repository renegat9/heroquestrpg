# Conception — Quêtes & MJ IA (2/3) : Mémoire & cohérence

> Document d'analyse. Couvre les couches de mémoire, le RAG (bible d'univers) et la gestion du contexte long. Voir aussi *Génération* (1/3) et *Garde-fous* (3/3).

---

## 1. Principe directeur

Le LLM **n'a pas de mémoire propre** : il ne « connaît » que ce qu'on met dans son contexte. La persistance est donc un **problème de données**, pas d'IA. On distingue ce qui doit être **exact et toujours présent** de ce qui peut être **récupéré au besoin**.

---

## 2. Les couches de mémoire

| Couche | Contenu | Traitement |
|---|---|---|
| **État vivant** | État de jeu courant : positions, PV, inventaires, quête en cours, branche active | **Toujours** dans le contexte, exact (jamais en RAG) |
| **Journal d'événements** | Séquence des actions, jets, choix de groupe | Historique ; source de vérité rejouable |
| **Bible d'univers (bibliothèque / RAG)** | PNJ, lieux, événements passés, branches prises, réputation, promesses | **Récupérée par recherche** sémantique, injectée selon la scène |

> L'état vivant ne va **jamais** en RAG (il doit être exact et présent). Le RAG ne sert qu'à la **bible** qui grossit.
>
> Le **squelette de campagne** (prémisse, menace, jalons), généré à la création (doc Quêtes §2), est un **socle stable** : conservé exact et consulté à **chaque** génération de quête pour tenir le fil rouge.

---

## 3. Portée : une bible par groupe/campagne

- Chaque **groupe** possède sa **propre bible isolée** (cohérent avec le modèle multi-groupes du doc Session).
- L'**identifiant de groupe** sert de clé : charger une partie = charger sa bible.
- Deux groupes ne partagent jamais leur univers (pas de fuite de lore entre campagnes).
- À la **clôture** (ou si le groupe devient vide), la bible du groupe est **purgée** avec le reste des données (doc Session §6) ; seul un **résumé compact** survit dans l'historique des personnages.

---

## 4. Le RAG : quand et comment

- **État vivant + événements récents** → injectés **directement** dans le contexte à chaque tour.
- **Bible** → on **recherche** les quelques faits pertinents à la scène courante (PNJ présent, lieu, historique lié) et on les injecte.
- En début de campagne, la bible tient parfois entière dans le contexte : le RAG ne devient nécessaire qu'avec la croissance du monde.

---

## 5. Gestion du contexte long (seuil + compactage)

Processus déclenché par le **remplissage du contexte** :

1. On **surveille le taux de remplissage** du contexte.
2. À un **seuil** (un certain %), on **verse les éléments anciens dans la bibliothèque** (entrées de bible récupérables).
3. On **compacte** ensuite le contexte (résumé) de ce qui n'a pas été versé.
4. Les **événements récents restent en clair** ; les anciens deviennent des entrées de bible, ré-injectables via le RAG au besoin.

> Le seuil exact (%) et le grain des entrées restent à régler (questions ouvertes).

---

## 6. Cohérence des quêtes ramifiées

La bible trace ce qui rend le monde cohérent dans le temps :
- **branches prises** et leurs conséquences,
- **PNJ** vivants / morts / alliés / hostiles,
- **réputation** du groupe, promesses faites,
- état de la **ville persistante**.

C'est sur ces entrées que s'appuient le hub évolutif et les quêtes suivantes (doc Génération).

---

## 7. Intégration

- **Session** : `groupe_actif_id` → clé de la bible ; multi-groupes = bibles isolées.
- **Génération** : branches et PNJ persistés ici ; hub évolutif alimenté par la bible.
- **Garde-fous** : en cas de fait manquant, **récupérer via RAG plutôt qu'inventer** (doc 3/3).

---

## 8. Décisions actées

1. **Portée (Q7)** : **une bible par groupe/campagne**, isolée.
2. **Contexte long (Q8)** : **versement en bibliothèque au seuil de remplissage, puis compactage** ; événements récents gardés en clair.

---

## 9. Questions ouvertes à trancher

1. **Seuil de déclenchement** : à quel % de contexte verser/compacter ?
2. **Grain des entrées de bible** : par événement, par scène, par PNJ ?
3. **Déduplication / conflits** : que faire si deux entrées se contredisent ?
4. **Oubli volontaire** : certaines infos mineures peuvent-elles être purgées définitivement ?
