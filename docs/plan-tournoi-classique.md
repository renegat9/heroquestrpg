# Plan — Le tournoi de World's End dans une campagne classique

> ⚠ **Document DATÉ du 2026-11-23**, comme les autres `docs/plan-*.md`.
> Décision de René (2026-11-22) : le tournoi d'*Against the Ogre Horde* (quêtes 1 à 3, F9528 p. 12-17 et 21-25)
> « il faut l'intégrer au mode classic si elles font partie de la campagne ». Elle lève, pour le mode classique
> seulement, l'AQ6 du 2026-10-04 (`docs/plan-mode-arene.md` : « pas de tournoi dans la campagne »), qui visait la
> campagne RPG générée. Rien ici ne touche le mode RPG ni l'arène (mémoire « branche classique isolée »).

## 1. Ce qui existe : le moteur de l'arène

Les règles du tournoi sont déjà jouées par l'arène (lots T1 à T5) : activation alternée (le Defender d'abord),
puissance d'équipe, trophées (élan, combat, potion, riposte), combattant seul et sa manœuvre, mort « hors jeu ».
Mais elles se déclenchent toutes sur le **type de groupe** (`Groupe::estArene()`), et l'arène les sert sur sa propre
carte (le hall fixe, `HallArene`) avec des **doublures** des héros et un **palmarès**.

## 2. Le principe : un prédicat de RÈGLE, distinct du prédicat de GROUPE

- `Quete::estTournoi()` : une quête construite dont le document porte `tournoi`.
- `Groupe::estEnTournoi()` : un groupe d'arène, OU un groupe classique dont la quête courante est un tournoi.

Chaque appel à `estArene()` est classé :

| Règle de combat (→ `estEnTournoi()`) | Cycle de vie de l'arène (reste `estArene()`) |
|---|---|
| alternance des activations (`OrdreDuTour::mainPartagee()`, `ResolveurTour` 973, 13481, 14034, 14721, 18492, `MenuCourant`) | ouverture du combat d'arène (`ouvrirCombatArene()`), fin du combat d'arène et palmarès (13431) |
| trophées : combat, potion, riposte (`ResolveurTour` 2633, 2943, 16165 ; `MoteurDegats` ; `MoteurReactions`) | doublures, purge, contrôleur d'arène |
| mort « hors jeu », jamais relevé (`ResolveurTour` 9341, `MenuMoteur` 2545) | pas d'instantané (14733), pas de fouille de zone ni de vote (`MenuMoteur` 3455, 3574) |
| combattant seul (`ManoeuvreSolo`) | |

Ce classement est le lot TC1 ; il ne change rien à l'arène (sa suite de tests le prouve).

## 3. Le document : `quete.tournoi`

```json
"tournoi": {
  "camp_heros": [{"x": 2, "y": 3}],        // la tuile de départ du Challenger
  "camp_defender": [{"x": 9, "y": 3}],     // la tuile du Defender (les vagues y entrent)
  "trophees": [{"x": 5, "y": 4}],          // les cases des tuiles trophées, face cachée
  "vagues": [[{"slug": "orque"}], …]       // q. 1 : les vagues SUIVANTES, posées sur la tuile du Defender
}
```

Les figures de la première vague sont les `monstres` de la quête, comme partout. Doralf (q. 2) et Gruzbella (q. 3)
sont des monstres au profil d'auteur (Gruzbella et ses phases existent déjà au bestiaire).

## 4. Les lots

- **TC1** — les deux prédicats et le classement ci-dessus ; aucune règle nouvelle, l'arène inchangée.
- **TC2** — `quete.tournoi` : validation, `CarteConstruite` (trophées dans la grille, tuiles de départ),
  `DemarreurQueteConstruite` ouvre le combat (le Defender joue d'abord), les héros partent de leur tuile.
- **TC3** — la fin : le Defender éteint, la quête est gagnée par sa fin d'auteur (`vaincre`) ; un héros tombé est hors
  jeu jusqu'à la fin de la quête, JAMAIS tué (« Mort dans le tournoi », p. 12), quel que soit le mode de mort.
- **TC4** — les vagues (q. 1) : « When a wave of combatants is defeated, immediately add the next wave to the
  Defender tile and continue the battle round with the new combatants. Heroes maintain activation. Trophy tiles
  don't replenish between waves. »
- **TC5** — le trésor du tournoi : un coffre fouillé au contact tire la table 2d6 (p. 14) dans une quête-tournoi.
- **TC6** — écran du constructeur, guide, MCP relu, contrat, campagne d'essai `essai-*` jouée.
