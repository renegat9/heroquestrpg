# Verdict — test de jeu du 5 août 2026 (2 joueurs + narrateur, UI réelle)

> Partie courte jouée de bout en bout par l'interface réelle : narrateur (écran
> de table, session par code) + **Krogar** (barbare) et **Lithiel** (elfe, Feu),
> chacun piloté par un agent distinct dans un vrai navigateur, sans jamais
> toucher à l'API ni à la base. Campagne « Les Cryptes de Kellar » (3 quêtes,
> squelette généré par l'IA), **quête 1 terminée par vote de sortie**, les deux
> héros vivants, bourse 400 → 325 or.
>
> Premier test après l'arrivée du **mobilier de salle** et du **ciblage en deux
> temps** — aucun des deux n'avait encore été éprouvé en jeu.

## 0. Écart assumé du protocole

Une campagne neuve démarre avec **0 or** : à la première ouverture du marché, la
bourse est vide, rien n'est achetable et les trois mercenaires (120/150/220 or)
affichent tous « Or insuffisant ». **C'est voulu** (décision de René,
2026-08-05) — les héros partent avec leur arme de classe, et le premier butin se
gagne dans le donjon, comme au plateau. Aucune dotation de départ à ajouter.

Pour pouvoir éprouver malgré tout achat / équipement / don, la bourse a été
portée à **400 or** avant le départ de ce test. Tous les constats de marché
ci-dessous sont donc à lire avec cette réserve : en partie réelle, la **première**
phase de marché d'une campagne n'a rien à proposer, et le marché ne prend son
sens qu'après la quête 1.

## 1. Bloquants — corrigés pendant le test

### 1.1 Groupe gelé indéfiniment : `GenererMenu` mort, aucun menu, aucun signal

**Symptôme.** Les deux manettes figées ~20 min sur « Le maître du jeu prépare la
suite… », narration d'ouverture affichée, aucune option, aucune erreur visible
côté client ni côté table. Les deux joueurs ont signalé un blocage total.

**Cause.** Les démons `queue`/`queue-jeu` tournaient depuis 43 h, donc sur le
code d'**avant** le commit e9752dc qui a scindé `mobiliers.bloquant` en
`bloque_mouvement`/`bloque_vue`. Chaque `GenererMenu` mourait sur
`Unknown column 'bloquant'` (`FabriqueGrille`), et **le menu est la seule chose
qui rende la main à un joueur**.

**Correctifs.**
1. Opératoire : workers redémarrés, jobs rejoués — la partie est repartie.
2. Structurel : `GenererMenu::failed()` publie désormais un **menu de secours**
   minimal (« Terminer le tour »), sans dépendance au moteur, à la carte ni à
   l'IA — précisément ce qui vient de casser quand on emprunte ce chemin. Le
   repli interne du job ne couvrait que l'appel LLM ; `MenuMoteur::generer()`,
   la résolution du personnage et la base tournaient **hors** du `try`.
   Tests : `tests/Feature/Partie/MenuSecoursTest.php` (3/3), dont un qui vérifie
   que le menu de secours est bien accepté par `POST /choix`.
3. `CLAUDE.md` : le redémarrage des workers après toute modification PHP est
   désormais documenté comme **obligatoire**.

### 1.2 « Fermer » d'une feuille recouvert par le bouton Hub → on quitte la partie

Le lien **Hub** (`.scene-ctrl`, `position: fixed`, centré en bas, `z-index: 200`)
se superpose au bouton **Fermer** de toute feuille (`.overlay`, `z-index: 60`).
Taper au centre de « Fermer » suivait le lien et **sortait de la quête** — c'est
exactement le bug corrigé au verdict §2.2, revenu par une autre porte :
`ManetteView` masque le lien via `v-if="!feuilleOption && !voteAffiche && !desReveles"`,
énumération qui ne peut pas connaître les feuilles ouvertes par un **onglet**
(`SpellInfoSheet`, onglet Sorts). Lithiel l'a rencontré et a dû viser à côté du
centre pour s'en sortir.

