# Plan — Le *World's End Tournament* (Against the Ogre Horde)

> ⚠ **Document DATÉ du 2026-10-02**, comme les autres `docs/plan-*.md`.
> Demandé par René (Q1 de `docs/plan-ogre-horde.md` : « fait un plan dédié »).
> **Source** : livret officiel F9528, pages imprimées 12 à 17 (règles, roster)
> et quêtes 1 à 3 (p. 21-25). Les blocs de stats ont été relus sur le rendu
> PNG des pages.

## ⚠ Décision du 2026-10-04 — ce plan sert l'ARÈNE, pas la campagne

René (AQ6 de `docs/plan-mode-arene.md`) : **pas de tournoi dans la campagne**.
La forme A (une quête de l'arc) et ses quêtes T6 en campagne sont abandonnées.
Ce document reste la référence des **règles** du tournoi (§1) et de son
**moteur de combat** (lots T1 à T5 : arène fixe, activation alternée,
puissance d'équipe, trophées, combattant seul), que le **mode Arène**
réutilise tel quel. Les vagues, Doralf et Gruzbella y deviennent des défis
d'arène. TQ1 et TQ2 sont tranchées par ce choix ; TQ3 à TQ5 se reposent dans
le plan de l'arène.

## 1. Ce que le tournoi est, au plateau

Les trois premières quêtes de la boîte se jouent dans le hall du tournoi de
World's End, et non dans un donjon. Le livret en fait aussi un mode
rejouable « en dehors des quêtes » (p. 14).

| Règle | Texte (abrégé, p. 12-14) |
|---|---|
| **Deux équipes** | *Challenger* : les héros et leurs alliés. *Defender* : les forces de Zargon. Chaque équipe est posée sur sa propre tuile de départ, et le Defender est caché derrière l'écran du MJ jusqu'au début du combat |
| **Rounds d'activation** | Au début de chaque round, le **Defender joue en premier** : il active un membre qui n'a pas encore joué. Puis le Challenger active un héros. On alterne ainsi. Quand une équipe n'a plus personne à activer, l'autre enchaîne ses activations jusqu'au bout. Un jeton marque ceux qui ont joué. Le round finit quand tout le monde a été activé |
| **Fin du combat** | Elle survient quand une équipe est entièrement vaincue |
| **Puissance d'équipe** | Puissance d'un héros = 1 + les dés de sa meilleure attaque. On additionne pour le Challenger. Le Defender est composé dans le roster (p. 16-17) avec une puissance **inférieure ou égale** |
| **Jetons trophées** | Posés face cachée sur des cases désignées. On en ramasse un en **finissant son tour** dessus, sans action. Ils vont dans une réserve d'équipe utilisable par tous les membres, on ne peut pas les voler, et ceux qui ne sont pas ramassés disparaissent à la fin du combat. Effets : *Burst of Speed* (+2 cases), *Combat* (+1 crâne ou +1 bouclier blanc, après le jet), *Healing Potion* (soin du nombre indiqué, utilisable même à 0 Body, avant de mourir), *Riposte* (contre-attaque immédiate après avoir été blessé par un adjacent, même diagonal, même si on vient de mourir) |
| **Combattant seul** | Le dernier membre d'une équipe gagne, en plus de son tour, une **manœuvre de base** après chaque tour adverse : se déplacer, **ou** 2 dés d'attaque contre un adjacent (en diagonale s'il le peut). Elle est impossible s'il est neutralisé (sous *Tempest*, par exemple) |
| **Mort dans le tournoi** | Le héros est hors jeu jusqu'à la fin de la quête. Ses objets restent sur sa case et n'importe quel héros peut les ramasser en passant à côté |
| **Trésor du tournoi** | Fouiller adjacent (ou en diagonale) à un coffre : on lance 2d6. 2 = explosion (−3 Body), 3-4 = gaz (−2), 5-6 = flèche (−1), 7 = une hache de bataille **en os**, 8-9 = 100 po, 10-11 = 200 po, 12 = 300 po. Puis le coffre est retiré |
| **Échec** | Si un tournoi tourne au désastre, le MJ le modifie avant de le rejouer |
| **Variante monstres contre monstres** | Deux équipes de monstres, puissance égale, sélection alternée (p. 16) |

**Les trois quêtes du livret** :
1. *The Tournament Gauntlet* : trois **vagues** d'affilée (Skutter Rat and
   the Bruisers, Death's Chosen, puis le **Spawn of the Pit**, qui a deux
   phases). Les héros gardent leur activation d'une vague à l'autre et les
   trophées ne se renouvellent pas.
2. *Proven Worthy* : une équipe composée au roster, dont **Doralf** est
   obligatoirement membre.
3. *Glory and Gold* : un duel contre **Gruzbella** (trois phases, trois
   capacités). Vaincue, elle s'incline et paie **1000 po**.

## 2. Pourquoi c'est un chantier à part

Le tournoi heurte trois fondations du moteur :

1. **L'ordre du tour.** Aujourd'hui un round fait jouer les héros (dans leur
   initiative), puis les alliés (`phaseAllies()`), puis tous les monstres
   d'une traite (`phaseMonstres()`, appelé depuis `jouerFinDeRound()`). Le
   tournoi **alterne** une figurine d'un camp, puis une de l'autre, et le
   camp choisit **qui** il active. C'est une seconde boucle de tour.
2. **La carte.** Le hall est une **arène fixe** : deux tuiles de départ, des
   cases de trophée, des coffres. Elle n'a ni brouillard, ni portes, ni
   fouille de salle. Nos cartes sont générées (`AssembleurCarte`), avec leurs
   topologies.
3. **La victoire.** On ne gagne pas en atteignant un objectif ou une sortie,
   mais quand **une équipe est éteinte**. Et la mort n'est pas définitive dans
   la campagne : on est seulement hors jeu pour la quête.

Ce qu'on réutilise tel quel : le combat (`ResolveurTour::frapper()`, la
défense), les grands monstres, les monstres à distance (Q6), le moteur
multi-phase du lot C (le Spawn, Gruzbella), les sorts, les alliés, la
manette et la scène de table.

## 3. Où le tournoi vit dans notre jeu — trois formes possibles

| Forme | Description | Coût |
|---|---|---|
| **A. Un type de quête « tournoi »** | Une quête de la campagne dont la carte est l'arène et la victoire l'équipe éteinte. Elle s'insère dans l'arc : par exemple la première quête d'une campagne au thème `horde_ogre`, ou un jalon de sous-boss (Doralf, le Spawn) | Moyen : un gabarit, une carte d'arène, la boucle alternée, la victoire par extinction |
| **B. Un mode « Arène » au hub**, entre deux quêtes | On le propose quand le groupe est au hub. C'est un combat rejouable, sans effet sur l'arc, qui rapporte or et objets d'os (le « tournoi hors des quêtes » du livret, p. 14) | Moyen, plus une entrée au hub et une économie à cadrer (on ne doit pas pouvoir y farmer l'or) |
| **C. Les deux** | A pour l'histoire, B pour le plaisir | La somme, mais B réutilise tout A |

