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

**Sept** mots-clés. Toute autre valeur textuelle est un bug de catalogue ; un
**entier** reste valide et signifie tout autre chose (§3).

| mot-clé | prend fin… | exemples |
|---|---|---|
| `prochaine_attaque` | quand le porteur **attaque** (quel que soit le résultat) | Courage, Potion de force |
| `prochaine_defense` | quand le porteur **se défend** (le jet, qu'il encaisse ou non) | Potion de défense |
| `premier_degat_subi` | au premier dégât **réellement encaissé** — parer sans rien perdre ne le consomme pas | Peau de Pierre |
| `ce_tour` | à la **fin du tour du porteur** | Vent Véloce |
| `prochain_tour` | au **début du prochain tour du porteur** | Voile de Brume |
| `fin_du_combat` | quand **plus aucun monstre n'est engagé** (actif ET révélé) | Image double, Peau de Pierre |
| `plus_de_monstre_en_vue` | quand **aucun monstre n'est en LIGNE DE VUE du porteur** | Potion de rage guerrière, Potion de peau de givre |

⚠ **`plus_de_monstre_en_vue` n'est pas `fin_du_combat`** (2026-08-15). Le second
raisonne sur la QUÊTE entière — plus aucune instance active et révélée nulle
part ; le premier sur la **vue du porteur**, et un ennemi vivant derrière un mur
ne prolonge rien. Les deux potions du Barbare l'exigent : « as soon as there are
no monsters in the Barbarian's line of sight, this potion's effect wears off ».

⚠ Il est évalué au **DÉBUT DU TOUR** du porteur (et à la génération de son menu),
pas en continu — `MoteurSorts::rythmerBuffsDeVue()`, le même crochet que la
récupération des styles du Moine, avec la même garde d'idempotence. Conséquence
assumée : la peau de givre protège encore pendant la phase de monstres qui suit
la mort du dernier ennemi. Ce crochet fait DEUX choses, et il faut les deux — il
expire les buffs, et il **ré-arme** la seconde attaque de la rage guerrière, que
la fin du tour précédent a consommée. Sans la garde d'idempotence, le
ré-armement repasserait après chaque action et offrirait une troisième attaque.

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

## 2bis. Une `duree` à PLUSIEURS déclencheurs (2026-09-02)

`duree` peut porter une **liste** de mots-clés : le premier déclencheur qui
survient retire le buff. Un seul effet en a besoin aujourd'hui, et c'est sa
carte qui l'impose — *Courage* (doc 16 §3bis) : « The next time that hero
attacks, they may roll 2 extra combat dice. **The spell is broken the moment a
monster is no longer in the hero's line of sight.** » Deux conditions de fin,
pas une, et nous n'en portions qu'une : le buff survivait au combat et attendait
la bagarre suivante.

⚠ La comparaison a **un point de passage unique**, `DureeEffet::correspond()`.
Elle se faisait par `===` sur deux sites (`expirerBuffs()`,
`rythmerBuffsDeVue()`), et une durée composée y aurait été silencieusement
ignorée — le buff n'expirant alors **jamais**, exactement ce que ce document
reproche à un mot-clé sans déclencheur.

⚠ Le vocabulaire ne change pas : une liste n'est légale que si **chacun** de ses
termes l'est (`DureeEffet::estMotCle()` est récursive), et un test parcourt tout
le catalogue.

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

## 4 bis. Le REGAIN — troisième axe, à ne pas confondre avec la durée

Ajouté le 2026-08-11 (`App\Engine\RegainEffet`, clé `effet.regain`). Trois
choses différentes vivent côte à côte et se confondaient :

| | question | porté par |
|---|---|---|
| `effet.duree` | quand le **buff** s'arrête | `DureeEffet` (§2) |
| `personnage_sorts.disponible` | le **sort** est-il relançable | pivot |
| `effet.regain` | à quel **événement** il le redevient | `RegainEffet` |

⚠ Un regain **n'est pas une durée**. Dire qu'un sort « dure jusqu'à ce que
vous tuiez un monstre » serait faux : le buff est fini depuis longtemps, c'est
le *droit de le relancer* qui revient. Avant ce chantier, `disponible` ne se
rechargeait qu'au **changement de quête**, ou par deux nœuds d'arbre codés en
dur (Concentration rend un sort, Réserve arcanique en accorde un second) —
aucune **donnée** ne pouvait dire « ce sort revient quand… », alors que les
cartes officielles le disent constamment.

| regain | événement | point d'ancrage |
|---|---|---|
| `body_au_max` | les PV de Body du lanceur reviennent au maximum | observateur `Personnage::booted()`, sur toute HAUSSE de `pv_body` — comme `premier_degat_subi` l'est sur la baisse |
| `monstre_vaincu` | le lanceur réduit un monstre à 0 Body | `ResolveurTour::resoudreAttaque()`, après le jet |
| `allie_deux_boucliers_blancs` | un **autre** héros en vue obtient 2 boucliers blancs en défense | `MoteurSorts::regainSurParade()` |

Deux subtilités portées par les cartes et toutes deux mécaniques : « **any
hero you can see, excluding yourself** » — le lanceur ne se recharge pas sur sa
propre parade (ce serait quasi permanent à 4 dés de défense), et il doit avoir
**vue** sur le défenseur. On compte les boucliers **blancs** : c'est la face qui
pare pour un héros, un bouclier noir dans sa volée ne vaut rien.

Les trois sorts qui les portent sont **semés depuis le 2026-08-12**, en même
temps que leurs classes (`reference/18_extensions.md`) : *Métamorphose*
(Druide, `body_au_max`), *Forme démoniaque* (Warlock, `monstre_vaincu`) et
*Conte inspirant* (Barde, `allie_deux_boucliers_blancs`). Les trois événements
avaient été écrits **avant** leurs porteurs, et c'est l'ordre qui a marché :
`RegainEffet::SANS_UTILISATEUR` nommait la dette, elle a été payée.

## 4 ter. Les dégâts subis par un héros : un point de passage INTERCEPTABLE

`App\Partie\MoteurDegats::infligerAHeros()` (2026-08-11). Douze endroits
écrivaient `pv_body` à la main — attaque de monstre, sort de Dread, piège, tir
ami, jetons de rejeton. Chacun calculait son « après » et l'écrivait, ce qui
interdisait deux choses :

1. **Intervenir avant.** `Personnage::booted()` observe la baisse une fois
   écrite : assez pour expirer un buff, inutile pour l'**annuler**. Or deux
   cartes officielles annulent des dégâts — *Dark Wings* (Warlock, « Reduce
   that damage to zero ») et *Twisting Torrent* (Moine, « cancel that
   damage »), toutes deux **pendant le tour d'un monstre**.
2. **Savoir d'où ça vient.** L'observateur voit « −2 PV » et rien d'autre. Une
   réaction qui annule le coup d'un monstre ne doit pas annuler une chute dans
   une fosse.

L'événement `App\Events\HerosVaSubirDegats` est émis **avant** l'écriture ;
son champ `degats` est **mutable**, un écouteur peut le réduire jusqu'à 0, et
le moteur applique ce qu'il en reste. Il ne peut pas l'**augmenter** : le
point d'interception protège, il ne frappe pas plus fort. Sources déclarées :
`attaque_monstre` · `sort_dread` · `piege` · `tir_ami` · `rejeton`.

**La moitié interface existe depuis le 2026-08-11** (`App\Partie\MoteurReactions`,
`POST /groupes/{id}/reaction`). Ce paragraphe a d'abord dit l'inverse — « demander
« veux-tu annuler ? » suppose d'interroger une manette au milieu de la phase des
monstres, ce que la boucle de jeu ne sait pas faire » — et la solution n'a pas
été de suspendre la boucle, mais d'inverser l'ordre : le coup est **appliqué**,
puis la question posée sur le canal privé du joueur, et accepter **défait** le
coup. C'est exactement l'ordre de la table, où l'on annonce les dégâts avant que
le joueur dise « j'annule ».

Six actions en vivent aujourd'hui (contrat §Réactions hors tour) : annuler,
plancher de PV, couvrir un voisin, riposter, relever un défi, et **se soigner
d'urgence** — cette dernière offrant un vrai choix de remède, pas un oui/non. Un
écouteur **automatique** (*Image double*, une charge dépensée sans décision)
reste par ailleurs le bon outil quand la carte ne demande pas d'arbitrage : il
ne coûte aucun aller-retour.

⚠ Ce que la boucle ne sait toujours pas faire : **s'arrêter**. La phase des
monstres se résout d'un bloc, dans la requête d'un autre joueur. Toute réaction
est donc POSTÉRIEURE à son déclencheur — et le seul verdict qui attende
vraiment une réponse est celui du TPK, suspendu tant qu'une offre peut encore
relever quelqu'un (`ResolveurTour::verdictDeChute()`, 2026-08-13).

⚠ **`Personnage::booted()` reste en place** et ce n'est pas un doublon : il est
le filet. Le moteur couvre les chemins connus, l'observateur rattrape tout ce
qui écrirait `pv_body` sans passer par lui. L'un intercepte, l'autre constate.

Conséquence pour les payloads : ils publient désormais les dégâts **relus après
application** (`$subis`, `(int) $personnage->pv_body`) et non
`$resultat->pvBodyApres`, que `Engine\Combat` calcule avant toute réaction.
Publier le calcul plutôt que le fait ferait mentir le journal dès la première
réaction portée.

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
| `soi` | le lanceur ; aucune liste de cibles n'est proposée (*Métamorphose*, *Ailes sombres*, *Forme démoniaque*) |
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
| `aucune` | **pas de jet du tout** : l'effet s'applique (Tempête, carte officielle doc 16 §3bis) |
| `des_rouges` | la cible lance `des_resistance` **d6 bruts** ; chaque **5 ou 6** annule 1 dégât (les deux sorts de feu) |
| `rupture_6_par_mind` | le sort prend toujours, puis la cible tente de rompre — **1 d6 par point de Mind, un 6 réussit** — sur-le-champ puis à chacun de ses tours (Sommeil) |

⚠ Les deux derniers ne se ressemblent qu'en apparence : `des_rouges` décide des
DÉGÂTS au moment du lancer, `rupture_6_par_mind` décide de la DURÉE d'un effet
déjà posé. Le second est donc lu à deux moments — à l'application, puis au début
de chaque tour du monstre — et c'est ce qui le distingue de `jet_mind`, qui
tranche une fois pour toutes au lancer. Le seuil se lit sur le **d6 brut** dans
les deux cas : nos faces de combat regroupent 4-5 en bouclier blanc et
écraseraient la moitié de la règle.

`jet_mind` est le défaut, et c'est précisément pourquoi `aucune` a dû être écrit
plutôt que sous-entendu : l'ABSENCE de la clé retombe sur `jet_mind`, elle ne
peut donc pas dire « pas de jet ». Nous imposions à *Tempête* une résistance que
sa carte ne demande pas, ce qui affaiblissait le sort exactement là où il sert —
sur une créature à fort Mind.

C'est le défaut. La clé décrivait jusqu'ici ce que `type = mental` imposait de
toute façon ; la lire permet d'ajouter d'autres résistances sans toucher au
routage par type. Une valeur inconnue **échoue bruyamment** (422) plutôt que de
résoudre en silence avec la mauvaise règle.

### `defense_applicable` — booléen

`true` (défaut) : la cible lance sa défense. `false` : le sort frappe sans parade
possible. Aucun sort ne l'utilise à `false` aujourd'hui, mais la clé **pilote**
désormais le jet au lieu de le décrire.

## 6 bis. Les mots-clés des RÉPERTOIRES DE CLASSE (2026-08-12)

Semés avec les sorts du Barde, du Druide, du Warlock et le répertoire elfique.
Tous ont un lecteur — c'est la condition pour être semés — et aucun n'invente de
valeur : chacun traduit une phrase de carte.

| clé | ce qu'elle fait | lecteur | sort porteur |
|---|---|---|---|
| `zone` | `salle_du_lanceur` : le sort ne se cible pas, il **balaie la salle ou le couloir du lanceur** | `ResolveurTour::sortDeZone()` / `soinDeZone()` | Flamme hypnotique, Chant de guérison |
| `condition_monstre` | pose sur un MONSTRE une condition de `MoteurSorts::CONDITIONS_MONSTRE` (`terrifie`, `ralenti`, `paralyse`, `endormi`, `saute_tour`) | `InstanceMonstre::attaqueEffective()` / `defenseEffective()` | Terreur, Ralentissement, Flamme hypnotique |
| `seuil_mind_max` | s'applique **sans jet** aux créatures dont le Mind est au niveau ou en dessous du seuil | `ResolveurTour::sortMental()` | Sommeil profond |
| `exclut_soi` | « any hero you can see, **excluding yourself** » : le lanceur sort de la liste des cibles | `MoteurSorts::ciblesLegales()` | Conte inspirant |
| `condition_bonus_attaque` | le bonus de dés ne vaut que dans un contexte (`au_contact`) | `MoteurSorts::bonusDes()` | Métamorphose |
| `image_miroir` | un leurre encaisse le coup sur 1-3 d'un d6, **sans décision du joueur** | écouteur `App\Listeners\ImageMiroir` | Image double |
| `tour_supplementaire` | le tour ne s'achève pas, il **recommence** | `ResolveurTour::marquerCreneau()` | Arrêt du temps |
| `regain` | à quel ÉVÉNEMENT le sort redevient lançable (§4 bis) | `MoteurSorts::regagnerSorts()` | Métamorphose, Forme démoniaque, Conte inspirant |
| `reaction` | le sort s'active **hors tour**, quand son porteur encaisse (§4 ter) | `MoteurReactions::sortReactifDisponible()` | Ailes sombres |
| `ignore_pieges_fosse` | « the warlock ignores pit traps » — la FOSSE seulement | `MoteurPieges::declencher()` via `aBuff()` | Forme démoniaque |

⚠ **`jet_contre_mind` a été retiré le 2026-08-13.** Il décrivait la règle du
sort de zone — *1 d6 par figure, touchée si le dé dépasse son Mind* — sans que
personne ne le lise : c'est `zone: salle_du_lanceur` qui route vers
`sortDeZone()`, dont **la règle est le chemin lui-même**. Troisième mot supprimé
plutôt que laissé décoratif, après `cout` et `heros_ou_soi`. Dette nommée en
échange, dans le code : le jour où un sort de zone infligera des DÉGÂTS et non
une condition, ce routage devra se scinder.

### Deux conditions neuves (`conditions`, ConditionSeeder)

| condition | effet | durée | posée par |
|---|---|---|---|
| **Paralysé** | `deplacement_interdit` + `action_interdite` + `defense_nulle` | 3 tours | Flamme hypnotique |
| **Évanescent** | `action_interdite` + `inattaquable` + `ignore_pieges` | jusqu'à rupture | Évanescence |

⚠ Les deux **interdisent l'action, pas le même reste**, et c'est ce qui les rend
jouables : l'Évanescent **marche encore et ouvre les portes** — c'est tout
l'intérêt du sort —, quand le Paralysé ne fait plus rien du tout et **ne pare
même plus**. `MenuMoteur` retire les options correspondantes, `ResolveurTour`
refuse l'action si elle arrive quand même.

L'Évanescence ne s'éteint pas au compteur mais sur un **jet de déplacement ≥ 5**
(décision de René, 2026-08-12) : le plateau lit 9+ sur 2 dés rouges — un peu plus
d'une chance sur quatre — et nous 5+ sur notre unique d6, soit une sur trois.
C'est l'approximation la plus proche que permet un seul dé.

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

**Les sept effets apportés par les potions officielles** (2026-08-15, doc 16
§2.1bis). Aucun n'est un nombre de dés — et c'est précisément pour eux que
`MoteurPotions` pose désormais un buff dès qu'une `duree` est déclarée, là où il
ne le faisait que pour les deux clés `bonus_des_*` :

| clé | ce qu'elle fait | lecteur |
|---|---|---|
| `relance_des_attaque` | une relance des dés d'attaque ratés (Potion de bataille) | `ResolveurTour::frapper()`, via le paramètre qui servait déjà au nœud *Coup puissant* |
| `multiplicateur_degats` | dégâts × N sur la prochaine attaque (Force glaciale) | `MoteurSorts::multiplicateurDegats()` + `ResultatAttaque::avecDegatsMultiplies()` |
| `bonus_deplacement` | cases de déplacement en plus ce tour (Dextérité) | `ResolveurTour::pointsDeplacement()` — ⚠ et son **miroir** dans `MenuMoteur` |
| `saut_fosse_automatique` | le franchissement de fosse réussit d'office | `resoudreFranchissement()`, patron du *Dragon bondissant* : le jet a lieu, il ne décide plus |
| `deplacement_multiplie` | dés de déplacement × N (Vitesse) | `MoteurSorts::multiplicateurDeplacement()` — **aucun lecteur neuf**, il lisait déjà les potions |
| `revele_pieges_et_portes_en_vue` | pièges ET portes secrètes en ligne de vue (Vision) | `MoteurPieges::revelerEnVue()` + `MoteurPortes::revelerSecretesEnVue()` |
| `restaure_jauges_depart` | Body et Mind au niveau du début de quête (Restauration supérieure) | `MoteurPotions` — chez nous c'est le **maximum**, `DemarreurQuete` remettant les jauges à plein |
| `une_par_tour` | une seule potion de ce type par tour (Dextérité) | `MoteurPotions`, compteur `etat.capacites_tour` — ⚠ ne vaut QUE pour les potions marquées |

⚠ `restaure_sorts` a changé de TYPE sans changer de nom : `true` = tous
(Parchemin de Sorts), un **entier** = ce nombre-là (Potion de magie 3, Potion de
rappel 1). `! empty()` reste vrai dans les trois cas, donc aucun appelant
existant n'a bougé.

⚠ `bonus_deplacement` est un **homonyme** : la même chaîne nomme une mécanique de
COMPÉTENCE (`competences.effet.mecanique`, nœuds *Pas léger* / *Charge*) qui vit
dans une autre table et modifie `personnages.deplacement_base` en permanence.
Ici c'est un buff temporaire.

**Les quatre clés du MATÉRIEL** — les cartes officielles qui ne sont ni arme, ni
armure, ni potion :

| clé | carte | lecteur |
|---|---|---|
| `tue_creatures` | Eau bénite — liste de `monstres.nom_base` tués d'office | `ResolveurTour::resoudreUsageObjet()` |
| `pose_chausse_trappes` | Chausse-trappes — couche `carte.grille['chausse_trappes']`, posée au runtime | `resoudreUsageObjet()` + `tronquerSurChausseTrappes()` |
| `enfume_monstre_adjacent` | Bombe fumigène — le monstre quitte `$occupees` | `FabriqueGrille::pour()` |
| `compte_comme_arme` | Bandoulière — « toujours considéré armé d'une dague » | `Equipement::compteCommeArme()` |

⚠ `tue_creatures` nomme ses cibles par `nom_base`, le nom de CATALOGUE, jamais
`nomAffiche()` : l'IA habille les monstres à chaque quête, et l'eau bénite
cesserait de reconnaître un squelette dès la première partie narrée. Même règle
que `des_attaque_contre` et `attaque_double_contre`, dont le comparateur est
désormais partagé (`ResolveurTour::nomBaseParmi()`).

⚠ `enfume_monstre_adjacent` lève d'un seul geste le blocage du MOUVEMENT et
celui de la LIGNE DE VUE, parce que `$occupees` est la seule boucle d'occupation
du moteur. Mais **traverser n'est pas s'arrêter** : finir son mouvement sur la
case d'un monstre enfumé est refusé, sans quoi deux figurines s'empileraient.

⚠ `compte_comme_arme` n'ajoute **aucun dé** — le Rogue à mains nues en lance déjà
un, autant que la dague. Elle donne les RÈGLES qui exigent une dague :
l'Ambidextrie, et la fermeture des techniques mains nues du Moine. Aucune arme
virtuelle n'entre dans `armesEnMain()`, dont les entrées sont de vraies lignes
d'inventaire qu'on supprime et qu'on équipe.

**Une clé de MOBILIER**, hors de ce vocabulaire parce qu'elle vit sur
`mobiliers.effet` et non sur `objets.effet` : `fouille` porte la table de butin
propre à chaque meuble (doc 17 §3). Ses issues réutilisent celles du deck —
`tresor` / `objet` / `piege` / `rien` — pour qu'`appliquerButin()` les applique
sans rien savoir de leur provenance.

### Charges et économie de sorts

Deux mécaniques ouvertes le **2026-08-09**, qui bloquaient à elles seules sept
cartes des deux paquets.

**Une charge** dit « cet exemplaire-ci a N utilisations ». C'est autre chose que
`inventaire.quantite`, qui compte des exemplaires IDENTIQUES (une pile de
potions) : un arc à quatre flèches est un seul objet, utilisable quatre fois.

| clé | ce qu'elle fait | carte |
|---|---|---|
| `charges` | Nombre d'utilisations INITIAL. Le restant vit sur l'exemplaire (`inventaire.charges`), pas sur le catalogue. `null` = jamais entamé, **pas** épuisé : toute ligne d'inventaire démarre donc pleine sans que les chemins qui la créent (marché, coffre, don, butin) aient à connaître les charges. À zéro l'objet devient **inerte** — il reste au sac, son effet ne s'applique plus. | Arc elfique (4), Anneau de Sort (1), Baguette de Galimatias (1) |
| `tue_sauf_bouclier_noir` | Tue la cible d'emblée, sauf si elle sort un **bouclier noir** sur un unique dé. S'accompagne toujours de `charges` : une mort instantanée illimitée viderait un donjon sans combat. | Arc elfique de Vindication |

**L'économie de sorts** dit quand un sort épuisé peut revenir. Le pivot
`personnage_sorts` n'a qu'un booléen `disponible`, remis à vrai au début de
chaque quête ; les deux seules exceptions étaient des **nœuds** (Concentration
en rend un, Réserve arcanique donne un second sort par tour). Aucun objet ne
savait toucher à cette économie.

| clé | ce qu'elle fait | carte |
|---|---|---|
| `restaure_sorts` | Rend **tous** les sorts épuisés. À distinguer du nœud *Concentration*, qui n'en rend qu'un au prix du tour : la différence d'échelle est la valeur de ces cartes. | Parchemin de Sorts (consommable), Baguette de Galimatias (à l'équipement, une charge) |
| `second_sort_par_tour` | Un second sort dans le tour. Exactement le pouvoir de *Réserve arcanique*, accordé par un OBJET — et les deux passent par le même `etat.bonus_sort_utilise`, donc ils ne se cumulent pas. | Baguette de Rappel |
| `sort_non_epuise` | Le prochain sort lancé ne s'épuise pas, contre une charge. | Anneau de Sort |
| `sort_non_epuise_sur_bouclier_noir` | Après chaque sort, un dé : bouclier noir (1 sur 6) → le sort reste disponible. Illimité — c'est le dé qui limite, pas une charge. | Sceptre de Mémoire |

