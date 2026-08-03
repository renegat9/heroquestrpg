# Conception — Système de Personnages

> Document d'analyse. Les technologies seront décidées plus tard ; ici on raisonne uniquement sur les règles et les données. Les valeurs chiffrées sont des **propositions de départ à équilibrer en playtest**, pas des chiffres définitifs.

---

## 1. Philosophie de conception

Le personnage repose sur **deux statistiques seulement — Body et Mind** — pour rester simple et fidèle à HeroQuest. La profondeur ne vient pas d'une multiplication d'attributs, mais de **l'arbre de compétences propre à chaque héros**. Division du travail :

- **Body / Mind** → résolvent les jets bruts (réussir une action, résister, percevoir, convaincre).
- **Arbre de compétences** → porte l'identité et la spécialisation de chaque héros.

---

## 2. Point critique : attribut ≠ points de vie

C'est la décision structurante du système. Dans HeroQuest d'origine, « Body Points » et « Mind Points » sont des **jauges de vie**. Pour pouvoir *faire des jets* de Body et de Mind sans casser le système de vie, on **dédouble** chaque axe :

| Élément | Rôle | Évolue ? |
|---|---|---|
| **Attribut Body** | Nombre de dés roulés pour un jet physique | Monte avec les niveaux |
| **Attribut Mind** | Nombre de dés roulés pour un jet mental | Monte avec les niveaux |
| **Points de Body (PV physiques)** | Jauge de vie ; à 0 → le héros tombe | Baisse aux dégâts, se régénère |
| **Points de Mind (PV mentaux)** | Résistance à la peur, magie, corruption ; à 0 → état mental brisé | Baisse, se régénère |

À cela s'ajoutent les valeurs de combat HeroQuest classiques, **distinctes des attributs** :

| Valeur de combat | Rôle |
|---|---|
| **Dés d'Attaque** | Dés de combat roulés à l'attaque |
| **Dés de Défense** | Dés de combat roulés en défense |
| **Déplacement** | Valeur de base (selon le héros) **+ 1d6** par tour |

> En clair : un personnage a *attribut Body/Mind* (jets), *Points Body/Mind* (vie), *Attaque/Défense* (combat). Trois familles de chiffres qu'il ne faut pas confondre.

---

## 3. Système de jet de compétence

On réutilise les **dés de combat HeroQuest** (6 faces : 3 crânes, 2 boucliers blancs, 1 bouclier noir → un crâne sort 1 fois sur 2).

**Règle proposée :** le joueur lance un nombre de dés de combat égal à son attribut concerné (Body ou Mind). Chaque **crâne = 1 succès**. Le MJ fixe une **difficulté** = nombre de succès requis.

| Difficulté | Succès requis | Exemple |
|---|---|---|
| Facile | 1 | Forcer une porte branlante |
| Moyenne | 2 | Crocheter une serrure, convaincre un garde hésitant |
| Difficile | 3 | Désamorcer un piège complexe, déchiffrer une rune ancienne |
| Très difficile | 4+ | Exploit héroïque |

Probabilités indicatives (≈50 % de crâne par dé) : 3 dés → ~50 % d'atteindre 2 succès ; 4 dés → ~31 % d'atteindre 3 succès. À ajuster en playtest.

### Ce que couvre chaque attribut

- **Body** : force, agilité, endurance, discrétion physique, athlétisme, résistance physique.
- **Mind** : savoir, perception, volonté, persuasion / dialogue, résistance à la magie, intuition.

> C'est ainsi que les **choix de conversation** prennent vie : un échange social tendu se résout par un jet de Mind, modulé par des nœuds d'arbre (ex. *Intimidation* du Barbare).

---

## 4. Les quatre héros (profils de départ)

Valeurs de vie tirées du canon HeroQuest ; attributs de jet = proposition nouvelle.

