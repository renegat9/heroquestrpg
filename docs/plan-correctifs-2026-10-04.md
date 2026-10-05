# Plan — Correctifs trouvés en relisant les livrets officiels (2026-10-04)

> ⚠ **Document DATÉ du 2026-10-04**, comme les autres `docs/plan-*.md` : un
> instantané, pas tenu à jour. Les règles en vigueur iront dans `docs/regles/`.
>
> **Origine** : la relecture intégrale des livrets Hasbro (téléchargés le
> 2026-10-04 dans `/home/reneg/heroquest-livrets/`, hors dépôt) pour écrire les
> plans d'intégration par boîte (`docs/plan-kellars-keep.md`,
> `plan-witch-lord.md`, `plan-frozen-horror.md`, `plan-mage-du-miroir.md`,
> `plan-dread-moon.md`, `plan-crypte-tenebres.md`, `plan-telor.md`, …). Ces plans
> décrivent ce qui MANQUE ; celui-ci isole ce qui est **faux aujourd'hui** — du
> code en production qui contredit une carte, ou une source qui cite la mauvaise
> page. Chaque point a été **revérifié dans le code** par l'orchestrateur, pas
> seulement repris d'un rapport d'agent.

## Avancement — 2026-10-04 (même jour)

Décisions de René : **l'Ogre ET le Loup géant font 2 cases** (C3) ; C1 à C5
à corriger, recommandations retenues pour Q1 et Q3. **Tout est fait** :

- **C1** — garde `ethere` à l'appel côté monstre ; test témoin + éthéré
  (`TerrainEnJeuTest`).
- **C2** — `ignore_par_monstres` (Rivière gelée) et `interdit_aux_monstres`
  (Glissière) dans `MotsClesTerrain`, lus par `FabriqueGrille::pour()` quand la
  grille est bâtie pour un monstre (`exceptInstanceId`, qui disait déjà « qui
  bouge ») — aucun des 35 appels touché ; tests dans `TerrainCarteTest`.
- **C3** — `grande_taille` posée sur le Loup géant, gardée sur l'Ogre ; test
  dans `BestiaireSourceTest`.
- **C4** — `choix_attaque` (de nous) remplacée par `deux_attaques`
  (`ResolveurTour::deuxAttaques()`). ⚠ En la portant, une seconde règle de la
  boîte est apparue : « only 1 defend roll against that monster per turn »
  (p. 9). Sur une seule cible, les deux attaques sont donc UNE volée de 2×N
  dés contre UN jet de défense (identique en probabilité) ; avec un second
  héros au contact, une attaque chacun. Contrat API mis à jour
  (`mode: deux_attaques`, `repartition`).
- **C5** — toutes les lignes du tableau corrigées dans `reference/18`, plus
  six copies de « Kellar's Keep p. 15 » dans le code et la doc
  (`MoteurSorts`, `ResolveurTour`, `SortsFonctionnelsTest`,
  `docs/regles/sorts-heros.md`, `reference/02`, `reference/19`).

## Résumé

| # | Défaut | Nature | Taille | Décision requise ? |
|---|---|---|---|---|
| **C1** | Les monstres éthérés sont ralentis par les chausse-trappes | bug moteur | petite | non |
| **C2** | Les monstres paient le terrain de glace (et peuvent entrer sur une Glissière) | écart de règle, boîte jouable | moyenne | **oui** (Q1 : forme du vocabulaire) |
| **C3** | `grande_taille` posée sur l'Ogre au lieu du Loup géant (Mage of the Mirror) | donnée non sourcée | petite | **oui** (Q2) |
| **C4** | L'Ours polaire de guerre porte `choix_attaque`, pas sa capacité imprimée | écart de règle | petite | **oui** (Q3) |
| **C5** | Quinze citations fausses, obsolètes ou manquantes (`reference/18`, un test) | documentation | petite | non |

