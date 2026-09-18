# Plan — Échanger avec un allié adjacent, et jeter un objet du sac

> ⚠ **Document DATÉ du 2026-09-17.** Instantané de décision, comme les autres
> `docs/plan-*.md` : il n'est pas tenu à jour. Le contrat, lui, est **déjà
> écrit** dans `docs/contrat-api.md` §« Gérer son inventaire EN QUÊTE » — c'est
> lui qui fait foi, ce fichier dit le **pourquoi** et le découpage.

---

## 1. D'où vient le besoin

Doc 01 §7 nomme **trois** gestes d'inventaire, tous au prix de l'action du tour :
équiper/ranger, **jeter**, **échanger avec un joueur adjacent**. Un seul existe.
Le §7 le dit lui-même depuis le 2026-07-30 : « L'échange **en pleine quête**
(adjacence + coût d'action) reste à faire, comme "équiper" en quête et "jeter un
objet" » — et « équiper » a été fait depuis, laissant les deux autres seuls.

Ce n'est pas une lacune cosmétique. Le §Sac à dos pose une **tension de gestion**
qui n'a aujourd'hui aucune issue en quête : « au ramassage d'un trésor, sac plein
→ il faut jeter un objet pour le prendre ». Rien ne permet de jeter. Et la potion
trouvée par le barbare ne peut pas rejoindre le magicien avant le hub, alors que
les deux sont côte à côte dans le couloir.

---

## 2. La couture existe déjà, entièrement — et c'est l'essentiel du plan

⚠ **Ne rien inventer ici.** Les deux gestes se posent sur trois mécanismes
éprouvés, et le travail consiste à les BRANCHER, pas à les réécrire.

**a. `App\Partie\DonObjet` fait déjà l'échange.** Hub-only aujourd'hui, mais la
règle entière y est : pile de consommables fusionnée, **ligne déplacée** et non
recréée (sans quoi les `ameliorations` de Forge disparaîtraient en silence —
le héros recevrait une épée ordinaire au lieu de l'épée Affûtée), refus d'une
pièce équipée, capacité du receveur vérifiée. `resoudreEchange()` doit
l'**appeler**, exactement comme `resoudreEquipement()` appelle `Equipement`.
⚠ **Interdit de dupliquer une seule de ces règles** : c'est le défaut le plus
répété du projet.

**b. Le menu à trois niveaux est générique.** `LISTES` dans `ManetteView.vue` est
indexé par `option.id` ; une option qui porte `parametres.<cle>[]` ouvre
`ChoixListeSheet`, et une entrée qui porte `cibles` empile `CibleSheet` toute
seule (« la profondeur suit la donnée »). Le front se réduit donc à **deux
entrées dans une map**.

**c. Le créneau est déjà le bon.** `ResolveurTour::creneauOption()` retombe sur
`'action'` par défaut, et le miroir front `ActionTab.creneauConsomme()` grise sur
`a_agi` par `default`. ⚠ **Ne toucher ni l'un ni l'autre** : les deux nouveaux
types tombent juste, et ce miroir porte déjà trois cicatrices.

---

## 3. Les deux arbitrages

### 3.1 `cibles` est PAR ENTRÉE, et ce n'est pas du mimétisme

Le patron des sorts met `cibles` par entrée parce qu'un sort de soin et un sort
de dégâts ne visent pas les mêmes figures. Ici la raison est **différente et plus
forte** : le receveur légal dépend de l'objet. Un consommable entre dans
n'importe quel sac (`RangementObjet::peutRanger()` rend `true` d'emblée pour
`emplacement === 'consommable'`), une armure n'entre que dans un sac qui a la
place. Une liste d'alliés commune à toutes les entrées **offrirait un allié que
le résolveur refuserait** — la règle « le menu ne propose jamais ce que le
résolveur refusera », en une ligne.

### 3.2 Jeter DÉTRUIT — arbitrage à relire avant d'y toucher

Il n'existe **aucune couche d'objets posés au sol**. En créer une pour ce geste
serait une mécanique entière (rendu table, rendu manette, ramassage, snapshot,
brouillard), pas un effet — et le projet a un précédent net dans l'autre sens :
l'arme lancée est **supprimée** (`consommerArmeLancee()`), « elle reste où elle
tombe » n'étant qu'une phrase de fiction.

Donc : **destruction**, libellé explicite (« Jeter — définitif »), confirmation
côté manette. ⚠ C'est le seul geste du jeu qui détruit de la valeur sans rien
rendre : la confirmation n'est pas une politesse, c'est le garde-fou.

⚠ **Si René préfère le sol**, c'est un autre chantier et il faut le dire comme
tel — pas l'ajouter en douce à celui-ci.

---

## 4. Découpe — deux agents, périmètres disjoints

⚠ **AUCUN fichier partagé entre les deux lots.** Le contrat
(`docs/contrat-api.md`) est déjà écrit et gelé : il est la seule interface.

### Lot A — moteur (backend)

`app/Partie/MenuMoteur.php` · `app/Partie/ResolveurTour.php` ·
`app/Partie/JournalCombat.php` · `tests/`

1. `MenuMoteur` — émettre `echanger` et `jeter` dans le bloc **créneau ACTION**,
   à côté du bloc équipement existant (vers la ligne 1466). Les entrées se
   construisent sur `$lignesInventaire`, déjà chargé là.
   - `jeter` : les lignes du **sac** (jamais `Equipement::SLOTS`). Pas d'option
     si le sac est vide.
   - `echanger` : mêmes lignes, chacune avec ses `cibles` = héros à distance de
     Manhattan **1**, `tombe` faux, passant `RangementObjet::peutRanger()` pour
     **cette** pièce. Une entrée sans cible est **omise** ; pas d'option si
     toutes le sont. Le patron d'adjacence est celui de `relever`
     (`MenuMoteur` ~1420).
2. `ResolveurTour` — deux branches dans le `match` (~ligne 386) et deux
   résolveurs. `resoudreEchange()` **appelle `DonObjet::donner()`** ; il
   re-valide l'appartenance de `cle` à la liste publiée **et** de `cible_id` aux
   `cibles` de cette entrée (422 sinon). `resoudreJeter()` décrémente la pile ou
   supprime la ligne.
3. `JournalCombat::ligneType()` — une ligne pour `echanger` et `jeter`, **et
   aussi pour `equiper`/`desequiper`**, muets aujourd'hui (ils tombent sur
   `default => []`). Même règle : un effet que rien n'annonce est injouable.
