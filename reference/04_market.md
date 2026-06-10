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

> La rareté se combine au **multiplicateur du profil** (§3) : un objet rare reste cher en cité et introuvable dans un village.

### Armes
| Arme | Rareté | Prix ≈ | Effet |
|---|---|---|---|
| **Dague** | Commun | 25 | 1 dé d'attaque ; jetable (à distance, 1×/combat). |
| **Bâton** | Commun | 100 | 1 dé ; attaque en diagonale (utile aux lanceurs). |
| **Épée courte** | Commun | 150 | 2 dés. |
| **Lance** | Peu commun | 250 | 2 dés ; attaque en diagonale et au 2ᵉ rang. |
| **Épée large** | Peu commun | 350 | 3 dés ; pas d'attaque diagonale. |
| **Arbalète** | Peu commun | 350 | 3 dés à distance (ligne de vue) ; inutilisable si ennemi adjacent. |
| **Hache de bataille** | Rare | 450 | 4 dés ; deux mains (pas de bouclier) ; diagonale. |

### Armures
| Pièce | Rareté | Prix ≈ | Effet |
|---|---|---|---|
| **Casque** | Commun | 125 | +1 dé de défense. |
| **Bouclier** | Commun | 150 | +1 dé de défense ; incompatible armes à deux mains. |
| **Cotte de mailles** | Peu commun | 500 | +1 dé de défense. |
| **Armure de plates** | Rare | 850 | +2 dés de défense ; *déplacement = base seule, sans le 1d6* (décision AP). |

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
3. **Armure de plates (AP)** : déplacement = **base seule, sans le 1d6** (cohérent avec Combat).
4. **Or (M3)** : **bourse commune au groupe** — ressource de la partie, pas du personnage.
5. **Prix dynamiques (M4)** : **statiques au MVP** (profils fixes) ; dynamiques en phase 2.