# Plan — Gestion des THÈMES de bestiaire

> ⚠ **Document DATÉ du 2026-09-04.** C'est un instantané de décision, comme les
> autres `docs/plan-*.md` : il n'est pas tenu à jour. Ce qui est implémenté vit
> dans `CLAUDE.md` et en `reference/09_bestiaire.md` §4bis.3bis ; ce fichier dit
> ce qui a été décidé, ce qui reste à faire, et **pourquoi**.

---

## 1. D'où vient le besoin

Trois constats, dans l'ordre où ils sont apparus.

**a. Il n'existait aucune notion de thème.** `DemarreurQuete` ne connaissait que
`tier` et `cout`. Une quête « glacée » et une quête « jungle » puisaient dans le
même sac indifférencié. Le thème ne venait que de l'habillage IA — lequel
**renomme ce qui est déjà là** et ne choisit jamais quelle créature apparaît.

**b. La donnée existait pourtant, en commentaire.** `MonstreSeeder` groupe ses
créatures par boîte d'extension depuis le portage de la doc 18, et la doc 18 les
source ainsi, livret par livret. Personne ne pouvait la lire.

**c. ⚠ Le groupe déclare DÉJÀ un thème à la création — en prose.**
`groupes.theme` est un texte libre **obligatoire** (`required, max:2000`) qui
sert de brief à l'IA pour le squelette de campagne. Le bestiaire l'ignore
totalement. Un joueur qui écrit « une crypte glaciale sous l'abbaye » peut donc
affronter des ogres, et rien n'explique pourquoi.

C'est (c) qui fait la force du besoin : il ne s'agit pas d'ajouter de la
variété, il s'agit de **cesser de contredire ce que le joueur a écrit**.

---

## 2. Ce qui est FAIT (2026-09-04)

| Élément | État |
|---|---|
| `monstres.boite` — colonne, migration, seeder, test | ✅ |
| `DemarreurQuete::BOITES_THEMATIQUES` — 4 boîtes actives | ✅ |
| `DemarreurQuete::themeBestiaire()` — rotation sur l'id du groupe | ✅ |
| Biais du thème sur la rencontre finale + les « forts » | ✅ |
| `BOITES_INCOMPLETES` — désactivation déclarée des glaces | ✅ |
| **Choix du thème par le joueur** | ❌ à faire — §3 |

### Règles arrêtées, et pourquoi

⚠ **`null` n'est pas un trou : il vaut « aucune boîte ».** C'est le cas de nos
propres blocs de stats (Troll, Champion, Seigneur, les trois sorciers nommés).
Une créature sans boîte convient à **tous** les thèmes — et c'est précisément ce
qui garantit qu'aucun pool ne se vide, quelle que soit la boîte tirée.

⚠ **Le thème est une PRÉFÉRENCE, jamais un filtre.** Il porte sur la rencontre
finale et sur les quelques « forts » ; la masse de faibles reste le bestiaire
commun. C'est ainsi que les vraies boîtes sont bâties : elles **ajoutent**
quelques créatures signature au fond commun, elles ne le remplacent pas.
Filtrer les faibles aurait donné un donjon de Gremlins — la boîte des glaces n'a
**qu'une** créature de tier `base`. Ne rien filtrer ne montrait jamais la
signature.

⚠ **Un thème par CAMPAGNE, pas par quête.** Rotation sur l'id du groupe, comme
le boss final : on ne passe pas de la banquise à la jungle entre deux portes.
C'est un **placement**, au même titre que `salle_artefact` — le re-tirer à chaque
quête serait le défaut que le deck de fouille a déjà connu.

⚠ **Le levier d'intensité existe déjà** : `jeu.rencontres.forts_par_quete`,
réglable au panneau. C'est lui qui décide combien de créatures signature
accompagnent le fond commun — pas une nouvelle molette.

### Boîte désactivée

`horreur_des_glaces` — arbitrage de René. Trois des six sorts de son boss ne
sont pas portés (*Ice Wall*, *Mind Freeze*, *Skate* : terrain destructible,
dégâts de Mind, mode de déplacement pour monstre), l'étreinte du Yéti et le vol
du Gremlin non plus, l'équipement de glace était déjà écarté. **Le thème et le
boss sont retirés ; les créatures restent** au catalogue comme blocs de stats —
le traitement que le projet applique déjà aux traits non portés de Delthrak.

⚠ La désactivation est **déclarée** dans `BOITES_INCOMPLETES`, avec sa raison, et
un test exige qu'une boîte désactivée ne soit jamais proposée comme thème. Sans
cette entrée, le contrôle de couverture aurait signalé l'Horreur des Glaces
comme une régression. Écarter du contenu est un choix écrit, pas un oubli.

---