4. Tests Pest : option absente sans allié adjacent · absente sac vide · entrée
   sans cible omise quand le sac du voisin est plein · 422 sur une `cle` hors
   liste · 422 sur une cible hors `cibles` · pièce équipée jamais offerte ·
   créneau d'action consommé · **`ameliorations` de Forge préservées** à
   l'échange (le piège que `DonObjet` existe pour éviter).

### Lot B — manette (frontend)

`resources/js/views/ManetteView.vue` · `resources/js/components/manette/` ·
build

1. Deux entrées dans `LISTES` (~ligne 540) : `echanger` et `jeter`, `cle:
   'objets'`, titres « Quel objet donner ? » / « Quel objet jeter ? ».
2. Confirmation avant l'envoi de `jeter` (destruction définitive) — au plus près
   du patron de confirmation du **tir ami**, qui existe déjà.
3. Vérifier que `ciblesVersListe()` rend correctement des **héros** (le tir ami
   en affiche déjà) et que le fil de combat montre les nouvelles lignes.
4. `npm run build`, puis captures Playwright.

---

---

# Révision du 2026-09-17 — trois décisions de René

> La première livraison (§1-4 ci-dessus) est en place et testée. René l'a
> essayée et a tranché trois points. Ce qui suit **remplace** les §3.2 et §4
> correspondants ; le reste tient.

## R1. Jeter ne coûte PLUS l'action

« Jeter des items ne prend pas d'action, permettant de jeter plusieurs choses
dans le même tour. » Le geste passe donc au créneau **`interaction`**, avec
`ouvrir_porte` et `objet_libre`.

⚠ **Deux conséquences qu'on rate facilement.**

**a. L'option doit SORTIR de la garde `! $aAgi`.** Tout le bloc d'équipement vit
dans `if ((! $aAgi || $bonusAttaqueDisponible) && ! $actionInterdite …)`. Laisser
`jeter` dedans le ferait disparaître dès que le héros a agi — c'est-à-dire
exactement quand on veut encore pouvoir se délester. Gratuit dans
`creneauOption()` mais masqué dans le menu, ce serait une gratuité pour rien.

**b. Le miroir front doit suivre, et ce serait sa QUATRIÈME cicatrice.**
`ActionTab.creneauConsomme()` grise `jeter` sur `a_agi` via son `default`.
`actionner_levier` s'est cru gratuit, `objet_libre` manquait, l'attaque ignorait
le bonus d'héroïsme : trois fois le même défaut. Ici c'est l'inverse — le serveur
accepterait, le client griserait. Un miroir se vérifie, il ne se déclare pas.

