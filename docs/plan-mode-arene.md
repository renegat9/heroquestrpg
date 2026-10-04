# Plan — Un mode Arène, étanche à la campagne

> ⚠ **Document DATÉ du 2026-10-04**, comme les autres `docs/plan-*.md`.
> Demande de René : « Fait un plan pour ajouter un mode arène. Il faudrait que
> ça n'impacte pas le mode campagne et leur héros. »
>
> C'est la **forme B** de `docs/plan-tournoi-worlds-end.md` (« un mode arène,
> rejouable, sans effet sur l'arc »). Ce plan en reprend le moteur de combat
> (lots T1 à T5) et ajoute ce que le plan du tournoi ne traitait pas :
> **l'étanchéité**.

## 0. Décisions de René (2026-10-04)

| # | Décision | Conséquence |
|---|---|---|
| **AQ1** | **Copie de son héros**, ou **nouveau héros dédié à l'arène** | Deux sortes de combattants (§2) |
| **AQ2** | Oui pour un héros en campagne, « vu que c'est une copie, mais **rafraîchie** » | La copie entre à PV pleins, sorts rechargés, sans conditions |
| **AQ3** | **Coop et Monster vs Monster** (René, 2026-10-04 : « Retire le pvp et ajoute le monster vs monster (aucune stats) »). Le PvPvE puis le PvP, d'abord demandés, sont **retirés** : ni l'un ni l'autre n'est dans le livret | Deux modes (§4), tous deux **dans les règles** : le tournoi oppose Challenger (héros) et Defender (Zargon), et sa variante officielle « Monster vs Monster » (p. 16) fait s'affronter deux équipes de monstres. Aucun héros ne vise jamais un héros |
| **AQ4** | **Palmarès**, plus, **par héros** : ennemis vaincus, nombre de combats, nombre de victoires | Statistiques d'arène rattachées au héros source, dans leur propre table (§5) |
| **AQ5** | **Scripté** | Aucun appel d'IA en arène |
| **AQ6** | **Non** : pas de tournoi dans la campagne | `plan-tournoi-worlds-end.md` ne garde que son moteur de combat (T1-T5), au service de l'arène ; ses quêtes en campagne sont abandonnées |
| — | « **Il faut garder les noms originaux des monstres** » | En arène, aucun habillage : un gobelin s'appelle « Gobelin » (§4) |
| **AQ7** | Le héros dédié à l'arène est créé **niveau 1** | Pas de niveau au choix. ⚠ Lu comme « commence au niveau 1 » : s'il doit **monter** avec les victoires d'arène (ma recommandation) ou rester au niveau 1, c'est à confirmer au lot A6b |
| **AQ8** | Formation des équipes : **un écran dédié**, conçu par Claude | §7 bis |
| **AQ9** | ~~Cible de Zargon en PvPvE~~ | Sans objet : PvPvE retiré |
| **Règles** | Alignement sur le livret (René, 2026-10-04, après relecture des p. 12-17) | Trois écarts du plan corrigés : difficulté jamais au-dessus des héros, table de trésor 2d6 rendue, alliés dans l'équipe Challenger (§4, §2). Trois points du tournoi fixés comme au livret : activation du premier qui appuie, trophées face cachée, mort = hors jeu |

## 1. La contrainte, et pourquoi elle est difficile

« Ne pas impacter les héros » ne peut pas reposer sur la prudence. Le moteur
écrit **directement** dans les lignes d'un héros, à des dizaines d'endroits.
Une arène qui ferait combattre le vrai héros le modifierait :

| Ce que le combat change | Où c'est écrit |
|---|---|
| PV de Body et de Mind, chute, mort | `personnages.pv_body` / `pv_mind`, `etat_personnage_quete.tombe` |
| Potions bues, parchemins lus | `inventaire` (lignes supprimées ou décrémentées) |
| Arme lancée (dague, hachette), objet détruit par la *Rouille* | `inventaire`, **définitivement** |
| Sorts lancés (épuisés) | `personnage_sorts.disponible` |
| Conditions (empoisonné, endormi, choc…) | `personnage_conditions` |
| Or ramassé, butin de fouille | `personnages.or`, `inventaire` |
| Niveau, points de compétence | `MonteeNiveau` → `personnages.niveau`, talents |
| Bénédiction / malédiction de l'Oracle | `personnages.benediction_oracle` / `malediction_oracle` |
| Appartenance au groupe | `personnages.groupe_actif_id`, `groupe_personnages` |

Interdire chacune de ces écritures en mode arène demanderait un garde-fou à
chaque site, et le **prochain** site écrit l'oublierait. C'est exactement le
défaut « deux copies d'une règle » que ce projet paie le plus souvent.

