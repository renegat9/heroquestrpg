# Plan — les restes des extensions (2026-10-29)

Demande de René (2026-10-29) : « Oublie l'erratum du Deathspinner, fait un plan pour le reste ».
Ce plan rassemble ce que les plans par boîte ont laissé **non porté, écrit** (`plan-mage-du-miroir.md`,
`plan-spirit-queen.md`, `plan-telor.md`, `plan-witch-lord.md`), plus deux restes d'outillage
(jetons de monstres, images orphelines). Les textes des livrets ont été relus le même jour.

Chaque lot suit l'ordre maison : **vocabulaire fermé → lecteur → test EN JEU → données**, contrat
d'API d'abord, registres testés dans les deux sens, effet automatique annoncé. Pest sur sqlite
jetable ; `sauvegarder.sh` avant toute migration ; **aucune purge** de `groupes`/`personnages`/`joueurs` ;
redémarrer `queue` et `queue-jeu` après le PHP. Une modification de lignes existantes passe par une
migration.

## 0. Retiré par René

- **Erratum du Deathspinner** (*Spawn*, *Agile*, *Venomous*) : **abandonné** (René, 2026-10-29). La
  recherche du 2026-10-23 n'a trouvé aucune source officielle (`docs/plan-delthrak.md` §Exécution
  2026-10-23) ; les trois mots sont ceux du Rampant putride, dont la figurine représente la reine.
  La question « à trancher par René » de ce paragraphe est close. Rien n'est ajouté.

## 1. Lots, par ordre conseillé

| # | Lot | Taille | Boîte | Qui |
|---|---|---|---|---|
| A | Ménage : 11 images orphelines, 3 branches fusionnées | minuscule | — | moi |
| B | Parchemins à plusieurs usages | petit | Telor | moi |
| C | Miroirs (habillage du passage secret) | petit | Mage du Miroir | moi |
| D | Jetons carrés (Rejeton putride, Golem) et clôture des jetons absents | petit | Delthrak | moi |
| E | Coffre piégé qui lance un sort de Dread | moyen | Spirit Queen | agent |
| F | Grilles de fer et clé de laiton | moyen | Mage du Miroir | agent |
| G | Fosse longue | moyen | Mage du Miroir | agent |
| H | Terrains des tours (sol meuble, courants, vents) | moyen à gros | Spirit Queen | agent(s) |
| I | Talisman du Savoir maudit | gros | Telor | agent |
| J | Statues d'orcs | — | Witch Lord | à écarter |

### A — Ménage

- **Images orphelines** (`images:purger-orphelines`, relevé du 2026-10-29) : 11 fichiers, 14,6 Mo —
  5 objets du catalogue renommés ou retirés (`63-capuche-du-magister`, `64-runes-naines`,
  `66-parchemin-de-sorts`…), 1 illustration de quête, 4 de hub, 1 de monstre dont le sujet a disparu.
  Geste : sauvegarde, puis `--supprimer`. Aucun code.
- **Branches** `pouvoirs-finaux`, `pouvoirs-finaux-2`, `qwindrak-wip` : entièrement dans `main`,
  `git branch -d`.

### B — Parchemins à plusieurs usages (Telor, lot C)

Source : G0052, quête 11 trouvailles D et F, quête 13 trouvaille D — *Heal Body* et *Lightning Bolt*
« cast three times before the scroll crumbles to dust », *Water of Healing* « twice ».

