# Plan — Dégâts de Mind, terrain de glace, et réactivation de The Frozen Horror

> ⚠ **Document DATÉ du 2026-09-06.** Instantané de décision, comme les autres
> `docs/plan-*.md` : il n'est pas tenu à jour. Ce qui sera implémenté vivra dans
> `docs/regles/` et `CLAUDE.md` ; ce fichier dit ce qui est décidé, ce qui reste
> à trancher, et **pourquoi**.

---

## 1. D'où vient le besoin

**The Frozen Horror est la seule boîte DÉSACTIVÉE, pas absente.** Ses 4 créatures
sont seedées (Gremlin des glaces, Ours polaire de guerre, Yéti, Horreur des
Glaces), son boss a son archétype `horreur_glacee` avec **4 sorts qui marchent**,
et ses 8 cartes sont au registre. `DemarreurQuete::BOITES_INCOMPLETES` nomme
précisément ce qui manque — c'est un choix écrit, gardé par `SorciersNommesTest`.

En confrontant ces manques au moteur d'aujourd'hui, trois choses apparaissent.

**a. Les dégâts de Mind ont déjà leurs LECTEURS, il leur manque un PRODUCTEUR.**
C'est l'inverse du défaut habituel du projet (une clé sans lecteur), et le code
le dit lui-même à deux endroits :

- `ResolveurTour:4377` — « ⚠ Aucun chemin ne réduit `pv_mind` d'un héros
  aujourd'hui (seuls des soins l'augmentent), la branche Mind est donc correcte
  mais **dormante** ». `relever` traite déjà **les deux jauges**.
- `ResolveurTour:4012` — `restaure_pv_mind` (*Récupération Psychique*, portée
  depuis) « rendra donc 0 tant que rien ne réduit `pv_mind` ».

Un sort porté, une action portée, et rien qui puisse les déclencher.

**b. `TypeDegat::SANS_SOURCE` est PÉRIMÉ.** Il déclare toujours que le froid n'a
aucune source, alors que `Morsure de Froid` (`SortDreadSeeder:96`) porte
`'type_degat' => 'froid'` depuis le 2026-09-04. CLAUDE.md l'annonçait
explicitement ; la constante n'a jamais suivi. Contrairement à son jumeau
`RegainEffet::SANS_UTILISATEUR`, **aucun test ne la confronte au catalogue** —
d'où la dérive silencieuse.

**c. Il n'existe AUCUNE notion de terrain.** La grille ne connaît que `m` (mur)
et `s` (sol) ; les couches posées dessus (`pieges`, `leviers`, `mobilier`,
`epreuves`) répondent toutes à « qu'y a-t-il ICI ? ». Aucune ne répond à « que
coûte cette case, et que se passe-t-il quand je la traverse ou que j'y reste ? ».
C'est la question que pose toute la boîte de glace.

---

## 2. Deux distinctions à ne pas rater

⚠ **`pv_mind` n'est pas `attribut_mind`.** Les jets de rupture et de résistance
lisent l'**attribut** (`MoteurSorts:1573`, `desResistanceMentale()`) ; *Mind
Freeze* dit « 1 dé de combat par point de **Mind possédé** », c'est-à-dire la
**jauge**. Deux nombres différents. Les confondre ferait qu'un héros gelé
résisterait moins bien aux sorts — une règle que personne n'a écrite.

⚠ **Le coût de déplacement n'est pas la distance.** `Grille::casesAtteignables()`
est une BFS à coût uniforme (`$d + 1`, file FIFO). La Rivière Gelée coûte 2 cases
par case : il faut un parcours **pondéré**. Mais `Grille::distance()` sert aussi à
la **portée** et à l'adjacence — la flèche d'un arbalétrier n'est pas ralentie par
la glace. Seuls `casesAtteignables()` et `chemin()` deviennent pondérés ;
`distance()` reste géométrique. Les mélanger raccourcirait la portée de toutes les
armes à distance dès qu'une case de glace se trouve sur la ligne.

---

## 3. Phases

### Phase 0 — La déclaration périmée (indépendante, ~30 min)

- `TypeDegat::SANS_SOURCE` : retirer `FROID`, la constante devient vide.
- **Ajouter son test**, sur le modèle de `RegainEtDegatsTest:160` pour
  `RegainEffet::SANS_UTILISATEUR` : toute nature déclarée sans source ne doit
  apparaître dans **aucun** `effet.type_degat` du catalogue, et réciproquement
  toute nature utilisée doit être hors de `SANS_SOURCE`. C'est l'absence de ce
  test qui a laissé la dérive passer.
