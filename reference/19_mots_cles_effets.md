# 19 — Mots-clés d’effet : durées, cibles, résistances, équipement

> **Nature de ce document.** Contrairement aux docs 16-18, ce n'est pas un
> extrait de livret officiel : c'est une **décision de portage**. Le jeu de
> plateau n'a pas de système de durées — ses cartes disent leur effet en
> toutes lettres. Nous en avons besoin parce que nos effets sont des données.
>
> Décidé le **2026-08-05** par René. Autorité côté code : `App\Engine\DureeEffet`
> (§2-5), `App\Engine\MotsClesSort` (§6-7), `App\Engine\MotsClesEquipement` (§9,
> ajouté le 2026-08-08). Les trois classes disent la même chose : un effet est
> une **donnée**, donc chaque valeur qu'il porte est un mot déclaré, câblé et
> documenté — jamais du texte libre.

## 1. Pourquoi ce document existe

La clé `duree` vivait dans les données depuis le début — `prochaine_attaque`,
`ce_tour`, `fin_du_combat`, `un_combat`, `jusqu_au_prochain_tour` — mais
**aucun lecteur ne l'appliquait**. Les rares effets qui expiraient le faisaient
par des appels codés en dur sur leur **clé d'effet**, pas sur leur durée :

```php
consommerBuffs($personnage, 'bonus_des_attaque');   // Courage
consommerBuffs($personnage, 'deplacement_multiplie'); // Vent Véloce
```

Confondre *ce que fait* un buff et *quand il s'arrête* a deux conséquences
directes, l'une et l'autre constatées en jeu (verdict 2026-08-05) :

1. **Il n'existait aucun `consommerBuffs('bonus_des_defense')`.** La *Potion de
   défense* (+2 dés, 150 or) et *Peau de Pierre* ne s'arrêtaient donc **jamais** :
   une `duree` à 0 n'est pas décrémentée, et rien d'autre ne les retirait. Bonus
   permanent, pour toute la campagne. Reproduit : +2 après 10 tours et après une
   attaque.
2. **La *Potion de rage*, annoncée « un combat », disparaissait dès la première
   attaque** — comme Courage, puisque les deux portent `bonus_des_attaque`.

Et une troisième, structurelle : on ne *pouvait pas* exprimer « +2 en défense
jusqu'à la prochaine défense », faute de mot pour le dire.

## 2. Le vocabulaire

Six mots-clés. Toute autre valeur textuelle est un bug de catalogue ; un
**entier** reste valide et signifie tout autre chose (§3).

| mot-clé | prend fin… | exemples |
|---|---|---|
| `prochaine_attaque` | quand le porteur **attaque** (quel que soit le résultat) | Courage, Potion de force |
| `prochaine_defense` | quand le porteur **se défend** (le jet, qu'il encaisse ou non) | Potion de défense |
| `premier_degat_subi` | au premier dégât **réellement encaissé** — parer sans rien perdre ne le consomme pas | Peau de Pierre |
| `ce_tour` | à la **fin du tour du porteur** | Vent Véloce |
| `prochain_tour` | au **début du prochain tour du porteur** | Voile de Brume |
| `fin_du_combat` | quand **plus aucun monstre n'est engagé** (actif ET révélé) | Potion de rage, Peau de Pierre |

### `ce_tour` vs `prochain_tour` — la distinction est mécanique

Elle n'est pas cosmétique : entre les deux se trouve **la phase des monstres**.

- `ce_tour` s'arrête quand le héros termine son tour (décision explicite,
  « Terminer le tour »). L'effet **n'atteint pas** la phase des monstres.
- `prochain_tour` s'arrête à la fin du round, **après** la phase des monstres.
  L'effet **couvre** donc l'assaut ennemi — tout l'intérêt d'une protection.

Voile de Brume rend le héros inattaquable : le mettre en `ce_tour` le rendrait
inutile, puisqu'il expirerait juste avant les attaques dont il protège.

### `fin_du_combat` — monstres ENGAGÉS, pas donjon vidé

**Le combat s'arrête quand plus aucun monstre n'est engagé**, c'est-à-dire quand
il ne reste aucune instance à la fois `etat = actif` **et** `revele = true`.