## 2. Le principe : le héros n'entre jamais dans l'arène, sa DOUBLURE y entre

À l'entrée en arène, le serveur crée une **doublure** : une **copie** du héros,
c'est-à-dire une nouvelle ligne `personnages` (mêmes classe, niveau,
attributs, PV max, dés), avec la copie de son inventaire porté, de ses sorts et
de ses talents. Tout le combat s'applique à la doublure ; **le moteur ne change
pas d'une ligne**. À la fin du combat, la doublure est supprimée : ses lignes
filles (`inventaire`, `personnage_sorts`, `personnage_conditions`, talents,
`etat_personnage_quete`) partent avec elle, par les `cascadeOnDelete` qui
existent déjà.

Le vrai héros n'est **jamais lu en écriture** : aucune requête de l'arène ne
porte son `id`. L'étanchéité tient par construction, pas par vigilance.

- **Colonne** : `personnages.doublure_de` (nullable, FK vers le héros source).
  Lue à quatre endroits seulement :
  - le **roster** (`/moi`), qui ne montre jamais une doublure ;
  - la **création de doublure**, qui refuse de doubler une doublure ;
  - la **fin de combat**, qui supprime les doublures du groupe d'arène ;
  - le **ménage de secours** (§6).

  Ce n'est pas le drapeau `est_test` refusé le 2026-08-23 : c'est le serveur
  qui la pose, et chaque lecteur est nommé.
- **Ce qui est copié** : l'état **de départ** du héros, PV **au maximum**
  (on entre frais dans l'arène, quel que soit l'état du héros en campagne),
  sans conditions, inventaire **porté** compris.
- **Ce qui n'est jamais copié** : l'or, l'appartenance au groupe de campagne,
  la bénédiction et la malédiction de l'Oracle (ce sont des états de
  campagne, et la malédiction se lève contre de l'or au marché).
- **Alliés** (livret p. 12 : « *Heroes **and allies** make up the Challenger
  team* ») : chaque joueur peut engager **un** allié, choisi dans le
  catalogue des alliés (mercenaires et compagnons, `MercenaireSeeder`), sans
  coût. Il n'y a rien à copier : un allié n'appartient pas au héros, il est
  engagé par un groupe, pour une quête. Il naît dans le groupe d'arène et
  disparaît avec lui. Il compte dans la **puissance** du Challenger (1 + ses
  dés d'attaque, même règle qu'un héros). Les ennemis qu'il abat ne sont
  crédités à personne dans les statistiques.
  - ⚠ Prérequis moteur : aujourd'hui **les monstres ne ciblent jamais un
    allié** (`ResolveurTour::phaseAllies()`, « hors périmètre v1 »). Dans
    l'arène, un allié est un membre de l'équipe : les monstres doivent pouvoir
    le viser, et il se défend avec les boucliers **blancs**, comme un héros
    (errata 2021, confirmé par Hasbro). C'est aussi le prédicat d'adversaire
    unique du lot A9. Le lot A4 en dépend.
- **Héros dédié à l'arène** (AQ1) : un vrai personnage du roster du joueur,
  créé pour l'arène (classe, nom, portrait) et marqué
  `personnages.mode = 'arene'`. Il n'entre **jamais** en campagne : il n'est
  pas proposé à la création ni à l'adhésion d'un groupe de campagne, et le
  refus est testé. Il combat lui aussi **par doublure**. Il reste donc
  toujours frais, et ses statistiques (§5) s'attachent à lui.
  - **Niveau 1 à la création** (AQ7). Monter de niveau est l'une des rares
    choses qui ne peuvent pas fuir dans une campagne, puisque ce héros n'en
    fera jamais ; reste à confirmer s'il monte avec ses victoires d'arène.
- **Copie d'un héros de campagne** (AQ1, AQ2) : la doublure de son état
  **rafraîchi** — PV et Mind au maximum, sorts rechargés, sans conditions —
  avec son niveau, ses talents et son équipement porté du moment.

**Le test qui garde tout le reste** (premier écrit, avant toute fonctionnalité) :
1. prendre une **empreinte** complète du héros source : sa ligne et toutes ses
   lignes filles ;
2. jouer un combat d'arène complet par les vraies routes : dégâts, potion
   bue, dague lancée, sort lancé, Rouille subie, mort, victoire ;
3. vérifier que l'empreinte est **identique au bit près**, et que plus aucune
   doublure n'existe.

Si un jour un site écrit par erreur sur le héros source, ce test casse.

## 3. Le groupe d'arène : un mode, pas une campagne