**Correction du plan Telor** : il proposait une colonne neuve `objets.usages_restants`. Elle existe
déjà sous un autre nom : `inventaire.charges` + `objets.effet.charges` + `MoteurCharges` (René,
2026-08-09 — « un objet à charges fait ce qu'il annonce N fois, puis il est DÉTRUIT »). Une seconde
colonne serait une seconde copie de la même règle. Donc :

- `MoteurSorts::consommerParchemin()` (le point de passage unique : lecture **et** relance de
  *Vision du futur*) passe par `MoteurCharges::consommer()` quand le parchemin a des `charges`, et
  garde la destruction immédiate sinon.
- Trois lignes catalogue (`ObjetSeeder`, catégorie `parchemin`, hors marché comme tous les
  parchemins) : Soin du Corps ×3, Éclair ×3, Eau de Guérison ×2 — les trois sorts existent
  (`SortSeeder` : Éclair est un sort `element: parchemin`).
- À tester : un échec de lecture dépense une charge (règle S1 : « échec = parchemin gaspillé ») ;
  une pile `quantite > 1` ; l'annonce des charges dépensées (`TamponCharges`) ; le menu propose
  encore la lecture tant qu'il reste une charge.

**Question B1** : où se trouvent-ils ? Recommandation : dans le butin du **thème `prophecy_telor`
seulement** (même règle que les terrains scopés de la Q4 Spirit Queen), rareté `rare`. À vérifier
avant de coder : la table de butin sait-elle filtrer par boîte ? Sinon, un petit lecteur en plus.

### C — Miroirs (Mage du Miroir p. 5)

Source : « These standing mirrors are secret portals that lead to great treasure and hidden rooms. »
Distincts du miroir de la quête double (écartée le 2026-10-15).

- Aucune règle neuve : sous le thème `mage_du_miroir`, le **passage secret** (déjà généré, déjà payé
  par son trésor, `docs/regles/exploration-et-fouille.md`) s'habille en miroir une fois trouvé.
- Contrat d'abord : un champ publié sur la porte secrète révélée (ex. `habillage: 'miroir'`),
  le libellé côté front, et le symbole du miroir extrait du livret (manifeste, comme les autres).
- Rien ne change tant que le passage n'est pas trouvé : il reste peint en roche (`Grille::caseEmbrasure()`).

**Question C1** : le faire (petit, visible, sans risque) ou l'écarter comme pur décor ?
Recommandation : le faire.

### D — Jetons de monstres

État : 45 monstres sans jeton, chacun avec sa raison écrite dans `config/symboles_monstres.php`
(`absents`). La plupart n'ont tout simplement aucun jeton dans aucun livret : ce sont des
figurines, jamais dessinées sur une carte. Il n'y a rien à extraire pour eux.

Deux cas récupérables, tous deux représentés par une **tuile carrée** dans le livret :
- **Rejeton putride** (Spawnling, F9907 PDF 7) ;
- **Golem Cœur-de-Forge** (« Use the Forgeheart Golem's tile », F9907 PDF 12) — taille de la
  tuile à mesurer avant tout (une tuile 2×2 changerait aussi son emprise : à soumettre à René).

Geste : une forme `tuile` (d'abord écrite `carre`) à côté de `rond` et `ovale` (`symbole_forme`, contrat d'abord),
extraction des deux tuiles, test.

Écarté d'office : mettre l'**illustration** du monstre en guise de jeton. La règle de la carte
l'interdit (« illustrations dans la LÉGENDE, jamais sur la carte » — à 22-38 px une image devient
une bouillie et efface la silhouette).

**Question D1** : clore le sujet ainsi (2 tuiles carrées, les 43 autres gardent le jeton de
repli) ? Recommandation : oui.

### E — Coffre piégé qui lance un sort de Dread (Spirit Queen, lot I-a)

Source : G0053 q. 2 et q. 6 — si la fouille précède le désamorçage, « the Dread spell Ball of Flame
is cast on that hero ».

Deux manques, dans cet ordre :
1. **Pas de vocabulaire fermé des pièges** : il n'existe pas de `MotsClesPiege`, les clés d'effet
   des pièges ne sont déclarées nulle part. Ajouter une clé `sort_dread` sans ce registre, c'est la
   clé décorative que la règle dure interdit. Premier pas : créer `App\Engine\MotsClesPiege` avec
   toutes les clés déjà semées, testé dans les deux sens contre `PiegeSeeder`.
2. **Pas de sort sans lanceur** : `MoteurDread` résout chaque sort contre un lanceur-monstre. Il
   faut une entrée « sort lancé par un piège » : une seule cible (le fouilleur), l'effet lu au
   catalogue (Boule de Flammes : 2 dégâts, résistance 2 dés rouges) — **jamais recopié** dans la
   ligne du piège. Précédent partiel : le reflet du *Bâton Ancien*.

Puis une ligne de piège qui nomme le sort, et une entrée `issue: piege` dans la table de fouille
des coffres (le format existe déjà : `Fiole de poison`, `Aiguille empoisonnée`).

**Question E1** : ce piège n'apparaît que sous le thème `spirit_queen` ? Recommandation : oui.
À vérifier : si la table de fouille ne sait pas filtrer par thème, un petit lecteur en plus.

### F — Grilles de fer et clé de laiton (Mage du Miroir p. 4 et 6)

Sources : « Some of these massive iron gates open when the heroes spring a certain trap, while others
are unlocked by the brass key or forced open by brute strength. Heroes and monsters cannot see through
a portcullis until it is opened. » ; quête 10 note G : « a hero must roll less than their Body Points on
two red dice to force the portcullis open » ; *Brass Key* : « used to open a portcullis in several
quests. The brass key tile is given to the hero who finds the key. »

Modèle : le patron des **portes de pierre** et des **portes à condition**, déjà en service.
- Un verrou `grille` sur une porte `fermee`, posé **uniquement sur une arête de boucle**, au plus
  une par carte, sous le thème `mage_du_miroir` : jamais le seul chemin, par construction.
- **Bloque la vue** tant qu'elle est fermée (texte du livret), à vérifier contre `Grille::ligneDeVue()`.
- Voie **force** : deux dés rouges sous les PV de Body, échec = fin du tour (comme la porte à
  condition).
- Voie **clé** : le lecteur existe (`verrou.type: 'cle'`, `MoteurPortes::possedeCle()`), il manque
  le producteur. Un objet « Clé de laiton », donné par la fouille d'un meuble d'une autre salle
  (patron de la Table des brasseurs : objet NOMMÉ, `MoteurMobilier::carteDepuisEntree()`).
  Atteignable avant la grille par construction : la grille est sur une boucle, le reste de la
  carte reste connexe sans elle.
- Voie **piège** : propre à la quête 6, écartée le 2026-10-23.

**Question F1** : la voie « force » n'est sourcée que pour la quête 10 ; on l'étend à toutes les
grilles ? Recommandation : oui (sans elle, une clé ratée ferme la grille pour la quête).
**Question F2** : la clé disparaît en fin de quête (elle n'ouvre que la grille de cette carte) ?
Recommandation : oui — sinon le sac accumule des clés mortes.

### G — Fosse longue (Mage du Miroir p. 6)

Source : « If a hero wants to jump over this pit the long way, they must have at least three squares of
movement left after they move next to the pit. The hero then rolls one combat die. A black shield means
the hero has jumped across safely, using up three squares of movement. […] Any result other than a black
shield means the hero falls into the pit, suffering 2 Body Points of damage. On a subsequent turn, the hero
must roll a 5 or 6 on a red die to climb out of the pit. While in the pit, the hero rolls one less combat
die when attacking or defending (but never less than one die). Note: These traps can be jumped across the
short side, the same as regular pit traps. »

- Une fosse **de deux cases** (le « long way » coûte 3 cases ; le petit côté se saute comme une
  fosse ordinaire, `franchissable` existant). Taille à mesurer sur la carte du livret, comme le
  mobilier.
- **État durable « dans la fosse »** : une colonne sur `etat_personnage_quete`, comme `enlise`
  (Sables mouvants) — jamais le cache. Sortie sur 5-6 au dé rouge au tour suivant ; malus d'un dé
  de combat en attaque et en défense, jamais sous 1, lu au point de passage du combat.
- Saut long : un dé de combat, bouclier noir = franchie. Précédent : le saut de `MoteurSables`
  (même dé, même résultat), pas le jet de Body des fosses ordinaires.
- Le menu ne propose le saut long que s'il reste au moins 3 cases (« le menu n'offre pas ce que le
  résolveur refuse »).

**Question G1** : piège caché comme la Fosse (découvert par la fouille ou en tombant), ou terrain
visible comme les Sables ? Le livret ne parle que de la sauter, donc d'une fosse déjà vue.
Recommandation : **piège caché**, qui une fois découvert offre les deux sauts — c'est le
comportement de toute fosse du jeu.

### H — Terrains des tours (Spirit Queen, lot F)

Scopés `boite: spirit_queen` (décision Q4 du 2026-10-15). Trois des quatre portent sur une
**salle entière** : ils se posent comme une plaque de terrain qui couvre le sol de la salle.

| # | Terrain | Source | Ce qu'il faut | Taille |
|---|---|---|---|---|
| H1 | **Courants contraires** | q. 11 note B : « Each square in these rooms takes 2 movement points to traverse. Monsters are unaffected. » | `cout_deplacement: 2` existe ; il manque « héros seulement » (les monstres passent au coût normal) — une clé de `MotsClesTerrain` lue par `FabriqueGrille::pour()` selon qui marche | petit |
| H2 | **Sol meuble** | q. 10 note B : « if the result of the movement dice is odd, they become stuck in the first square of their movement and take 1 Body Point of damage. Once stuck, a hero may only roll 1 die for movement, and they only move if the result of the die is an even number. » | Un état durable « enlisé dans le sol » (colonne), lu au jet de déplacement ; la parité décide | moyen |
| H3 | **Courant porteur** | q. 11 note C : « A hero may move 2 extra squares when they first enter this area. » | Un **gain** de budget à la première entrée. Le Dijkstra pondéré refuse les coûts négatifs ; il faut un parcours qui porte « bonus déjà pris ou non » dans son état, et des destinations recalculées côté serveur | gros |
| H4 | **Vents de couloir** | q. 12 notes : en finissant son mouvement dans un couloir, un dé de combat : bouclier = 2 cases dans la direction **choisie par le héros**, crâne = 2 cases dans la direction **choisie par Zargon** | Un sous-menu de direction (choix en attente, option de manette) et un choix du moteur pour Zargon. C'est une règle de tous les couloirs d'une quête, posée ici sur les couloirs du thème | gros |

**Question H1** : porter H1 et H2 d'abord, et H3 et H4 seulement ensuite si tu y tiens ?
Recommandation : oui. H3 et H4 coûtent chacun un mécanisme neuf (parcours à état, choix de
direction en attente) pour un effet de quelques cases.

### I — Talisman du Savoir maudit (Telor, lot B)

Source : G0052 p. 5 et 18 — le porteur ne peut pas l'ôter (tenter : 1 PV de Body) ; à chaque début
de son tour, deux dés rouges : total sous ses PV de Mind actuels = 1 PV de Body perdu (+1 au total par
héros dans la même salle ou le même couloir, +2 par héros adjacent) ; il connaît *Lightning Bolt* et
*Firestorm*, une fois chacun par quête, et gagne 1 PV de Mind à chaque lancer.

Le livret en fait un **objet de scénario** (« One of the heroes must carry the Talisman of Lore »). Dans
une quête générée, la seule façon de le porter est d'en faire un **artefact maudit qu'on choisit
d'équiper**, en connaissant sa carte. Trois mécanismes neufs :
1. **Retrait impossible** : le menu ne propose pas de l'ôter (la pénalité du livret devient
   inatteignable — écart à nommer, Q2 du plan Telor).
