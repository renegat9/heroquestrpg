# Plan — Définir entièrement l'extension *Rise of the Dread Moon*

> ⚠ **Document DATÉ du 2026-10-04**, comme les autres `docs/plan-*.md` : un
> instantané d'audit et de découpage, pas tenu à jour. Les règles en vigueur
> iront dans `docs/regles/`, les sources dans `reference/18_extensions.md`
> §Rise of the Dread Moon.
>
> **Source** : livret de quêtes officiel **F6646**, *Rise of the Dread Moon*
> (© 2023 Hasbro, 40 pages imprimées / 21 pages PDF), téléchargé le
> 2026-10-04 depuis `instructions.hasbro.com` (page produit *avalon-hill-
> heroquest-rise-of-the-dread-moon-quest-pack*). Texte extrait page par page
> (`texte/F6646_en-us/p01.txt`…`p21.txt`) ; **chaque bloc de stats et chaque
> table relus sur le rendu PNG** (`rendu/F6646_en-us_p03/04/05/06/16/17/19.png`)
> — l'extraction texte désaligne les colonnes et, sur ce livret, mélange même
> l'ORDRE des deux pages imprimées d'un même PDF page (le label « Page N »
> apparaît parfois avant celui de la page qui le précède réellement). Les
> numéros ci-dessous sont les **pages imprimées**, confirmées sur le rendu
> pour toutes les pages citées plus d'une fois.

## 0. Ce que le livret contient, et ce qu'il ne contient pas

**Le livret porte** : les règles de la boîte (p. 2-11), dix quêtes (p. 14-33),
le tableau des monstres + leur bestiaire narratif (p. 36-37) et une page
« concevez votre propre quête » avec planche de symboles (p. 38-39).

**Il ne porte PAS les 58 cartes de jeu** (p. 3 : « 58 game cards ») : cartes
de monstre, mercenaires elfiques, les 5 artefacts nommés, les parchemins de
sort, les cartes du deck d'alchimie (potions, réactifs), la carte *Potion of
Unforeseeable Fate*. Tout ce qui n'est écrit que sur ces cartes reste **⚠ non
trouvé** tant que René ne les a pas photographiées — même limite que pour
l'armurerie (doc 16 §2.1bis). Le livret suffit cependant pour l'essentiel :
toutes les RÈGLES mécaniques (déguisement, jetons de réputation, planques,
artisanat de potions, monstres éthérés, Marché Souterrain) sont écrites en
toutes lettres dans le corps du livret, pas seulement sur les cartes.

**Contenu physique (p. 3)** : figurines (Chevalier/Dread Wraith, Gardes-mages
×2, Assassins, Cultistes du Dread, Spectres ×4, Statues ×2, Archers elfiques
×3, Guerriers elfiques), Rack (prison arcane), Armoire, Table, Table du
sorcier, porte d'entrée en fer, porte de sortie en bois, 2 pieds de miroir,
planche de tuiles cartonnées, 58 cartes.

## 1. Déjà en place

`dread_moon` est déjà un **thème actif** (`DemarreurQuete::BOITES_THEMATIQUES`,
`app/Partie/DemarreurQuete.php:524`), absent de `BOITES_INCOMPLETES` (vide
depuis 2026-09-06) : la boîte tourne déjà en vraie partie. Ce qui la fait
tourner, vérifié dans le code :

