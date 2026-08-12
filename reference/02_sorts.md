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
| **Magicien** | Lanceur complet. Démarre avec **3 éléments au choix** (9 sorts, parité HeroQuest de base), débloque le 4ᵉ via le nœud *Écoles* (répétable) de son arbre. |
| **Elfe** | Magie légère. Démarre au **CHOIX** : soit **1 élément** (ses 3 sorts), soit **3 sorts du répertoire elfique** (§7 bis) — décision de René, 2026-08-11, adossée au livret elfique officiel. Éléments supplémentaires via les nœuds *Première magie* / *Second élément* de l'arbre. |
| **Barbare / Nain** | Pas de sorts connus. Peuvent utiliser des **parchemins** (voir §6). |

---

## 3. Acquisition des sorts

- À la création, le lanceur **choisit ses éléments** de départ parmi Feu, Eau, Terre, Air (**Magicien 3, Elfe 1** — parité HeroQuest de base). Connaître un élément = connaître ses 3 sorts.
- **L'Elfe a une seconde voie** (2026-08-11) : au lieu d'un élément, il peut prendre **3 sorts au choix parmi les 8 du répertoire elfique** (§7 bis). C'est un choix EXCLUSIF — élément *ou* sorts elfiques, jamais les deux au départ —, et il porte sur un répertoire **fermé** qui n'appartient à aucun élément.
- La progression débloque des **éléments supplémentaires** via l'arbre de compétences, pas automatiquement.
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
| **Traverser la Pierre** | Le héros **traverse la roche et les portes closes pendant TOUT son déplacement** du tour — plusieurs murs, et des cases normalement inaccessibles. **Terminer son mouvement dans la roche le fait tomber** (0 PV) : atteindre quelqu'un pris dans un mur exige le même sort, l'issue est donc de fait fatale (notre moteur n'a pas de mort instantanée — décision de René, 2026-08-06). Entrer ainsi dans une salle inexplorée la **révèle**, monstres compris, **sans ouvrir la porte** : c'est le principal usage tactique, avec le contournement d'une porte tenue par des figures. Réécrit le 2026-08-06 sur le texte officiel (« traverse les murs sur tout le déplacement du jet, danger de rester bloqué dans la roche massive », reference/18_extensions.md §3) ; c'était auparavant un **saut de 2 cases** par-dessus un seul mur, sans risque, et qui exigeait une case de sortie libre. |
| **Peau de Pierre** | **+1 dé de défense** à un héros, **jusqu'au premier dégât subi** — se défendre sans rien encaisser ne le consomme pas. Aligné le 2026-08-06 sur le texte officiel (« 1 dé de défense supplémentaire jusqu'au premier dégât subi », reference/18_extensions.md §3) : on donnait auparavant **2 dés pour tout le combat**. |