**Correctif** : la garde porte désormais sur la **présence** d'une feuille, pas
sur une liste à tenir à jour — `body:has(.overlay) .scene-ctrl { display: none; }`.
Vérifié en navigateur réel : `elementFromPoint` au centre de « Fermer » renvoie
le bouton, et le clic ferme la feuille en restant sur `/manette/…`.

## 2. Incohérences de règle — corrigées

### 2.1 Arme unique DUPLIQUÉE dans le même groupe

Lithiel puis Krogar ont chacun fouillé la salle-artefact (`tresors_fouilles` =
`["1:18","1:17","3:17","3:18","5:18","5:17"]`) et sont repartis **chacun avec
l'Arbalète des Murmures** (`objet_id` 14 en double). L'IA a même narré le bug
fidèlement : « il débusque une **seconde** Arbalète des Murmures, **identique** à
celle découverte par Lithiel ».

L'unicité n'était vérifiée qu'à la **construction du deck**
(`choisirArtefact()` écarte ce que le groupe possède), donc une fois par quête —
alors que la fouille est passée à « une par héros et par salle » entre-temps.
`carteCoffre()` re-teste maintenant la possession réelle (`artefactDejaPris()`)
et verse l'or du coffre au second fouilleur, conformément à la règle déjà
énoncée dans `CLAUDE.md`. Test de non-régression à deux héros dans
`DeckFouilleTest` (21/21).

### 2.2 Piège marché en se déplaçant : dégâts silencieux

Krogar est tombé dans une Fosse pendant un déplacement (`degats: 1`,
`immobilise: true`), a perdu 1 PV… et **aucune ligne au fil du combat**, alors
que le coffre piégé de Lithiel était, lui, parfaitement journalisé. Les pièges du
chemin arrivent sous `pieges_declenches` (**pluriel, une liste** — un chemin peut
en croiser plusieurs), et `JournalCombat` ne lisait que le `declenchement`
singulier des coffres. Le correctif précédent des « pièges muets » n'avait couvert
qu'une des deux formes de payload. Corrigé, 2 tests ajoutés (piège simple, chemin
à plusieurs pièges) — 12/12.

### 2.3 Le mélange du deck (et du pool de pièges) n'en était pas un

`PrngLineaire::melanger()` tirait son indice Fisher-Yates par
`suivant() % ($i + 1)`. Or les bits de poids faible d'un LCG à module 2^31 sont
**périodiques** — mesuré : `% 2` donne `010101…`, `% 4` donne `0123 0123…`.
Fisher-Yates finissant sur les petits modules, les dernières permutations
n'étaient pas aléatoires, et surtout la **tête de liste** restait quasi intacte.

Conséquences mesurées (4 000 donjons simulés) :

| | avant | après | attendu |
|---|---|---|---|
| 1re carte de fouille = `gemme` | 25,6 % | **8,1 %** | 8,3 % |
| 1re carte de fouille = `errant` | 17,2 % | **24,7 %** | 25 % |
| pièges placés en couloir | 62,7 % | **49,9 %** | 50 % |

La 1re fouille de CHAQUE donjon sortait donc une gemme 3× trop souvent (c'est
simplement le premier type que `cartes()` empile), et le pool de pièges
concaténé `[...couloirs, ...salles]` — fusionné en un seul tas précisément pour
corriger le « 58 pièges sur 61 en couloir » — replaçait encore 63 % des pièges
en couloir : le correctif était **décoratif**.

Corrigé en tirant l'indice sur les bits de poids fort (`suivant() >> 15`).
`suivant()` et ses constantes sont **inchangés** : les cartes déjà stockées ne
bougent pas, et `PrngLineaire` reste déterministe à graine égale — ce qui
importe pour `AssembleurCarte`, qui garde une graine dérivée du groupe. Le deck
de fouille, lui, n'a plus de graine stable du tout (voir §2.4). Tests :
`tests/Unit/PrngLineaireTest.php` (5/5), portant sur la distribution et non sur
des valeurs figées.

### 2.4 Le deck était REPRODUCTIBLE — tranché : il se rebrasse

