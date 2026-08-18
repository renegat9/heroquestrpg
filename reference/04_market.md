# Conception — Marché & Économie

> Document d'analyse. Technologies décidées plus tard. Les prix sont **dérivés de l'armurerie HeroQuest, à équilibrer en playtest** (notés « ≈ »).

---

## 1. Principe directeur

**Les chiffres appartiennent au moteur ; l'habillage appartient à l'IA.** Le MJ IA choisit *quel* marchand on rencontre et le met en scène, mais **n'invente jamais un prix ni un stock** — sinon l'économie déraille. Le moteur fixe disponibilité et coûts via des **profils de lieu**.

---

## 2. Monnaie

**L'or**, monnaie unique. C'est une **bourse commune à la partie** (pas un solde par personnage — décision M3) : gagné par butin / quêtes / revente, dépensé au market. Géré par le moteur.

---

## 3. Profils de marché par type de lieu

Chaque lieu marchand applique un profil : catégories disponibles, raretés accessibles, multiplicateur de prix.

| Profil | Stock | Raretés | Multiplicateur | Exemple |
|---|---|---|---|---|
| **Village isolé** | Restreint, basiques | Commun | ≈ ×1,2 (pénurie) | hameau frontalier |
| **Bourg / avant-poste** | Moyen | Commun, peu commun | ≈ ×1,0 | poste de garde |
| **Cité marchande** | Large | Commun → rare | ≈ ×1,0 (concurrence) | capitale |
| **Marché noir / camp** | Variable | Rare, illicite | ≈ ×0,8 à ×1,5 (volatil) | repaire, contrebandiers |

> Le MJ IA sélectionne le profil cohérent avec le lieu narratif ; le moteur en dérive l'inventaire réel.

---

> ⚠ **L'étal ne présente pas ce qu'AUCUN membre du groupe ne peut utiliser**
> (décision de René, 2026-08-18 — même règle que le butin de mobilier, doc 17
> §3). Trois potions sont réservées au Barbare, deux à l'Elfe : un groupe qui
> n'en compte aucun ne se les voit plus proposer.
>
> ⚠ Cela ne referme **pas** l'achat pour autrui, et c'est délibéré : le filtre
> porte sur l'**union** des maîtrises de tous les membres actifs
> (`Equipement::tagsAccessiblesAux()`), donc une potion de barbare reste en rayon
> dès qu'un barbare est dans le groupe, quel que soit le joueur qui paie. La
> bourse est commune et le don existe. Ce qui disparaît, c'est seulement ce
> qu'aucun héros de ce groupe ne pourra jamais boire ni porter — et le badge
> « non maîtrisé » continue de dire à CE joueur ce que SON héros ne peut pas
> équiper.
>
> Liste de maîtrises vide = aucune restriction (**fail open**).

## 4. Catalogue

Stats des objets centralisées ici (référencées par les docs Combat et Sorts). Prix indicatifs à ajuster.

### Niveaux de rareté
Chaque objet porte une rareté qui détermine **où** il apparaît, son **stock** et son **prix** :

| Rareté | Disponibilité | Stock | Prix |
|---|---|---|---|
| **Commun** | Presque partout | Large | Base |
| **Peu commun** | Bourg et plus | Moyen | Base |
| **Rare** | Cité marchande / marché noir | Limité (souvent 1) | Majoré |
| **Unique** | **Jamais à l'achat** — uniquement butin de quête | — | — |

**Artefacts — les armes `unique`.** Une quête en offre **au plus une**, dans un
**coffre désigné** : la salle la plus profonde du donjon (doc 06 §9). Le héros qui
la fouille la reçoit. Aucune arme disponible — le groupe les détient déjà toutes,
l'unicité étant **par groupe** — → le coffre verse une grosse somme d'or à la place
(`deck_fouille.or_coffre`). Un artefact **ne s'achète pas, ne se revend pas, ne se
forge pas** ; il est retiré de la liste vendable, et une vente forcée est un 422.

Rappel de règle de combat (doc 03 §8) : `des_attaque` **remplace** la valeur du
porteur — l'arme fait l'attaque —, tandis que `des_defense` **s'ajoute**.

