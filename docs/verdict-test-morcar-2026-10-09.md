# Verdict — campagne d'agents sous le thème Wizards of Morcar (2026-10-09)

> ⚠ **Document DATÉ**, comme les autres `docs/verdict-*.md` : un constat, pas tenu à jour.
> Les corrections retenues remontent dans `docs/regles/`.

## Le banc

Harnais `browser-shots/campagne/` (`BOITES=wizards_of_morcar LONGUEUR=tres_courte`), groupe
jetable `harnais-morcar-vjg9`, une quête (la finale : « Détruire : Haut Autel »). Trois agents
Haiku (effort max) : Aldric magicien, Grom barbare, Sylvaine elfe, ~19:12 → 20:06. Scène montée
à la main : parchemins *Vision du futur* et *Mur de Pierre* à Aldric, *Brassard du Garde-Crocs*
à Grom, un Maître des orages caché en salle 1. Nettoyé par `nettoyer.sh` ; comptes réels
inchangés (3 groupes / 9 personnages / 4 joueurs avant et après). **Aucune erreur serveur**
dans `laravel.log` pendant toute la partie.

## Ce qui marche

- **Allié joué par son joueur** (chantier 3a) : le menu du Raptor revient à chaque tour de Grom
  (`allie_id`), déplacement puis attaque dans le même tour, `attendre_allie` ; sa mort endort le
  Brassard, et c'est annoncé.
- **Vision du futur** : offerte sur un jet, acceptée, jet relancé (2 → 5), parchemin consommé.
- **Sorciers de Morcar** : Foudroiement, Tremblement de terre, Vent voleur, Ouragan, Sommeil du
  Maître des orages joués par le moteur ; **Mur de Pierre** posé sur 2 cases.
- `battre_en_retraite` offert partout ; `quitter_donjon` jamais avant l'objectif.

## Défauts trouvés — par gravité

### 1. Effets automatiques NON ANNONCÉS (règle dure du projet) — systémique
Absents de `journal_combat` (seule la réponse HTTP du joueur qui agit les porte) : soins et
buffs (Eau de Guérison, Courage/« Renforcé », Voile de Brume/« Vaporeux », Traverser la
Pierre/« Intangible »), **Mur de Pierre** posé, **Sommeil**, **Ouragan** (Grom projeté de
13 cases, de (30,18) à (17,18), sans une ligne), **Vent voleur** (épée de Grom détruite),
**relevé** d'un héros (0 → 1 PV), monstre **errant** de la fouille, **levier** forcé, et les
**déplacements des monstres** absents de `tour_monstres`. Seuls les sorts offensifs sont
journalisés. Les autres joueurs ne voient donc pas ce qui arrive à leurs compagnons.

### 2. Résultats contradictoires ou trompeurs
- **Sommeil** : `effet_applique: true`, `condition: "Endormi"`, mais `rupture_immediate: true`
  (un 6) — le monstre n'est pas endormi, sans trace.
- **« Résiste »** affiché alors que les dégâts passent (Boule de Feu : `degats: 1`,
  `libelle_def: "résiste"`) ; trois jeux de dés différents dans la même réponse
  (`des_resistance`, `des.def`, `des.defensive`) ; Tremblement de terre sans l'en-tête
  « Maître des orages — » que porte Foudroiement.
- **Levier** : `force: true` avec `portes_ouvertes: []` alors que la porte s'ouvre ; un levier
  déjà forcé reste proposé (le menu offre une action inutile).
- **Fouille** : `issue: "reussite"` avec `a_trouve: false`.

### 3. Menus
- **« Aucun menu en attente »** sur `fouiller` / `attendre` 1 à 2 s après une action ou un
  déplacement : le menu est en cours de régénération ; un menu transitoire ne montrait que
  « Jeter ». Le joueur croit l'option refusée.
- **Allié** : « Approcher le Maître des orages » proposé alors que la seule case libre au
  contact est prise — le Raptor s'arrête sans attaquer, et l'option revient à l'identique
  (le menu propose une action qui ne peut aboutir).
- `lire_parchemin` absent du premier menu alors que les parchemins étaient au sac —
  **probable artefact de la scène** (parchemins posés après la génération du menu).

### 4. Règles à arbitrer (signalées par les joueurs)
- **Déplacement partiel puis action** : le reliquat de déplacement est perdu sans message ;
  action puis déplacement le conserve. Asymétrie voulue ? À dire au joueur.
- **Intangible** (Traverser la Pierre) consommé à la fin du déplacement : impossible de
  revenir, sans avertissement.
- **Relever** consomme le tour du héros (créneau « tour »).
- **Vision du futur** offerte sur un 2 au d6 de déplacement : sur quel seuil la proposer ?
  (Aujourd'hui : après tout jet ; fenêtre de ~20 s.)
- **Mur de Pierre** : libellés de pose relatifs au lanceur (« au nord, puis à l'est ») sans
  repère des monstres ; il peut couvrir la case d'un levier ; il bloque la vue du lanceur
  (fidèle) ; image de repli.

### 5. Textes manquants
Ni le menu ni `/moi` ne décrivent l'effet de Voile de Brume ou d'Eau de Guérison ; les
conditions « Vaporeux » et « Intangible » n'ont aucune description.

### 6. Harnais (pas le jeu)
- **Un battement de table oublié depuis le 2026-10-05** (`livret-*`) écrivait dans le même
  `jar-table.txt` que celui de la partie : très probable cause du passage à
  « narrateur inactif » vers 20:05. Tué. → `preparer.sh` devrait refuser de démarrer si un
  autre `battement.sh` tourne.
- `vue.py` n'affiche pas les alliés (mercenaires) ; il annonce « À PORTÉE » une porte que le
  menu ne propose qu'au contact (embrasure (31,18) côté serveur, porte publiée en (30,18)).
- La partie s'est figée en fin de session parce que l'agent d'Aldric a cessé de jouer avec un
  menu en attente — artefact d'agent, pas du jeu.

## Non testé
Victoire par destruction du Haut Autel (jamais atteint), sortie par l'escalier, relèvement du
mode Story (des monstres restaient actifs), pièges magiques posés, trésors propres à Morcar,
Muraille de glace, Grésil aveuglant.

## Décisions de René sur les règles signalées (2026-10-09, après le verdict)

| Sujet | Décision |
|---|---|
| Déplacement partiel puis action | **Garder** la règle (on ne coupe pas son déplacement en deux), **mais l'annoncer** : avant d'agir, la manette prévient « tu perdras tes N cases restantes ». |
| Relever un compagnon | **Une action, pas tout le tour** : le héros peut encore se déplacer avant ou après. |
| Vision du futur | **Proposée après chaque jet** (inchangé) : le joueur juge lui-même. |
| Intangible (Traverser la Pierre) | **Garder** un seul passage, **mais l'annoncer** avant de valider le déplacement (« tu ne pourras pas revenir »). |