La graine était `crc32("{identifiant}:{positionArc}:fouille")` : un même groupe
rejouant la même quête retrouvait **exactement le même deck, dans le même
ordre**. Après un TPK, une reprise sur snapshot ou un « Recommencer la quête
actuelle » du menu d'urgence, le groupe disposait de la liste ordonnée de ses
propres trésors, pièges et errants.

**Décision de René (2026-08-05) : le deck se rebrasse à chaque partie, reprises
comprises.** La graine est désormais tirée au sort (`random_int`), et
`Sauvegarde::restaurerQuete()` **remélange** le deck restauré au lieu de le
rendre tel quel : on restaure la **composition** (le deck cycle, aucune carte ne
se perd — le snapshot est la seule source de vérité là-dessus) et on rebrasse
l'**ordre**.

Restent figés à la reprise : `salle_artefact`, `salles_coffre` et
`artefact_objet_id`. Ce sont des **placements** liés à la carte, pas des tirages
— les re-tirer déplacerait le coffre sous les pieds du groupe, voire lui
offrirait une seconde arme unique.

Le test qui verrouillait l'ancien comportement (« rend exactement le même deck à
graine égale ») a été **inversé** : il vérifie maintenant même composition /
ordre différent. Deux tests ajoutés (rebrassage à la construction, rebrassage à
la reprise sur 5 restaurations successives).

## 3. Frictions d'interface — non corrigées, à arbitrer

1. **Le marché ne dit pas ce que fait une arme.** Le payload de `PhaseMarche`
   n'expose ni dés d'attaque/défense ni effet. Lithiel a acheté une Dague (25 or)
   et est passée de **2 dés d'attaque à 1** sans le moindre avertissement. La
   valeur est juste (doc 16 §42 : 1 dé, carte magicien) — c'est l'achat qui se
   fait à l'aveugle.
2. **Le mobilier n'est identifiable que par l'attribut HTML `title`**, invisible
   au tactile. On voit un bloc brun, on ne sait ni ce que c'est, ni s'il bloque
   la vue ou seulement le passage.
2 bis. **`objets.effet.duree` est une clé DÉCORATIVE.** Les potions de force,
   de défense et de rage portent `duree` (`0`, `0`, `"un_combat"`), mais
   `MoteurSorts::appliquerBuffPotion()` lit `duree_tours` — qu'**aucun objet ne
   porte** (seul `SortDreadSeeder` l'utilise). Le buff est en fait consommé à la
   prochaine attaque, jamais à l'expiration d'un compte de tours, et
   `"un_combat"` n'est lu par personne. Le guide n'affiche plus « Durée : 0 »
   (qui se lisait « expire immédiatement »), mais la clé reste à trancher :
   soit on implémente une durée réelle, soit on la retire du seeder — comme
   `attaque_second_rang` avant elle.
3. **Message de validation en anglais** à l'inscription :
   `The identifiant has already been taken.`
4. **« Total projeté » sur l'écran de table** affiche 400 or paniers vides :
   `total_projete` = `or + ventes − achats`, donc le **solde** projeté, pas un
   total de paniers. Le libellé dit l'inverse de ce qu'il montre.
5. **Cotte de mailles à 500 or** : hors de portée avant plusieurs quêtes, la
   campagne démarrant à 0 or (§0). Constat d'équilibrage, pas un bug — à revoir
   quand on aura des données sur le rythme d'accumulation d'or.
6. **BODY** désigne à la fois les PV (bandeau, « 8/8 ») et l'attribut (fiche,
   « BODY (ATTR.) 4 »).
7. **Don sans confirmation** : taper le nom du destinataire envoie l'objet
   immédiatement ; re-taper l'icône annule, mais rien ne le dit.
8. **Ouverture de porte** tantôt par option dédiée, tantôt implicitement en
   marchant dessus — la règle n'est pas lisible depuis l'interface.
9. Le lanceur d'un sort **se voit lui-même** dans la liste « Alliés (tir ami) ».
   Conforme au design (tir ami S3), mais surprenant sur un sort de dégâts.

## 4. Signalé par les joueurs, NON confirmé

