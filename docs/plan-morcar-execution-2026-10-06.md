# Plan — Finaliser *Wizards of Morcar* (exécution, 2026-10-06)

> ⚠ **Document DATÉ du 2026-10-06**. Il ordonne le portage de la boîte ; les
> règles citées sont dans `docs/plan-wizards-of-morcar.md` (lots A-H, questions)
> et les textes de carte dans `reference/18_extensions.md` §**Wizards of Morcar —
> cartes TRANSCRITES (2026-10-05)** — source unique des valeurs, rien d'inventé.

## Décisions de René en vigueur

| Sujet | Décision |
|---|---|
| Murs magiques | **2 cases**, comme la carte (« covers 2 squares not occupied by figures ») — 2026-10-05. Bâtis sur le mobilier attaquable (1 PV, 6 dés de défense). *Wall of Ice* : Storm Master ; *Wall of Flame* : High Mage ; *Wall of Stone* : sort de héros (*Spells of Protection*). |
| Mercenaires (Q1) | **Entretien partout** (2026-10-06) : 10 po par mercenaire et par quête, pour TOUS les groupes ; non payé, le mercenaire part. Le **statut de Gardien** est porté (débloqué après 2 quêtes, 4 mercenaires par héros), livret p. 8-9. |
| Faveurs de Hopekins Rest (Q2) | **Récompense de quête séparée** (2026-10-06), hors arbre de talents : une faveur offerte au groupe à la fin de certaines quêtes, comme le livret après la quête 2. |
| Sir Ragnar (Q3) | Captif-figurine de la mission « secourir » (carte M7 A3 D5 B6 Mi2), nommé distinctement du Sir Ragnar monstre de *Rise of the Dread Moon*. |
| Thème (Q5) | Oui : `wizards_of_morcar` entre dans `BOITES_THEMATIQUES` une fois les cinq sorciers jouables (vague 2). |
| Alliés | Joués par leur joueur (2026-10-04). |

## Vague 1 — trois chantiers en parallèle

**1a. Magie des héros et murs** — les 9 sorts des répertoires *Spells of
Protection / Detection / Darkness* (dont *Wall of Stone*) comme nouveaux
répertoires que le Magicien et l'Elfe peuvent choisir (« may replace existing
sets », Elfe 1 jeu, Magicien 3, changeables entre les quêtes — livret p. 11) ;
les trois murs comme mobilier attaquable de 2 cases posé en cours de quête
(couche de carte durable, publiée, rendue), avec le point de passage que les
sorts de Dread de la vague 2 réutiliseront.

**1b. Donjon et butin** — pièges magiques (*Fireburst*, *Hurricane*,
*Teleport* : non détectables par la fouille, usage unique), coffres renforcés
(fouille à l'adjacence), les potions de la boîte (*Fire Resistance*, *Magical
Aptitude*, *Magic Resistance*, *Alchemy*, *Charm*), les cartes de trésor
ajoutées, les artefacts *Urdyn the Unmaker* et *Drakehide Cuirass*.

**1c. Mercenaires, Gardien, faveurs, Sir Ragnar** — entretien 10 po/quête
partout (état durable, annoncé au hub), statut de Gardien (après 2 quêtes,
4 mercenaires par héros), les 5 faveurs comme récompense de fin de quête
(choix ou tirage à préciser dans le chantier, écrit), Sir Ragnar captif.

## Vague 2 — les cinq Sorciers du Dread et le thème

Après la vague 1 (les murs de Dread réutilisent 1a). Les **30 sorts** (6 par
sorcier : Storm Master, Necromancer, High Mage, Orc Warcaster, Artificer) —
« Each Sorcerer may cast one spell per turn instead of attacking. Each spell may
only be used once per quest » (p. 10) —, les cinq sorciers et leurs stats, les
monstres de la boîte (Dreadshifter et son embuscade, Golem, Minotaure),
l'entrée dans `BOITES_THEMATIQUES`, boss et rencontre finale. Les mécaniques
neuves (sort réactif sans action, MJ qui déplace un héros, bouclier à jetons,
buff de faction) passent chacune par vocabulaire fermé → lecteur → test en
jeu → donnée.

## Fin

Suite Pest complète, migrations/seeders sur la vraie base après
`./image-tools/sauvegarder.sh`, workers redémarrés, campagne d'agents sur le
thème `wizards_of_morcar`.

## Décisions de René du 2026-10-16 (addendum, consignées — le plan d'origine n'est pas réécrit)

- **Faveurs de Hopekins — DURÉE : suivre le livret.** Une faveur est TENUE jusqu'à la réception d'une
  autre (« A hero may only benefit from one boon at a time » ; « This Mercenary returns to the guild if
  you ever choose another boon »). Recevoir une faveur relâche celles que le héros tient, effet compris
  (`FaveursHopekins::relacherFaveursTenues()`). Pas de durée « une quête » : le livret ne la donne pas.
  Le TIRAGE reste au hasard — écart nommé et non tranché : le livret fait choisir chaque joueur pour son
  héros. Repli : les faveurs héritées tombent à la prochaine réception. Tests : `FaveursHopekinsDureeTest`.
- **Potion de soin** (100 po, prix non sourcé) : hors de l'étal, par le drapeau `effet.hors_marche`
  (migration `2026_10_16_120000_potion_de_soin_hors_marche`). Raison écrite : « prix non sourcé ; la
  Potion de guérison sourcée, 500 po, reste en vente ». Elle reste buvable.
- **Mercenaire offert par la Guilde** : l'entretien de 10 po par quête reste DÛ (« cost » = le prix
  d'engagement de la carte).
- **Bibliothèque arcanique** : pas de répertoire elfique — la carte ne le nomme pas (« an additional set
  of spells that you are eligible to cast »).
- **Mind Burst** : écart de crânes BRUTS, sans boucliers, comme le livret (la décision du 2026-10-15
  portait sur Mind Lock et Dominate seulement).
- **Monstre errant** : s'il est encore au contact du fouilleur à la phase des monstres du même round
  (tour de Zargon), il frappe à nouveau, comme tout monstre.
- **Deathspinner** : PAS de *Spawn* / *Agile* / *Venomous* tant que la source n'est pas officielle — ils
  viennent de la compilation communautaire d'errata (`docs/plan-errata-2021.md` §5), sans confirmation.
