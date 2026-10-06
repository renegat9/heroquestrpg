# Plan — Définir entièrement l'extension *Return of the Witch Lord*

> ⚠ **Document DATÉ du 2026-10-04**, comme les autres `docs/plan-*.md` : un
> instantané d'audit et de découpage, pas tenu à jour. Les règles en vigueur
> iront dans `docs/regles/`, les sources dans `reference/18_extensions.md`
> §Return of the Witch Lord.
>
> **Source** : livret de quêtes officiel **F4193** (© 2021/22 Hasbro, 32
> pages imprimées / 17 pages PDF), téléchargé le 2026-10-04 depuis
> `instructions.hasbro.com` (page produit
> `avalon-hill-heroquest-return-of-the-witch-lord-quest-pack-for-ages-14-and-up`).
> Texte extrait page par page (`texte/F4193_en-us/pNN.txt`), et **chaque
> bloc de stats relu sur le rendu PNG** (`rendu/F4193_en-us_pNN.png`) —
> confirmé identique au texte extrait sur toutes les pages vérifiées (p. 11,
> 17, 21, 25, 27). Les numéros ci-dessous sont les **pages imprimées**.

## 0. Ce que le livret contient, et ce qu'il ne contient pas

**Le livret porte** : les règles de la boîte (p. 2, 6-7), dix quêtes (p.
8-27), la référence des artefacts (p. 29), et la planche de symboles (p.
31).

**Il ne porte PAS tout le contenu physique.** Comme Kellar's Keep, la page
4-5 annonce « **14 game cards** », mais la page Artifact Reference (p. 29)
n'en montre que **10** : 5 artefacts + 5 parchemins de sort. Les **4 cartes
manquantes** restent ⚠ **non trouvées** — même hypothèse que pour Kellar's
Keep (cartes de rappel de monstre rencontré), à vérifier sur les cartes
physiques.

**Contenu physique (p. 4-5)** : 16 figurines (8 squelettes, 4 momies, 4
zombies — **aucun type de monstre neuf**, seulement des variantes nommées
avec des stats relevées), 2 portes de donjon, une planche cartonnée (tuile
salle du trône, 4 tuiles cercueils, 6 cases bloquées, 2 fosses, 4 portes
secrètes, salle rotative, brume de la mort), 14 cartes de jeu.

**Aucune nouvelle classe de héros** (p. 4-5, confirmé) : 4 des 10 quêtes
(la 6, notamment) reposent même sur les 4 héros de base NOMMÉMENT (sorcier,
nain, barbare, elfe — voir §2.1 ci-dessous), ce qui est une divergence plus
profonde qu'« aucune classe neuve ».

## 1. Déjà en place — l'essentiel de la boutique et des artefacts

Même constat que pour Kellar's Keep (`docs/plan-kellars-keep.md` §1) : la
quasi-totalité du matériel de trésor de cette boîte est **déjà porté**,
sourcé depuis le paquet officiel `potions.pdf` / `sjeng-artefacts.pdf` (doc
16 §2.1bis) plutôt que depuis ce livret, qui sert de CONFIRMATION
secondaire.