⚠ **Pourquoi la leçon d'`actionner_levier` NE s'applique PAS.** Le levier a
QUITTÉ la liste des gratuits le 2026-08-24 parce qu'un jet retentable sans coût
se relancerait à l'infini dans le même tour. Jeter n'a pas cette forme : chaque
geste **retire** une pièce du sac, donc la suite est finie et décroissante. La
répétition est ici le but, pas la faille.

## R2. Une quantité choisie, au clavier, avec de VRAIES bornes

Quand la ligne porte plusieurs exemplaires, un palier de saisie numérique
s'ouvre : `min` 1, `max` = la pile réelle.

⚠ Le `max` est **publié par le serveur** (`quantite` de l'entrée) et
**re-validé** à la résolution contre la ligne en base — jamais seulement borné
côté client. Un champ numérique est la plus facile des whitelists à contourner.

⚠ Ça corrige au passage un payload qui MENTAIT : la liste affichait `×3` et la
confirmation « Jeter Potion de soin », alors qu'un seul exemplaire partait.

## R3. L'échange devient la SÉANCE du canon

Doc 01 §7 : « transférer armes/armures **entre les deux inventaires**, dans la
limite des **capacités respectives** ». Pluriel, bidirectionnel — une séance qui
coûte **une** action, pas un objet qui coûte une action. La première livraison
avait importé la forme du **don au hub**, qui est unidirectionnel et à l'unité :
c'était la forme du service, pas celle de la règle.

**Flux** : `echanger` porte `parametres.allies[]` (un par allié adjacent debout).
Choisir l'allié ouvre la **séance** : les deux sacs côte à côte, des pièces
déplaçables dans les deux sens, une quantité par pile (même palier que R2), et
**une seule validation** qui coûte l'action.

⚠ **La capacité se juge sur l'ÉTAT FINAL, jamais coup par coup.** C'est la
raison d'être de la séance : deux sacs pleins qui **échangent** deux armures est
légal au canon, et pourtant aucun ordre d'application ne passe le contrôle
actuel — `DonObjet::donner()` vérifie `peutRanger()` avant chaque mouvement, donc
le premier échoue quel que soit le sens. Deadlock garanti sur le cas le plus
naturel de la règle.

⚠ **Sans dupliquer `DonObjet` pour autant.** Extraire de `donner()` son corps
transactionnel (la mécanique : pile fusionnée, ligne **déplacée** pour préserver
les `ameliorations` de Forge) et laisser `donner()` = contrôle unitaire +
mécanique. La séance valide le **net** une fois, puis appelle la mécanique pour
chaque mouvement, le tout dans **une** transaction. Une règle, un point de
passage — c'est le contrôle qui change d'échelle, pas le transfert.

**Le net, en clair** : pour chacun des deux héros, `occupation actuelle − pièces
encombrantes qui partent + pièces encombrantes qui arrivent ≤ capacité`. Les
consommables ne comptent jamais.

⚠ **Le client a besoin d'un total qui bouge en direct**, sinon la séance est
inutilisable — et c'est précisément le terrain de la règle enfreinte cinq fois en
une semaine. La ligne de partage : le serveur publie la **décision par pièce**
(`encombrant: true/false`, et la capacité max de chaque sac), le client se
contente d'**additionner des entiers** pour un aperçu. Il ne re-dérive jamais la
règle « un consommable ne compte pas » — il lit un booléen. Et la validation à la
soumission reste **entièrement serveur** : l'aperçu peut se tromper, le refus
fait foi.

⚠ **Aucun accord demandé à l'allié.** Le canon dit « échanger avec un joueur
adjacent » et la table le fait de vive voix — les joueurs sont dans la même
pièce. Un flux de consentement (offre `MoteurReactions` sur l'autre téléphone)
serait lourd pour un problème qui n'existe pas en jeu coopératif. **Choix assumé,
écrit ici** : il permet bien de vider le sac d'un allié sans le lui demander.

## R4. Découpe de la révision

Mêmes périmètres disjoints qu'au §4 — **Lot A** `app/Partie/**` + `tests/`,
**Lot B** `resources/js/**`. S'ajoutent pour A : `creneauOption()` (désormais
autorisé, pour `jeter` uniquement), la sortie du bloc hors garde `! $aAgi`,
l'extraction de la mécanique de `DonObjet`, le service de séance, et
`parametres.quantite` dans le validateur de `ChoixController`. Pour B :
`creneauConsomme()` (désormais autorisé, pour `jeter` uniquement), le palier de
quantité, et l'écran de séance.

## 5. Vérification (orchestrateur, pas les agents)

Suite Pest complète · `docker compose restart queue queue-jeu` (⚠ obligatoire) ·
partie réelle sur la stack seedée : deux héros adjacents, une potion qui passe,
une armure refusée par un sac plein, un objet jeté et le sac qui se libère.
