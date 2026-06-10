# Synthèse & revue d'ensemble

> Document chapeau. Porte d'entrée des 10 documents de conception. Recense l'architecture transversale, les décisions clés, les renvois inter-docs, les valeurs à équilibrer et les questions ouvertes restantes.

---

## 1. Le projet en une page

Un jeu de rôle **fondé sur HeroQuest**, avec un **maître de jeu IA**. Le combat tactique reste fidèle au jeu original ; la profondeur vient des jets de compétence, des choix d'action/dialogue et d'une histoire générée. Format **multijoueur** : une **tablette-hôte** (narration, écran partagé, TTS/ambiance) et un **téléphone par joueur** (interface individuelle). **Projet interne, auto-hébergé** (pas de diffusion grand public).

---

## 2. Principes d'architecture (transversaux)

1. **Moteur déterministe vs MJ IA.** Le moteur (code) fait autorité sur **toute mécanique** (dés, PV, combat, règles). L'IA **narre et propose**, ne résout jamais une mécanique. Principe fondateur, rappelé partout. Mise en œuvre : sorties d'IA sous **schéma** (structured outputs) + **validation moteur** contre le catalogue.
2. **Tablette-hôte + téléphones-clients.** État autoritaire sur l'hôte ; les téléphones envoient des intentions et affichent.
3. **Menus seulement.** Boucle de jeu : l'IA **narre → génère un menu de choix contextuels → le moteur résout**. Pas de texte libre. Surface de risque réduite.
4. **Catalogue défini, l'IA habille.** Monstres, objets, sorts de Dread et pièges sont des **blocs définis** ; l'IA les renomme/redécrit sans changer les effets.
5. **Mémoire en couches.** État vivant **toujours en contexte** (exact) ; journal d'événements ; **bible d'univers** récupérée par RAG, **une par groupe**.

---

## 3. Carte des documents

| # | Document | Couvre |
|---|---|---|
| 00 | **Synthèse** | Ce document : index, architecture, décisions, valeurs, ouvert |
| 01 | **Personnages** | Body/Mind (attribut vs PV), jets, 4 héros, arbres, sac à dos |
| 02 | **Sorts** | 4 éléments, 12 sorts, parchemins, récupération |
| 03 | **Combat** | Règles HeroQuest, déplacement, dés, tour, initiative |
| 04 | **Market** | Bourse commune, profils de lieu, rareté, phase marché |
| 05 | **Session & Multijoueur** | Groupe/campagne, roster, arrivée/départ, vote |
| 06 | **Quêtes & MJ IA (1/3)** | Boucle MJ IA, génération, tuiles, arc, ramification, hub |
| 07 | **Quêtes & MJ IA (2/3)** | Mémoire, RAG, gestion du contexte long |
| 08 | **Quêtes & MJ IA (3/3)** | Garde-fous : règles, cohérence, ton/contenu |
| 09 | **Bestiaire** | Monstres, sous-boss/boss, sorts de Dread |
| 10 | **Pièges & hasards** | Catalogue de pièges, détection, désarmement |
| 11 | **Architecture technique** | Stack Docker : Laravel/Vue, MariaDB, Qdrant, flux, données |
| 12 | **Schéma de données & base de connaissance** | Tables MariaDB + collection/payload Qdrant |

---

## 4. Décisions clés par domaine

**Personnages** — Attribut Body/Mind **séparé** des PV ; jets en dés de combat (crânes ; difficulté = succès requis). Mort **relevable sinon définitive** (P1) ; récupération **entre quêtes + potions** (P2) ; **aucun plafond** d'attribut (P3) ; réussite **mixte** (P4) ; inventaire **par emplacements** (P5) ; niveaux **liés aux quêtes** (P6). Sac = **PV Body max ÷ 2** (Nain +1, nœud *Solides épaules* +2).

**Sorts** — 4 éléments, 12 sorts ; récupération **1×/quête** ; parchemins à **difficulté variable** (S1) via jet de Mind ; sorts mentaux **binaires** (S2) ; **tir ami possible** (S3) ; **pas de fabrication** (S4) ; **aucun repos** (S5) ; *Concentration* = **1×/quête en sacrifiant son tour** (S6).

**Combat** — Fidèle HeroQuest ; déplacement **base + 1d6** ; initiative **figée par quête, par personnage** (C1) ; monstres **scriptés** (C2) ; **pas d'attaque d'opportunité** (C3) ; figure tombée **occupe sa case, relevable** (C4) ; armure de plates = **base seule** (AP).

**Market** — **Bourse commune** (M3) ; profils de lieu ; rareté **Commun/Peu commun/Rare/Unique** ; **phase marché atomique**, paniers par joueur étiquetés ; revente **50 % du prix marchand** (M1) ; marchandage **phase 2** (M2) ; prix **statiques** au MVP (M4).

**Session** — Groupe = **session IA** + bible propre ; création avec **thème (fantasy)** + **longueur** ; **roster persistant** par joueur ; **multi-groupes**, 1 personnage actif par groupe ; arrivée (nouveau **entre quêtes**, reconnexion à tout moment) ; départ (**vote** en quête, libre hors quête, **égalité = reste**).