Les neuf artefacts sont la conversion du paquet de cartes `sjeng-artefacts.pdf`
(**reference/16_armurerie.md §9.1** ; registre machine : `config/cartes.php`).
Ils ont remplacé le 2026-08-09 sept artefacts **inventés** — Lame d'Aube, Kriss
du Fossoyeur, Arbalète des Murmures, Bâton des Sept Sceaux, Marteau du Gardien
de Pierre, Hache du Roi sous la Montagne, Fendoir des Titans — et la raison
compte : c'étaient sept **armes à dés croissants** (4, puis 5, puis 6). Le
Fendoir rendait toute l'armurerie caduque dès qu'on le trouvait. Un artefact ne
doit pas monter la courbe, il doit faire ce que rien d'autre ne fait.

| Artefact | Prix indicatif | Effet | Carte |
|---|---|---|---|
| Dague de jet magique | 700 | **1 PV garanti** (aucun dé, aucune défense) ; lancée elle est perdue ; interdite au contact | *Magical Throwing Dagger* |
| Talisman du Savoir | 800 | +2 Mind maximum ; aucune restriction de classe | *Talisman of Lore* |
| Fléau des Orques | 900 | 2 dés ; **frappe deux fois** contre un orque | *Orcs Bane* |
| Amulette du Nord | 1000 | +2 Body, +1 Mind — **barbare seul** | *Amulet of the North* |
| Brassards elfiques | 1000 | idem — **elfe seul** | *Elven Bracers* |
| Capuche du Magister | 1000 | idem — **magicien seul** | *Magister's Hood* |
| Runes naines | 1000 | idem — **nain seul** | *Dwarven Runestones* |
| Lame des Esprits | 1100 | 3 dés, **4 contre Squelette / Zombie / Momie** | *Spirit Blade* |
| Armure de Borin | 1200 | +2 défense, **sans malus de déplacement** (là est sa supériorité sur la plate) | *Borin's Armour* |

Le « prix indicatif » ne sert qu'à situer la pièce sur la courbe de puissance : il
n'est **jamais** payé ni encaissé. Valeurs à playtester, comme le reste des chiffres
du projet.

Cinq artefacts sont **verrouillés par une classe** : les quatre bijoux (un par
héros) et rien d'autre. Le coffre **écarte du tirage tout artefact qu'aucune
classe active du groupe ne pourrait porter** — sans quoi l'unique artefact d'une
quête pourrait être des Runes naines dans un groupe sans nain, c'est-à-dire du
butin mort. La règle croise `tag_equipement` avec les `tags_equipement` des
classes présentes et les nœuds `acces_equipement` de leurs arbres ; elle a
remplacé un test codé en dur sur le seul barbare.