2. **Jet automatique en début de tour du porteur** : précédent du point d'ancrage
   `MoteurOmbre::debutDeTour()` ; à construire comme ancrage générique « objet porté, début de
   tour » (Q5 du plan Telor), annoncé à la table.
3. **Deux sorts de Dread lancés par un héros**, une fois par quête chacun : aucun héros ne lance de
   sort de Dread aujourd'hui (Éclair de Chaos et Tempête de feu existent côté MJ).

Une ligne catalogue **distincte** du Talisman du Savoir actuel (Q1 du plan Telor) — sinon toute
campagne qui le tire en butin hérite d'une malédiction. La défaite narrée « Rise of Fellmarak » reste
écartée (fin de série scénarisée).

**Question I1** : le porter ainsi, en dernier, ou l'écarter comme objet de scénario ?
Recommandation : le porter, en dernier — c'est un vrai choix risque/récompense pour le joueur, et
l'ancrage « début de tour » resservira.

### J — Statues d'orcs (Witch Lord q. 10, p. 27)

« If a hero attacks any of these statues, their weapon breaks […] The only exception […] is the Spirit
Blade […]. However, it still does not harm the statue. » Elles bloquent totalement le couloir.

La forme (invulnérable, arme brisée sauf exemptée) est **construite et testée**
(`invulnerable_casse_arme`, `ImmunitesSelectivesTest`) mais non semée, avec sa raison dans
`MotsClesAdversaires::SANS_DONNEE` : une figure ne se pose qu'en salle, et un obstacle de couloir
est une règle de carte. Les porter demanderait une seconde règle de placement des figures, pour
une scène que le livret ne place que dans une seule quête.