**Vérifié et écarté** : l'*Anneau du Retour* (« returns all heroes the ring
wearer can see ») — le plan Crypt demandait s'il ne ramène que le porteur. Non :
le vocabulaire le dit (`MotsClesEquipement.php:1027`, « tous les héros que le
porteur voit, lui compris »). Rien à faire.

---

## C1 — Éthéré × chausse-trappes

**Source** : « *Ethereal monsters are unaffected by all traps, including
caltrops placed by heroes* » (Rise of the Dread Moon, p. 6, répété p. 36).

**Constat** (`app/Partie/ResolveurTour.php:9049`) : le déplacement d'un monstre
passe par `tronquerSurChausseTrappes($quete, [$departMonstre, ...$chemin])` sans
regarder la capacité `ethere`, alors que la même méthode, quelques lignes plus
haut (`:8965`), lit déjà `aCapacite($instance, 'ethere')` pour rendre la grille
traversable. Un Spectre ou un Dread Wraith s'arrête donc sur des chausse-trappes
qu'il devrait ignorer.

**Correctif** : ne pas tronquer quand `$this->dread->aCapacite($instance, 'ethere')`.
Garde posée **à l'appel côté monstre** (`:9049`), pas dans
`tronquerSurChausseTrappes()` : la méthode sert aussi aux héros (`:893`) et la
capacité `ethere` est une capacité de monstre.

**À vérifier dans le même passage** : « *all traps* » — un monstre éthéré
déclenche-t-il un autre piège de case (fosse, lame balancière à venir) ? Si les
monstres ne déclenchent aujourd'hui **aucun** piège, la phrase est déjà tenue
pour le reste ; sinon, la même garde s'applique au point de passage commun.

**Test** (Pest, `tests/Feature/Partie/`) : un monstre `ethere` dont le chemin
croise une tuile de chausse-trappes atteint la case qu'il aurait atteinte sans
elles ; le témoin non éthéré est toujours arrêté.

**Annonce** : aucune — une absence d'effet n'a rien à journaliser.

---

## C2 — Les monstres et le terrain de glace

**Source** (The Frozen Horror, relu sur rendu PNG) :
- *Slippery Ice* (p. 4) : « *Monsters are not affected by slippery ice.* »
- *Ice Slide* (p. 5) : « *Monsters cannot move onto ice slide squares.* »
- *Ice Vault* (p. 6) : « *Monsters are not affected by the heat-draining property of this room.* »
- *Icy River* (p. 6) : « *Monsters suffer neither movement penalties nor damage from the icy river.* »

**Constat** : `FabriqueGrille::pour()` (`app/Partie/FabriqueGrille.php:229-232`)
pose un seul tableau de coûts, sans savoir qui traverse. Le déplacement du
monstre (`ResolveurTour.php:9058`, `$grille->pasAffordables(...)`) paie donc la
Rivière gelée **2 points** comme un héros, et rien ne lui interdit une
Glissière. Les **dégâts** et la **fin de tour** du terrain (`saignerSurRiviere()`,
`:1045` ; glace glissante, `:1008`) ne sont appelés que dans le déplacement du
héros : cette moitié de la règle est déjà tenue, par construction.

**Ce qui reste faux** : (1) le coût de la rivière pour un monstre ; (2) la
Glissière ouverte aux monstres.

**Forme du correctif** — règle dure « vocabulaire → lecteur → test → donnée » et
« une règle, un point de passage » :
1. **Vocabulaire** : deux entrées dans `App\Engine\MotsClesTerrain` (noms à
   arrêter, Q1), p. ex. `monstres_ignorent_cout` et `interdit_aux_monstres`.
2. **Lecteur unique** : `FabriqueGrille::pour()` reçoit le type d'occupant
   (paramètre explicite, héros par défaut) et construit couts/obstacles en
   conséquence. **Pas** de seconde boucle sur les terrains ailleurs —
   `carte-donjon.md` l'interdit nommément.
