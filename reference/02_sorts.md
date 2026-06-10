# Conception — Système de Sorts

> Document d'analyse. Technologies décidées plus tard. Valeurs chiffrées = **propositions à équilibrer en playtest**. S'appuie sur les décisions actées : récupération **une fois par quête**, parchemins utilisables **par tous via jet de Mind**.

---

## 1. Vue d'ensemble

La magie reste **fidèle à HeroQuest** : sorts regroupés par **éléments**, **résolution majoritairement automatique** (pas de jet de toucher), et **récupération entre les quêtes**. La profondeur vient de deux ajouts : les **parchemins** (accès ponctuel hors répertoire) et le branchement de certains effets sur les **Points de Mind** définis dans le doc Personnages.

> La magie **ennemie** (sorts de Dread) suit les mêmes règles de résolution et est définie dans le doc Bestiaire ; le **Mind des héros sert de défense** contre elle.

---

## 2. Qui lance des sorts

| Héros | Accès |
|---|---|
| **Magicien** | Lanceur complet. Démarre avec **2 éléments au choix**, débloque les autres via les nœuds *Écoles* de son arbre. |
| **Elfe** | Magie légère. **1 élément** via le nœud *Première magie* ; *Second élément* via l'arbre. |
| **Barbare / Nain** | Pas de sorts connus. Peuvent utiliser des **parchemins** (voir §6). |

---

## 3. Acquisition des sorts

- À la création, le lanceur **choisit ses éléments** de départ (parmi Feu, Eau, Terre, Air). Connaître un élément = connaître ses 3 sorts.
- La progression débloque de **nouveaux éléments** via l'arbre de compétences, pas automatiquement.
- Les **parchemins** donnent un accès *ponctuel* à un sort, même hors de son répertoire.

---

## 4. Modèle de récupération — *une fois par quête*

- Chaque sort connu est **lançable une fois par quête**.
- Une fois lancé, il est **épuisé** jusqu'à la fin de la quête.
- **Réinitialisation complète entre les quêtes** (tous les sorts redeviennent disponibles).
- **Aucun repos en cours de quête** : la seule récupération possible pendant une quête vient du nœud *Concentration* du Magicien, qui peut **une fois par quête sacrifier son tour** pour récupérer un sort épuisé.

> Modèle simple et fidèle : aucune jauge de mana à suivre. Le moteur marque juste chaque sort comme « disponible / épuisé ».

---

## 5. Résolution d'un sort

- La plupart des sorts **se résolvent automatiquement** (pas de jet de réussite du lanceur).
- **Sorts de dégâts** → infligent des dés de combat sur les **Points de Body** de la cible ; la cible peut lancer ses **dés de défense** (règle de combat de base).
- **Sorts mentaux** (sommeil, peur, contrôle) → opposés aux **Points de Mind** : la cible tente un **jet de Mind** pour résister ; échec = subit l'effet. C'est le pont entre la magie et la jauge mentale.
- **Sorts utilitaires** (déplacement, soin, défense) → effet direct, sans opposition.
- **Tir ami possible** : un sort offensif de zone ou à distance peut toucher un **allié mal placé** ; le placement avant de lancer devient un vrai choix tactique.

---

## 6. Les parchemins (consommables)

- **Usage unique** : consommé à l'activation, qu'elle réussisse ou échoue.
- **Source** : butin de quête ou achat au market (voir doc Market).
- **Donne accès** à un sort, y compris hors du répertoire du personnage.
- **Activation** :
  - **Lanceur de sorts** (Magicien / Elfe) → **réussite automatique**.
  - **Non-lanceur** (Barbare / Nain) → **jet de Mind** dont la difficulté **dépend du sort** (1 à 3 succès, voir §7). Échec = parchemin gaspillé sans effet.
- Effet et résolution = identiques au sort correspondant (§5).

> Conséquence de design : les parchemins donnent un goût de magie à tous, valorisent l'attribut Mind même chez les guerriers, et créent un crochet de butin/économie — sans toucher à l'économie des lanceurs.

---

## 7. Liste des sorts par élément

Adaptés de HeroQuest à notre système. Dégâts exprimés en **dés de combat**, soins en **Points de Body**.

**Difficulté d'usage par parchemin** (non-lanceur, jet de Mind), selon la puissance du sort :
- **1 succès (mineur)** : Trait de Feu, Vent Véloce, Traverser la Pierre.
- **2 succès (standard)** : Courage, Voile de Brume, Peau de Pierre, Soin du Corps, Eau de Guérison.
- **3 succès (puissant)** : Boule de Feu, Génie, Sommeil, Tempête.

### Feu — offensif
| Sort | Effet |
|---|---|
| **Boule de Feu** | Attaque à distance, **2 dés** de dégâts (défense applicable). |
| **Courage** | **+2 dés d'attaque** à un héros pour sa prochaine attaque. |
| **Trait de Feu** | Attaque à distance, **1 dé** de dégâts. |

### Eau — contrôle / soin
| Sort | Effet |
|---|---|
| **Sommeil** | Un monstre tente un jet de Mind ; échec → hors combat (endormi) jusqu'à être réveillé/attaqué. |
| **Voile de Brume** | Un héros devient **indétectable** : ne peut être attaqué jusqu'à son prochain tour. |
| **Eau de Guérison** | Rend jusqu'à **4 Points de Body** à un héros. |

### Terre — défense / soin
| Sort | Effet |
|---|---|
| **Soin du Corps** | Rend jusqu'à **4 Points de Body** (lançable sur soi). |
| **Traverser la Pierre** | Le héros franchit **un mur** (vaut son déplacement). |
| **Peau de Pierre** | **+2 dés de défense** à un héros jusqu'à la fin du combat. |

### Air — mobilité / puissance
| Sort | Effet |
|---|---|
| **Génie** | Invoque un génie qui exécute **une attaque puissante** puis disparaît. |
| **Vent Véloce** | **Double le déplacement** d'un héros ce tour (total base + 1d6, ×2). |
| **Tempête** | Les monstres ciblés tentent un jet de Mind ; échec → **ne peuvent pas attaquer** au prochain tour. |

---

## 8. Intégration avec le reste

- **Personnages** : utilise attribut Mind (jets de parchemin, résistance), Points de Body/Mind (cibles), nœuds d'arbre (*Première magie*, *Écoles*, *Concentration*, *Second élément*).
- **Combat** : dégâts et défense suivent les règles de combat de base, inchangées.
- **Market** : parchemins comme marchandise, prix/disponibilité selon le profil de lieu (chiffres au moteur, jamais à l'IA).

---

## 9. Périmètre

- **MVP** : 4 éléments, 12 sorts ci-dessus, récupération par quête, nœud Concentration, parchemins avec jet de Mind.
- **Phase 2** : nouveaux sorts hors canon, sorts de rituel (hors combat, effets narratifs pilotés par le MJ IA), parchemins rares à effets uniques.

---

## 10. Décisions actées

1. **Difficulté des parchemins (S1)** : **variable selon le sort** (1 à 3 succès, voir §7).
2. **Sorts mentaux (S2)** : **effet binaire** — la cible résiste (jet de Mind) ou subit l'effet. Pas de dégâts de PV de Mind au MVP.
3. **Ciblage (S3)** : **tir ami possible** — un sort mal placé peut toucher un allié.
4. **Fabrication (S4)** : **jamais** — les parchemins ne s'obtiennent que par butin ou achat.
5. **Repos (S5)** : **aucun repos en cours de quête** ; récupération entre quêtes uniquement.
6. **Concentration (S6)** : le Magicien peut, **une fois par quête, sacrifier son tour** pour récupérer un sort épuisé.