- Corriger les trois textes `manque` de `config/cartes.php` qui invoquent encore
  « rien à quoi résister » (Ring of Warmth, Armband of Ice, Snowshoes of Speed) :
  la raison a changé, elle doit le dire.

Fichiers : `app/Engine/TypeDegat.php`, `tests/Feature/Partie/RegainEtDegatsTest.php`,
`config/cartes.php`.

### Phase 1 — Les dégâts de Mind

**Le producteur qui manque.** `MoteurDegats` fait 179 lignes et n'a qu'une
méthode publique.

- `infligerMindAHeros(Personnage, int, string $source, array $contexte): int`
  — **méthode sœur, pas un paramètre `$jauge`**. Les deux jauges ne partagent ni
  leurs réactions, ni leur réduction, ni leur mémoire : un `if ($jauge === …)`
  toutes les cinq lignes serait deux méthodes déguisées en une.
- Ce qu'elle NE reprend PAS de la branche Body, et pourquoi :
  - `reduction_degats` (Cuir tanné, Peau de fer, Rempart) — les trois cartes
    disent « damage », dans un jeu où le seul dégât est physique. Étendre au Mind
    serait inventer.
  - `HerosVaSubirDegats` / `MoteurReactions::proposer()` — *Dark Wings* et
    *Twisting Torrent* annulent **un coup**. Aucune carte réactive ne parle de
    l'esprit. ⚠ Ne pas offrir `soin_urgence` non plus : la liste blanche des
    soins (`soinsDisponibles()`) est peuplée de `soin_pv_body`.
  - `SOURCES_REACTIVES` n'accueille donc **aucune** source Mind.
- Ce qu'elle reprend : `memoriser()` (le cumul par source sur l'état de quête),
  avec une clé distincte pour ne pas mélanger les deux jauges dans
  `degats_subis` — sinon la Plume anti-poison rendrait des PV de Body pour des
  points d'esprit perdus.
- **Chute.** Les 14 sites qui écrivent `'tombe' => true` testent tous
  `pv_body === 0`. `relever` traite déjà les deux jauges : la symétrie est
  attendue. → **décision à prendre en §5.**
- `Personnage::booted()` : ⚠ **ne pas** étendre `premier_degat_subi` au Mind.
  Ce déclencheur nomme le premier **dégât subi** dans un vocabulaire où le dégât
  est physique ; l'étendre ferait expirer Peau de Pierre sur un gel mental.

Fichiers : `app/Partie/MoteurDegats.php`, `tests/Feature/Partie/RegainEtDegatsTest.php`
(nouveau cas), et un `tests/Feature/Partie/DegatsMindTest.php`.

**Ce que ça débloque immédiatement** : *Récupération Psychique* cesse de rendre 0,
`relever` sort de sa dormance, et l'**Orbe du Ciel** (Sky Orb) devient portable —
un artefact d'une autre boîte, qui attendait exactement cette mécanique.

### Phase 2 — Les trois sorts manquants du boss

**Mind Freeze / Gel de l'Esprit.** Carte : « 1 dé de combat par point de Mind
possédé. Au moins un bouclier blanc : il lui reste 1 Mind. Aucun : Mind à zéro,
le héros entre en *état de choc*. »
- Le jet et les deux issues sont portables tels quels sur la Phase 1.
- ⚠ **L'« état de choc » n'est sourcé nulle part** : la carte délègue à une
  section du livret *Frozen Horror* que nous n'avons pas. → **décision §5.**

**Ice Wall / Mur de Glace.** Carte : « jusqu'à 4 cases de glace pleine qui
bloquent le déplacement **mais pas la vue** ; chacune dure tant que le lanceur la
voit, ou jusqu'à 5 crânes cumulés d'attaques. »
- ⚠ Le registre désigne `chausse_trappes` comme parent le plus proche. **C'est le
  mobilier qui l'est** : « bloque le mouvement mais pas la vue » est *mot pour
  mot* la séparation `bloque_mouvement` / `bloque_vue` du 2026-08-05, et
  `FabriqueGrille::pour()` la lit déjà — un mur de glace n'est pas une figure, il
  ne doit jamais entrer dans `$occupees`.