Un combat d'arène est un `groupes` comme les autres, pour réutiliser la
table, la manette, Reverb, les menus et le tour. Une colonne
**`groupes.mode`** (`campagne` par défaut, `arene`) le distingue, lue par un
seul prédicat, **`Groupe::estArene()`**.

Les étapes **propres à la campagne** sont fermées au groupe d'arène, chacune
à son point d'entrée :

| Étape de campagne | En arène |
|---|---|
| Squelette de campagne et bible (IA + Qdrant) | Jamais lancés : aucune écriture dans la bible d'un groupe de campagne, ni dans une bible propre |
| Thème de bestiaire, jalons, gabarit de quête | Remplacés par la composition à puissance d'équipe (tournoi T3) |
| Génération de carte (`AssembleurCarte`) | Remplacée par l'arène fixe (tournoi T1) |
| Montée de niveau (`MonteeNiveau`) | Jamais : aucun déclencheur en arène |
| Hub, marché, forge, mercenaires, dons | Fermés |
| Vote de retraite, clôture de campagne, résumé IA | Remplacés par « abandonner le combat » et l'écran de résultat |
| Images de quête, récits pré-générés | Aucun (scripté, sans IA ; question AQ5) |
| Instantanés / reprise | Non : un combat d'arène se rejoue, il ne se reprend pas |

⚠ Chaque fermeture est **testée** : un groupe d'arène qui atteint une de ces
étapes doit lever une erreur nommée, jamais passer en silence. Sinon la
première étape de campagne ajoutée plus tard s'appliquera à l'arène sans que
personne ne le voie.

**Un héros en campagne peut-il aller en arène ?** Oui, puisque c'est sa
doublure qui y va : son `groupe_actif_id` ne bouge pas, et la campagne
continue de le voir disponible (question AQ2).

## 4. Le combat (repris du plan du tournoi)

**Deux modes** (AQ3), tous deux tirés du livret :

| Mode | Équipes | Ce qui manque au moteur |
|---|---|---|
| **Coop** | les héros (Challenger) contre Zargon (Defender) | Rien de plus que le tournoi (T1 à T5) |
| **Monster vs Monster** | deux équipes de **monstres**, chacune dirigée par un joueur (ou plusieurs) | Des **monstres joués par des joueurs** : aujourd'hui un monstre n'agit que par l'IA du moteur. Il faut un menu de monstre sur la manette (se déplacer, attaquer, lancer un sort de Dread s'il en connaît), construit par `MenuMoteur` et revalidé par le résolveur, comme pour un héros |

**Monster vs Monster — la règle du livret (p. 16)** : « Both sides agree on an
equal team power for both sides. Randomly determine which team will be the
Challenger team, and which team the Defender team. The Defender team selects
a monster from those available first, and the two teams proceed to alternate
selecting monsters until each team meets the agreed-upon team power. As with
all tournament battles, the Defender team starts the first round. »
- La **sélection alternée** se joue dans le salon (§7 bis) : la puissance
  convenue est choisie par la table, le tirage au sort est fait par le
  serveur, et le roster est celui du tournoi (p. 16-17, puissance par
  monstre).
- **Qui est l'adversaire** devient une question d'**équipe** et non plus de
  nature (deux équipes de monstres se frappent). Le moteur l'apprend à **un
  seul** endroit, un prédicat `adversaires(figure)`, lu par le ciblage, les
  zones d'effet, le flanquement et les réactions — sinon chaque règle aurait
  sa propre idée de qui est un ennemi. En Coop, il rend exactement ce que le
  moteur fait déjà.
- Les capacités et les sorts de Dread d'un monstre restent les siens : un
  joueur qui dirige un Sorcier lance ses sorts sur l'équipe adverse.
- **Aucune statistique** (René) : un combat Monster vs Monster n'écrit ni
  dans le palmarès ni dans les statistiques par héros. Il n'y a d'ailleurs
  aucun héros dans ce mode, donc aucune doublure.