**Quêtes & MJ IA** — Génération **hybride** (Q1) ; cartes **par tuiles** (Q2) ; **menus seulement** (Q3) ; quêtes **ramifiées convergeant vers les boss** (Q4) ; **ville persistante** (Q5) ; **catalogue défini** (Q6) ; **arc** sous-boss + boss final selon la longueur ; **génération en deux temps** — squelette à la création + détail à la volée (Q10). Mémoire **par groupe** (Q7) ; **versement en bibliothèque au seuil + compactage** (Q8). Ton **configurable par groupe** avec **défaut sûr et bornes non désactivables** (Q9).

**Bestiaire** — 8 monstres de base ; **Mind 0 = immunité mentale** ; sous-boss/boss = blocs élites + capacités ; **sorts de Dread répartis sur tout l'arc** (mineurs aux sous-boss, vilains au boss).

**Pièges** — 4 pièges de base ; détection (*Fouiller* / Nain) ; désarmement (Body / Trousse) ; déclenchement.

---

## 5. Toile des dépendances inter-docs

- **PV de Mind** : résistance aux sorts mentaux (Sorts), aux **sorts de Dread** (Bestiaire), usage des parchemins (Sorts). Le Mind est le **bouclier anti-magie ennemie**.
- **Or = bourse commune** (Market) → sorti des champs du personnage (Personnages), réglé en phase marché par joueur (Market).
- **Sac à dos** = PV Body max ÷ 2 (Personnages) → vérifié à la confirmation de la phase marché (Market).
- **Catalogue défini** (Q6) → s'applique aux monstres, objets, pièges et sorts de Dread (Bestiaire, Market, Pièges).
- **Initiative par personnage** (Combat C1) ← un joueur peut contrôler plusieurs personnages (Session).
- **Bible par groupe** (Mémoire) ← `groupe_actif_id` (Personnages), identifiant de groupe (Session).
- **Arc à boss** (Quêtes) ← blocs de sous-boss/boss et Dread (Bestiaire) ; difficulté croissante ← aucun plafond P3 (Personnages).
- **Nain** : *Œil du mineur* / *Désamorçage* / *Sang robuste* (Personnages) + Trousse à outils (Market) → cœur du système de pièges (Pièges).

---

## 6. Valeurs à équilibrer (playtest)

Tout ce qui est chiffré est une **proposition de départ** :

- Attributs Body/Mind, PV, dés Attaque/Défense, déplacement de base, **par héros**.
- Difficulté des jets (succès requis), capacité de sac, effets des nœuds d'arbre.
- Difficulté des parchemins par sort (1/2/3).
- Prix du catalogue, multiplicateurs de profil, taux de revente (50 %), **prix des améliorations de Forge**.
- Stats des 8 monstres, échelle des sous-boss/boss, **cadence des sous-boss par longueur**.
- Nombre d'**usages de Dread** par rencontre.
- Dégâts des pièges.
- Effets et durées des **états** (conditions).
- Cadence de niveau (~5 à 8 par campagne).
- **Seuil de remplissage** du contexte (versement/compactage).

---

## 7. Questions ouvertes restantes

Les docs 01 à 05 sont **entièrement tranchés**. Restent :

**Quêtes (06)** — profondeur de ramification ; bibliothèque de tuiles fixe ou extensible ; choix de la quête suivante (imposé vs offres au hub).
**Mémoire (07)** — seuil exact (%) ; grain des entrées de bible ; déduplication/conflits ; oubli volontaire.
**Garde-fous (08)** — crans de ton ; détection mineurs / mode famille ; modération si on pousse l'IA hors bornes ; transparence du filtrage.
**Bestiaire (09)** — échelonnage exact des boss ; taille de la bibliothèque de capacités/Dread ; déplacement des monstres (fixe ?) ; butin (tables vs gabarit) ; usages de Dread.
**Pièges (10)** — dégâts par piège ; détection auto vs jet de Mind ; désamorçage raté ; difficulté du franchissement ; réarmement.

---

## 8. Périmètre MVP vs Phase 2

**MVP** — Règles complètes (personnages, sorts, combat, market, session) ; boucle MJ IA en menus ; génération hybride + tuiles ; arc de campagne (sous-boss + boss final) ; ramification simple ; bible + RAG ; 8 monstres + boss avec sorts de Dread ; 4 pièges ; bourse commune ; phase marché atomique.

**Phase 2** — Alliés recrutables ; marchandage ; économie dynamique ; ramifications profondes ; lanceurs de Dread autonomes ; bestiaire élargi & boss multi-phases ; pièges magiques / de salle ; succès à coût généralisé ; événements de ville dynamiques.

---

## 9. Prochaines étapes (technique)

Stack choisie (doc 11) : **Laravel + Vue**, **MariaDB** (+ phpMyAdmin), **Qdrant**, en **Docker** (base Sail). Reste :

1. **Schéma de données** : MariaDB + Qdrant détaillés (doc 12). ✓
2. **Migrations Laravel + seeders** des catalogues (bestiaire, objets, sorts, tuiles, pièges).
3. **Prototype vertical** : une seule quête jouable — combat + un jet + un menu de dialogue — pour valider la boucle MJ IA (Laravel + Reverb + Vue) avant d'élargir.