> **La colonne « Attaque » est la valeur AVEC l'arme de départ**, pas une force innée.
> Comme au plateau, **l'attaque vient de l'arme équipée** (doc 03 §8) : à mains nues tout
> héros lance **1 dé**. Les 3/2/2/1 ci-dessous sont produits par l'équipement initial —
> Barbare épée large (3), Nain hachette (2, lançable) + trousse à outils, Elfe épée courte (2),
> Magicien dague (1). La **défense**, elle, vaut **2 pour tous** sans armure, et les pièces
> d'armure s'y **ajoutent** (casque +1, bouclier +1…).

| Héros | Body (PV) | Mind (PV) | Attr. Body | Attr. Mind | Attaque | Défense | Dépl. base | Identité |
|---|---|---|---|---|---|---|---|---|
| **Barbare** | 8 | 2 | 4 | 1 | 3 | 2 | 4 | Brute de combat |
| **Nain** | 7 | 3 | 3 | 2 | 2 | 2 | 3 | Robuste, technique (pièges) |
| **Elfe** | 6 | 4 | 2 | 3 | 2 | 2 | 5 | Polyvalent, magie légère |
| **Magicien** | 4 | 6 | 1 | 4 | 1 | 2 | 4 | Lanceur de sorts, fragile |

---

## 5. Progression : niveaux

Progression **par jalons** : aucune accumulation de points, rien ne se gagne au monstre. On monte de niveau en franchissant des **jalons de campagne**.

