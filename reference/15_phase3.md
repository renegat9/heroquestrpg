# 15 — Feuille de route Phase 3 : extensions maison & contenu canon périphérique

> **Document de synthèse.** Regroupe tout ce qui n'est **pas** une priorité de Phase 2 (doc 14) : d'un côté les extensions **maison**, au-delà de ce que propose le jeu de plateau HeroQuest (mentionnées comme « Phase 2 » dans les docs 00-13 avant ce recadrage) ; de l'autre, du contenu **canon HeroQuest réel** mais périphérique, optionnel, ou peu compatible avec l'architecture du projet (tablette-hôte + téléphones, coopératif, menus seulement). Aucune règle déjà actée n'est redéfinie ici.

---

## 1. Pourquoi deux parties dans ce document

- **Partie A — Extensions maison** : des idées qui prolongent vos systèmes (alliés joueurs, marchandage, économie dynamique…) mais qui **n'existent pas comme telles** dans HeroQuest — ce sont vos propres ajouts, donc à concevoir de zéro plutôt qu'à adapter d'un canon existant.
- **Partie B — Canon périphérique** : des mécaniques bien réelles dans les extensions HeroQuest, mais qui soit s'écartent de votre architecture (mode Arène PvP, quêtes solo), soit ajoutent une couche optionnelle plutôt que structurante (déguisements, fabrication de potions, tuiles à règles spéciales).

---

## Partie A — Extensions maison

### A.1 Vue d'ensemble
| Item | Domaine(s) | Doc(s) source |
|---|---|---|
| Alliés **joueurs** (PNJ recrutable jouable par un membre du groupe) | Personnages, Session | 01 §9/§12 |
| Arbres de compétences étendus | Personnages | 01 §12 |
| Conditions avancées | Personnages | 01 §12 |
| Marchandage (palier de prix via jet de Mind) | Market | 04 §2/§5/§9 |
| Économie dynamique / fluctuations | Market, Personnages | 01 §12 · 04 §9 |
| Marché noir volatil | Market | 04 §9 |
| Sorts hors canon | Sorts | 02 §9 |
| Sorts de rituel (hors combat, narratifs) | Sorts | 02 §9 |
| Parchemins rares à effets uniques | Sorts, Market | 02 §9 |
| Ramifications profondes | Quêtes | 06 §9 |
| Gabarits de quête riches | Quêtes | 06 §9 |
| Événements de ville dynamiques | Quêtes, Mémoire | 06 §9 |
| Bestiaire élargi (au-delà des 8 + canon) | Bestiaire | 09 §8 |
| Capacités avancées (au-delà de la bibliothèque définie) | Bestiaire | 09 §8 |
| Boss multi-phases | Bestiaire, Combat | 09 §8 |
| Pièges magiques / de salle / réarmables | Pièges | 10 §9 |
| Succès à coût généralisé | Personnages | 00 §8 |
| Sidecar Python (embeddings locaux) | Technique | 11 §12 |
| Monitoring / observabilité | Technique | 11 §12 |

> Détail de chaque item, dépendances et impacts UI/schéma : voir la version précédente de ce document (conservée ci-dessous, inchangée sur le fond).

### A.2 Le chantier « Alliés joueurs » — distinct de l'item 3.5 du doc 14
Le doc 14 tranche désormais les alliés **canon** (mercenaires + animal, scriptés). Ce chantier-ci est différent : un PNJ qu'**un joueur du groupe pilote temporairement** comme un personnage à part entière (arbre de compétences réduit, inventaire propre, etc.) — au-delà de ce que propose HeroQuest. À ne considérer qu'après la version scriptée (doc 14 §3.5) et seulement si le besoin de jeu se confirme.

### A.3 Marchandage, économie dynamique, marché noir volatil
Un seul mécanisme générique à concevoir (déclencheur narratif → effet moteur sur des multiplicateurs de prix), réutilisable sur les trois. Le marchandage de base (jet de Mind → palier) est déjà esquissé au doc 04 §5.

### A.4 Sorts hors canon, sorts de rituel, parchemins rares
Extension de contenu plutôt que de mécanique pour les deux premiers (mêmes blocs, plus de variété). Les sorts de rituel restent les plus sensibles : effets narratifs hors combat pilotés par l'IA, à cadrer strictement par un schéma de sortie borné (doc 08) pour ne pas réintroduire de marge d'improvisation.

### A.5 Ramifications profondes, gabarits riches, événements de ville
S'appuient sur la bible (doc 07) comme source de déclenchement. Le canon HeroQuest confirme la faisabilité de quêtes à embranchements profonds (voir Partie B, *Jungles of Delthrak*) — bon signal pour ce chantier, mais reste un développement maison du système de gabarits (doc 06).

### A.6 Bestiaire élargi, capacités avancées, boss multi-phases
Au-delà des items canon déjà priorisés en Phase 2 (monstres à distance, sorciers nommés, élites, capacités à choix). Les boss multi-phases en particulier demandent une extension du comportement scripté (C2) pour gérer des transitions d'état en cours de combat — aucun équivalent direct trouvé dans le canon HeroQuest étudié, donc conception maison complète.

### A.7 Pièges magiques / de salle / réarmables
Extension du catalogue de pièges (doc 10) au-delà des 4 de base ; les pièges à déclencheur (levier, plaque) dépendent d'une évolution du modèle de carte (doc 12 §3) déjà nécessaire pour les portes (doc 14 §3.1/3.3) — bonne occasion de mutualiser ce chantier de schéma.

