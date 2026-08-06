# 19 — Durées d'effet : le vocabulaire de `duree`

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

Cinq mots-clés. Toute autre valeur textuelle est un bug de catalogue ; un
**entier** reste valide et signifie tout autre chose (§3).

| mot-clé | prend fin… | exemples |
|---|---|---|
| `prochaine_attaque` | quand le porteur **attaque** (quel que soit le résultat) | Courage, Potion de force |
| `prochaine_defense` | quand le porteur **se défend** | Potion de défense |
| `ce_tour` | à la **fin du tour du porteur** | Vent Véloce |
| `prochain_tour` | au **début du prochain tour du porteur** | Voile de Brume |
| `fin_du_combat` | quand **plus aucun monstre n'est actif** dans la quête | Potion de rage, Peau de Pierre |

### `ce_tour` vs `prochain_tour` — la distinction est mécanique

Elle n'est pas cosmétique : entre les deux se trouve **la phase des monstres**.

- `ce_tour` s'arrête quand le héros termine son tour (décision explicite,
  « Terminer le tour »). L'effet **n'atteint pas** la phase des monstres.
- `prochain_tour` s'arrête à la fin du round, **après** la phase des monstres.
  L'effet **couvre** donc l'assaut ennemi — tout l'intérêt d'une protection.

Voile de Brume rend le héros inattaquable : le mettre en `ce_tour` le rendrait
inutile, puisqu'il expirerait juste avant les attaques dont il protège.

### `fin_du_combat`

Le moteur ne connaît **pas** de notion d'« engagement » plus fine qu'une quête :
il n'y a pas de compteur de rencontres, pas de sortie de mêlée. Le seul
événement de fin de combat qui existe est **« plus aucun monstre actif »**
(`donjon_nettoye`). C'est donc la définition retenue, et elle est volontairement
généreuse : un buff « combat » couvre toute la descente jusqu'au dernier monstre.

Trois chemins mènent au nettoyage du donjon (déplacement, action, phase des
alliés) : ils passent tous par `ResolveurTour::donjonNettoye()`, précisément pour
qu'aucun ne puisse laisser survivre un buff.

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
| `ce_tour` | `ResolveurTour::marquerCreneau()`, créneau `tour` |
| `prochain_tour` | fin de round, après la phase des monstres |
| `fin_du_combat` | `ResolveurTour::donjonNettoye()` |

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

## 6. Ajouter un effet à durée

1. Choisis un mot-clé de §2 (ou un entier si c'est un décompte de tours).
2. Pose-le dans `effet.duree` du sort ou de l'objet.
3. Rien d'autre : `expirerBuffs()` s'en charge, et le guide l'affiche.

Si aucun mot-clé ne convient, **n'invente pas de valeur** : ajoute-la à
`DureeEffet`, câble son déclencheur, et documente-la ici. Une valeur sans
déclencheur est un effet qui ne s'arrête jamais — c'est exactement le bug que ce
document referme.