## 3. Ce qui RESTE à faire — le choix à la création

### 3.1 Les sept options

| Valeur | Signifie | Éligibles |
|---|---|---|
| *(absent)* | « surprise » — rotation actuelle | selon l'id du groupe |
| `melange` | tous thèmes confondus | tout le bestiaire, aucun biais |
| `base` | jeu de base pur | `boite ∈ {base, null}` |
| `dread_moon` | Rise of the Dread Moon | `boite ∈ {dread_moon, null}` |
| `mage_du_miroir` | The Mage of the Mirror | `boite ∈ {mage_du_miroir, null}` |
| `horde_ogre` | Against the Ogre Horde | `boite ∈ {horde_ogre, null}` |
| `jungles_delthrak` | Jungles of Delthrak | `boite ∈ {jungles_delthrak, null}` |

### 3.2 ⚠ Deux pièges, tous deux non évidents

**a. « Jeu de base » n'est PAS un thème comme les autres.** La boîte de base n'a
**aucun sous-boss ni boss** — les huit cartes officielles sont toutes de tier
`base`, et le Champion, le Seigneur et le Troll sont à nous (`boite = null`). Un
mode puriste doit donc signifier `boite ∈ {base, null}`, sinon la campagne n'a
pas de boss, retombe en silence sur le pool complet, et **les extensions rentrent
par la fenêtre** — exactement ce qu'un puriste voulait éviter. Avec `null`
inclus, il obtient un arc complet : 8 créatures de base, Troll / Champion /
Chamane Gobelin en sous-boss, Seigneur / Liche / Sorcier des Tempêtes en boss.

**b. « Tous confondus » est un vrai TROISIÈME mode**, pas l'absence de choix.
C'est le comportement d'avant le thème, et il doit rester distinguable de « non
renseigné » — lequel mérite de garder la rotation-surprise. Confondre les deux
rendrait impossible de demander explicitement un mélange.

### 3.3 Surface technique

| Fichier | Changement |
|---|---|
| migration | `groupes.bestiaire` — `string(40)` nullable ⚠ **surtout pas `theme`**, le nom est pris par la prose du brief IA |
| `GroupeController::creer()` | `'bestiaire' => ['nullable', Rule::in([...])]` |
| `Groupe` | `bestiaire` dans `$fillable` |
| `DemarreurQuete::themeBestiaire()` | prend le `Groupe` : valeur explicite → elle ; `melange` → `null` (aucun biais) ; absent → rotation actuelle |
| `DemarreurQuete` | le biais compare `boite ∈ {theme, null}` au lieu de `boite === theme` |
| `JoueurView.vue` | un `<select>` à sept entrées dans le formulaire de création |
| `docs/contrat-api.md` | §Joueur — le champ et ses valeurs |
| tests | valeur respectée ; `base` produit un arc sans extension ; `melange` ne biaise rien ; valeur inconnue → 422 |

⚠ **`null` doit rester éligible dans TOUS les thèmes**, y compris `base` : c'est
l'invariant qui empêche un pool de se vider. Le seul mode qui l'ignore est
`melange`, qui n'a de toute façon aucun biais.

### 3.4 Ce que ça ouvre, et qui n'est pas dans ce plan

- **Montrer le thème** au narrateur et sur `/guide` — aujourd'hui il n'est
  visible nulle part, alors qu'il gouverne tout le bestiaire d'une campagne.
- **Le donner à l'IA** : `ContexteAssembleur` pourrait passer la boîte au
  squelette de campagne, pour que la prose et les figurines cessent de diverger.
- **Réactiver les glaces** quand les trois sorts manquants seront portés : une
  ligne à retirer de `BOITES_INCOMPLETES`, plus la remise de `horreur_glacee`
  au pool de la Confrontation finale.

---

## 4. Ce qui a été vérifié le 2026-09-04

Sur le vrai stack (`docker compose`), pas seulement en tests :

- les deux migrations appliquées à MariaDB, seeders rejoués, workers redémarrés ;
- `GET /api/guide` sert les cinq paquets de cartes, 29 cartes de Dread dont 23
  portées, 41 monstres ;
- une campagne réelle créée par les routes de la manette : quête démarrée
  (`boss_final`), menu servi, deux tours joués, round refermé
  (`nouveau_tour` journalisé), squelette de campagne et narration produits par
  l'IA, **aucune erreur** dans les logs `app` / `queue` / `queue-jeu` ;
- campagne de vérification purgée derrière (`nettoyer.sh`).

⚠ **Non vérifié en partie réelle** : le lancement effectif d'un sort de Dread —
le boss n'a pas été révélé pendant la vérification. Les 18 cas de
`SortsDreadCartesTest` l'exercent par les vraies routes, mais ce n'est pas la
même chose qu'une partie jouée.