### A.8 Succès à coût généralisé
Cité une seule fois (doc 00 §8), jamais détaillé ailleurs. Transformer l'arbitrage ponctuel du MJ IA (P4, « mixte ») en mécanique systématique nécessite une table de conséquences par type de jet manqué de peu — reste à spécifier entièrement.

### A.9 Infrastructure (sidecar Python, monitoring)
Pas de valeur joueur directe ; à déclencher seulement si un besoin concret apparaît (cohérent avec doc 11 §11, qui exclut explicitement le scaling par anticipation).

---

## Partie B — Canon HeroQuest périphérique

### B.1 Vue d'ensemble
| Item | Extension d'origine | Pourquoi périphérique |
|---|---|---|
| Fabrication de potions (banc d'alchimiste, kit à usages limités) | *Rise of the Dread Moon* | Système orthogonal aux sorts (S4 ne concerne que les sorts), mais ajoute une boucle hub entière à elle seule |
| Consommables tactiques (bombe fumigène, chausse-trapes) | *Rise of the Dread Moon* | Extension de catalogue d'objets, faible impact structurel |
| Barrières magiques (mur temporaire posable) | *Wizards of Morcar* | Item utilitaire isolé |
| Artefacts à capacité active rechargeable (ex. téléportation) | *Kellar's Keep* | Cas particulier d'objet ; le modèle `effet JSON` (doc 12 §5) l'absorbe déjà sans changement de schéma |
| Déguisements / infiltration / réputation quantifiée | *Rise of the Dread Moon* | Ajoute une couche stealth/sociale orthogonale au combat — séduisant mais hors du MVP « menus + combat scripté » |
| Tuiles à règles intégrées (ex. la Place : annule les murs, monstres inactifs) | *Rise of the Dread Moon* | Extension de la bibliothèque de tuiles (doc 06 §3), pas une nouvelle mécanique de fond |
| Mouvement non menacé (mouvement garanti sans jet si aucun monstre visible) | *Against the Ogre Horde* | Confort de jeu, aucun enjeu de règle |
| Mode Arène / PvP | *Against the Ogre Horde* (2024) | **Contraire au principe coopératif** du projet — à n'envisager qu'en mode annexe optionnel, jamais par défaut |
| Quêtes solo pour un seul héros | *The Frozen Horror* | Ne correspond pas au modèle « groupe entier toujours présent » (doc 05) ; pertinence à réévaluer seulement si un besoin de jeu en solo émerge |

### B.2 Fabrication de potions
Mécanique complète à elle seule : un lieu dédié au hub (banc d'alchimiste), un objet consommable à usages limités (kit de réactifs, 5 utilisations avant épuisement), et une recette par potion. Compatible avec le principe « catalogue défini » (Q6) si les recettes sont des blocs fixes. À évaluer après les chantiers de Phase 2 : ajoute une économie parallèle (ingrédients) qui mérite sa propre réflexion plutôt qu'un ajout rapide.

### B.3 Déguisements / infiltration / réputation
La plus structurante de cette partie B si elle est un jour retenue : elle change la nature de certaines rencontres (éviter le combat plutôt que le résoudre), ce qui touche au principe « menus + combat scripté » du doc 06 §1. À ne considérer qu'avec un cadrage explicite des Garde-fous (doc 08), comme les sorts de rituel (A.4).

### B.4 Mode Arène / PvP et quêtes solo
Les deux s'écartent du cadre coopératif/groupe du projet. Recommandation : **ne pas les développer**, sauf demande explicite de jeu en dehors du cadre de campagne normal — à garder en note plutôt qu'en backlog actif.

---

## 2. Dépendances avec la Phase 2 (doc 14)

- **Pièges réarmables/à déclencheur (A.7)** et **portes secrètes/destructibles (doc 14 §3.1/3.3)** partagent le même besoin d'évolution du modèle de carte — à traiter dans la même passe de schéma si les deux sont un jour lancés.
- **Boss multi-phases (A.6)** réutilise l'extension du comportement scripté C2 déjà nécessaire pour les capacités à choix tactique (doc 14 §3.7) — bonne suite logique une fois ce socle posé.
- **Sorts hors canon (A.4)** et **sorciers nommés à deck dédié (doc 14 §3.8)** partagent le même catalogue de sorts de Dread — à enrichir ensemble plutôt qu'en deux passes.

---

## 3. Questions ouvertes à trancher

1. **Alliés joueurs (A.2)** : ce chantier reste-t-il au catalogue à long terme, ou la version scriptée (doc 14) suffit-elle définitivement ?
2. **Succès à coût généralisé (A.8)** : table de conséquences mécanique ou maintien de l'arbitrage MJ IA au cas par cas ?
3. **Déguisements/infiltration (B.3)** : compatible avec « menus seulement », ou nécessite-t-il une nouvelle famille de menus (« se faire discret », « maintenir le déguisement ») ?
4. **Mode Arène (B.4)** : à exclure définitivement du périmètre, ou à garder en option non prioritaire pour une future « Phase 4 annexes » ?

---

## Renvois

- Priorités de Phase 2 (mécaniques canoniques structurantes) → **doc 14**.
- Statut MVP de chaque domaine → **doc 00 (Synthèse) §8**.
- Principe « catalogue défini, l'IA habille » à préserver sur tout nouveau contenu → **doc 08 §2, §6**.
- Impacts schéma de données pressentis → **doc 12**.
- Impacts UI pressentis → **doc 13**.