3. **Appels** : les chemins de monstre (`ResolveurTour.php:9058`, `:9560` ;
   `MoteurDread.php:3334`, `:3462`) demandent la grille « monstre ».
4. **Données** : `TerrainSeeder` — Rivière gelée, Glace glissante, Chambre forte
   de glace → `monstres_ignorent_cout` (seule la rivière a un coût ≠ 1, mais la
   clé déclare l'immunité écrite par la carte) ; Glissière de glace →
   `interdit_aux_monstres`. Registre testé **dans les deux sens** (toute clé
   déclarée est lue, toute clé en base est déclarée).
5. **Tests en jeu** : un Yéti traverse la Rivière gelée au coût 1 ; un monstre ne
   finit jamais ni ne passe sur une Glissière ; un héros paie toujours 2.

⚠ **Lire `docs/regles/carte-donjon.md` (§ terrain) avant de toucher
`FabriqueGrille`** : c'est le point de passage central de la couche terrain.
Coût mesuré : quatre appels de grille côté monstre à faire passer par le
paramètre — oublier un appel recrée exactement le défaut « deux copies d'une
règle ».

---

## C3 — `grande_taille` sur le mauvais monstre (Mage of the Mirror)

**Source** : le livret de The Mage of the Mirror ne nomme que le **Loup géant**
comme grande figurine ; il n'en dit rien pour l'Ogre.

**Constat** (`database/seeders/MonstreSeeder.php`) : l'**Ogre** (`:212-213`,
`boite: mage_du_miroir`) porte `grande_taille: {l:1, h:2}` ; le **Loup géant**
(`:290`) ne la porte pas.

**Correctif** : décision Q2. Si on suit le livret : retirer `grande_taille` de
l'Ogre, la poser sur le Loup géant. Le seeder est `updateOrCreate` (rejouable
sans purge). ⚠ **Instances en cours** : une figurine qui change d'emprise en
pleine quête peut chevaucher un mur ou une autre figure. Compter les instances
vivantes de ces deux monstres sur la vraie base (lecture seule) **avant**
de rejouer le seeder, après `./image-tools/sauvegarder.sh`, et le faire entre deux
quêtes si possible.

**Test** : `BestiaireSourceTest` fige la grande taille de ces deux fiches.

---

## C4 — L'Ours polaire de guerre

**Source** (The Frozen Horror, p. 37, relu PNG) : « *The Polar Warbear attacks
once with its mighty paw and once with its spiked mace. Two attacks can be made
against one opponent or one attack can be made against each of two different
opponents.* »

**Constat** (`MonstreSeeder.php:200-205`) : il porte `choix_attaque` (coup massif
unique sur cible robuste, double attaque sur cible affaiblie) — une mécanique de
nous, conçue pour un autre monstre, qui ne répartit jamais sur **deux cibles**.

**Correctif** : décision Q3. Porter la carte demande un mot-clé neuf (deux
attaques, 1 ou 2 cibles adjacentes, répartition décidée par le moteur) dans le
vocabulaire des capacités, un lecteur, un test en jeu ; ou bien inscrire l'écart
dans la liste nommée de `BestiaireSourceTest` comme divergence assumée.

---

## C5 — Citations de page

