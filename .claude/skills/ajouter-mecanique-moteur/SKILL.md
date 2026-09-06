---
name: ajouter-mecanique-moteur
description: >-
  Utiliser quand on AJOUTE ou MODIFIE une mécanique dans le moteur : un mot-clé
  d'effet, un talent ou une capacité de carte, une durée de buff, un regain de
  sort, une nature de dégât, une réaction hors tour, un effet d'épreuve. Impose
  l'ordre vocabulaire → lecteur → test EN JEU → donnée, et interdit la clé
  décorative. Déclencheurs : « nouvel effet », « nouveau mot-clé », « ajoute un
  talent/une capacité », « cette clé n'est lue nulle part », « le buff n'expire
  jamais », « MotsCles… », « effet.duree / effet.regain / effet.mecanique ».
---

# Ajouter une mécanique au moteur

**La faute que ce projet chasse partout** : une clé annoncée au joueur que rien
n'applique. Le catalogue en a porté des années (`degats_pv_body_par_tour` sur
*Empoisonné*, `perd_prochain_tour` sur *Étourdi*, `deplacement_interdit` sur
*Immobilisé*, `fin: liberation`) — chacune promettait une règle et ne faisait
**rien**, sans une seule erreur nulle part.

## L'ordre, et il n'est pas négociable

> **1. vocabulaire → 2. lecteur → 3. test EN JEU → 4. donnée**

Écrire la donnée d'abord, c'est livrer une règle muette. Écrire le lecteur sans
l'inscrire au vocabulaire, c'est le rendre introuvable au prochain passage.

## 1. Le vocabulaire fermé — lequel ?

| Vocabulaire | Pour |
|---|---|
| `App\Engine\MotsClesTalent` | talents de la grille **et** capacités de carte (`competences.innee`) |
| `App\Engine\MotsClesEquipement` | effets d'arme, d'armure, de potion, d'artefact |
| `App\Engine\MotsClesSort` | `cible`, `resistance`, booléens de sort de héros |
| `App\Engine\MotsClesSortDread` | sorts du MJ |
| `App\Engine\MotsClesEpreuve` | effets d'épreuve (`or`, `objet`, `soin_groupe`…) |
| `App\Engine\DureeEffet` | **quand un buff s'arrête** (7 mots-clés ; un entier = un compte à rebours en tours) |
| `App\Engine\RegainEffet` | sur quel ÉVÉNEMENT un sort redevient lançable |
| `App\Engine\TypeDegat` | nature du dégât (`feu`, `froid`) |
| `App\Engine\ReactionEffet` | actions hors tour |

⚠ Une durée, un regain et une fréquence sont **trois choses** : `effet.duree` dit
quand le **buff** s'arrête, `personnage_sorts.disponible` si le **sort** est
relançable, `effet.regain` sur quel événement il le redevient, `frequence` la
**cadence** (par quête) là où `charges` est un **total** qui ne se recharge jamais.

`MotsClesTalent` demande **trois** champs, tous obligatoires : `lecteur`
(classe::méthode), `libelle` (ce que le JOUEUR lit — le vocabulaire d'affichage
vit côté serveur, pas dans une table parallèle du front) et `icone`.

## 2. Le lecteur — sur une couture existante

Chercher la couture avant d'en créer une. Les principales :
`ResolveurTour::frapper()` (le **seul** cœur de frappe) · `MoteurDegats::infligerAHeros()`
(interception avant écriture) · `MoteurSorts::absorbeDegat()` / `expirerBuffs()` /
`valeurBuff()` · `ResolveurTour::resoudreAttaqueMonstre()` · `MoteurReactions` ·
`Talents::a()/valeur()/disponible()/consommer()` · `FabriqueGrille::pour()` (la
**seule** boucle de mobilier) · `Grille::autoriser*()`.

⚠ Un mot-clé déclaré `NON_IMPLEMENTE` (voir `MotsClesSort::NON_IMPLEMENTES`) est
une dette **nommée** ; un test interdit qu'une donnée l'utilise.

## 3. Le test — en jeu, pas dans le catalogue

Les tests de registre confrontent le vocabulaire à ses lecteurs **dans les deux
sens**, et vérifient que le fichier du lecteur **contient littéralement la clé** :
`GrilleTalentsTest` · `CapacitesInneesTest` · `EpreuvesCatalogueTest` ·
`SortsDreadSourcesTest`.
Puis la preuve **en jeu** : `TalentsEnJeuTest` · `TalentsRecablesTest` (rejoue
chaque lecteur sur une classe qui n'est pas son nom historique) ·
`ObjetsFonctionnelsTest` · `SortsFonctionnelsTest` · `DureesEffetsTest`.
**Corollaire assumé : une mécanique sans cas là-dedans ne se seede pas.**

## 4. La donnée
Seulement maintenant. → skill `ajouter-element-de-jeu`.

## Pièges déjà payés
- **Lire par MÉCANIQUE, jamais par NOM de nœud** — le moteur cherchait
  `'Garde tenace'`, `'Concentration'`… : quinze nœuds d'extension portaient la
  bonne `effet.mecanique` sous un autre nom et ne faisaient **rien**.
- **`Talents::noeud()` prend des CRITÈRES sur `effet`** : `bonus_des_attaque`
  nomme à la fois un bonus permanent et une Frénésie conditionnelle — les lire
  indistinctement applique l'un pour l'autre.
- **Compteurs** : « une fois par tour » et « une fois par quête » n'ont pas le
  même compteur (`capacites_tour` vs `capacites_utilisees`). En oublier un donne
  « une fois par campagne », ou aucune limite.
- **Jamais en cache** : un compteur durable est une **colonne**, et il doit
  monter dans le snapshot (`Sauvegarde`).
- **Annoncer l'effet** : journal + payload + écran. Un effet automatique que rien
  n'annonce est injouable.

## Lore
`docs/regles/vocabulaires-effets.md` · `talents-et-capacites.md`

## Definition of done
- [ ] Clé inscrite au bon vocabulaire (avec `lecteur`/`libelle`/`icone` si talent)
- [ ] Lecteur sur une couture existante, la clé nommée **dans le fichier du lecteur**
- [ ] Test de registre deux sens **et** test en jeu
- [ ] Compteur/durée câblé et porté par le snapshot si durable
- [ ] Effet visible (journal, payload, écran)
- [ ] Donnée seedée **en dernier**