| Élément du livret | Chez nous |
|---|---|
| Tableau des 7 monstres, stats p. 36 (M/A/D/B/Mi) | `MonstreSeeder` (`database/seeders/MonstreSeeder.php:250-280`), boîte `dread_moon` : Cultiste du Dread 7/2/2/1/2, Spectre 8/3/3/1/0, Assassin 10/5/3/2/3, Garde-mage 8/4/4/3/3, Ombre du Dread (Dread Wraith) 9/6/\*3/5/5 |
| Archer elfe / Guerrier elfe (p. 36, « Elven Archer\*/Warrior ») | Déjà au catalogue sous `boite: mage_du_miroir` (`MonstreSeeder.php:276-280`), même figurine/texte que Dread Moon — **non rattachés au thème `dread_moon`**, voir §2.5 |
| « Elven Archer rolls four Attack dice […] one Attack die if adjacent » (p. 6, répété p. 36) | `portee: distance, attaque: 1, attaque_distance: 4` sur Archer elfe |
| *Large Monsters* (p. 6, Dread Wraith explicitement cité) | `grande_taille` (porté par Ogre Horde, générique) |
| **Monstres éthérés** (p. 6, répété p. 36) — traversée murs/figures, touché sur bouclier noir sauf sort/artefact, immunité aux pièges | **Entièrement porté**, capacité `ethere` : jet de touche (`ResolveurTour::frapper()`, `app/Partie/ResolveurTour.php:2798-2804`, exception artefact `rarete === 'unique'` incluse), déplacement traversant (`Grille::autoriserEthere()`, `ResolveurTour.php:8965`), règle « ne jamais finir sur une case occupée / non découverte » (`derniereCaseOuSArreter()`, `ResolveurTour.php:2809`). ⚠ **Bug trouvé, voir §2.2** : l'immunité aux chausse-trappes n'est PAS appliquée |
| Sorts de Dread connus par monstre (p. 6 « once per quest… at will » ; p. 36-37 détail par créature) | `config/archetypes_lanceurs.php:52-95` — `culte_effroi` (Feux de l'Effroi=*Dreadlights*, Canaliser l'Effroi=*Channel Dread*), `spectre_hurlant` (Canaliser l'Effroi, borné à 1/rencontre — divergence assumée du « at will », commentée en l'état), `garde_magus` (Boule de Flammes=*Ball of Flame*, Tourmente=*Tempest*), `spectre_effroi` (les 4 : Feux de l'Effroi, Canaliser l'Effroi, Frayeur=*Fear*, Invocation de spectres=*Summon Specters*) |
| Mappage carte→sort dans `config/cartes.php` | Déjà là pour les 6 sorts ci-dessus (`config/cartes.php:407-438`), testé dans les deux sens |
| **État de choc à 0 Mind** (p. 7 : « can only roll one red movement die, one Attack die, and two Defend dice […] cannot go below 0 Mind Points ») | Porté (`Personnage::estEnChoc()`, `app/Models/Personnage.php:218`), lu à 6 points de passage (`EtatGroupe`, `MenuMoteur`, `MoteurSorts`, `ResolveurTour` ×2). Sourcé jusqu'ici sur *Against the Ogre Horde* p. 9 (`ResolveurTour.php:8729`) ; Dread Moon p. 7 en donne une **seconde formulation indépendante, mot pour mot compatible** — à ajouter comme source en `reference/18` (§2.4) |
| **5 artefacts nommés** (p. 8) | Tous seedés et mappés : Serre du Corbeau=*Raven's Talon*, Cape des Ombres=*The Cloak of Shadows*, Écailles d'Elethorn=*The Scales of Elethorn*, Bouclier de l'Aube=*Dawnshield*, Cendres du Phénix=*Phoenix Ash* (`database/seeders/ObjetSeeder.php:442,473,476,580,628` ; `config/cartes.php:163-175`) |
| ***Caltrops*** (p. 5 : règle complète de la tuile ; p. 2-3 : carte d'objet, 100 po) | Déjà l'objet **Chausse-trappes** (`ObjetSeeder.php:195`, `effet: pose_chausse_trappes`, 100 po, `categorie: outil`), posée sans action, 1 dé de combat, bouclier blanc = continue sinon fin de mouvement, retirée en fin de tour dessus, déclenchée par héros ET monstres (`ResolveurTour::poserChausseTrappes()/tronquerSurChausseTrappes()/retirerChausseTrappeSous()`) — **c'est la carte Dread Moon, déjà jouable**, modulo le bug §2.2 |
| **Armoire** (*Cupboard*, p. 3) | Mobilier de base déjà seedé (`MobilierSeeder.php:138`), rien à faire |
| Table, Table du sorcier (hors effet de soin, voir §3 lot F), Rack | `Table` seedée ; `Table du sorcier` et `Rack (prison arcane)` absents — §3 lot F/J |

## 2. Corrections à `reference/18_extensions.md` (lot A)

`reference/18_extensions.md` §Rise of the Dread Moon (lignes 847-1027) est
globalement **fiable** — chaque bloc de stats relu (tableau monstres p. 36,
mercenaires p. 11, Sir Ragnar p. 31, Magrian p. 33) **correspond exactement**
au rendu PNG, sans erreur numérique trouvée. Les corrections sont des
**statuts**, pas des chiffres :

1. **Monstres éthérés** (§5, reference/18 ligne 949) est rédigé comme un
   coût à venir (« *Coût : changement profond du modèle de collision…* »).
   C'est **obsolète** : la mécanique est entièrement portée (§1 ci-dessus).
   À corriger en « ✅ porté, `capacite: ethere` » avec pointeur vers
   `ResolveurTour::frapper()`/`Grille::autoriserEthere()`.
2. **Bug trouvé en vérifiant le point ci-dessus** (pas une correction de
   `reference/18`, un défaut de code pour le lot B) : la carte dit « Ethereal
   monsters are unaffected by all traps, **including caltrops** placed by
   heroes » (p. 6, répété p. 36). `ResolveurTour::tronquerSurChausseTrappes()`
   est appelé sans condition pour tout déplacement de monstre
   (`ResolveurTour.php:9047`, juste avant l'appel à `derniereCaseOuSArreter()`
   qui, lui, connaît déjà `$this->dread->aCapacite($instance, 'ethere')`) :
   un Spectre ou l'Ombre du Dread qui traverse une case de chausse-trappes
   voit aujourd'hui son mouvement tronqué comme n'importe quel monstre.
3. **Garde-mage / « Magus Knight »** : le tableau des monstres (p. 36) nomme
   la créature **« Magus Knight »** dans l'intitulé de ligne, alors que
   partout ailleurs dans le même livret (texte des capacités p. 36, fiche
   narrative p. 37, règles générales p. 6) elle est appelée **« Magus
   Guard »**. C'est une incohérence **du livret lui-même**, pas de
   `reference/18` : notre doc et notre catalogue suivent déjà l'usage
   majoritaire (« Magus Guard » → « Garde-mage »), qui est le bon choix. À
   ajouter en note dans `reference/18`, pour qu'une relecture future ne
   « corrige » pas vers le nom minoritaire du tableau.
4. **État de choc** (reference/18 ne mentionne pas Dread Moon comme source) :
   ajouter la citation p. 7 comme seconde source de `Personnage::estEnChoc()`,
   à côté de celle d'*Against the Ogre Horde* p. 9 déjà notée ailleurs.
5. **Archer elfe / Guerrier elfe rattachés à `mage_du_miroir` seulement** :
   ce ne sont pas des stats fausses (elles sont identiques dans les deux
   boîtes), mais une boîte-source à une seule valeur ne peut pas dire
   « partagé entre deux thèmes ». Sous le thème `dread_moon`, ces deux
   créatures **n'apparaissent jamais** aujourd'hui alors que la carte p. 36
   les y inclut explicitement. Pas une erreur à corriger seule dans
   `reference/18` — une vraie question de modélisation, posée en §4 Q5.
6. **Dread Cultist/Specter/Magus Guard/Dread Wraith déjà répertoriés avec
   leurs sorts corrects** (§1) : `reference/18` ne dit pas qu'ils sont
   PORTÉS (juste que le livret les nomme) — ajouter la mention « ✅ porté »
   à côté de chacun, pour que la prochaine relecture ne les remette pas en
   chantier par erreur.

## 3. Les règles à porter, sourcées, par lots

### Lot B — Corriger le bug éthéré + chausse-trappes (petit, autonome)

| Élément | Règle (citation) | Chez nous |
|---|---|---|
| Immunité éthérée aux pièges | « Ethereal monsters are unaffected by all traps, including caltrops placed by heroes » (Dread Moon p. 6, répété p. 36) | `ResolveurTour::tronquerSurChausseTrappes()` doit court-circuiter (retourner le chemin intact) quand `$this->dread->aCapacite($instance, 'ethere')` — même garde que celle déjà posée dans `derniereCaseOuSArreter()`. Un seul point de passage à corriger (`ResolveurTour.php:9047`), pas deux |

Test : un Spectre qui traverse une case de chausse-trappes **ne voit pas**
son chemin tronqué ; un monstre normal, si.

### Lot C — Jetons de réputation (ressource de groupe)

| Élément | Règle (citation) | Chez nous |
|---|---|---|
| Jeton de réputation, gagné | « Zargon will award one reputation token at the end of each quest for the players to share » (p. 8) | Nouvelle colonne `groupes.jetons_reputation` (int, défaut 0) — même point de passage que `groupes.or` (`Groupe::$fillable`, `app/Models/Groupe.php:22`), jamais de cache (règle consolidée CLAUDE.md) |
| Vente contre or | « Reputation tokens can be sold between quests for 250 gold coins each, which must immediately be used to purchase something from the Underground Market. Any excess gold […] is lost » (p. 8) | Action de phase marché : convertit 1 jeton en 250 po **fléchés**, non reportables — comparable à une dépense forcée, pas un simple +250 po au solde |
| Dépense narrative | « Zargon must declare when the heroes have the opportunity to spend a reputation token […] does not have to reveal what the heroes are spending it on until they have spent it » (p. 8) | Hors moteur : c'est une **option de menu contextuelle**, générée par l'IA à des points que le gabarit de quête désigne (lot J) — jamais une dépense libre côté joueur, cohérent avec « le moteur n'offre que ce que `MenuMoteur` propose » |
| Paiement mercenaire par jeton | « Heroes may also spend one reputation token to hire an elven mercenary […] instead of paying their listed cost » (p. 8) | Dépend du lot D (mercenaires elfiques) |

Prérequis des lots D (paiement alternatif) et J (sources narratives de
jetons). À porter en premier parmi les lots neufs.

### Lot D — Mercenaires elfiques (p. 10-11)

**Ce ne sont PAS nos 5 mercenaires génériques.** `MercenaireSeeder` porte
Éclaireur/Arbalétrier/Fauchard/Estafier/Ogre mercenaire + 3 compagnons
animaux (cartes © 2023, `MercenaireSeeder.php:30-80`) — un catalogue
disjoint. Dread Moon ajoute 4 mercenaires **propres à la boîte**, à
déverrouillage progressif :

| Mercenaire | Coût/quête | M/A/D/B/Mi | Trait |
|---|---|---|---|
| Striker | 100 po | 5/4/5/2/2 | — |
| Glaive | 75 po | 6/3/3/2/2 | attaque diagonale (« wields a polearm ») |
| Arbalist | 75 po | 6/3/3/2/2 | arbalète à distance, épée large au contact (même patron que notre Arbalétrier) |
| Scout | 50 po | 9/2/3/2/2 | détecte/désamorce les pièges comme le Nain |

Table confirmée sur le rendu (`rendu/F6646_en-us_p06.png`), p. 11.

| Règle | Citation | Chez nous |
|---|---|---|
| Déverrouillage progressif | « these mercenaries become available for hire as the quests progress — the heroes must find them before they can be hired » (p. 10) | Nouvel état `groupe_mercenaires_debloques` (ou colonne liste sur `groupes`) activé par un **élément de quête** (lot J) ; AUCUN des 4 n'est achetable avant |
| Un seul à la fois | « More than one of the same type of mercenary cannot be hired for the same quest » (p. 11) | Déjà la règle d'un mercenaire par type au catalogue actuel — à réappliquer |
| Reconduction à moitié prix | « Heroes may retain that mercenary's aid in following quests by paying half the mercenary's cost (rounded down) per quest » (p. 11) | **Même disposition que le mercenaire ogre d'*Against the Ogre Horde*, lot E — ne pas réécrire deux fois la même règle** ; un seul lecteur pour « reconduction à moitié prix », paramétré par mercenaire |
| Mort → prix plein | « If a mercenary dies, they may be hired again for a future quest. Their gold coin cost resets to the full listed amount » (p. 11) | Idem — même point de passage |
| Paiement par jeton de réputation | « a hero may spend one reputation token to hire a mercenary instead of paying the listed cost […] remains with the hero until the mercenary dies or is dismissed […] no payment required » (p. 11) | Branche de paiement alternative sur le même menu d'embauche — dépend du lot C |
| Toujours déguisés | « Mercenaries are always disguised, regardless of what weapons and armor they use » (p. 11) | Drapeau lu par le lot E (déguisement) — un mercenaire ne perd jamais son déguisement, quel que soit son équipement |
| Ne fouillent pas (sauf Scout) | « Mercenaries cannot search for traps or treasure, with the exception of the Scout, who can find and disarm traps as a Dwarf can » (p. 11) | Capacité `fouille_pieges` conditionnelle, patron Nain, sur Scout seulement |

### Lot E — Déguisement (p. 9, composants p. 4)

| Règle | Citation | Chez nous |
|---|---|---|
| Prise du jeton | « A hero may go disguised by taking a disguise token (no action required) at the start of quests that allow it » (p. 9) | Nouvel état de personnage `deguise: bool`, posé par le GABARIT de quête (lot J), jamais à volonté — « at the start of quests **that allow it** » |
| Conditions de maintien | « Attack unarmed or with a dagger, shortsword, handaxe, or staff. Refrain from casting spells. Wear no armor other than bracers and helmets. Possess a disguise token » (p. 9) | Recalcul à **chaque** changement d'arme/armure et à chaque tentative de sort — même seam que la maîtrise d'équipement par classe (`Equipement::estAccessible()`, hard rule CLAUDE.md « une règle, un point de passage »), mais réévalué en cours de partie plutôt que figé à l'équipement de départ |
| Perte automatique | « A player with a disguise token must surrender it if they ever break the rules of use » | Effet automatique **annoncé** (hard rule) : journal + scène dès l'infraction, pas une perte silencieuse |
| Reddition volontaire | « At any time on their turn, they may surrender their disguise token and disguise (no action required) » | Option de menu, toujours disponible à qui est déguisé |
| Artefacts traités comme l'objet représenté | « Borin's Armor counts as plate mail and is prohibited, but a Wizard's Cloak is not armor, and may be used when a hero is disguised » (p. 9) | Lire le `tag_equipement`/`emplacement` de l'artefact, pas son nom — cohérent avec le reste de l'armurerie |
| Effets de jeu du déguisement | « Elven Warrior ignores disguised heroes and remains inactive unless an undisguised hero enters their line of sight or they are attacked » (quête 2, p. 17, répété quêtes 3/5) | Hors catalogue : condition d'activation de monstre liée à `deguise`, posée par le GABARIT de quête (lot J) — proche du `Plaza` (lot H), même famille « monstre inactif tant que... » |

Dépend de rien, mais est un prérequis narratif du lot J (quêtes qui
proposent le déguisement) et du trait « toujours déguisés » du lot D.

### Lot F — Planques (*Hideouts*, p. 5 composant / p. 7 règle complète)

| Règle | Citation | Chez nous |
|---|---|---|
| Fouille neutralisée | « Any Wandering Monster or Hazard cards drawn when searching the hideout do not take effect and are immediately returned to the bottom of the deck » (p. 7) | Nouvel état de salle `sanctuaire: bool` (mobilier **Planque**, pas une règle de salle ad hoc — même patron qu'un mobilier qui modifie la fouille) |
| Soin réparti, une fois par quête | « each hero may roll one red die and restore that number of points, divided among their Body Points and Mind Points […] The hero decides the distribution » (p. 7) | Action nouvelle, bornée 1×/quête/héros — répartition Body/Mind, pas un simple soin fixe ; **dépend des dégâts de Mind**, déjà producteurs (`MoteurDegats::infligerMindAHeros()`, porté 2026-09) |
| Établi d'alchimiste inclus | « Each hideout comes equipped with an Alchemist's Bench » (p. 7) | ⚠ **Faux ami** : notre mobilier `Établi d'alchimiste` (doc 17, `MobilierSeeder.php:99`) existe déjà mais ne fait QUE rendre une fiole de butin à la fouille — aucun lien avec l'artisanat de potions (lot G). La Planque doit poser SON PROPRE établi, support du lot G |
| Fouillable 1×/quête | « may be searched for treasure once per quest » (p. 7) | Mobilier `fouillable: true`, patron existant |

### Lot G — Artisanat de potions + Bombe fumigène (p. 2-3, 9)

| Élément | Citation | Chez nous |
|---|---|---|
| Transformer un réactif en potion | « the Wizard may transform a reagent into one of the potions listed on that reagent card […] adjacent to an Alchemist's Bench […] The Wizard does not need a Reagent Kit » (p. 9) | **Chaîne neuve** : objet `categorie: reactif` → action → objet `categorie: consommable` (potion), géo-ancrée sur l'Établi d'alchimiste de la Planque (lot F). Un réactif peut donner PLUSIEURS potions au choix (« some reagent cards can be made into different kinds of potions, or more than one potion », p. 9) — le choix revient au joueur, menu à options multiples |
| *Reagent Kit* (400 po) | « allows any hero adjacent to an Alchemist's Bench to craft potions […] After […] used 5 times, it is lost » (p. 2) | Objet `categorie: outil`, `charges: 5` — patron EXACT de `Charges`/`detruireSiEpuise()` déjà en service (Serre du Corbeau, etc.) |
| *Potion of Unforeseeable Fate* | « draw and activate one random card from the alchemy deck […] even at 0 Body Points » (p. 10) | ⚠ Dépend d'un **deck de potions aléatoire** — absent du moteur aujourd'hui (aucun tirage de potion aléatoire nulle part dans `app/Partie`). Nouveau mécanisme, à nommer dans le vocabulaire fermé avant de le seeder |
| *Smoke Bomb* (100 po) | « A thick cloud of colored smoke envelops any one monster adjacent to you. Until that monster's next turn, all heroes move unseen through the monster's space » (p. 2) | Nouvel effet d'objet, proche d'un sort de contrôle sans jet — condition de monstre `enfume` existe DÉJÀ (`MoteurSorts::MONSTRE_ENFUME`, cf. `ResolveurTour::monstreEnfumeSur()`) : **vérifier avant d'écrire quoi que ce soit** si le lecteur couvre déjà le franchissement de case, auquel cas il ne reste qu'à vendre la carte |

### Lot H — *Plaza* (p. 4, règle complète p. 5)

| Règle | Citation |
|---|---|
| Salle sans murs | « Any room a plaza is placed over completely will not count as having walls, and the plaza will effectively extend into the corridors » (p. 5) |
| Bordure partielle | « If it cuts into other rooms without covering them completely, the boundaries of the plaza will count as walls along its borders with rooms it does not fully cover » (p. 5) |
| Monstres inactifs | « Monsters in the plaza are inactive unless they are attacked or an undisguised hero (or active monster) enters their line of sight » (p. 5) |
| Non fouillable | « A plaza cannot be searched for treasure » (p. 5) |

Nouveau type de zone de carte : ni salle ni couloir au sens de
`Salles::indexDe()` (hard rule « un point de passage »), un **état de
visibilité/activation** plus proche du *Plaza*/*Living Fog Room* déjà nommé
comme dette dans `docs/plan-glace-et-degats-mind.md` (§1c, §3 Phase 4) que
d'une nouvelle topologie de salle. À lire avec la skill
`travailler-la-carte` avant d'écrire une ligne — c'est une mécanique de
ligne de vue/mur, le cœur de cette skill. Dépend du lot E pour la clause
« héros non déguisé ».

### Lot I — Monstres nommés à phases (p. 30-33) — **PAS un lot à part**

| Nom | Stats | Règle propre |
|---|---|---|
| **Sir Ragnar** (boss, quête 9, p. 31) | 5/5/5/4\*/4 | « The first time Sir Ragnar's Body Points are reduced to 0, they are instead reduced to 1 » |
| **Magrian, le Dread Wraith** (boss final, quête 10, p. 33) | 9/6/4/5/5, éthéré | Deux rencontres : la 1ʳᵉ une image-miroir qui vole en éclats, la 2ᵉ le combat réel avec 4 capacités à usage unique sans action : *Terror* (invulnérable aux sorts/attaques le reste du tour d'un héros ciblé), *Consume Magic* (annule un sort qui vient d'être lancé, regagne 2 PV), *Reflection* (redirige les dégâts qu'il vient de subir vers un héros en vue), *Shift Reality* (lance un sort Dread connu en début de tour MJ) |

**Aucune des deux mécaniques sous-jacentes n'existe encore dans le code** —
vérifié (`grep` sur `phases`, `survit_une_fois`, `increvable`, `ragnar` :
aucun résultat). C'est **exactement** le chantier du lot C d'*Against the
Ogre Horde* (monstre « increvable une fois » pour Gruzbella/Sir Ragnar,
capacités à usage unique sans action pour Gruzbella/Magrian — `docs/plan-
ogre-horde.md` §3 lot C). Ne pas écrire une seconde version : Dread Moon
**attend** ce chantier, il ne le redéfinit pas. Une fois le mot-clé
« increvable une fois » et le patron « capacité à usage unique sans action »
posés par Ogre Horde, Sir Ragnar et Magrian s'y branchent en seedage pur.

⚠ Magrian n'a **pas de boss générique nommé** pour l'instant : l'« Ombre du
Dread » (nos stats, divergentes en Défense — §1) EST le Dread Wraith
catalogue, et Magrian n'est qu'un nom d'habillage dessus tant que les 4
capacités ne sont pas portées. Après le lot C d'Ogre Horde, Magrian
redevient un sous-boss **nommé et mécaniquement distinct**, candidat naturel
pour enrichir l'arc `dread_moon` au-delà de son unique Garde-mage
(`plan-themes-bestiaire.md` : un thème a besoin de boss ET de sous-boss
distincts pour « colorer » un arc).

### Lot J — Ce que les 10 quêtes apportent au générateur

Comme pour *Against the Ogre Horde* (§3 lot G de son plan), les quêtes sont
des donjons imprimés que nous ne rejouons pas, mais leurs **beats** nourrissent
`GabaritQueteSeeder` — qui n'a aujourd'hui que **3 gabarits**
(`Exploration simple`, `Antre du sous-boss`, `Confrontation finale`,
`database/seeders/GabaritQueteSeeder.php:20,105,176`) :

- **Infiltration déguisée** (quêtes 2, 3, 5) : prise de jeton de déguisement
  au départ, monstres inactifs tant que non attaqués/vus par un héros non
  déguisé, perte du déguisement = combat. Matière du lot E ;
- **Extraction vers planque** (quête 2 : « The quest ends when all heroes
  enter the hideout ») : objectif de sortie = une salle-mobilier plutôt
  qu'une porte, premier cas d'un objectif posé sur du mobilier et non une
  case de sortie ;
- **Piste d'indices à portes verrouillées** (quête 3, p. 19) : « When the
  heroes accumulate three successes, the next X door they knock on will be
  the correct door. If all X doors but one have been knocked on, the last X
  door will automatically be the correct one » — mécanique de
  recherche probabiliste à compteur global de quête, nouveau type
  d'action hors combat/fouille/désamorçage. Même famille que la « Piste
  d'indices » proposée côté *Ogre Horde* (aucune, en fait — à inventer ici,
  première occurrence) ;
- **Objet de quête conditionnant un passage** (Lunar Charm, quêtes 6-10 : la
  Reine ne peut être vue qu'« avec les bénédictions de la lune » ; les
  Mirror Gates n'admettent qu'un porteur de lunarium) : même famille que
  l'amulette en forme d'étoile d'*Against the Ogre Horde* (déjà nommée dans
  son plan, lot G) — objet de quête non-équipement, condition de passage ;
- **Salle qui invoque en continu tant qu'une condition tient** (quête 6,
  note A : « At the start of Zargon's turn, if there are at least two Dread
  Cultists remaining, place two Specters in the room ») — à vérifier contre
  `MoteurDread::pondre()`/invocation existante avant d'écrire un nouveau
  lecteur (séam probable) ;