**Déclencheurs de montée** (cadence visant ~5 à 8 niveaux par campagne) :
- chaque **sous-boss** vaincu (fin d'acte, doc Quêtes §4),
- le **boss final**,
- certains **objectifs de quête majeurs** marqués par le gabarit.

> La cadence exacte se cale sur l'arc selon la longueur (lien avec la cadence des sous-boss, doc Quêtes §10).

**Gains au passage de niveau :**
- **+1 point de compétence** à dépenser dans l'arbre (gain principal) ;
- à certains paliers, **+1 Point de Body ou de Mind** selon la classe ;
- les **attributs Body/Mind** ne montent **que** via des nœuds dédiés de l'arbre — choix significatif, pas d'automatisme. **Aucun plafond** (P3) ; le MJ IA relève la difficulté en conséquence.

---

## 6. Arbres de compétences (brouillon de départ)

Garder **petit pour le MVP** : ~6 à 8 nœuds par héros. Trois types de nœuds : **passif** (bonus permanent), **actif** (capacité déclenchable), **déblocage** (accès à équipement/sort).

### Barbare
- *(passif)* **Carrure** : +1 Point de Body.
- *(actif)* **Coup puissant** : relance une fois les dés d'attaque ratés.
- *(déblocage)* **Maîtrise lourde** : débloque les **armures lourdes** (plates). Les armes à deux mains, elles, lui sont acquises d'emblée.
- *(passif)* **Intimidation** : avantage aux jets de Mind sociaux par la peur.
- *(actif)* **Frénésie** : +1 dé d'attaque quand sous la moitié des PV.

### Nain
- *(passif)* **Œil du mineur** : détecte automatiquement les pièges adjacents.
- *(actif)* **Désamorçage** : tente de neutraliser un piège (jet de Body).
- *(passif)* **Garde tenace** : +1 dé de défense contre la première attaque d'un combat.
- *(déblocage)* **Forge** : **améliore** un équipement de façon **permanente** (+1 dé ou une propriété).
- *(passif)* **Sang robuste** : résistance au poison.
- *(passif)* **Solides épaules** : +2 emplacements de sac à dos.
- *(déblocage)* **Poigne de forgeron** : débloque les **armes à deux mains**. (Le nain porte l'**armure lourde** sans nœud — c'est le robuste du groupe.)

### Elfe
- *(passif)* **Pas léger** : +1 déplacement.
- *(actif/déblocage)* **Première magie** : 1 emplacement de sort (un élément).
- *(passif)* **Sens aiguisés** : bonus aux jets de Mind de perception.
- *(actif)* **Tir précis** : avantage en attaque à distance.
- *(déblocage)* **Second élément** : un domaine de sort supplémentaire.

### Magicien
- *(passif)* **Réserve arcanique** : emplacement de sort supplémentaire.
- *(déblocage)* **Écoles** : accès à de nouveaux domaines (Feu, Eau, Terre, Air).
- *(actif)* **Concentration** : une fois par quête, sacrifie son tour pour récupérer un sort épuisé.
- *(actif)* **Contresort** : annule un effet magique (jet de Mind).
- *(passif)* **Érudition** : avantage aux jets de Mind de savoir.

> Détail des sorts → document séparé (`Sorts`), référencé par les nœuds de déblocage.
>
> **Forge (Nain)** : amélioration **permanente et attachée à l'objet** — elle survit aux échanges, à la répartition de fin de campagne et passe dans le **roster**. Réalisée **au hub, entre les quêtes**, contre de l'**or**. **Un objet n'est améliorable qu'une fois** ; le Nain peut en forger **plusieurs** différents. Les **artefacts** (objets de rareté Unique) ne sont **pas** améliorables. Catalogue & prix : **doc Market §4**.

---

## 7. Équipement et inventaire

### Emplacements équipés
Chaque héros porte un équipement réparti en emplacements fixes, **distincts du sac à dos** :
- **Arme principale**
- **Arme secondaire / bouclier**
- **Armure**

Chaque pièce équipée modifie les valeurs de combat (ex. épée large = +1 dé d'attaque) ou débloque une action. (Cartes d'équipement HeroQuest réutilisées — stats au doc Market.)

### Maîtrises d'équipement (qui peut porter quoi)

Chaque pièce d'arme ou d'armure porte **un tag de maîtrise** ; chaque classe en
autorise un ensemble **de base**, et les nœuds de type `deblocage` en ouvrent
d'autres, au prix d'un point de compétence. Profil retenu : **canon HeroQuest** —
le magicien est le seul vraiment bridé, comme au plateau.

| | armes | armures |
|---|---|---|
| **Barbare** | légère, courante, distance, **deux mains** | légère, bouclier |
| **Nain** | légère, courante, distance | légère, bouclier, **lourde** |
| **Elfe** | légère, courante, distance | légère, bouclier |
| **Magicien** | légère **seule** (dague, bâton) | **aucune** |

| Tag | Pièces | Ouvert par |
|---|---|---|
| `arme_legere` | Dague, Bâton, Bâton des Sept Sceaux | *(base, toutes classes)* |
| `arme_courante` | Épée courte, Lance, Épée large, Kriss du Fossoyeur, Lame d'Aube | base sauf magicien → *Escrime de fortune* |
| `arme_distance` | Arbalète, Arbalète des Murmures | base sauf magicien |
| `arme_deux_mains` | Hache de bataille, Marteau du Gardien, Hache du Roi, Fendoir des Titans | base pour le **barbare** ; *Poigne de forgeron* (nain) |
| `armure_legere` | Casque, Cotte de mailles | base sauf magicien → *Cuir d'apprenti* |
| `bouclier` | Bouclier | base sauf magicien |
| `armure_lourde` | Armure de plates | base pour le **nain** ; *Maîtrise lourde* (barbare) |

> **Symétrie des deux costauds** : chacun a sa spécialité gratuite et paie l'autre.
> Le barbare manie les armes à deux mains de naissance et achète l'armure lourde ;
> le nain porte l'armure lourde de naissance et achète les armes à deux mains.

Deux précisions qui évitent des contresens :

- **`deux_mains` n'est pas une maîtrise.** Il dit seulement « pas de bouclier
  avec » ; c'est le **tag** qui dit qui a le droit de porter. Le *Bâton des Sept
  Sceaux* est à deux mains **et** `arme_legere` : il reste l'artefact du magicien.
- **Aucune maîtrise déclarée = aucune restriction.** Si le catalogue des classes
  n'est pas semé, le moteur échoue *ouvert* : une donnée de référence manquante
  ne doit jamais verrouiller un héros hors de son propre équipement de départ.

Un objet **déjà équipé** n'est jamais retiré rétroactivement : la vérification
n'a lieu qu'au moment d'équiper.

Au **marché**, une pièce que le héros ne maîtrise pas porte un badge
« Non maîtrisé », mais reste **achetable** : la bourse est commune et le don entre
héros existe, donc acheter pour un coéquipier est un usage normal.

### Sac à dos
- **Capacité = PV de Body *max* ÷ 2** (arrondi inférieur, sur le max et non les PV courants), **+1 pour le Nain** (bonus racial) : **Barbare 4, Nain 4, Elfe 3, Magicien 2**. Le nœud *Solides épaules* du Nain l'augmente encore.
- Le sac stocke les **armes et armures non équipées** ; les emplacements équipés ne comptent pas dans la capacité.
- Les **consommables** (potions, parchemins) et les **objets de quête** sont **illimités** : ils ne consomment jamais la capacité du sac.
- Au **ramassage d'un trésor** : l'objet va au sac s'il reste de la place ; **sac plein → il faut jeter un objet** pour le prendre (tension de gestion).

### Gérer son équipement = une action
Réorganiser son stuff coûte **l'action du tour** (voir doc Combat) :
- **Équiper / déséquiper** : déplacer un objet entre un emplacement équipé et le sac.
- **Jeter** un objet du sac (libère de la place).
- **Échanger avec un joueur adjacent** : transférer armes/armures entre les deux inventaires, dans la limite des capacités respectives.

L'interface affiche en parallèle les **objets équipés** et le **contenu du sac** des personnages concernés.

> **Implémenté (2026-07-30) — le don au hub.** L'échange existe désormais, mais
> **entre deux quêtes**, pas comme action de tour : `POST /groupes/{id}/dons`,
> depuis ses propres héros vers n'importe quel héros actif du groupe. La capacité
> du **receveur** est respectée ; celle du donneur peut être en dépassement (se
> délester est la façon de régulariser un sac saturé par un butin). Une pièce
> équipée se déséquipe d'abord. Les consommables se transfèrent par pile.
> Un **artefact circule** : il appartient au groupe, pas à son découvreur.
>
> L'échange **en pleine quête** (adjacence + coût d'action, tel que décrit
> ci-dessus) reste à faire, comme « équiper » en quête et « jeter un objet ».