- **« Équiper » qui désarme, avec 401 + 422 »** — rapporté par les **deux**
  agents. Aucun `/equipement` n'a renvoyé autre chose que **200** de toute la
  journée (log nginx) : les 401/422 de leur console étaient ceux du `/api/moi`
  d'avant connexion et de l'inscription en doublon, pendant la mise en place. Le
  tampon de console d'une session est cumulatif — piège classique de diagnostic
  par agent.
- **« Aucun mobilier visible sur la carte de la manette »** (Krogar) — il
  inspectait les classes des cellules ; le mobilier est un **bloc absolu**
  distinct (`.dg-furn`), pas une classe de cellule. Lithiel l'a bien vu et a
  confirmé le blocage de mouvement géométriquement.
- **« L'Établi d'alchimiste ne figure pas dans la doc mobilier »** (Lithiel) —
  il y figure : `reference/17_mobilier.md`, emprise mesurée LQ p. 13.

## 5. Ce qui marche

Marché à paniers confirmés en parallèle, équipement (dés recalculés en direct),
don entre héros, **ciblage en deux temps** (feuille nette, retour arrière fiable,
avertissement tir ami), **lancer d'arme** (libellé « Lancer Dague (perdue) », arme
réellement supprimée), **seuils de 2 cases** (deux héros de front, confirmé en
capture), lisibilité des portes (jambages dorés / absents), révélation de salle à
l'ouverture, **mobilier** (rendu distinct des figures, blocage de mouvement
effectif), pièges révélés et marqués durablement, conditions à décompte
(Empoisonné 2t→1t→disparu), fouille **une par héros et par salle**, journal de
combat détaillé (crânes/boucliers), **vote de groupe de sortie** (décompte en
direct, résolution, retour au hub), habillage IA des monstres, narration cohérente
avec la mécanique, niveaux inchangés (correct : `MonteeNiveau` n'agit que sur
les jalons `sous_boss`/`boss_final`).

## 6. Guide de jeu — maîtrises exposées, tous les objets documentés

Suite directe du test : Krogar a acheté et équipé un Casque sans le moindre
refus, et n'a donc jamais vu de règle de maîtrise. Et pour cause — la
restriction n'apparaissait **nulle part** dans le guide. Un joueur ne pouvait
l'apprendre qu'au refus, en essayant d'équiper.

`GET /api/guide` expose désormais `classes.tags_equipement` et
`objets.tag_equipement`, les deux moitiés de la règle (doc 01 §7). La page les
croise avec les nœuds `deblocage` (`effet.mecanique === 'acces_equipement'`) :

- **fiche de classe** → « Équipement maîtrisé » : les maîtrises de départ en
  plein, celles qu'un talent débloque en pointillé (« Armures lourdes — via
  Maîtrise lourde ») ;
- **fiche d'objet** → la maîtrise exigée, et **qui peut la porter** :
  « Barbare, Nain, Elfe · Magicien *via Cuir d'apprenti* », ou pour la Hache de
  bataille « Barbare · Nain *via Poigne de forgeron* ».

⚠ Une classe **sans** tags déclarés est affichée comme **sans restriction** :
c'est le comportement réel du moteur (il échoue ouvert pour ne jamais enfermer
un héros hors de son équipement de départ). Annoncer une interdiction que le jeu
n'applique pas serait pire que se taire.

**Tous les objets documentés.** Les 40 pièces portent un effet non vide (test de
non-régression), mais six clés n'étaient pas traduites et s'affichaient en brut :

| clé | avant | après |
|---|---|---|
| `soin_pv_body_de` | « soin pv body de : 6 » | **« 1d6 PV Body soignés »** |
| `attaque_supplementaire` | « attaque supplementaire : oui » | « Attaque supplémentaire ce tour » |
| `deplacement_sans_d6` | « deplacement sans d6 : oui » | « Déplacement fixe (sans dé) » |
| `sort_nom` | « sort nom : Boule de Feu » | masqué (double le nom de la pièce) |
| `duree: 0` | « Durée : 0 » | masqué (cf. §3, clé sans lecteur) |

