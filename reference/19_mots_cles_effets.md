# 19 — Mots-clés d’effet : durées, cibles, résistances

> **Nature de ce document.** Contrairement aux docs 16-18, ce n'est pas un
> extrait de livret officiel : c'est une **décision de portage**. Le jeu de
> plateau n'a pas de système de durées — ses cartes disent leur effet en
> toutes lettres. Nous en avons besoin parce que nos effets sont des données.
>
> Décidé le **2026-08-05** par René. Autorité côté code :
> `App\Engine\DureeEffet`.

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
| `premier_degat_subi` | observateur `Personnage::booted()` — toute BAISSE de `pv_body`, quelle qu'en soit la source |
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
| `heros` | un héros de la quête |
| `heros_ou_soi` | un héros, le lanceur compris (Soin du Corps) |
| `monstre` | un monstre |
| `monstres_zone` | plusieurs monstres — ⚠ **non implémenté**, voir §7 |

⚠ Pour un sort de **dégâts ou mental**, `cible` documente l'INTENTION, il ne
restreint pas : le **tir ami est délibéré** (doc 02 §5, S3), donc la liste légale
contient monstres *et* héros en ligne de vue. La restriction ne s'applique qu'aux
sorts **utilitaires**.

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