**Noms originaux des monstres** : en arène, aucun habillage n'est jamais
écrit (pas de job `HabillageMonstres`, pas d'IA, AQ5). `InstanceMonstre::nomAffiche()`
retombe alors sur le nom du catalogue, partout où le jeu l'affiche. Un test
le vérifie : un combat d'arène complet n'affiche que des noms du catalogue.

| Lot du tournoi | Repris tel quel | Adaptation au mode arène |
|---|---|---|
| T1 arène fixe | oui | — |
| T2 activation alternée | oui | Côté héros, active **le premier joueur qui appuie** parmi ceux qui n'ont pas joué : « *activate any one hero* » |
| T3 puissance d'équipe et roster | oui | Difficulté au choix, mais **jamais au-dessus des héros** : puissance du Defender **égale ou inférieure** à celle du Challenger (« *must be less than or equal to* », p. 14). La puissance compte la meilleure attaque, **sort compris** (le Génie vaut 6, exemple du livret), et les **alliés** |
| T4 trophées | oui | **Face cachée**, révélés au ramassage, comme au plateau |
| T5 combattant seul, mort, trésor 2d6 | oui, **tout** | **Mort** : la copie est hors jeu jusqu'à la fin du combat, ses objets restent au sol et se ramassent en passant à côté. **Trésor** : la table 2d6 du livret (p. 13) telle quelle — les pièges blessent la copie, la **hache de bataille en os** sert jusqu'à la fin du combat, l'or est trouvé. Tout disparaît avec la copie : rien ne sort de l'arène, l'or n'est noté qu'au palmarès |
| T6 vagues, Doralf, Gruzbella | oui | Ils deviennent des **défis** au choix : « trois vagues », « champions de World's End », « Gruzbella » |
| T7 écrans | oui | Plus un bandeau permanent « Arène — rien ne compte pour la campagne » |

La mort en arène ne concerne que la doublure : elle est hors jeu jusqu'à la
fin du combat, puis supprimée comme les autres.

## 5. Ce que l'arène rapporte : rien à la campagne, des statistiques au héros

L'or, les objets, les niveaux et le butin ne sortent jamais de l'arène :
c'est la contrainte. Ce qui en sort (AQ4) :

- **Palmarès des combats** : table `arene_combats` (date, défi, joueurs et
  classes, victoire ou défaite, rounds, puissance de chaque camp). **Coop
  seulement** : un combat Monster vs Monster n'y entre pas (René : « aucune
  stats »).
- **Statistiques par héros** : table `arene_statistiques`, une ligne par
  **héros source** (le héros de campagne ou le héros d'arène, jamais la
  doublure, qui disparaît) :
  - nombre de combats et de victoires ;
  - **ennemis vaincus par type**, comptés par **nom de catalogue**
    (`monstres.nom_base`, jamais un nom habillé) ;
  - le **coup de grâce** est ce qui compte un ennemi vaincu : c'est le
    héros qui fait tomber le dernier PV qui le gagne.
- **Où ça s'affiche** : la page Arène (palmarès général), et la fiche du
  héros (ses statistiques d'arène, dans une section à part).

⚠ Écrire ces statistiques **n'est pas** toucher au héros. Elles vivent dans
leur propre table, liée au héros par une clé étrangère en
`cascadeOnDelete` (supprimer un héros emporte ses statistiques). Le test
d'étanchéité (§2) compare l'empreinte du héros **hors** de cette table, et
vérifie à part que la table a bien été incrémentée. La doublure garde un
lien vers son héros source précisément pour que le coup de grâce soit
crédité au bon héros avant qu'elle ne soit supprimée.

## 6. Ménage et sécurité des données

- **Fin de combat** (victoire, défaite, abandon) : on supprime les doublures
  du groupe d'arène, puis le groupe lui-même. Cette purge ne passe **jamais**
  par `partie:purger` ni par `ClotureCampagne::purger()`, qui visent les
  campagnes. Elle a son propre point de passage, qui **refuse** un groupe dont
  `mode ≠ arene` et une ligne `personnages` dont `doublure_de` est nul. Ce
  double verrou rend impossible la suppression d'un vrai héros ou d'une vraie
  campagne par ce chemin, même par erreur d'identifiant.
- **Ménage de secours** : une commande `arene:nettoyer` supprime les combats
  d'arène abandonnés (onglet fermé, serveur redémarré) de plus de N heures,
  avec le même double verrou. Elle peut être planifiée.
- **Sauvegardes et page Système** : les groupes d'arène sont éphémères.
  Le garde-fou « 0 groupe alors que la sauvegarde précédente en avait »
  (`sauvegarder.sh`, page Système) ne doit compter **que les campagnes**
  (`mode = campagne`), sinon la fin d'un combat d'arène ferait varier les
  comptes. À adapter dans les deux.
- **Tests** : toujours sur une copie sqlite jetable, jamais sur la base du
  conteneur (règle du projet).

## 7. Écrans

- **Accueil** : une entrée « Arène », à côté de Narrateur et Joueur.
- **Écran d'arène (table)** : on choisit un défi (vagues, champions, un boss,
  ou un combat libre à la puissance choisie), puis on ouvre la partie par un
  code, comme une campagne.
- **Manette** : le joueur rejoint avec le code et choisit « mon héros » (sa
  doublure) ou un champion prétiré. Le reste est la manette du tournoi (T7),
  avec le bandeau d'étanchéité.