**Question J1** : les écarter pour de bon ? Recommandation : oui. La forme reste en
`SANS_DONNEE`, sa raison mise à jour (« écartées par René, 2026-10-29 »).

## 2. Questions pour René (récapitulatif)

| # | Question | Recommandation |
|---|---|---|
| B1 | Parchemins à plusieurs usages : butin du thème Telor seulement ? | Oui, rareté `rare` |
| C1 | Miroirs : habiller le passage secret, ou écarter ? | Habiller |
| D1 | Jetons : 2 tuiles carrées, les 43 autres restent sur le repli ? | Oui |
| E1 | Coffre à sort : thème Spirit Queen seulement ? | Oui |
| F1 | Grilles : forcer à deux dés rouges sous le Body, sur toutes les grilles ? | Oui |
| F2 | Clé de laiton : disparaît en fin de quête ? | Oui |
| G1 | Fosse longue : piège caché comme toute fosse ? | Oui |
| H1 | Terrains des tours : H1 et H2 d'abord, H3 et H4 plus tard ? | Oui |
| I1 | Talisman maudit : artefact maudit qu'on choisit d'équiper, en dernier ? | Oui |
| J1 | Statues d'orcs : écartées ? | Oui |

## 3. Décisions de René (2026-10-29)

« Ok pour tout sauf H1 que je veux tout intégrer. »