### Air — mobilité / puissance
| Sort | Effet |
|---|---|
| **Génie** | **DEUX modes au choix** (texte officiel, Kellar's Keep p. 15) : une **attaque à 5 dés** à distance, **ou** **ouvrir une porte au choix** — sans adjacence ni clé, ce qui dégage un passage bloqué par des figures. Le menu propose une option par porte fermée d'une salle découverte. Aucune invocation persistante n'est attestée : `invocation_ephemere` retiré (2026-08-06). |
| **Vent Véloce** | **Double le déplacement** d'un héros ce tour (total base + 1d6, ×2). |
| **Tempête** | **UN monstre choisi** tente un jet de Mind ; échec → il **passe son prochain tour** (ni déplacement ni attaque). Corrigé le 2026-08-06 sur le texte officiel : « un monstre choisi passe son prochain tour » (Kellar's Keep p. 15). On lisait auparavant « les monstres ciblés » (sort de zone jamais implémenté) et « ne peuvent pas attaquer » (le monstre avançait quand même). |

---

## 7 bis. Répertoire elfique (8 sorts — SOURCÉS, © 2023 Hasbro)

Livret de sorts elfique de *The Mage of the Mirror*, numérisé par René
(2026-08-11). C'est la seconde voie de l'Elfe : **3 sorts au choix parmi ces
8**, au lieu d'un élément. Le répertoire n'appartient à aucun élément et ne
se mélange pas avec eux.

| Sort | Texte de la carte | Portable ? |
|---|---|---|
| **Twist Wood** | « causes any wooden weapon, such as a staff, bow, or crossbow, to become so warped it is rendered useless » | ⚠ suppose une **matière** sur les armes ; `objets` n'en a pas |
| **Disappear** | cible le lanceur ou un héros ; se déplace invisible s'il fait **8 ou moins** aux dés rouges (9-12 rompt le sort) ; ne peut que **bouger et ouvrir des portes** — ni attaquer, fouiller, désamorcer, lancer, déclencher un piège, ni **être affecté** par attaques et sorts, sauf s'il annule lui-même | ✅ **porté** sous le nom *Évanescence*. ⚠ Rupture à **5+ sur notre unique d6** (arbitrage de René) là où le plateau lit 9+ sur 2 dés rouges : une chance sur trois contre un peu plus d'une sur quatre |
| **Flashback** | le lanceur ou un héros **rejoue son tour entier**, tous les résultats du premier étant annulés ; lançable **après le tour de n'importe quel héros** ; **ne compte pas comme action** | ❌ demande d'**annuler un tour résolu** — le moteur ne sait pas revenir en arrière |
| **Slow** | un monstre tombe à **1 case** de déplacement et **−1 dé** en attaque comme en défense (jamais sous 1) ; dure jusqu'à sa mort ou sa **sortie de la ligne de vue** du lanceur | ✅ malus de dés + de déplacement, tout existe |
| **Double Image** | cible le lanceur ou un héros ; si une attaque le touche, **1 dé rouge** : sur **1-3** c'est l'image qui est frappée et le héros ne subit rien ; rompu dès que le héros ne voit plus de monstre | ✅ **un lecteur de `HerosVaSubirDegats`** — annulation AUTOMATIQUE sur jet, sans choix du joueur, donc portable telle quelle |
| **Hypnotic Blaze** | toute figure de la salle ou du couloir **sauf le lanceur** jette 1 dé rouge ; **≤ Mind** = indemne, **> Mind** = **paralysée 3 tours** (ni bouger, ni attaquer, ni défendre) | ⚠ vrai sort de **zone**, mot-clé retiré faute de source — celle-ci en est une |
| **Deep Sleep** | tout monstre en ligne de vue ayant **1 à 3 Mind** s'endort **immédiatement**, jusqu'au prochain tour de Zargon ; **ne peut pas défendre** pendant ce temps | ✅ notre *Sommeil*, sans jet de résistance et avec un seuil de Mind |
| **Timestop** | le lanceur ou un héros **rejoue un tour immédiatement** après le sien | ✅ motif `attaque_supplementaire` étendu au tour entier |

**Ce que ce répertoire apporte au moteur, au-delà de l'Elfe :**

- **Double Image** est le premier effet qui peut être porté **tout de suite**
  sur `HerosVaSubirDegats` : il annule des dégâts sur un jet de dé, sans
  demander de décision au joueur — donc sans la moitié interface qui manque
  encore aux réactions à choix.
- **Hypnotic Blaze** **re-source le sort de zone**. `monstres_zone` avait été
  retiré parce que la seule zone qu'on croyait avoir (Tempête) était une erreur
  de lecture ; ici la zone est écrite noir sur blanc, et elle frappe **toute
  figure**, alliés compris — cohérent avec notre tir ami assumé.
- **Deep Sleep** confirme rétroactivement notre traitement du **Mind 0** : le
  sort exige « from 1 to 3 Mind Points », donc une créature à 0 n'est pas
  seulement immunisée au jet, elle est **hors de portée du sort**.
- **Flashback** est la seule des huit que je marque **non portable sans
  réserve** : annuler un tour déjà résolu suppose un point de restauration par
  tour de héros. Nos snapshots existent (`debut_quete`, `nouveau_tour`) mais
  pas à cette granularité.

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
