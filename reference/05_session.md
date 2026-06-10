# Conception — Session & Multijoueur

> Document d'analyse. Couvre le démarrage d'une partie, la composition du groupe et les règles d'arrivée des joueurs. S'appuie sur l'architecture **tablette-hôte + téléphones-clients**.

---

## 1. Modèle tablette-hôte + téléphones

- La **tablette** est l'hôte : moteur de règles autoritaire, écran partagé, narration / TTS / ambiance.
- Chaque **téléphone** est l'interface individuelle d'un joueur (saisie de ses actions, de son panier marché, etc.).

---

## 2. Créer et démarrer une partie

- On crée une partie en saisissant un **identifiant de groupe** sur la tablette (façon code de salon) ; les joueurs **rejoignent** en entrant cet identifiant sur leur téléphone.
- L'identifiant désigne la **partie/campagne** : chaque partie a le sien (plusieurs parties en parallèle).
- **Chaque groupe est lié à une session d'agent IA dédiée** — sa propre bible, son propre contexte (doc Mémoire).
- À la création, on définit :
  - un **thème** libre, **contraint au registre fantasy** (validé par les garde-fous — doc 3/3) ; il amorce la bible de la campagne ;
  - une **longueur** (nombre de quêtes), qui fixe l'arc de campagne :

| Longueur | Quêtes |
|---|---|
| Très courte | 1 |
| Courte | 3 à 5 |
| Normale | 7 à 10 |
| Longue | 12 à 15 |
| Très longue | 17 à 20 |

> L'arc (sous-boss et boss final) découle de cette longueur — voir doc Quêtes & MJ IA (1/3) §4.

---

## 3. Composition du groupe

- Chaque joueur possède une **liste (roster) de personnages** persistante, conservée d'une partie à l'autre. Il peut contrôler **un ou plusieurs personnages** (pratique à peu de joueurs, voire en solo) ; `joueur_id` relie plusieurs personnages à un même joueur (doc Personnages).
- Un joueur peut **créer ou rejoindre plusieurs groupes**, mais un **personnage donné ne peut être actif que dans un seul groupe actif à la fois** (pour un autre groupe, le joueur engage un autre de ses personnages).
- Les **personnages sont des entités distinctes** ; un personnage ne figure **qu'une seule fois** dans un groupe (pas de doublon).
- **Taille maximale d'un groupe = nombre de personnages distincts existants** — aujourd'hui les 4 héros (Barbare, Nain, Elfe, Magicien), soit **max 4, un de chaque**. Le plafond s'élargit si de nouveaux personnages sont créés.
- En rejoignant, un joueur **choisit le(s) personnage(s)** qu'il engage, parmi ceux libres.

---

## 4. Règles d'arrivée d'un joueur

Deux cas distincts :

- **Nouveau joueur** : ne peut rejoindre qu'**entre deux quêtes**, de préférence dans un **lieu-relais** (hub) où le groupe choisit sa prochaine action. Interdit en plein cœur d'une quête active, pour préserver l'équilibre et la cohérence narrative.
- **Membre existant de la quête active** : peut **(re)rejoindre à tout moment** — typiquement une **reconnexion** après une coupure (contexte mobile). Il reprend le contrôle de son personnage là où il en était.

> Règle synthétique : rejoindre est permis si **(entre quêtes, au relais)** *ou* **(déjà membre de la quête active = reconnexion)**.
>
> **Une seule session active par joueur** : une **nouvelle connexion déconnecte l'ancienne** (pas de double-contrôle du même personnage).

> À l'arrivée, l'**or personnel** du personnage est **versé au pot commun** du groupe (doc Personnages §7).

---

## 5. Départ d'un joueur

- **Pendant une quête** : retirer **définitivement** quelqu'un exige un **vote majoritaire** (le joueur visé **ne vote pas** ; **égalité = il reste**). S'il est retiré, il emporte une **part de l'or d'avant la quête** (pot au **début de la quête** ÷ membres) — pas de cut sur le butin de la quête en cours.
- **Entre les quêtes** : un joueur peut **partir librement**, sans vote, en **emportant sa part égale** du pot commun (pot ÷ membres présents).
- Dans tous les cas, **les personnages du joueur restent dans sa liste** (équipement + or personnel) ; ils quittent seulement le groupe concerné et redeviennent disponibles pour ses autres groupes.

> Ce vote est la première application concrète du **système de vote de groupe** envisagé dès le départ du projet.

---

## 6. Clôture de campagne & nettoyage

Quand une campagne se termine (boss final vaincu ou fin décidée), une **fenêtre de clôture** s'ouvre sur la tablette :

1. **Répartition des équipements** : le groupe répartit librement l'**équipement** acquis durant la campagne entre les personnages (chacun repart avec son stuff, y compris le butin unique de boss).
2. **Partage de l'or** : la **bourse commune** (M3) est répartie entre les personnages, vers leur **bourse personnelle persistante** (roster).
3. **Résumé de campagne** : l'IA génère un **résumé** (depuis le journal et la bible) ajouté à l'**historique** de chaque fiche de personnage — une mémoire durable et **compacte** de l'aventure.
4. **Détachement** : les personnages quittent le groupe (`groupe_actif_id` = nul) et **retournent au roster** de leur joueur, avec équipement, or et historique.
5. **Nettoyage** : toutes les données du groupe sont **supprimées** (quêtes, événements, snapshots, cartes, instances, et la **bible Qdrant** du `group_id`) pour **libérer les ressources**. Seul l'historique compact survit, dans les fiches.

### Suppression automatique d'un groupe
- Un groupe dont **tous les joueurs se sont retirés** (groupe vide) est **supprimé automatiquement**, avec le même nettoyage (données relationnelles + bible Qdrant).
- Les personnages des joueurs partis sont déjà revenus dans leur roster (règle de départ, §5).

### Clôture par abandon (après TPK)
Sur un **Total Party Kill** (doc Combat §6), le groupe peut **abandonner** plutôt que recharger : même nettoyage que la clôture, mais l'or réparti est celui **d'avant la mission** (`or_initial`) et le résumé d'historique est un **échec**.

> Le résumé est généré **avant** la purge : c'est ce qui permet de tout supprimer sans rien perdre d'essentiel.

---

## 7. Intégration

- **Persistance** : l'identifiant de groupe charge la partie correspondante (état vivant + journal). Les **personnages** vivent dans le roster persistant du joueur, au-dessus des parties individuelles ; chaque personnage référence le **groupe actif** où il est engagé.
- **Initiative (C1)** : l'ordre est défini **par personnage** (un joueur contrôlant plusieurs héros joue chacun à sa position). Figé par quête, il intègre proprement un nouveau venu **entre quêtes**.
- **Bourse commune** (M3) et **paniers de marché par joueur** → docs Personnages / Market.

---

## 8. Questions ouvertes à trancher

*Aucune en suspens.* Départ en cours de quête : part de l'**or d'avant la quête** (pot au début ÷ membres). Lieu-relais : **ville persistante** (Q5).