| # | Décision |
|---|---|
| B1, C1, D1, E1, F1, F2, G1, I1, J1 | **Recommandation retenue**, telle qu'écrite au §2. |
| H1 | **Les quatre terrains des tours sont portés** : courants contraires, sol meuble, **courant porteur** et **vents de couloir** — pas de report pour H3 et H4. Le courant porteur demande un parcours qui porte « bonus déjà pris » dans son état ; les vents, un choix de direction en attente côté héros et un choix du moteur pour Zargon. |
| A | Feu vert pour le ménage : images orphelines effacées après sauvegarde, branches fusionnées supprimées. |

## 4. Exécution

### Exécution — lots A à D (2026-10-29, orchestrateur, branche `restes-petits`)

- **A — ménage** : sauvegarde `2026-10-29-2048`, puis `images:purger-orphelines --supprimer` (11 fichiers, 14,6 Mo) ; branches
  `pouvoirs-finaux`, `pouvoirs-finaux-2`, `qwindrak-wip` supprimées (entièrement dans `main`).
- **B — parchemins modifiés** : trois lignes `Parchemin modifié : Soin du Corps|Éclair|Eau de Guérison` (`effet.charges` 3/3/2,
  `rare`, `boite: prophecy_telor`, `prix_base` 0 faute de prix au livret). `MoteurSorts::consommerParchemin()` passe par
  `MoteurCharges` quand le parchemin a des charges ; `MoteurCharges::detruire()` ne brise que l'exemplaire entamé d'une pile ;
  `resoudreParchemin()` journalise la lecture AVANT la consommation (le bris s'écrit après) ; le menu suffixe « (N usages
  restants) ». Le Grimoire ne tire un parchemin boîté que sous son thème (`epreuveParchemin()`). Le même filtre sur le vivier des
  meubles est confié au chantier du lot E (propriétaire de `MoteurMobilier`). Tests : `ParcheminsModifiesTest` (8),
  `SortsFonctionnelsTest` mis à jour ; mutations : charges, thème, pile — chacune fait tomber au moins un test.