**Un artefact appartient au GROUPE, pas à son découvreur** : il circule librement entre
héros au hub (§ Don d'objets ci-dessous). C'est ce qui rend le coffre jouable — sans
quoi des brassards elfiques tombés au nain seraient perdus pour la campagne. Donner
n'est pas vendre : l'interdiction de revente tient toujours.

## Don d'objets entre héros (doc 01 §7)

`POST /api/groupes/{identifiant}/dons` — **au hub uniquement**, comme équiper et forger.
Un joueur donne depuis **ses** héros vers **n'importe quel héros actif** du groupe, y
compris celui d'un autre joueur ; le receveur n'a rien à confirmer (vous jouez autour
d'une même table) mais sa **capacité de sac est vérifiée** — un don ne peut jamais lui
nuire. Le donneur, lui, peut être en dépassement : se délester est justement la façon
de régulariser un sac saturé par un butin de quête.

Une pièce **équipée** se déséquipe d'abord (ses dés doivent être proprement révoqués).
Les consommables se transfèrent par pile (`quantite`) et fusionnent chez le receveur.
Tout le reste **change de propriétaire sans changer de ligne d'inventaire**, ce qui
préserve les améliorations de Forge attachées à l'exemplaire.

> La rareté se combine au **multiplicateur du profil** (§3) : un objet rare reste cher en cité et introuvable dans un village.

> **Source.** Les 26 pièces ci-dessous sont la conversion carte par carte du
> paquet `sjeng-equipment.pdf` — prix, dés et mots-clés viennent des cartes, pas
> d'un arbitrage de table. Table complète avec le niveau de source de chaque
> valeur : **reference/16_armurerie.md §2.2**. Registre machine :
> `config/cartes.php`, verrouillé par `CartesSourcesTest`.

### Armes (20)
| Arme | Rareté | Prix | Effet |
|---|---|---|---|
| **Canne** | Commun | 125 | 1 dé ; diagonale. Ni barbare ni nain. |
| **Fronde** | Commun | 125 | 1 dé à distance ; inutilisable au contact. |
| **Dague** | Commun | 150 | 1 dé ; lançable (perdue au lancer). |
| **Fouet** | Commun | 175 | 1 dé ; diagonale. |
| **Bâton** | Commun | 200 | 2 dés ; diagonale ; **deux mains**. Toutes classes. |
| **Arc court** | Commun | 200 | 2 dés à distance ; deux mains. Ni magicien ni barbare. |
| **Épée courte** | Commun | 225 | 2 dés. |
| **Hachette** | Peu commun | 250 | 2 dés ; lançable. |
| **Lance** | Peu commun | 250 | 2 dés ; diagonale ; lançable. |
| **Rapière** | Peu commun | 275 | 2 dés ; diagonale. |
| **Épée large** | Peu commun | 300 | 3 dés ; **pas** de diagonale. |
| **Hallebarde** | Peu commun | 325 | 3 dés ; diagonale ; deux mains. |
| **Masse** | Peu commun | 350 | 3 dés. |
| **Épée longue** | Peu commun | 350 | 3 dés ; diagonale (livret p. 14). |
| **Arbalète** | Peu commun | 350 | 3 dés à distance ; inutilisable au contact. |
| **Fléau** | Peu commun | 400 | 3 dés ; diagonale. |
| **Hache de bataille** | Rare | 475 | 4 dés ; deux mains. Ni magicien ni elfe. |
| **Espadon** | Rare | 525 | 4 dés ; diagonale ; deux mains. Ni magicien ni elfe. |
| **Arc long** | Rare | 525 | 4 dés à distance ; deux mains. Ni magicien ni nain. |
| **Épée bâtarde** | Rare | 825 | 5 dés ; diagonale ; deux mains. Ni magicien ni elfe. |

*Carte non portée : la **Torche** (2 dés, dégâts de feu, éclaire la salle, dure
une quête) — nous n'avons ni éclairage ni type de dégât « feu ».*

### Armures (6)
Elles se **cumulent**, comme au plateau : casque + armure de corps + bouclier →
plafond de **6 dés de défense** (2 de base + 1 + 2 + 1).

| Pièce | Rareté | Prix | Emplacement | Effet |
|---|---|---|---|---|
| **Casque** | Commun | 125 | `casque` | +1 dé de défense. |
| **Bouclier** | Commun | 125 | `arme_secondaire` | +1 dé ; incompatible deux mains. |
| **Brassards** | Commun | 200 | `armure` | +1 dé — **magicien seul**. |
| **Cape de protection** | Peu commun | 350 | `armure` | +1 dé — **magicien seul**. |
| **Cotte de mailles** | Rare | 450 | `armure` | +1 dé de défense. |
| **Armure de plates** | Rare | 850 | `armure` | +2 dés ; **−2 cases de déplacement** (texte de la carte). |

Brassards et cape sont le premier — et le seul — équipement défensif du
magicien, qui restait sinon à 2 dés toute la campagne.

### Outils & consommables
| Objet | Rareté | Prix ≈ | Effet |
|---|---|---|---|
| **Trousse à outils** | Peu commun | 250 | Permet de désamorcer les pièges (clé pour le Nain). |
| **Potion de soin** | Commun | variable | Rend des Points de Body (doc Personnages, §potions). |
| **Parchemin** | Selon le sort | variable | Sort à usage unique ; rareté = puissance du sort (doc Sorts §6/§7). |

### Améliorations de Forge (Nain) — *prix fixe*
Forgées par le Nain **au hub, entre les quêtes**, payées sur la **bourse commune**. **Une seule amélioration par objet**, **permanente** et **attachée à l'objet** (doc Personnages §6). Les objets de rareté **Unique** (artefacts, butin de boss) **ne peuvent pas être améliorés**.

| Amélioration | Cible | Prix ≈ | Effet |
|---|---|---|---|
| **Affûtée** | arme | 150 | +1 dé d'attaque. |
| **Perforante** | arme | 250 | Annule 1 bouclier de la défense de la cible. |
| **Cruelle** | arme | 120 | Relance 1 dé d'attaque raté, 1×/combat. |
| **Renforcée** | armure / bouclier | 250 | +1 dé de défense. |
| **Allégée** | armure | 200 | Annule le malus de déplacement de l'armure lourde (récupère le 1d6, règle AP). |
| **Gardée** | armure / bouclier | 250 | Ignore le **premier état** subi d'un combat (étourdi / apeuré). |

### Alliés — *phase 2*
Recrutables contre or (+ entretien éventuel). Unité PNJ avec ses stats. Contrôle en multijoueur à trancher (doc Personnages, §9).

---

## 5. Acheter, vendre et phase marché

- **Achat** : prix catalogue × multiplicateur du profil. Bloqué si la bourse commune est insuffisante ou l'objet absent du profil.
- **Revente (M1)** : **50 % du prix de vente du marchand courant** (donc variable selon le profil de lieu) ; à défaut, 50 % du prix de base.
- **Marchandage (M2)** : reporté en **phase 2** — un jet de Mind réduira le prix d'un palier.

### La phase marché (téléphones + tablette)
Entrer dans un marché ouvre une **phase dédiée**, répartie sur les deux surfaces :

**Sur le téléphone de chaque joueur** (saisie individuelle) :
- Il choisit les objets qu'**il** achète (vers son propre sac) et ceux qu'**il** vend (depuis son inventaire).
- Chacun gère son propre panier, indépendamment des autres.

**Sur la tablette** (vue partagée) :
- **Or courant** (bourse commune) affiché en permanence.
- **Panier consolidé** : chaque ligne d'achat ou de vente est **étiquetée du nom du joueur** lié à l'objet.
- **Total projeté**, recalculé en direct sur l'ensemble des paniers : `or courant + ventes − achats`.

La transaction est **groupée et atomique** sur tous les paniers : chaque joueur valide son panier, et la phase se **finalise quand tous ont confirmé**. Rien n'est appliqué avant ; annulable jusque-là.

**Garde-fous à la confirmation :**
- Total projeté **≥ 0** (la bourse commune couvre l'ensemble des achats).
- Objets achetés **présents et en stock** dans le profil du lieu.
- Objets vendus **réellement possédés** par le joueur qui les vend.
- **Capacité de sac respectée** pour chaque personnage après application.

> L'or étant une **bourse commune** (M3), tous les paniers individuels se règlent sur le même solde ; le total projeté agrège les achats/ventes de tous les joueurs.

---

## 6. Variation d'accès

- Les objets **rares** n'apparaissent que dans les profils qui les autorisent (cité, marché noir).
- Certains objets ne s'obtiennent **que par butin de quête**, jamais à l'achat (récompenses uniques).
- Un lieu peut **manquer** d'une catégorie entière (un village n'a pas d'armurier lourd).

---

## 7. Rôle du MJ IA

- **Choisit** le profil de marché cohérent avec le lieu courant.
- **Incarne** le marchand (personnalité, dialogue, ambiance) — c'est là que vit la profondeur narrative.
- **Ne fixe pas** les prix ni les stocks : il lit ceux du moteur et les présente.
- Peut **proposer** un objet rare comme accroche narrative ; le moteur valide la disponibilité.

---

## 8. Intégration

- **Personnages** : `inventaire` et équipement équipé (l'**or** est au niveau de la partie, pas du personnage).
- **Combat** : stats d'armes/armures définies au §4.
- **Sorts** : parchemins comme marchandise ; difficulté d'usage côté doc Sorts.
- **Quêtes / MJ IA** : profils de lieu pilotés par le contexte narratif.

---

## 9. Périmètre

- **MVP** : bourse commune, profils de lieu, rareté des objets, catalogue armes/armures/consommables, **phase marché avec panier et confirmation atomique**.
- **Phase 2** : marchandage, alliés recrutables, marché noir volatil, fluctuations économiques liées aux événements de campagne.

---

## 10. Décisions actées

1. **Revente (M1)** : **50 % du prix de vente du marchand courant** (variable selon le lieu) ; à défaut, 50 % du prix de base.
2. **Marchandage (M2)** : **phase 2**.
3. **Armure de plates (AP)** : **−2 cases** de déplacement (`malus_deplacement`, texte de la carte ; cohérent avec Combat §3).
4. **Or (M3)** : **bourse commune au groupe** — ressource de la partie, pas du personnage.
5. **Prix dynamiques (M4)** : **statiques au MVP** (profils fixes) ; dynamiques en phase 2.