- Ce qui manque vraiment : une pose **en cours de quête** (le mobilier est posé à
  l'assemblage), un **compteur de crânes**, et un **entretien lié à la vue du
  lanceur**. La pose runtime a son précédent exact : `chausse_trappes`.
- Implémentation : couche `carte.grille['glace']`, entrées
  `{x, y, source_instance_id, cranes: 0}`, lue par `FabriqueGrille::pour()` dans
  la **même** boucle que le mobilier — ⚠ jamais une seconde, sinon déplacement,
  ciblage et ligne de vue divergent (c'est la raison d'être de ce point de passage).

**Skate / Patinage.** Carte : « le lanceur patine sur 12 cases et traverse héros
et monstres. Dure un tour. »
- `franchit_figures` existe déjà, côté héros seulement. Le déplacement des
  monstres passe par `derniereCaseOuSArreter()` — même règle à respecter :
  ⚠ **traverser n'est pas s'arrêter**, la case d'arrivée doit être libre.

### Phase 3 — Les deux capacités de créature

**Étreinte du Yéti** (doc 18) : « dès qu'il inflige au moins 1 Body Point,
agrippe le héros ; 2 Body Points **automatiques** (sans jet de défense, sans
action possible) à chaque tour suivant du MJ, jusqu'à la mort du héros ou celle
du Yéti — le Yéti ne peut alors faire aucune autre attaque. »
- ⚠ **Les deux moitiés existent déjà** : les dégâts automatiques indéfendables
  sont exactement les jetons de Rejeton (`ResolveurTour:1967`, le seul dégât du
  jeu qui ne lance aucun dé), et « sans action possible » est
  `deplacement_interdit` + la privation d'action de *Immobilisé*.
- Capacité `etreinte` sur la couture `frapper()`, condition portée par
  `etat_personnage_quete`, libérée à la mort du Yéti. ⚠ La capacité doit aussi
  **empêcher le Yéti d'attaquer** tant qu'il agrippe — la carte le dit.

**Vol du Gremlin** (doc 18) : « attaque **ou** vole un objet (jamais
l'arme/armure/bouclier équipés) puis s'enfuit à pleine vitesse ; l'objet est perdu
si aucun héros ne le voit au début du tour suivant du MJ. »
- La suppression d'une ligne d'inventaire a son précédent : *Rouille*, qui
  supprime **et** rappelle `Equipement::recalculerCombat()`. Le déplacement de
  ligne a le sien : `DonObjet` (⚠ qui **déplace** la ligne plutôt que de la
  recréer, pour ne pas perdre les `ameliorations` de la Forge).
- Neuf : porter l'objet sur l'instance de monstre, et le test de vue au tour
  suivant. Récupérable en tuant le Gremlin avant qu'il sorte de vue.

### Phase 4 — La couche TERRAIN (les nouveaux types de tuiles)

**C'est l'ajout structurel de ce plan.** Table `terrains` + couche
`carte.grille['terrain']`, sur le patron **exact** de `mobiliers` /
`AssembleurCarte::placerMobilier()` — quatre précédents (`pieges`, `leviers`,
`mobilier`, `epreuves`), aucun type de case nouveau, aucune migration de tuile.

Doc 18 §4 liste ~16 tuiles ; **la plupart sont des noms de salle sans mécanique**
(Frozen Crypt, Cage Room, Seat of Power, Ice Cave Entrance, Ice Gremlin Treasure
Room…) et ne demandent rien d'autre qu'un habillage. Celles qui portent une règle :

| Tuile | Règle (doc 18) | Couture |
|---|---|---|
| **Slippery Ice** | posée au contact ; 1 dé de combat, bouclier blanc = chute et **fin de tour immédiate** | `tronquerSurChausseTrappes()` — même famille |
| **Ice Slide** | glissière à sens unique, fin de tour, 1 Body sur bouclier blanc | idem + direction |
| **Ice Vault** | 1 Body par tour passé dedans, sur un skull | `saignerParConditions()` (poison) |
| **Ice Tunnels** | paires de téléportation | neuf, mais borné |
| **Magic Ice** | support d'*Ice Bridge* / *Ice Wall* | dépend de la Phase 2 |
| **Ice Ledge** | rebord de crevasse | décor + `exige_placement` |
| **Crystal Key Tile** | clé d'ouverture | proche de `leviers` |
| **Icy River** | **2 cases de déplacement par case**, dégâts sur bouclier blanc | ⚠ **coût pondéré** — voir §2 |

⚠ **La Rivière Gelée est le seul poste qui touche le cœur.** Elle impose de passer
`casesAtteignables()` et `chemin()` d'une BFS uniforme à un parcours pondéré (file
de priorité), en laissant `distance()` géométrique. C'est le poste le plus risqué
du plan : il touche le déplacement, l'affichage des cases atteignables sur la
manette, et le trajet des monstres. **Il peut être différé** — le reste de la
couche terrain n'en dépend pas.

⚠ **Bottomless Chasm** (perte définitive du personnage) est **hors périmètre** :
le moteur n'a aucune mort permanente, c'est une politique de jeu, pas un défaut.
→ décision §5.

⚠ **Living Fog Room** (leurres) et le **Sceptre** (cible de décor destructible
avec explosion de zone) restent des dettes **nommées** : chacune demande une
couche de résolution que rien n'approche aujourd'hui.

### Phase 5 — Les 8 cartes de glace

Une fois la Phase 0 faite, leur raison d'écart a changé. À reprendre carte par
carte via la skill `porter-une-carte-officielle` :
- **Warmth** — soin de 3 points, portable **tel quel** (CLAUDE.md le dit déjà) ;
- **Ring of Warmth** / **Armband of Ice** — `resistance_degats_type` +
  `MoteurSorts::absorbeDegat()` existent, et le froid a désormais une source ;
- **Chill** — sort de héros jumeau de *Morsure de Froid* ;
- **Snowshoes of Speed**, **Ice Bridge**, **Skate**, **Ice Storm** — dépendent de
  la Phase 4 (« régions gelées », pose de terrain).

### Phase 6 — Rallumer la boîte

- Retirer `horreur_des_glaces` de `BOITES_INCOMPLETES`, l'ajouter à
  `BOITES_THEMATIQUES` (5 thèmes → la rotation change de modulo).
- Compléter le répertoire de `horreur_glacee` avec les 3 sorts portés (il en a 4,
  il passera à 7 sur les 12 que sa carte lui accorde).
- ⚠ `SorciersNommesTest` vérifie **dans les deux sens** qu'aucune boîte
  désactivée n'est offerte en thème et qu'aucun thème n'est désactivé : les deux
  listes doivent bouger ensemble.
- ⚠ La rotation des thèmes se fait sur l'id du groupe : passer de 4 à 5 thèmes
  **redistribue les campagnes en cours**. Le thème est censé ne jamais changer en
  cours de route (`themeBestiaire()` le dit). → décision §5.

---

## 4. Ordre recommandé

`0 → 1 → 2 → 3 → 6`, puis `4`, puis `5`.

La raison : les phases 0 à 3 rendent la boîte **jouable et cohérente** sans
toucher au cœur du déplacement, et la phase 6 la rallume. La couche terrain
(4) est le gros morceau et n'est requise par **aucune** des cinq autres — la
sortir du chemin critique évite qu'un chantier de pathfinding retarde une boîte
qui n'attend que cinq règles.

---

## 5. Arbitrages — TRANCHÉS le 2026-09-06 par René

1. **Un héros à 0 Mind TOMBE**, comme à 0 Body. N'invente rien : `relever` traite
   déjà les deux jauges et son commentaire qualifie la branche Mind de « correcte
   mais dormante ». On applique au Mind la symétrie que le code porte déjà.
   ⚠ Conséquence assumée : `verdictDeChute()` et le TPK comptent désormais le Mind.
   L'« état de choc » du livret reste une **dette nommée** — on ne l'invente pas.
2. **Mind Freeze est porté** sur cette base.
3. **Le thème est FIGÉ à la création du groupe** — colonne `groupes.theme_bestiaire`
   écrite au démarrage, exactement comme `quetes.objectif_majeur` et `type_jalon`,
   et pour la même raison : rien ne doit pouvoir changer sa propre réponse en vol.
   Les campagnes en cours gardent leur boîte, les nouvelles tirent sur 5.
4. **Périmètre : TOUT, terrain compris** — phases 0 à 6, y compris la couche
   `terrains` et la Rivière Gelée (déplacement à coût pondéré).
5. **Bottomless Chasm reste hors périmètre** : le moteur n'a aucune mort
   permanente, c'est une politique de jeu. Dette nommée, comme Living Fog Room et
   le Sceptre.

## 6. Vérification

- `TypeDegat::SANS_SOURCE` : le nouveau test doit **échouer** sur le code actuel
  avant d'être corrigé — sinon il ne prouve rien.
- Dégâts de Mind : un test qui joue *Récupération Psychique* **après** un gel et
  vérifie qu'elle rend autre chose que 0 — c'est la preuve que le lecteur dormant
  s'est réveillé.
- Mur de Glace : vérifier qu'il **arrête un déplacement** et **n'arrête pas une
  flèche** — c'est le bug de la table qui arrêtait les flèches, un cran plus loin.
- Étreinte du Yéti : vérifier que le Yéti **cesse d'attaquer** pendant l'étreinte.
- Terrain : `CouloirsTest` doit rester vert (connectivité), et une case de terrain
  ne doit **jamais** couper l'accès à un coffre ou à un levier.
- Boîte rallumée : `SorciersNommesTest` deux sens, plus une campagne réelle via la
  skill `campagne-agents` — c'est la méthode qui a trouvé dix défauts que 1139
  tests verts n'avaient pas vus.
- Suite complète dans le conteneur jetable (skill `outillage-dev-et-tests`).