⚠ Ce n'est **pas** « plus aucun monstre dans le donjon ». `etat = actif` veut
seulement dire « pas encore vaincu » : une quête conserve des monstres actifs
mais `revele = 0` dans toutes les salles jamais ouvertes. Confondre les deux
repousserait la fin du combat au **nettoyage complet du donjon**, et un buff
« un combat » couvrirait alors toute la descente — ce qui n'est pas un combat,
c'est la quête entière.

Conséquence voulue : on nettoie la salle, le combat se termine, les buffs
tombent. Ouvrir une porte plus loin réveille des dormants et rouvre un **nouveau**
combat — auquel le buff dépensé ne s'applique plus.

`ResolveurTour::combatTermine()` porte cette définition, et
`verifierFinDuCombat()` (idempotent) l'applique après chaque action de héros,
après la phase des alliés, et à la victoire. Le nettoyage complet du donjon y
délègue plutôt que de dupliquer le test : **une seule définition de la fin du
combat**.

## 3. Une `duree` ENTIÈRE : le décompte en tours

`duree` peut aussi valoir un entier — c'est un **décompte de tours**, sans
rapport avec les mots-clés :

- posé sur le pivot `personnage_conditions.duree` ;
- décrémenté à la fin de chaque round par `MoteurSorts::decrementerDurees()` ;
- retiré quand il atteint 0.