Toutes vérifiées sur rendu PNG des livrets (la page du PDF regroupe souvent deux
pages imprimées : c'est la source de la plupart de ces erreurs).

| Où | Écrit | Correct |
|---|---|---|
| `reference/18` §Kellar's Keep, artefacts et parchemins (l. 95, 102) | p. 15 | **p. 28-29** (p. 15 est la page du PDF) |
| `reference/18` §Mage of the Mirror, High Alchemist (l. ~750) | p. 22 | **p. 23** |
| idem, Tormuk (l. ~753) | p. 24 | **p. 25** |
| idem, Sinestra (l. ~758) | p. 30 | **p. 33** |
| `tests/Feature/Partie/BestiaireSourceTest.php:89`, Sinestra | p. 30 | **p. 33** (même erreur, recopiée) |
| `reference/18` §Mage of the Mirror, gargouille lanceuse (l. 764) | p. 28 | **p. 29** ⚠ d'après le texte seul — re-rendre la page avant de corriger |
| `reference/18` §Frozen Horror, défenses multiples (l. 644, 668) | p. 11 | **p. 9** |
| `reference/18` §Rise of the Dread Moon, monstres éthérés | « coût à venir » | **porté** (`capacite: ethere`) — obsolète, pas faux |
| `reference/18` §Against the Ogre Horde (titre, l. 300) | « 2023, réédité 2024 » | une seule édition Avalon Hill (annonce 2023, sortie 2024) |
| `reference/18` §Spirit Queen's Torment, Barde (l. 1173-1175) | Rapière « confirmée par le livret », « cf. liste d'objets p. 34 » | **Faux** : le mot *rapier* n'apparaît sur aucune des 19 pages du PDF G0053. La rapière vient de la source tierce citée juste après (l. 1181) et de la carte transcrite l. 2033 — rattacher la mention à ces sources |
| `reference/18` §Spirit Queen's Torment (l. 1202) | statues animées « quêtes 10 et 13 » | **quête 10 seulement** (la quête 13 porte une règle de fournaise sans rapport) |
| `reference/18` §Prophecy of Telor (l. 1112) | le KO non létal « changerait une règle fondamentale du moteur (mort à 0 Body) » | **Obsolète** : un héros à 0 Body est déjà `tombe` (relevable, distinct de la mort) — `docs/regles/vocabulaires-effets.md`, `verdictDeChute()` |
| `reference/18` §Prophecy of Telor | parchemin *Rock Skin* absent | à ajouter (omission) |
| `reference/18` §Jungles of Delthrak §1 (l. 1314) | « Aucune fiche chiffrée » pour l'Explorateur et le Berserker | **Obsolète** depuis le scan du 2026-08-11 (fiches sourcées, classes jouables) — le tableau de synthèse du même fichier le dit déjà ; renvoyer vers `reference/01_personnages.md` §4bis |
| `reference/18` §Spirit Queen's Torment (l. 1169) | « Aucune fiche chiffrée » pour le Barde | **Obsolète** pour la même raison (Mythic Tier, A2 D2 B5 M4) |

---

## Questions pour René

| # | Question | Recommandation |
|---|---|---|
| **Q1** | Terrain de glace (C2) : deux mots-clés de terrain (`monstres_ignorent_cout`, `interdit_aux_monstres`), ou un seul champ `monstres: ignore|interdit` ? | Deux clés booléennes : c'est la forme actuelle de `MotsClesTerrain` (`bloque_mouvement`, `bloque_vue`) |
| **Q2** | `grande_taille` (C3) : suivre le livret maintenant (Loup géant oui, Ogre non), ou attendre une photo des cartes de monstre ? | Suivre le livret : c'est la seule source qu'on ait, et rien ne source l'emprise actuelle de l'Ogre |
| **Q3** | Ours polaire (C4) : porter la vraie capacité, ou déclarer `choix_attaque` comme divergence nommée ? | Porter : la capacité est imprimée en entier, la mécanique est petite |

## Ordre proposé

1. **C5** — documentation seule, aucun risque.
2. **C1** — bug isolé, un garde et un test.
3. **C3**, **C4** — dès que Q2/Q3 sont tranchées.
4. **C2** — le plus large (point de passage central) ; après Q1, avec relecture
   de `carte-donjon.md`.

Après toute modification PHP : `docker compose restart queue queue-jeu`. Tests
sur la sqlite jetable, jamais sur la base MariaDB.

*Les dix plans de boîte sont relus : aucun autre bug de code ; Telor, Spirit
Queen et Delthrak n'ont ajouté que des lignes à C5.*