La première comptait : la **Fiole de soin** du deck rend 1d6 et la **Potion de
soin** du marché un montant fixe — deux objets distincts *à dessein*, et le
guide affichait le 1d6 comme s'il valait 6 PV secs, soit l'inverse de la règle.

Vu aussi au passage : la base de dev servait encore `attaque_second_rang` sur la
Lance, clé retirée du seeder par le commit a1a6208 mais jamais re-semée. Un
`db:seed --class=ObjetSeeder` suffit (`updateOrCreate` par nom, aucune référence
cassée) — pense à le lancer après toute correction de catalogue, sinon le guide
documente fidèlement une règle qui n'existe plus.

### 6 bis. Les objets sont-ils FONCTIONNELS ? — audit des 22 clés d'effet

Chaque clé de `objets.effet` est une promesse faite au joueur, et le projet a
déjà dû en retirer deux qui n'étaient tenues par personne (`attaque_second_rang`,
`ligne_de_vue`). Audit exhaustif des 40 objets :

**19 clés sur 22 ont un lecteur réel dans le moteur** — chacune retrouvée avec le
fichier qui l'applique : `des_attaque`/`des_defense` (Equipement, deltas sur les
colonnes), `deux_mains`/`incompatible_deux_mains` (garde-fou bouclier),
`portee`/`inutilisable_adjacent` (arbalète), `attaque_diagonale`, `jetable`,
`permet_desamorcage` (MoteurPieges), `deplacement_sans_d6`
(`Engine\Deplacement`), `sort_id` (résolution du parchemin), et les sept clés de
`MoteurPotions` (soins fixe et 1d6, Mind, antidote, buffs, attaque
supplémentaire).

**3 clés sont inertes**, laissées en place sciemment (décision de René) :

| clé | portée | risque |
|---|---|---|
| `duree` | 3 potions | aucun — le buff est consommé à la prochaine attaque, pas par un compte de tours |
| `sort_nom` | 12 parchemins | aucun — libellé de confort, double le nom de la pièce |
| `difficulte_non_lanceur` | 12 parchemins | **dérive** — copie de `sorts.difficulte_parchemin`, qui est l'autorité |

La troisième méritait un garde-fou : `ResolveurTour::resoudreParchemin()` roule le
jet de Mind contre `sorts.difficulte_parchemin`, jamais contre la clé de l'objet.
Les 12 valeurs concordent aujourd'hui (vérifié), mais rien ne les y obligeait —
la faire diverger ferait afficher au guide une difficulté que le jeu ne lance
pas. Un test la verrouille désormais.

`tests/Feature/Partie/ObjetsFonctionnelsTest.php` fige l'inventaire des clés en
deux ensembles, actives et inertes : **ajouter une clé au seeder casse le test**
tant qu'on n'a pas tranché — lui écrire un lecteur, ou la déclarer décorative en
connaissance de cause. C'est ce qui empêche les clés décoratives de revenir.

### 6 ter. Le déplacement, pour mémoire

`déplacement = base de classe + 1d6` (`Engine\Deplacement`), bases seedées
barbare 4 / nain 3 / elfe 5 / magicien 4 — soit 4 à 11 cases selon la classe.
Trois précisions : l'**Armure de plates** supprime le dé (`deplacement_sans_d6`,
le porteur avance de sa base seule, seul objet qui touche au déplacement) ; la
base **inclut déjà** les bonus permanents (nœud *Pas léger* de l'elfe) ; le d6
est lancé à la **génération du menu** et mémorisé (`etat.deplacement_tour`), pour
que le joueur voie son allonce avant de choisir sa case. Un sort peut multiplier
le total (`multiplicateur_sort`). Les monstres, eux, ont un déplacement **fixe**,
sans dé. Divergence assumée du plateau, qui lance 2 dés rouges sans base
(`reference/16_armurerie.md`).

## 7. Suite

Non traité ici, faute de rencontre en jeu : **porte secrète** (jamais révélée en
3 « Fouiller la zone »), **Armoire** (le seul meuble opaque de la quête, dans une
salle non explorée) — donc le blocage de **vue** par mobilier reste **non
éprouvé en partie réelle**.

Suite complète : **543 tests, 14 146 assertions, tout au vert.**