C'est ce que portent les conditions du catalogue via `conditions.duree_defaut`
(Empoisonné 3 tours, Étourdi 1 tour). `DureeEffet::tours()` fait le tri :
entier → compteur, mot-clé → 0 (l'expiration passe par un déclencheur).

**`duree_defaut = 0` ne veut donc pas dire « instantané »** mais « pas de
compteur » : la condition attend un déclencheur ou un retrait explicite.

## 4. Où c'est câblé

| déclencheur | point d'ancrage |
|---|---|
| `prochaine_attaque` | `ResolveurTour::resoudreAttaque()`, après le jet |
| `prochaine_defense` | résolution de l'attaque d'un monstre sur un héros |
| `premier_degat_subi` | observateur `Personnage::booted()` — toute BAISSE de `pv_body`, quelle qu'en soit la source (monstre, piège, Dread, tir ami) |
| `ce_tour` | `ResolveurTour::marquerCreneau()`, créneau `tour` |
| `prochain_tour` | fin de round, après la phase des monstres |
| `fin_du_combat` | `ResolveurTour::verifierFinDuCombat()` |

La durée est **relue sur la source** du buff (`sort:{Nom}` / `potion:{Nom}`) au
moment d'expirer, jamais recopiée sur le pivot : corriger un catalogue s'applique
donc aux buffs **déjà posés**.

## 5. Ce qui n'est PAS couvert

- **`conditions.effet.fin`** (`jet_mind_reussi`, `reveil_ou_attaque`,
  `liberation`, `releve_ou_fin_de_combat`…) reste **descriptif** : ces valeurs
  nomment des événements déjà traités en dur ailleurs (`reveillerHeros()`, la
  relève, la résistance) et ne sont lues par personne. Elles documentent la règle
  pour un humain — ne pas s'en servir comme d'un vocabulaire actif. Seule
  `son_prochain_tour` a été renommée `prochain_tour`, pour ne pas laisser deux
  orthographes du même instant.
- **Les monstres** n'ont pas de durées : leurs conditions vivent dans
  `habillage.conditions` en booléens, posés et retirés explicitement.
- **`duree_tours`** subsiste sur les sorts de Dread (`SortDreadSeeder`), où il
  alimente directement le pivot. Il n'a jamais concerné les objets — c'est
  précisément parce que `appliquerBuffPotion()` le lisait qu'aucune potion
  n'expirait.

## 6. Les autres vocabulaires de sort (`App\Engine\MotsClesSort`)

`duree` n'est pas la seule valeur textuelle d'un effet. Trois autres sont des
mots-clés déclarés, et désormais **lus** :

### `cible` — qui le sort vise

| mot-clé | sens |
|---|---|
| `soi` | le lanceur ; aucune liste de cibles n'est proposée (Traverser la Pierre) |
| `heros` | un héros de la quête, **lanceur compris** |
| `monstre` | un monstre |
| `monstres_zone` | plusieurs monstres — ⚠ **non implémenté**, voir §7 |

**Le lanceur est toujours une cible légale** d'un sort bénéfique : « This spell
may be cast on any one hero, **including yourself** » (Heal Body, LR p. 8) et
« un sort peut cibler soi-même, un autre héros, ou un monstre » (LR p. 14). Un
`heros_ou_soi` a existé jusqu'au 2026-08-06 ; il produisait **exactement** la
même liste que `heros`, sans recouvrir la moindre règle — retiré.

**La LIGNE DE VUE est exigée pour TOUT sort**, pas seulement les offensifs :
« nécessaire pour lancer un sort ou observer une cible » (LR p. 14,
reference/16_armurerie.md §6.4). Le filtre n'était appliqué qu'aux sorts de
dégâts et mentaux : on soignait donc un compagnon à l'autre bout du donjon, à
travers les murs, jusque dans une salle jamais explorée. Le lanceur, lui, se voit
toujours — il reste ciblable en toutes circonstances.

⚠ Pour un sort de **dégâts ou mental**, `cible` documente l'INTENTION, il ne
restreint pas : le **tir ami est délibéré** (doc 02 §5, S3), donc la liste légale
contient monstres *et* héros en ligne de vue.

### `cout` — retiré

Un vocabulaire de coût (`deplacement_du_tour`) avait été introduit le 2026-08-06
pour Traverser la Pierre, qui le déclarait sans que personne ne le lise. Le texte
officiel a tranché autrement le jour même : le sort ne **coûte** pas le
déplacement, il le **transforme** — « traverse les murs sur tout le déplacement
du jet » (Witch Lord). Facturer l'allonce rendait le sort inutilisable, puisque
c'est le déplacement qui EST l'effet.

Le mot et son lecteur ont donc été **retirés** plutôt que laissés sans usage —
un vocabulaire sans utilisateur redevient vite décoratif. Le rétablir est trivial
le jour où un sort en a besoin.

### `resistance` — comment la cible résiste

| mot-clé | sens |
|---|---|
| `jet_mind` | jet binaire de Mind (`Engine\SortMental`) ; un Mind 0 est immunisé |

C'est le défaut. La clé décrivait jusqu'ici ce que `type = mental` imposait de
toute façon ; la lire permet d'ajouter d'autres résistances sans toucher au
routage par type. Une valeur inconnue **échoue bruyamment** (422) plutôt que de
résoudre en silence avec la mauvaise règle.

### `defense_applicable` — booléen

`true` (défaut) : la cible lance sa défense. `false` : le sort frappe sans parade
possible. Aucun sort ne l'utilise à `false` aujourd'hui, mais la clé **pilote**
désormais le jet au lieu de le décrire.

## 7. Mots déclarés dont la MÉCANIQUE N'EXISTE PAS

`MotsClesSort::NON_IMPLEMENTES` recense les mots qu'on peut écrire dans un
catalogue mais que le moteur **n'applique pas**. Une dette déclarée est une dette
qu'on retrouve ; un test la verrouille, et le guide ne les affiche pas — promettre
au joueur une règle absente est pire que se taire.

| mot | ce qui manque | porté par un sort ? |
|---|---|---|
| `cible: monstres_zone` | aucun ciblage de surface : `ciblesLegales()` ne distingue pas de zone et `sortMental()` résout sur UNE cible | **non** |
| `invocation_ephemere` | aucun mécanisme d'invocation | **non** |

**Plus aucun sort ne s'appuie dessus** — et la vérification a montré que la
dette n'en était pas une : c'étaient deux **erreurs de donnée**. Le texte
officiel dit « **un monstre choisi** passe son prochain tour » pour Tempête
(Kellar's Keep p. 15) : elle n'a jamais été un sort de zone. Et il ne parle
d'aucune invocation pour Génie — « ouvre une porte au choix **ou** attaque avec
5 dés de combat ». Les deux clés ont donc été corrigées à la source, comme
`attaque_second_rang` avant elles.

Les mots restent déclarés ici pour qu'un futur catalogue ne les réintroduise pas
en croyant qu'ils marchent. Un test l'interdit : **aucun sort ne peut porter un
mot de cette liste**.

## 8. Ajouter un effet à durée

1. Choisis un mot-clé de §2 (ou un entier si c'est un décompte de tours).
2. Pose-le dans `effet.duree` du sort ou de l'objet.
3. Rien d'autre : `expirerBuffs()` s'en charge, et le guide l'affiche.

Si aucun mot-clé ne convient, **n'invente pas de valeur** : ajoute-la à
`DureeEffet`, câble son déclencheur, et documente-la ici. Une valeur sans
déclencheur est un effet qui ne s'arrête jamais — c'est exactement le bug que ce
document referme.

## 9. Mots-clés d'ÉQUIPEMENT (`App\Engine\MotsClesEquipement`)

Troisième vocabulaire, et celui qui a la source la plus directe : au plateau,
une **carte d'équipement** dit son effet en une phrase — « This weapon allows you
to attack diagonally », « You may not use a shield when using the battle axe ».
Convertir la carte, c'est traduire cette phrase en mots-clés. La conversion carte
par carte est en `reference/16_armurerie.md` §2.2 ; ici, ce sont les mots.

### Statistiques

| clé | ce qu'elle fait | ⚠ |
|---|---|---|
| `des_attaque` | Dés d'attaque de l'arme. **REMPLACE** la valeur du porteur (doc 03 §8 : l'attaque vient de l'arme) — à mains nues, 1 dé. | |
| `des_defense` | Dés de défense de la pièce. **S'AJOUTE** aux 2 dés communs aux quatre classes (LR p. 21). | |

L'asymétrie remplace/ajoute n'est pas un détail d'implémentation : elle vient du
plateau, et l'avoir manquée avait produit un barbare à 6 dés d'attaque (sa classe
encodait déjà l'épée large, puis l'arme achetée s'y ajoutait).

### Portée et ciblage

| clé | ce qu'elle fait | ⚠ |
|---|---|---|
| `attaque_diagonale` | Le contact inclut les 8 cases au lieu de 4. **Asymétrique** : le monstre ne riposte jamais en diagonale, le livret qualifiant cette case de « safe » (LR p. 14). | |
| `portee: distance` | Arme à distance : déclenche à elle seule le contrôle de ligne de vue. Pas de clé `ligne_de_vue` à côté — elle a été retirée, elle ne faisait que doubler. | |
| `inutilisable_adjacent` | Interdit le tir quand un ennemi est au contact (arbalète). | **de nous** — le livret n'interdit pas le tir à bout portant |
| `jetable` | L'arme peut être lancée en ligne de vue, puis elle est **détruite**. | la destruction est **de nous** : la dague officielle est une arme à distance permanente (LR p. 14) |

### Mains, déplacement, outil

| clé | ce qu'elle fait | ⚠ |
|---|---|---|
| `deux_mains` | Interdit le bouclier. **Orthogonal au `tag_equipement`** : ce mot dit « pas de bouclier avec », le tag dit « qui a le droit d'en porter ». Le **Bâton** est `deux_mains` ET `arme_legere` — sa carte n'énonce aucune restriction de classe —, donc jouable par le magicien. | |
| `incompatible_deux_mains` | La pièce **est** un bouclier : refuse de cohabiter avec `deux_mains`. | |
| `malus_deplacement` | Encombrement de l'armure lourde, **en cases** : « a 2 square movement penalty » (carte Plate Mail). Le dé est toujours lancé, et le total ne descend jamais sous 1. On supprimait auparavant le d6 entier (`deplacement_sans_d6`) : −3,5 cases en moyenne, **et** un déplacement devenu déterministe. | |
| `permet_desamorcage` | Désamorçage de piège — « you must possess a tool kit (or be the dwarf) » (LR p. 19). | |

### Artefacts

Le paquet d'artefacts (reference/16 §9.1) a demandé cinq mots de plus — tous
câblés, aucun décoratif :

| clé | ce qu'elle fait | carte |
|---|---|---|
| `degats_fixes` | Dégâts GARANTIS : ni jet d'attaque, ni jet de défense. Seule clé qui court-circuite `Engine\Combat`. | « This weapon always inflicts one Body Point of damage » |
| `des_attaque_contre` | `{noms: […], des: N}` — dés opposés à des créatures nommées. La valeur **remplace** celle de l'arme (le « OR » de la carte), elle ne s'y ajoute pas. | « three combat dice OR four against undead » |
| `attaque_double_contre` | Liste de `nom_base` contre lesquels l'arme accorde une SECONDE attaque ce tour. | « You may attack TWICE if you are fighting Orcs » |
| `bonus_pv_body_max` | PV de Body **maximum** en plus tant que la pièce est portée. Les gagner donne les points, les perdre écrête la valeur courante. | « adds 2 Body points … to the totals » |
| `bonus_pv_mind_max` | Idem pour le Mind. | idem |

⚠ Les deux clés « contre » testent `monstres.nom_base`, le nom de **catalogue** —
jamais le nom affiché, que l'IA habille à chaque quête (« Grull l'Éventreur ») :
une Lame des Esprits aurait cessé de reconnaître les morts-vivants dès la
première quête narrée.

### Consommables

`soin_pv_body` (montant fixe) · `soin_pv_body_de` (1d6 — Fiole de soin, ⚠ de
nous : les potions officielles annoncent toujours leur montant) · `soin_pv_mind` ·
`bonus_des_attaque` · `bonus_des_defense` · `attaque_supplementaire` (une
**seconde attaque** ce tour, pas des dés en plus : chez nous l'attaque vient de
l'arme) · `condition_appliquee` · `retire_condition` · `duree`.

⚠ **Un bonus sans `duree` ne s'arrête jamais.** C'est le bug de §1, et c'est
`bonus_des_attaque`/`bonus_des_defense` qui l'ont attrapé. Toute clé `bonus_*`
s'accompagne d'un mot de §2.

### Ce que la conversion NE passe PAS par un mot-clé

Deux phrases de carte sont portées ailleurs, exprès :

- **Toutes les restrictions de classe** — « May not be used by a Wizard »,
  « …by a Wizard or Elf », « May **only** be used by a Wizard » → `objets.tag_equipement`
  × `classes_heros.tags_equipement`. La règle est dite une fois, côté classe,
  plutôt que répétée sur chaque pièce. Onze tags couvrent les sept exclusions
  distinctes du paquet d'armurerie : `arme_legere` (aucune restriction),
  `arme_courante` / `arme_distance` (pas le magicien), `arme_deux_mains` (ni
  magicien ni elfe), `arme_arc_long` (ni magicien ni nain), `arme_arc_court` (ni
  magicien ni barbare), `arme_erudit` (ni barbare ni nain), `armure_legere` /
  `armure_lourde` / `bouclier` (pas le magicien), `armure_magicien` (**le
  magicien seul**). Détail en `reference/16_armurerie.md` §2.2.
- **« Both hands »** → `deux_mains`, mais c'est le tag `arme_deux_mains` qui dit
  *qui* peut la manier. Les deux mots coexistent parce qu'ils répondent à deux
  questions différentes ; les fusionner interdirait le Bâton au magicien, sa
  seule arme à 2 dés.

### Clés inertes assumées

`MotsClesEquipement::INERTES` — même principe que `NON_IMPLEMENTES` §7 : une clé
d'affichage ou un doublon d'une autorité qui vit ailleurs, déclarée pour qu'on
sache que son absence de lecteur est voulue.

| clé | pourquoi elle est inerte |
|---|---|
| `sort_nom` | libellé de confort : le nom du sort double déjà celui du parchemin |
| `difficulte_non_lanceur` | copie d'affichage ; `ResolveurTour` roule contre `sorts.difficulte_parchemin`, qui reste l'autorité (un test garde les deux synchronisées) |

### Le garde-fou

`ObjetsFonctionnelsTest` teste le vocabulaire **dans les deux sens** :

1. aucune clé de catalogue hors de `MotsClesEquipement` — sinon c'est une règle
   annoncée au joueur que personne n'applique ;
2. aucun mot déclaré que plus aucun objet ne porte — sinon c'est une règle qui
   n'existe que sur le papier.

C'est le deuxième sens qui aurait attrapé `attaque_second_rang` : déclaré, affiché
par le guide, porté par une Lance… et sans mécanique derrière.