### Or
- **À l'arrivée dans un groupe** : l'or **personnel** du personnage est **versé au pot commun** du groupe.
- **En campagne** : bourse **commune au groupe** (M3), gérée par le moteur (jamais inventée par l'IA) — voir doc Market.
- **Départ entre deux quêtes** : le personnage repart avec sa **part égale** du pot (pot ÷ membres présents).
- **À la clôture** : la bourse commune est **répartie entre les personnages** vers leur **bourse personnelle persistante** (roster), avec l'équipement (doc Session §6).

---

## 8. Potions et consommables

Effet immédiat à usage unique, simples à modéliser : soin (rend des Points de Body), restauration mentale, buffs temporaires (ex. +1 dé d'attaque pour un combat), antidote. Stockés comme objets d'inventaire avec un compteur d'usage.

---

## 9. Personnage vs Joueur (multijoueur)

Distinction importante pour l'architecture tablette-hôte + téléphones :

- Un **Joueur** est une personne (un appareil connecté).
- Un **Personnage** est l'entité de jeu (héros).
- Un Joueur **contrôle** un Personnage. Lien explicite à stocker.
- Les **alliés** (phase 2) sont des Personnages sans Joueur, ou assignés temporairement — à trancher.

---

## 10. Conditions / états

Une condition = **effet + durée + source**, attachée à un personnage **ou à un monstre**. Catalogue de base :

| État | Effet | Durée typique | Sources |
|---|---|---|---|
| **Empoisonné** | −1 PV de Body par tour | quelques tours | piège de coffre, attaque ; Nain *Sang robuste* résiste |
| **Étourdi** | perd son prochain tour | 1 tour | choc, capacité |
| **Apeuré** | −dés d'attaque ; ne peut avancer vers la menace | jusqu'à résistance | Dread *Frayeur* |
| **Endormi** | hors combat jusqu'à être réveillé/attaqué | jusqu'au réveil | sort *Sommeil*, Dread |
| **Commandé** | contrôlé un tour (agit pour l'ennemi) | 1 tour | Dread *Commandement* |
| **Ralenti** | déplacement réduit | quelques tours | capacité, terrain |
| **Immobilisé** | ne peut se déplacer (coincé) | jusqu'à libération | fosse (piège) |
| **Caché** | ne peut être attaqué | jusqu'à son prochain tour | sort *Voile de Brume* |
| **Renforcé** | +dés (attaque ou défense) | 1 combat / durée du sort | *Courage*, *Peau de Pierre*, potions |
| **Tombé** | hors de combat à 0 PV de Body ; relevable | jusqu'à relève ou fin de combat | dégâts (P1/C4) |

> Les **morts-vivants (Mind 0)** sont immunisés aux états **mentaux** — apeuré, endormi, commandé (doc Bestiaire).

---

## 11. Modèle de données conceptuel (agnostique)

Champs du **Personnage** (la techno viendra plus tard) :

| Champ | Type | Note |
|---|---|---|
| `id` | identifiant | unique |
| `nom` | texte | |
| `classe` | énum | Barbare / Nain / Elfe / Magicien |
| `niveau` | entier | progression par jalons |
| `attribut_body` | entier | dés de jet physiques |
| `attribut_mind` | entier | dés de jet mentaux |
| `pv_body_max` / `pv_body` | entier | jauge de vie physique |
| `pv_mind_max` / `pv_mind` | entier | jauge de vie mentale |
| `des_attaque` | entier | combat |
| `des_defense` | entier | combat |
| `deplacement_base` | entier | base de déplacement du héros ; total par tour = base + 1d6 |
| `equipement_equipe` | objet | arme principale, arme secondaire/bouclier, armure |
| `sac` | liste | armes/armures non équipées |
| `capacite_sac` | entier | dérivé : PV Body max ÷ 2 (arrondi inf.) + Nain +1 + bonus d'arbre |
| `sorts_connus` | liste | renvoie au doc Sorts |
| `competences_acquises` | liste | nœuds d'arbre débloqués |
| `conditions` | liste | états temporaires + durée |
| `joueur_id` | identifiant | qui contrôle ce personnage |
| `groupe_actif_id` | identifiant | groupe où le personnage est engagé (un seul actif à la fois) ; nul si au repos |
| `or` | entier | bourse **personnelle persistante** (roster) ; en campagne, l'or est commun au groupe (M3) |
| `historique` | relation | résumés des campagnes terminées (table dédiée) |

> Deux niveaux d'or : **commun au groupe** pendant une campagne (M3), **personnel au personnage** une fois réparti à la clôture (doc Session §6).

---

## 12. Périmètre

- **MVP** : Body/Mind (attribut séparé des PV) · jet de compétence par dés · 4 héros · arbre réduit (~6 nœuds) · équipement + or · potions · lien joueur↔personnage.
- **Phase 2** : alliés contrôlables · arbres étendus · conditions avancées · économie variant finement selon le lieu.

---

## 13. Décisions actées

1. **Mort (P1)** : à 0 PV de Body, le héros est **« tombé »** (occupe sa case), relevable par soin ou allié. **Mort définitive** s'il n'est pas relevé avant la fin du combat.
2. **Récupération (P2)** : PV et sorts **récupèrent intégralement entre les quêtes** ; les **potions** soignent en cours de quête. Pas de récupération par repos.
3. **Attributs (P3)** : **aucun plafond** ; le MJ IA monte la difficulté des jets pour conserver le défi.
4. **Réussite des jets (P4)** : **mixte** — selon le contexte, un quasi-échec donne un « succès à coût » ou un échec sec ; arbitrage du MJ IA.
5. **Inventaire (P5)** : **limité par emplacements**.
6. **Niveaux (P6)** : montée **liée à l'achèvement des quêtes** (~5 à 8 niveaux par campagne).