- **Protéger un PNJ jusqu'à la fin du combat** (quête 5, note C : marchand à
  2 Body/2 Defend, récompense si protégé) — PNJ à stats réduites, non
  contrôlable, candidat de gabarit « Escorte » ;
- **Porte à Points de Vie et dés de Défense** (quête 8, note F : « The door
  has 2 Body Points and rolls 3 Defend dice ») — une porte destructible
  comme cible, pas un mur ; à vérifier contre le mobilier destructible
  existant (`difficulte_destruction`) plutôt que la couche portes ;
- **Pit Trap Drain** (quête 1, p. 15) : « If a hero rolls doubles on the red
  dice for movement while in a waterway, they are […] dragged toward the
  nearest pit trap by one space per the number listed on one die » — classé
  (c) pour l'instant : résolution de mouvement déclenchée par le résultat du
  jet lui-même plutôt que par la case de destination, chantier isolé et
  propre à une seule quête-source, à ne prioriser qu'après les lots C-H.

## 4. Questions pour René

| # | Question | Recommandation |
|---|---|---|
| **Q1** | Archer elfe/Guerrier elfe sont `boite: mage_du_miroir` : sous le thème `dread_moon`, ils n'apparaissent jamais alors que le tableau p. 36 les y inclut. Dupliquer en entrées `dread_moon`, les passer en `boite: null` (génériques aux deux thèmes), ou laisser en l'état (la boîte reste identifiable par Cultiste/Spectre/Assassin/Garde-mage/Ombre du Dread seuls) ? | `boite: null` : ils sont LITTÉRALEMENT les mêmes figurines/cartes dans les deux boîtes, le cas `null` = « convient à tous les thèmes » est fait pour ça |
| **Q2** | Les 4 mercenaires elfiques (lot D) : même traitement que le mercenaire ogre (reconduction demi-prix) déjà **tranché** pour Ogre Horde (René, 2026-10-02, Q3). Confirmer que la même règle vaut ici, un seul lecteur pour les deux boîtes ? | Oui — c'est littéralement la même phrase de carte dans les deux livrets |
| **Q3** | Déguisement (lot E) : contrôle manette — un second état visible/actionnable par le joueur (comme Ogre Horde Q2 l'a posé pour l'allié animal) ? | Oui, c'est un état de PERSONNAGE (pas d'allié), donc un second menu léger est inévitable — pas de solution « gratuite » |
| **Q4** | *Potion of Unforeseeable Fate* (lot G) dépend d'un deck de potions aléatoire qui n'existe pas : construire un vrai tirage aléatoire (pondéré sur le catalogue de potions), ou une table fixe de repli en attendant les photos de cartes ? | Tirage aléatoire sur le sous-ensemble `categorie: consommable` déjà existant — pas besoin des cartes physiques pour un tirage, seulement pour les EFFETS de chaque potion individuelle qui n'existerait pas encore |
| **Q5** | Sir Ragnar et Magrian (lot I) sont bloqués sur le lot C d'*Against the Ogre Horde* (monstre increvable une fois, capacités à usage unique). Ce plan doit-il être exécuté **conjointement** pour les deux boîtes (un seul porteur de mécanique, deux jeux de données), ou Ogre Horde d'abord puis Dread Moon en seedage pur ensuite ? | Conjointement si les deux boîtes sont travaillées à la même fenêtre ; sinon Ogre Horde d'abord (son plan est déjà écrit et priorisé) |
| **Q6** | Jetons de réputation (lot C) : ressource de GROUPE comme l'or, ou par JOUEUR ? Le livret dit « for the players to share » (p. 8) | Groupe — le livret est explicite, et c'est la même discipline que `groupes.or` |