- **Résultat** : l'écran de fin donne le palmarès, puis « rejouer » ou
  « quitter ».

## 7 bis. Le salon d'un combat d'arène (AQ8, laissé à Claude)

L'écran entre l'ouverture de la table et le premier round. Il prend deux
formes, selon le mode.

**Sur la table** (l'écran partagé, qui le pilote) :
- Le **code** du combat, en grand, comme à l'ouverture d'une campagne.
- Le **mode** (Coop ou Monster vs Monster) et, en Coop, le **défi**,
  modifiables tant que personne n'est prêt.
- Le bouton **« Lancer »** n'apparaît que quand le combat est légal et que
  tous les joueurs ont dit « prêt ».

**Coop** :
- Une colonne de héros, avec la **puissance** de l'équipe (1 + les dés de la
  meilleure attaque, par héros, la règle du tournoi), recalculée à chaque
  arrivée, et celle du Defender composé en face.
- Chaque joueur, sur sa manette : rejoint avec le code, choisit son
  combattant (la copie d'un de ses héros de campagne, un de ses héros
  d'arène, ou « créer un héros d'arène », niveau 1), éventuellement **un
  allié** du catalogue, puis « prêt ».
- Un joueur engage un seul combattant, et un même héros ne peut pas avoir
  deux copies dans le même combat.

**Monster vs Monster** :
- La table fixe la **puissance convenue**. Les joueurs rejoignent et
  choisissent leur camp (deux colonnes) ; la table peut en déplacer un d'un
  appui long, et il voit « déplacé vers l'équipe B ».
- Au lancement de la sélection, le serveur **tire au sort** Challenger et
  Defender. Puis les deux camps **choisissent leurs monstres à tour de rôle**
  dans le roster, Defender d'abord, chacun sur la manette d'un de ses
  joueurs. Un monstre trop cher pour ce qui reste de puissance n'est pas
  proposé.
- Dans un camp à plusieurs joueurs, les monstres choisis se **répartissent**
  entre eux, chacun dirige les siens.

**Dans les deux cas** :
- **Tout est décidé par le serveur** (règle du projet) : la puissance, le
  tirage, le tour de sélection, les monstres encore abordables et la
  légalité du lancement arrivent calculés dans le payload. Le front ne
  recalcule rien.
- **L'état du salon est en base** (camp, statut prêt, monstres choisis),
  jamais en cache : un téléphone qui se recharge retrouve sa place.

## 8. Lots, dans l'ordre

| Lot | Contenu | Dépend de |
|---|---|---|
| **A0** | Prérequis du tournoi : T1 arène fixe, T2 activation alternée | plan du tournoi |
| **A1** | **Doublures et test d'étanchéité** : colonne, création, suppression, roster, empreinte | — (**à faire en premier**) |
| **A2** | Groupe d'arène : `groupes.mode`, `estArene()`, fermeture testée de chaque étape de campagne (§3), purge à double verrou, `arene:nettoyer` | A1 |
| **A3** | Composition par puissance et défis (T3, T6), difficulté choisie | A0 |
| **A4** | Trophées face cachée, combattant seul, mort, table de trésor 2d6 (T4, T5) ; **alliés** dans l'équipe Challenger, visés par les monstres, défense aux boucliers blancs | A0 |
| **A5** | Écrans : accueil, choix du défi, manette, résultat | A2, A3 |
| **A6** | Palmarès et statistiques par héros (combats, victoires, ennemis vaincus par nom de catalogue) | A5 |
| **A6b** | Héros dédié à l'arène : création, refus en campagne, niveau (AQ7) | A1 |
| **A9** | **Monster vs Monster** : prédicat d'adversaire par équipe, monstres joués par des joueurs (menu de monstre sur la manette, revalidé par le résolveur), sélection alternée à puissance convenue, aucune statistique | A2, A4 |
| **A7** | Garde-fous de sauvegarde et page Système qui ne comptent que les campagnes | A2 |
| **A8** | Campagne d'agents en arène, en Coop puis en Monster vs Monster (`campagne-agents`) : plusieurs combats complets, puis vérification que les héros de campagne et les deux groupes réels sont **intacts** | tout |

**A1 vient avant tout le reste** : tant que le test d'étanchéité n'existe
pas, aucune autre ligne de l'arène ne doit toucher la vraie base.

## 9. Questions pour René

Toutes tranchées (§0), sauf un point d'AQ7 : le héros d'arène, créé niveau 1,
**monte-t-il** avec ses victoires d'arène ? À confirmer au lot A6b.