| Élément du livret | Chez nous |
|---|---|
| **Boutique de l'Alchimiste** (p. 2) : identique à Kellar's Keep (4 mêmes potions, mêmes prix 500/300/100/200) | Portée — voir `docs/plan-kellars-keep.md` §1, même catalogue |
| **5 artefacts** (p. 7, 29) : *Magical Throwing Dagger*, *Dust of Disappearance*, *Anti-Poison Quill*, *Rabbit Boots*, *Arm Band of Healing* | `config/cartes.php` : les CINQ sont marquées portées → Dague de jet magique, Poudre d'Invisibilité, Plume anti-poison, Bottes de Lièvre, Bracelet de Guérison — **rien à faire** |
| **5 parchemins de sort** (p. 29) : *Heal Body*, *Pass through rock*, *Ball of flame*, *Courage*, *Fire of wrath* | Les 5 sorts existent au catalogue de héros officiel (doc 16 §3bis) avec `difficulte_parchemin` → parchemin dérivé automatique : **Soin du Corps**, **Traverser la Pierre** (nommée explicitement d'après CETTE carte dans le commentaire de `SortSeeder`, déjà sourcée), **Boule de Feu**, **Courage**, **Trait de Feu**. **Rien à faire** |
| ***Spirit Blade*** (mentionnée p. 13 comme objet du JEU DE BASE, pas une carte neuve de cette boîte) | `config/cartes.php` : Spirit Blade → Lame des Esprits, déjà portée |
| *Vial of holy water* (quête 2, note B, p. 11) — détruit un mort-vivant NORMAL au contact, usage unique, texte de quête seulement | ⚠ Non porté, absent du catalogue — objet à usage unique qui ne figure sur aucune carte artefact (le livret le dit lui-même : « Objet notable », pas une carte) |
| 0 Mind → choc plutôt que mort | S'applique déjà (`Personnage::estEnChoc()`) ; Bellthor (§3, lot B) l'utilise directement sans qu'il y ait de divergence à arbitrer ici — contrairement à Kellar's Keep, CETTE boîte (p. 17 : « they are not killed but knocked unconscious ») est COHÉRENTE avec notre règle actuelle, pas en tension avec elle |

## 2. Corrections à `reference/18_extensions.md` §Return of the Witch Lord

Moins d'erreurs factuelles que dans Kellar's Keep — les citations de page
existantes ont été recoupées une à une sur le rendu PNG et sont **toutes
exactes** (p. 11 Spirit Riders, p. 17 Bellthor, p. 21 Skulmar, p. 25
Doomguard/Kessandria, p. 27 Witch Lord/statues). Ce qui suit, ce sont des
TROUS, pas des corrections de chiffres.

1. **Trou important** : la quête 6 (« Halls of the Dead », note générale et
   notes A/B, p. 18-19) n'est pas un simple « split-party scripté »
   générique comme le §5 actuel le présente — elle est **câblée sur les 4
   héros de base PAR LEUR CLASSE** (« Tell the heroes that the wizard and
   dwarf are the only heroes taking turns […] until the elf and barbarian
   are found », « the barbarian and elf are chained up […] and the elf has
   their spells »). Avec notre roster ouvert (13 classes), une quête qui
   présuppose exactement un Sorcier + un Nain + un Barbare + un Elfe est
   **injouable telle quelle** dès qu'un groupe n'a pas ces quatre classes —
   un cran de divergence plus sévère que ce que « split-party scripté »
   laisse entendre. À documenter comme un blocage PROPRE à la boîte, pas
   seulement une mécanique « chère à construire ».
2. **Trou** : Bellthor (quête 5, p. 17) n'attaque qu'« after it has
   attacked » avec sa seconde capacité (le souffle empoisonné vient APRÈS
   l'attaque physique, une fois par tour) — nuance de séquençage absente du
   résumé actuel (« souffle empoisonné (6 dés de combat…) »).
3. **Trou** : les orques-statues (quête 10, note A, p. 27) ne sont pas
   seulement « immunisées » — elles **bloquent totalement le couloir**
   (« They completely block the corridors. They cannot be climbed over or
   passed »), ce qui en fait un obstacle de MOUVEMENT permanent en plus
   d'une immunité de dégâts ; le résumé actuel ne mentionne que l'immunité
   et la casse d'arme.
4. **Précision à ajouter** : Kessandria (quête 9, p. 25) a une potion de
   vitesse qui lui donne **12 cases de déplacement sur CE tour seulement**
   (« a potion of speed, which allows her to move 12 squares on the turn in
   which she drinks it »), pas un bonus permanent — déjà correctement
   résumé, mais vaut la peine de noter que ce chiffre (12 cases en un tour)
   est MOT POUR MOT celui de notre sort de Dread **Patinage** (`cases =>
   12`, `SortDreadSeeder`, palier boss), déjà porté pour un autre thème —
   voir lot C.
5. **Aucune erreur trouvée** sur les blocs de stats, les 5 artefacts, le
   Death Mist, ou les 4 statues — le document est fiable sur ces points.

## 3. Les règles à porter, sourcées — par lots

### Lot A — Corrections de §2 ci-dessus

Aucun code. `reference/18_extensions.md`.

### Lot B — Variantes nommées, stats simples (aucune mécanique neuve)

| Élément | Stats (M/A/D/B/Mi) | Chez nous |
|---|---|---|
| **Spirit Riders** (quête 2, note E, p. 11) | 8/4/4/3/3 | Nouveau monstre **Cavalier-esprit** (variante de Squelette), tier `sous_boss`, boîte `witch_lord` — confirmé sur rendu PNG, identique au texte |
| **Doomguard** (quêtes 9-10, p. 25) | 8/4/6/3/3 | Nouveau monstre **Garde du Dread** (variante de Guerrier du Chaos/Dread warrior), tier `sous_boss`, boîte `witch_lord` |
| **Skulmar** (quête 7, p. 21) | 8/5/6/3/4 | Nouveau monstre, tier `sous_boss` (ou `boss` selon Q2), boîte `witch_lord` ; voir lot C pour sa fuite |
| **Kessandria** (quête 9, p. 25) | 6/4/6/3/4 | Nouveau monstre, tier `boss`, boîte `witch_lord` ; lanceuse — voir lot C |
| **Le Seigneur Sorcier / Witch Lord** (quête 10, p. 27) | 10/5/6/4/5 | Nouveau monstre, tier `boss`, boîte `witch_lord` ; lanceur + immunité sélective — voir lot C |

Toutes ces stats sont simples à seeder : AUCUNE n'attend un mot-clé ou une
mécanique neuve pour exister en tant que bloc de combat ordinaire. Les
lignes qui suivent (lots C-D) ne portent que ce qui dépasse « un monstre
avec ses chiffres ».

### Lot C — Lanceurs de Dread et fuite au combat : tout est déjà au catalogue

Les répertoires de sort des trois lanceurs nommés de cette boîte sont
**ENTIÈREMENT couverts** par le catalogue `SortDreadSeeder` existant —
aucun des 29 cartes de Dread officielles qu'ils emploient ne manque.

| Lanceur | Répertoire cité (texte) | Correspondance `SortDreadSeeder` |
|---|---|---|
| **Gragor** n'est PAS de cette boîte — ignorer, c'est Kellar's Keep | — | — |
| **Kessandria** (p. 25) | « lightning bolt, tempest, fear, sleep, and cloud of Dread » | **Éclair de Chaos**, **Tourmente**, **Frayeur**, **Sommeil**, **Nuée d'Effroi** (= *Cloud of Dread*, carte officielle #12, confirmée par `reference/09_bestiaire.md` §12 — zone salle/couloir, condition Paralysé, rupture 6 par Mind) — **les cinq existent** |
| **Le Seigneur Sorcier** (p. 27) | « summon undead, firestorm, tempest, lightning bolt, fear, and command » | **Invocation de morts-vivants**, **Tempête de feu**, **Tourmente**, **Éclair de Chaos**, **Frayeur**, **Commandement** — **les six existent** |

Nouvelle entrée `archetype_lanceur` (ou liste directe `sorts_dread`, les
deux patrons existent déjà dans `MonstreSeeder` — direct pour un porteur
unique, comme la ligne 170/411 actuelle) par lanceur nommé, SANS écrire un
seul nouveau sort.

| Élément | Règle citée | Chez nous |
|---|---|---|
| **Fuite au combat, Skulmar et Kessandria** (p. 21, 25) | « Skulmar should try to escape. To do this, he must reach the spiral stairway, at which point he is removed from the gameboard » ; « If Kessandria's Body Points are greatly reduced, she should attempt to escape […] by trying to reach the spiral stairway. […] If she succeeds in escaping, remove her from the gameboard. » | Correspond EXACTEMENT au sort de Dread **Fuite** (= *Escape*, carte officielle base 2021) déjà porté (`SortDreadSeeder`, palier boss, type `TYPE_FUITE`), dont le commentaire dit déjà : « Nos donjons sont procéduraux et ne portent aucun refuge marqué : la destination est la case libre la plus ÉLOIGNÉE des héros. Même intention, seule lecture possible ici. » C'est MOT POUR MOT le problème que ce lot pose (« reach the spiral stairway » n'a pas de sens sur une carte procédurale). Nouvelle capacité `fuit_si_affaibli` (seuil de Body, non sourcé par le livret — « greatly reduced » n'est jamais chiffré) qui déclenche AUTOMATIQUEMENT le sort Fuite existant, sans action du joueur de MJ. Seuil à trancher (Q3) |
| **Le Witch Lord ne fuit JAMAIS** (p. 27 : « Now he has fled to his throne room. There you must do battle with him. This time there must be no escape for him. ») | Contraste volontaire avec Kessandria/Skulmar | Ne PAS poser `fuit_si_affaibli` sur le Witch Lord — confirmer explicitement dans le seeder pour que l'absence soit un choix écrit, pas un oubli |
| **Potion de vitesse de Kessandria** (p. 25) | 12 cases sur un tour, une fois | Deux options, aucune tranchée : (a) ajouter **Patinage** (`cases: 12`) à son répertoire de Dread — réutilise 100 % d'un lecteur existant, mais c'est un sort de PATINAGE sur glace pour une reine morte-vivante, thématiquement étrange ; (b) modéliser comme une capacité innée « boisson/objet à usage unique » hors du système de sorts, plus fidèle au texte (« drinks it ») mais sans lecteur existant. Voir Q4 |

### Lot D — Immunités sélectives (mécanique neuve, 3 usages dans cette seule boîte)

Aucune forme de résistance sélective n'existe aujourd'hui au-delà de
`resistance_magique` (+2 dés de défense contre un sort de héros,
`MoteurDread::BONUS_RESISTANCE_MAGIQUE`) — PAS une immunité. Cette boîte en
réclame TROIS formes différentes sur sa seule dernière quête, toutes à
construire ensemble.

| Élément | Règle citée | Chez nous |
|---|---|---|
| **Immunité partielle aux sorts, sauf une famille** (Kessandria, p. 25) | « She is immune to all spells except fire spells. » | Mot-clé `immunite_sorts_sauf` (liste de `type_degat` exemptés — ici `['feu']`), lu au point où un sort de héros choisit ses cibles légales, `MoteurSorts::ciblesLegales()` — même seam que `immunite_sorts` (voir `docs/plan-kellars-keep.md` lot C, Gargouille gardienne, immunité TOTALE). Les deux mots-clés sont les deux bornes d'une même échelle — à concevoir ensemble |
| **Liste blanche de sources de dégât** (Witch Lord, p. 27) | « The Witch Lord can only be harmed by four things at this time: the Spirit Blade, the fire of wrath spell, the ball of flame spell, and the Magical Throwing Dagger. » | Mot-clé NOUVEAU `vulnerable_uniquement_a` (liste d'identifiants d'objet/sort), lu au point de passage **unique** nommé par les règles dures pour toute frappe : `ResolveurTour::frapper()`. Si l'arme/le sort employé n'est pas dans la liste, le coup inflige **0 dégât** malgré un jet par ailleurs normal (pas une défense suppémentaire, un filtre en amont de la résolution) |
| **Invulnérabilité totale + casse l'arme de l'attaquant** (4 statues d'orcs, p. 27) | « If a hero attacks any of these statues, their weapon breaks (even a Magical Throwing Dagger or crossbow breaks) […]. The only exception […] is the Spirit Blade, which […] does not break. However, it still does not harm the statue. » | Symétrique du point précédent : une liste blanche d'armes EXEMPTÉES DE CASSE (ici, seule la Lame des Esprits), et un effet latéral inédit — une arme qui se BRISE en combat n'existe nulle part dans le moteur actuel (`equipement-et-armurerie.md` ne porte aucune notion de durabilité). Recommandation : modéliser les statues comme un MONSTRE à `deplacement: 0`, `attaque: 0`, défense pratiquement infinie ou un drapeau `indestructible`, avec une réaction `casse_arme_attaquant` au lieu de la résolution normale. Elles BLOQUENT aussi le couloir totalement (§2.3) — si ported, suivre le PRÉCÉDENT de la porte de pierre d'Ogre Horde : jamais sur le seul chemin vers l'objectif (`AssembleurCarte::marquerPortesDePierre()`), pour ne pas re-créer un « connected is not playable » |

Voir Q5.

### Lot E — Mobilier et carte (prudence : plusieurs ruptures de modèle)

| Élément | Règle citée | Chez nous |
|---|---|---|
| **Cercueils** (p. 4-5) | « may contain an undead creature and/or a treasure. They may also have traps on them. » | Le mobilier `Tombeau` existe déjà (coffre funéraire : or, objet, piège) mais ne peut PAS faire apparaître un monstre à la fouille — seul ajout réel : une nouvelle valeur `issue: 'monstre'` dans la table de fouille (`MobilierSeeder`/`MoteurMobilier`, même format que `issue: 'piege'` déjà en place), nommant quel monstre apparaît. Scope réduit, cohérent avec l'existant |
| **Death Mist** (quête 3, p. 13) | « moves up to 6 squares on each of Zargon's turns […]. Inflicts 1 Body Point […]. Not affected by normal weapons. Can only be destroyed by a tempest spell or by the Spirit Blade. » | ⚠ **Même famille que le rocher roulant de Kellar's Keep** (`docs/plan-kellars-keep.md` lot E) : entité de terrain mobile autonome sur plusieurs tours, AUCUNE des deux n'a de lecteur aujourd'hui. À concevoir UNE SEULE FOIS pour les deux boîtes (position + vitesse + dégât + liste blanche de ce qui peut la détruire — réutilise `vulnerable_uniquement_a` du lot D). Voir Q6 dans `docs/plan-kellars-keep.md` (Q3 là-bas) |
| **Salle rotative** (quête 2, note A, p. 11) | « When a hero attempts to leave this room, they must first roll 1 red die to see which door they use to exit. » (table 1-2→porte1, 3→porte2, 4-5→porte3, 6→porte4) | Rupture du modèle « le joueur choisit sa sortie et l'obtient » : ici, le choix du joueur est **recouvert** par un jet serveur après coup. Respecte quand même la règle dure « le serveur publie la décision » (le résultat du jet doit être renvoyé déjà tranché, jamais calculé côté client) mais demande un nouveau type de salle multi-portes dont la sortie réelle diverge du choix. Scope significatif pour un gain de fidélité faible sur cartes procédurales — Q7 |
| **Salle du trône** (p. 4-5, 27) | Grande salle pour la confrontation finale | Aucune règle propre au-delà du gabarit ; notre génération de salle de boss/objectif avec plancher de cases jouables (`docs/regles/carte-donjon.md` §2.12 ter) suffit déjà. Le mobilier **Trône** existe (porté par Against the Ogre Horde) — rien à ajouter |
| **Tas d'ossements** (*Bone Pile*, p. 4-5) | Pur décor, aucune règle | Rien à faire |
| **Porte d'entrée en fer / porte de sortie en bois** (p. 4-5) | Identique à Kellar's Keep | Narratif seul — voir `docs/plan-kellars-keep.md` §3 lot G, même constat : aucune mécanique de porte de bord de plateau n'existe dans le projet |

### Lot F — Ce que les quêtes apportent au générateur, et ce qu'elles n'apportent pas

- **Quête 5 « The Gate of Bellthor »** (p. 17) : fin de quête SANS sortie —
  tuer Bellthor fait exploser une brume qui assomme tout le groupe, « there
  is no successful way to exit from this adventure […] Turn to the next
  quest. » C'est un TROISIÈME état de fin de quête, en plus de `terminee`
  et `echouee` (vérifié : `ResolveurTour.php` ne connaît que ces deux
  états — une capture qui enchaîne directement sur la quête suivante SANS
  repasser par le hub n'existe nulle part). Gros morceau, hors scope sans
  décision explicite de René (Q8).
- **Quête 6 « Halls of the Dead »** (p. 18-19) : split-party avec
  confiscation/restitution d'inventaire scriptée — bloqué par §2.1 (câblé
  sur 4 classes précises), en plus d'être un gros chantier d'état par
  personnage (`actif` différé). Écarté comme matériau de gabarit — à noter
  comme choix ÉCRIT plutôt qu'un oubli silencieux.
- **Bellthor « ne bouge/n'attaque pas tant que tous les héros ne sont pas
  dans la salle »** (quête 5, note B) : encore une variante de
  `passif_jusqua_attaque` (voir `docs/plan-kellars-keep.md` lot C) — sauf
  que SON déclencheur est différent (présence du groupe complet dans la
  salle, pas « avoir été attaqué »). Si la mécanique du lot C de Kellar's
  Keep est généralisée à plusieurs déclencheurs (`passif_jusqua: 'attaque'
  | 'groupe_complet'`), Bellthor s'y ajoute sans nouveau mot-clé — sinon
  c'est un troisième mot-clé séparé. Petite décision de conception, pas une
  nouvelle mécanique.
- **Trésors-valeurs** (240-500 po par coffre selon les quêtes, clés d'or de
  200 po) : de l'or sous un autre nom, déjà couvert par la fouille.

## 4. Questions pour René

| # | Question | Recommandation |
|---|---|---|
| **Q1** | Quête 6 câblée sur Sorcier/Nain/Barbare/Elfe par leur CLASSE : écarter cette quête du matériau exploitable (elle ne généralise à aucun groupe), ou l'adapter en « 2 héros au choix séparés des 2 autres » générique ? | Écarter — l'adaptation perdrait tout ce qui fait le sel du texte (chaînes de dialogue liées aux classes précises) sans rien ajouter de mécanique neuf |
| **Q2** | Skulmar : sous-boss ou boss ? Son texte ne porte aucune capacité propre au-delà de la fuite, contrairement à Kessandria/Witch Lord qui lancent des sorts | Sous-boss — cohérent avec le patron « lanceur = boss, brute = sous-boss » déjà observé sur Doralf (Against the Ogre Horde) |
| **Q3** | Seuil de Body déclenchant `fuit_si_affaibli` (« greatly reduced », non chiffré par le livret) | Décision de portage pure, proposer 1/3 des Body de départ (cohérent avec d'autres seuils « gravement affaibli » du projet), à confirmer |
| **Q4** | Potion de vitesse de Kessandria : réutiliser le sort Patinage existant (`cases: 12`), ou une capacité innée hors sorts ? | Réutiliser Patinage — zéro nouveau code, le décalage thématique (patinage vs potion) est invisible au joueur puisque l'IA habille le libellé |
| **Q5** | Immunités sélectives (lot D) : construire les trois formes (immunité totale, immunité partielle par type de dégât, liste blanche de sources) comme UNE échelle de mot-clés, ou une seule boîte s'arrête-t-elle à ce qu'elle utilise réellement (ici : Kessandria + Witch Lord + statues) sans construire la forme totale que SEULE Kellar's Keep emploie (Gargouille gardienne) ? | Construire ensemble — les deux plans se recoupent sur la même quête-type (boss final protégé), autant livrer l'échelle complète une fois |
| **Q6** | Voir Q3 de `docs/plan-kellars-keep.md` (danger mobile autonome partagé rocher/Death Mist) | Une seule mécanique |
| **Q7** | Salle rotative : construire (gros scope, fidélité ponctuelle) ou écarter par une phrase écrite ? | Écarter pour l'instant — c'est la pièce la plus chère du lot E pour l'usage le plus rare (une seule quête du livret) |
| **Q8** | Fin de quête « capturé, enchaîne sans hub » (Bellthor) : un troisième état de fin de quête, ou rabattre sur `echouee` (le groupe perd, retour au hub, recommence) en perdant la continuité narrative scriptée ? | Rabattre sur `echouee` — un vrai troisième état de quête est un chantier d'ampleur (`ResolveurTour`, le modèle de reprise, les deux tests qui ferment aujourd'hui `terminee`/`echouee`) pour un usage qui n'existe que dans une quête imprimée qu'on ne rejoue pas telle quelle |

## 5. Sources à demander (photos des cartes)

Les **14 cartes** de la boîte, en priorité :
- les 4 cartes non comptées dans les 10 de la page Artifact Reference —
  même hypothèse que pour Kellar's Keep (cartes de rappel de monstre) ;
- toute carte de monstre nommé (Skulmar, Kessandria, le Seigneur Sorcier,
  Bellthor) si elle ajoute une information que le texte de quête ne donne
  pas (un symbole de Dread particulier, par exemple) — le texte de quête
  semble complet pour ces quatre, donc priorité plus basse que pour
  Kellar's Keep.

En attendant, le livret suffit à TOUS les lots : contrairement à Kellar's
Keep (Abomination non chiffrée), cette boîte ne porte aucune créature dont
les stats manquent — c'est, de ce point de vue, la boîte la plus
« complète » rencontrée jusqu'ici.

## 6. Ordre proposé

1. **Lot A** : corriger/compléter `reference/18` (quête 6, séquençage
   Bellthor, blocage des statues, note Patinage/potion). Aucun code.
2. **Lot B** : les cinq variantes nommées — stats pures, aucune dépendance.
3. **Lot C** : lanceurs + fuite — zéro nouveau sort de Dread, juste des
   répertoires et une capacité `fuit_si_affaibli` qui réarme le sort Fuite
   existant.
4. **Lot D** : l'échelle d'immunités sélectives — à séquencer AVEC
   `docs/plan-kellars-keep.md` (Gargouille gardienne) selon Q5, avant de
   livrer le Witch Lord/Kessandria/statues.
5. **Lot E** : cercueils (petit, autonome) en premier ; Death Mist après le
   rocher roulant de Kellar's Keep (mécanique partagée, Q6) ; salle rotative
   seulement si Q7 est tranchée en sa faveur.
6. **Lot F** : pas de code sans décision — Q1 et Q8 doivent être tranchées
   avant d'écrire quoi que ce soit ici ; Bellthor passif rejoint le lot C de
   Kellar's Keep selon la décision de généralisation.

Chaque lot suit l'ordre maison : **vocabulaire fermé → lecteur → test EN JEU
→ données**, registres testés dans les deux sens, contrat d'API d'abord,
effets automatiques annoncés. Pest sur une **copie sqlite jetable** ;
`sauvegarder.sh` avant toute migration ; aucune purge de
`groupes`/`personnages`/`joueurs` ; redémarrer `queue` et `queue-jeu` après
le PHP.

## 7. Cette boîte peut-elle devenir un THÈME de bestiaire ?

Pas sans un arbitrage distinct de celui des quatre thèmes actifs
(`docs/plan-themes-bestiaire.md`). Rappel des règles arrêtées : un thème
ajoute des créatures SIGNATURE sur la rencontre finale et les « forts »,
sans filtrer la masse commune. Or cette boîte n'a **aucune créature de tier
`base`** — ses cinq apports sont tous `sous_boss`/`boss` (Cavalier-esprit,
Garde du Dread, Skulmar, Kessandria, le Seigneur Sorcier). Ce n'est PAS
disqualifiant : la masse commune (squelette/zombie/momie de base) resterait
simplement celle du jeu de base, exactement le rôle que `boite = null`
joue déjà pour nos propres créations (Troll, Champion, Liche…). Ce qui
MANQUE réellement avant d'activer un thème `witch_lord` :
- le lot D (immunités sélectives) doit être posé — sinon le boss final
  perd sa règle la plus caractéristique (liste blanche de 4 sources) et
  devient un simple sac de PV ;
- une décision de PALIER (quel sous-boss accompagne quelle rencontre,
  quel boss ferme la campagne) — absente de ce plan, à construire comme
  Q7 d'Against the Ogre Horde l'a fait pour sa boîte ;
- aucun archétype de lanceur `base`/`sous_boss` n'existe dans cette boîte
  (Kessandria et le Witch Lord sont tous deux `boss`) — un thème qui
  n'offre de la magie qu'au niveau le plus haut est jouable, mais plat
  pendant toute une campagne avant la confrontation finale.

Conclusion : **viable comme thème UNIQUEMENT après le lot D**, avec son
propre tour de paliers (sur le modèle Q7 d'Ogre Horde) — pas un simple
ajout de `BOITES_THEMATIQUES`, une décision à part entière à documenter si
et quand René la demande.

> **Mise à jour 2026-10-05 — vérification des annexes du livret** : les
> 4 premières et 4 dernières pages du PDF relues à l'image, ainsi que la
> page « Artifact Reference » (p. 29, déjà bien citée). Rien à corriger :
> les 5 artefacts et 5 parchemins de sort y sont bien paraphrasés avec la
> bonne page (confirmé §1 : « aucune erreur trouvée… sur les 5 artefacts »).
> Rien à retirer de la liste de photos (§5).