⚠ **Ordre d'évaluation** : le sceptre est testé **avant** l'anneau. Réussir son
jet évite de gaspiller la charge de l'anneau — l'inverse aurait dépensé une
ressource rare derrière un effet gratuit.

⚠ **Écart assumé sur l'Anneau de Sort** : la carte fait CHOISIR le sort au début
de la quête. Ici le choix se fait en le lançant — même résultat, sans imposer un
pari à l'aveugle avant d'avoir vu le donjon.

### Types de dégâts (`App\Engine\TypeDegat`)

Un dégât était un dégât : nos sorts retiraient des points de Body sans dire de
quelle **nature**. `effet.type_degat`, posé sur la SOURCE (un sort de héros ou de
Dread), le dit désormais.

| clé | où | valeurs |
|---|---|---|
| `type_degat` | `sorts.effet`, `sorts_dread.effet` | `feu` · `froid` |
| `immunite_degat` | `objets.effet` | annule **intégralement** les dégâts de cette nature, contre une charge (Anneau de Feu, 2 charges) |

Une nature n'a d'intérêt que si elle a **une source ET un lecteur** :

- **`feu`** est complet — sources : Boule de Feu, Trait de Feu, Tempête de feu ;
  lecteurs : l'Anneau de Feu et la **régénération du troll**, qu'une brûlure
  interrompt définitivement (`instances_monstres.brule`).
- **`froid`** est déclaré **sans source** : les six sorts de *The Frozen Horror*
  sont nommés par le livret, leurs effets sont introuvables. `TypeDegat::SANS_SOURCE`
  le dit dans le code.

⚠ Un seul lecteur pour les deux chemins qui blessent un héros —
`MoteurSorts::absorbeDegat()`, appelé par le tir ami d'un sort de héros **et**
par le sort d'un Dread. La carte vise « Fire **or Chaos Fire** spells » : un
anneau qui protégerait d'un feu sur deux serait pire que pas d'anneau.

⚠ Écart assumé sur le troll : la carte rend permanents les seuls PV perdus **par
le feu**, ce qui demanderait une comptabilité des dégâts par nature. Ici, une
créature brûlée cesse simplement de régénérer — même intention tactique (le feu
est la réponse au troll), pour un booléen au lieu d'un grand livre.

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