**Recommandation : A d'abord**, puis B si l'envie vient. A s'insère dans
l'arc existant (jalons, rotation des sous-boss, montée de niveau) sans rien
inventer côté économie. Pour Doralf et le Spawn, sous-boss du thème, c'est
précisément la mise en scène du livret.

## 4. Les lots (forme A)

**T0 — Prérequis, venus du plan Ogre Horde** : le moteur **multi-phase**
(lot C) et les **variantes à distance** génériques (Q6). Le tournoi ne les
réécrit pas.

**T1 — L'arène.**
- Une carte fixe : deux tuiles de départ, des cases de trophée, des coffres,
  et ni brouillard ni portes. Elle passe par `FabriqueGrille::pour()` comme
  toute couche de grille, jamais par une seconde boucle (`carte-donjon.md`).
- Un gabarit de quête `tournoi` qui la demande.
- Le placement initial : le Defender est **caché** jusqu'au départ. Sur la
  table, l'équipe adverse se révèle au lever des portes du hall.

**T2 — La boucle alternée.**
- Un **mode de round** porté par la quête (`quetes.mode_tour`, une colonne :
  c'est un état durable, pas du cache).
- Le Defender commence. Il active **une** figurine (choix du moteur, comme
  il choisit aujourd'hui quel monstre joue).
- Le Challenger choisit **quel héros** joue : la manette du joueur choisi
  s'allume, les autres attendent.
- Quand un camp est à court, l'autre enchaîne. Le round se termine quand
  tous ont joué.
- Les alliés appartiennent au Challenger : ils sont **activables** comme un
  héros, au lieu de jouer en fin de round.
- ⚠ Le menu ne propose jamais d'agir à un héros dont ce n'est pas
  l'activation (règle de `combat-et-tour.md`).

**T3 — Puissance d'équipe et composition.**
- Puissance d'un héros = 1 + les dés de sa meilleure attaque, calculée par
  le moteur à partir de ce qu'il porte (même point de passage que les dés
  d'attaque).
- Le Defender est composé par le moteur jusqu'à une puissance ≤ celle du
  Challenger, depuis le **roster du livret** (p. 16-17) : un catalogue
  séparé, car c'est un équilibrage de tournoi qui ne remplace pas notre
  bestiaire (le Zombie y bouge de 5, l'Abomination y a des stats).
- Doralf (quête 2) ou le Spawn (quête 1) y sont **imposés** comme le livret
  l'exige.
- ⚠ La puissance remplace, **pour ce type de quête seulement**, le budget
  `cout` des rencontres.

**T4 — Trophées.**
- Un vocabulaire fermé des 4 effets, posés face cachée.
- Le ramassage en **fin de tour** sur la case, puis une **réserve d'équipe**
  persistée pour la quête (colonne, pas cache).
- Les effets « après le jet » (Combat) et « hors tour » (Riposte, Potion à
  0 Body) passent par `MoteurReactions`, le patron déjà écrit pour les
  réactions des héros.

**T5 — Combattant seul, mort, trésor.**
- La **manœuvre de base** du dernier survivant, après chaque tour adverse,
  côté héros et côté monstre.
- La **mort de tournoi** : le héros est hors jeu pour la quête, sans être
  mort pour la campagne. ⚠ C'est un point de règle à faire trancher (§5),
  et aujourd'hui un héros à 0 Body tombe.
- Ses objets restent au sol et se ramassent en passant à côté.
- Le **trésor 2d6** remplace la fouille dans l'arène. Il dépend des **armes
  en os** (lot B du plan Ogre Horde).

**T6 — Les trois quêtes en contenu.**
- Les **vagues** (quête 1) : une vague vaincue est remplacée tout de suite,
  les activations sont conservées, les trophées ne se renouvellent pas.
- **Doralf** (quête 2).
- Le **duel Gruzbella** (quête 3) : ses trois capacités, la défaite sans
  mort, la bourse de 1000 po.

**T7 — Écrans.**
- La barre d'initiative montre les deux équipes et les jetons d'activation.
- La manette dit « c'est à ton équipe de choisir qui joue ».
- Une réserve de trophées est visible par toute l'équipe.
- La scène de table annonce chaque effet automatique (la règle « un effet
  automatique que rien n'annonce est injouable »).

Ordre conseillé : **T1 → T2** (le cœur ; on peut le jouer avec un Defender
fixe), puis **T3**, **T5**, **T4**, **T6**, **T7** au fil de l'eau. Une
campagne d'agents (`campagne-agents`) en arène clôt T2 et T6.

## 5. Questions pour René (avant T1)

| # | Question | Recommandation |
|---|---|---|
| **TQ1** | Forme A (quête de l'arc), B (arène au hub) ou C (les deux) ? | **A**, B plus tard |
| **TQ2** | Où l'arène apparaît-elle ? Seulement en campagne au thème `horde_ogre` (en ouverture et/ou aux jalons de Doralf et du Spawn), ou dans n'importe quel thème, avec le roster du livret ? | `horde_ogre` d'abord : c'est l'histoire de la boîte |
| **TQ3** | La mort en tournoi : hors jeu pour **la quête seulement** (livret), ou mort réelle comme ailleurs ? | Le livret : on sort de l'arène, on n'en meurt pas. Le héros revient à 1 Body au hub |
| **TQ4** | Qui choisit l'activation côté Challenger : le **premier joueur qui appuie**, ou un tour de rôle fixe entre les héros ? | Le premier qui appuie parmi ceux qui n'ont pas joué : c'est le « activate any one hero » du livret |
| **TQ5** | Les trophées : face cachée et révélés au ramassage (livret), ou visibles d'emblée ? | Face cachée, comme au plateau |

## 6. Ce qui reste hors du plan, par choix écrit

- ~~La **variante monstres contre monstres** (p. 16)~~ — **réintégrée le
  2026-10-04** dans le mode Arène (`docs/plan-mode-arene.md`, AQ3) : chaque
  équipe de monstres y est **dirigée par des joueurs**, ce qui lève l'objection
  « pas de joueurs » de la première version de ce plan.
- La règle **« Zargon modifie un tournoi raté »** (p. 13) : notre moteur
  compose déjà l'adversaire à la puissance. Un tournoi raté se rejoue avec
  « Recommencer la quête », qui recompose.