## 5. Sources à demander (photos des cartes)

Les **58 cartes** de la boîte, en priorité :
- les 4 cartes de mercenaire elfique (Striker/Glaive/Arbalist/Scout) — pour
  vérifier si des règles chiffrées additionnelles (hors chart p. 11)
  figurent sur les cartes ;
- les 5 cartes d'artefact (*Raven's Talon*, *Cloak of Shadows*, *Scales of
  Elethorn*, *Dawnshield*, *Phoenix Ash*) — pour confirmer/corriger les
  valeurs déjà seedées dans `ObjetSeeder` (portées avant ce plan, source non
  tracée dans le code actuel) ;
- les parchemins de sort (*Swift Wind*, *Fire of Wrath* mentionnés en
  quêtes) et les cartes du deck d'alchimie (potions ET réactifs, pour
  construire la table réactif→potions du lot G) ;
- la carte *Potion of Unforeseeable Fate* (table du tirage aléatoire) ;
- les cartes de monstre (pour une éventuelle capacité non écrite dans le
  livret, sur le modèle d'Ogre Horde Q5).

En attendant, le livret suffit pour TOUS les lots B à J : chaque règle
mécanique y est écrite en toutes lettres, contrairement à l'armurerie.

## 6. Ordre proposé

1. **Lot A** : corriger `reference/18` (statut éthéré, note Magus
   Guard/Knight, seconde source de l'état de choc, statut des sorts/artefacts
   déjà portés). Aucun code.
2. **Lot B** : corriger le bug éthéré × chausse-trappes — petit, isolé,
   corrige un défaut déjà en production.
3. **Lot C** (jetons de réputation) puis **lot D** (mercenaires elfiques,
   qui en dépend pour le paiement alternatif) puis **lot E** (déguisement,
   dont dépend le trait « toujours déguisé » du lot D et la clause plaza du
   lot H).
4. **Lot F** (planques) avant **lot G** (artisanat de potions, qui a besoin
   de l'établi propre à la planque).
5. **Lot H** (*Plaza*) — à la jonction carte/combat, skill
   `travailler-la-carte`.
6. **Lot I** : seedage pur une fois le lot C d'*Against the Ogre Horde*
   disponible (Q5) — ne jamais le précéder.
7. **Lot J** (gabarits) en continu dès que B-H donnent à l'IA de quoi
   peupler un objectif — pas un lot isolé, un chantier qui absorbe les
   précédents au fil de l'eau.

Chaque lot suit l'ordre maison : **vocabulaire fermé → lecteur → test EN JEU
→ données**, registres testés dans les deux sens, contrat d'API d'abord,
effets automatiques annoncés (la perte de déguisement, le changement de
phase de Magrian). Pest sur une **copie sqlite jetable** ; `sauvegarder.sh`
avant toute migration ; **aucune purge de `groupes`/`personnages`/`joueurs`,
aucune commande qui écrit sur la vraie base** ; redémarrer `queue` et
`queue-jeu` après le PHP. Une campagne d'agents (`campagne-agents`) sur le
thème `dread_moon` clôt les lots C à H.

> **Mise à jour 2026-10-05 (René)** : trois réactifs du paquet d'alchimie
> sont photographiés et transcrits (*Sacred Plant*, *Mysterious Flower*,
> *Unidentified Ingredient* — `reference/18_extensions.md` § Rise of the Dread
> Moon). Le reste du paquet (les potions d'alchimie) reste à photographier.