- **C — miroirs** : `MoteurPortes::HABILLAGE_MIROIR`, posé par `AssembleurCarte` sur toute porte secrète créée sous le thème
  `mage_du_miroir`, publié `carte.portes[].habillage` (toujours présent), « Ouvrir le miroir » au menu, teinte d'argent et ligne de
  légende au client. **Tranché seul** : aucun symbole de livret — les cartes écrivent « MIR » (F7539 PDF 17) et les images de la
  p. 5 sont des miroirs DE QUÊTE (la princesse, l'arc elfique). Tests : `MiroirsTest` (6) ; mutations : assemblage, symbole, menu.
- **D — jetons** : forme `tuile` (config `tuiles`, `BibliothequeImages::formeSymboleMonstre()`, `.jeton-tuile`, clé `tuile`
  du manifeste et de `extraire.py`) pour les figures que le livret pose en TUILE et non en tête dans un anneau :
  le **Rejeton putride** (`spawnling.webp`, F9907 PDF 7, la tuile de la légende du monstre errant, celle posée sur la carte)
  et le **Golem Cœur-de-Forge** (`forgeheart-golem.webp`, F9907 PDF 12). Tests : `SymbolesMonstresTest`, `SymbolesLivretsTest`,
  `BestiaireSourceTest`.
  ⚠ **Correction du plan** : la tuile du Golem n'est PAS carrée — mesurée le 2026-10-29, une colonne sur DEUX cases (la tuile
  posée dans la salle A de la carte de la quête 6, et l'image des notes, 2:1). **Décision de René (2026-10-29) : « Oui passe à
  2 cases »** → emprise 1×2 (`MonstreSeeder`, migration `2026_10_29_200000_golem_coeur_de_forge_deux_cases` ; 0 instance de
  Golem en base ce jour). La règle des emprises devient « ovale OU tuile de deux cases » (`BestiaireSourceTest`).

### Exécution — lot E (2026-10-29)

Chantier RESTES-COFFRE (worktree `restes-coffre`). Décision E1 appliquée : **le piège n'existe que sous le thème `spirit_queen`** (`pieges.boite`). Aucune migration : `PiegeSeeder` et `MobilierSeeder` seulement, en `updateOrCreate`.

**Porté**
- Vocabulaire fermé `App\Engine\MotsClesPiege` : dix-huit clés, chacune avec son lecteur nommé. `MotsClesPiegeTest` les teste dans les deux sens contre les lignes semées (branches `aleatoire` comprises), et exige que le PREMIER lecteur LISE la clé (`data_get`, `effet[]`, `issue[]`) : une simple écriture de payload ne suffit pas. Dette nommée : `detection` (`SANS_LECTEUR`), semée et lue par aucun code, non retirée (une migration sur une valeur en base est une décision de René).
- Sort lancé par un piège : `MoteurDread::lancerSortDePiege()` (sorts de dégâts seulement). La frappe est extraite de `sortDreadDegats()` dans `frapperHerosDeSort()`, le point de passage commun ; `degatsInfliges()` accepte un lanceur nul. Pas de `lanceur_id` : le Bâton Ancien ne propose rien.
- Donnée : `Piège de Boule de Flammes` (`boite: spirit_queen`, `sort_dread: 'Boule de Flammes'`, `declencheur: ouverture_tresor`), et une entrée `issue: piege` de poids 1 dans la table du Coffre.
- **Filtre de thème dans les tables de meuble** (demande de l'orchestrateur) : `MoteurMobilier::tirerButin()` reçoit `BestiaireGroupe::duGroupe($groupe)->boites` aux deux points d'appel (fouille, destruction de meuble). Une entrée `piege` ou un objet NOMMÉ boîté hors thème sort du tableau avant le tirage pondéré ; le vivier d'une entrée à catégorie ne retient que les objets sans boîte ou de boîte jouée. **Conséquence voulue : les potions boîtées de Delthrak (*Potion de sang de serpent*) et de Morcar (*Potion de résistance au feu*, *Potion de prédisposition magique*, *Potion de résistance à la magie*) ne tombent plus des meubles hors de leur thème.**
- Annonce : `JournalCombat::piegeDeclenche()`, branche `sort_dread` (aucun type d'action nouveau, `JournalCombat::TYPES` inchangé). Contrat : `docs/contrat-api.md`.

**Non porté, et pourquoi**
- « May be disarmed normally » : un coffre n'a aucun geste de désamorçage avant fouille (seule la fournaise en a un). Le piège part à la fouille, comme le Piège de coffre.
- Sort de piège non-dégâts : exception explicite (aucun piège n'en lance aujourd'hui).
- Voie `paliers_d6` d'un sort de piège : le garde `$instance === null` est écrit, mais aucun test ne la joue (aucun piège porteur de paliers).

**Tranché seul**
- Nom « Piège de Boule de Flammes », poids 1 (⚠ non trouvé : aucun livret ne donne de fréquence), lanceur affiché « Le piège ».
- Le filtre couvre aussi les objets NOMMÉS, au-delà du vivier demandé : une seule copie, dans `MoteurMobilier::admisParLeTheme()`.
- Attribution de la note B (p. 9) à la quête 6 par la mise en page (la page n'est pas rendue sur l'hôte).

**Tests** : `MotsClesPiegeTest` (5), `PiegeCoffreSortDreadTest` (14, dont quatre de câblage passant par `resoudreFouilleMobilier()` et `fracasserMobilier()`), `MobilierBoiteTest` (7). Régression ciblée, douze suites : 246 passés. Mutations, une par lecteur neuf, toutes attrapées : sort non résolu (6 échecs), `sort_dread` non lu (7 en jeu, 1 au registre), filtre du piège (6), filtre de l'objet nommé (1), vivier par catégorie (1), absorption typée retirée du point de passage (1), fil sans le sort (1), boîtes non transmises à la fouille (1) et à la destruction (1). Restauration vérifiée octet pour octet.

**Suite complète** (une seule fois, en parallèle, 982 s) : 2 997 verts sur 2 999, 129 683 assertions. Les deux rouges venaient de ce chantier, pas d'une régression : le nouveau piège n'avait ni icône dans `symboles.js` (`SymbolesCarteTest`, « pièges sans icône propre ») ni entrée dans `image-tools/symboles-livrets/manifeste.json` (`SymbolesLivretsTest`, « le type pieges ne couvre pas exactement le catalogue »). Corrigé : icône `auto_awesome` (ni la flamme de la Fournaise, `whatshot`, ni celle du Piège d'embrasement), entrée « absent » sur le patron du Piège de coffre. Rejoués : les trois suites de symboles (32 verts), le lot ciblé du chantier (58 verts), le front reconstruit (`npm run build`, sortie 0).
