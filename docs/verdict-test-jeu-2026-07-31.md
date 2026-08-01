# Verdict — test de jeu à 2 (2026-07-31)

> Partie réelle sur la pile complète : **Aldric** (magicien niv. 1, 4 PV Body) et
> **Grom** (barbare niv. 1, 8 PV Body), joués par deux agents Sonnet via des
> navigateurs pilotés, orchestrateur à l'écran de table. 900 or communs, marché
> profil *cité*. Déroulé : marché → équipement → don → quête (2 salles nettoyées,
> 4 monstres vaincus, 2 fouilles) → arrêt en cours d'exploration.
>
> **Objectif** : éprouver les quatre chantiers récents — badge « non maîtrisé »,
> limites d'équipement par classe, don entre héros, deck de fouille + coffre.
>
> Chaque constat des agents a été **revérifié côté serveur** avant d'entrer ici.
> Les rapports bruts contenaient plusieurs conclusions erronées, signalées comme
> telles ci-dessous : un rapport d'agent est une observation, pas un diagnostic.

---

## 1. Ce que le test valide

Les quatre mécaniques visées ont fonctionné, observées indépendamment par les deux
agents :

- **Don entre héros** — « fonctionne parfaitement » (Grom), testé dans les deux
  sens, transfert instantané, sélection du destinataire claire.
- **Badge « non maîtrisé »** — présent et lu par les deux joueurs comme le signal
  attendu. Aldric : « seul le badge guide le choix ».
- **Marché** — panier partagé, totaux en direct sur la bourse commune, double
  confirmation, application atomique. Le bouton de confirmation se désactive bien
  quand le total groupe passe négatif.
- **Fouille** — a rendu 30 or sans consommer de dé ; le coffre à artefact
  (Kriss du Fossoyeur, salle 1) n'a pas été atteint, la partie s'étant arrêtée avant.
- **Équipement par classe** — le magicien a acheté et porté du `arme_legere`
  (Bâton), le barbare a monté Bouclier + Casque (défense 2 → 4). Aucun refus
  abusif observé.

Également confirmés sains : combat (jets, parades, morts), portes révélant la
salle et ses monstres à l'ouverture, suivi « 1×/quête » des sorts, journal de
combat lisible, coopération inter-joueurs en temps réel (soins et buffs d'Aldric
sur Grom pendant le combat).

---

## 2. Bugs confirmés

### 2.1 Le bouclier s'affiche comme une arme équipée — CONFIRMÉ (lecture de code)
`/moi` range `arme_principale` **et** `arme_secondaire` dans `equipement.armes`
(`AuthController` l. 153-157), et `SacTab.vue` rend chaque entrée avec l'icône
`swords` et le libellé « Arme équipée ». Un bouclier — emplacement
`arme_secondaire` — apparaît donc comme une seconde arme.

Purement cosmétique : Grom a vérifié que la défense passait bien de 2 à 4 et
l'attaque restait à 3. Correctif : distinguer l'emplacement dans le payload
(ou dans le rendu) et choisir l'icône/le libellé en conséquence.

### 2.2 Les portes ne sont pas lisibles sur la carte de déplacement — CONFIRMÉ
Grom a passé ~6 tours à chercher une sortie et a conclu à une impasse. **Le groupe
n'était pas bloqué** : vérification serveur — 52 cases atteignables depuis Aldric,
et la porte `fermee` en (18,23) menant à la salle 1 inexplorée **figurait bien dans
le payload transmis aux joueurs**. Elle était donc à l'écran et accessible.

Le vrai constat est celui que Grom formule par ailleurs : « aucune porte n'est
visuellement distincte des tuiles de sac/allié — la case bleue que j'ai prise pour
une porte s'est avérée être la position d'Aldric ». C'est un défaut de **lisibilité**
qui produit exactement le symptôme d'un blocage. Même famille que le §2.16 du
verdict précédent : le joueur ne sait plus où aller.

### 2.3 Instabilité des index de boutons au marché — CONFIRMÉ (les deux agents)
Ajouter un article insère un stepper (`−` / quantité / `+`) qui **décale tous les
index suivants**. Conséquences vécues : Grom a ajouté par erreur une Armure de
plates à 850 or, puis coché la vente de son épée de départ ; Aldric a ajouté deux
Trousses à outils et une potion non voulues. Les deux s'en sont sortis en relisant
les boutons, un joueur humain qui clique vite se ferait piéger.

C'est un artefact de pilotage par index, mais il révèle un vrai problème : la ligne
d'article **change de structure** quand on l'active, au lieu de garder un gabarit
stable.

---

## 3. Non reproduits — à ne pas corriger en l'état

### 3.1 « Équiper une arme désarme les deux » — NON REPRODUIT
Aldric le donne comme reproductible ×2. **Faux côté moteur** : rejoué sur la partie
live (Bâton équipé + Dague au sac → équiper la Dague) → `Dague:arme_principale`,
`Bâton:sac`, attaque recalculée. L'auto-swap est par ailleurs couvert par
`EquipementTest` (« auto-swap : équiper une seconde arme remet la première au sac »),
vert.

Hypothèse la plus probable : un mis-clic dû à **2.3** (les boutons « Équiper » /
« Déséquiper » se réordonnent quand l'état change). À reproduire via l'UI avant
toute correction.

### 3.2 « Fouiller — trésor reste proposé après fouille » — PRÉDICAT CORRECT
Signalé 3 fois par chacun. Vérifié : `MenuMoteur::salleFouillableTresor` lit bien
`tresorsFouilles()`, et une régénération à l'instant sur la partie live **retire**
`fouiller_tresor` pour une salle déjà fouillée. Les menus sont régénérés pour
**tous** les héros après chaque action (`ChoixController` l. 144), en **65-117 ms**
mesurés sur `queue-jeu`.

Le moteur rejette proprement (422, aucun tour perdu). Reste à expliquer pourquoi le
client a affiché l'option : refus de rafraîchir côté manette, ou confusion de salle
entre les deux héros. **Non élucidé** — nécessite une repro ciblée.

### 3.3 « Déplacer et attaquer sont mutuellement exclusifs » — CONTREDIT PAR LE CODE
`MenuMoteur` l. 221-223 : `aDeplace` et `aAgi` sont deux drapeaux **indépendants**,
un déplacement ne consomme pas le créneau d'action. Le symptôme décrit (arriver au
contact puis n'avoir aucune option d'attaque) relève vraisemblablement du même
mystère de rafraîchissement que 3.2.

### 3.4 « L'achat d'objets non maîtrisés est autorisé » — PAR CONCEPTION
Signalé comme friction par Grom. C'est le comportement voulu : la bourse est
commune et le don existe. Décision confirmée le 2026-07-30. Rien à faire.

---

## 4. Question de conception ouverte

### 4.1 Le magicien ne peut pas attaquer dans un couloir
Aldric, sur ~8 tours de combat : **aucun sort offensif lançable**, message constant
« Aucune cible en vue — un mur ou un allié bloque ta ligne de vue. » Le barbare
devant lui dans un couloir d'une case coupe toute ligne de tir.

Le moteur est cohérent (un allié bloque la ligne de vue), mais le résultat de jeu
l'est moins : le rôle « rester en retrait et lancer des sorts » devient injouable
dès que la carte est étroite, et l'assembleur produit beaucoup de couloirs à une
voie. À 2 joueurs, le magicien a passé le combat à soigner — utile, mais subi.

Pistes, à trancher : sorts à trajectoire non bloquée par les alliés (canon
HeroQuest : la ligne de vue des sorts ignore les héros) · couloirs à 2 cases ·
ou accepter et documenter que le magicien est un soutien en couloir.

### 4.2 « Traverser la Pierre » sans sélecteur de case
Aldric : le message « Choisis d'abord la case où ressortir » s'affiche mais aucune
carte n'apparaît. Testé 2 fois, sort non consommé. **À reproduire** — la même classe
de bug (sheet de ciblage manquant) avait été corrigée pour le déplacement.

---

## 5. Frictions mineures relevées

- Aucune description ni infobulle sur les objets de l'étal : impossible de savoir
  ce que fait une pièce avant de l'acheter.
- Le libellé de la fouille varie d'un tour à l'autre (« Fouiller — trésor »,
  « Fouiller la salle à la recherche d'un trésor »…) : normal, l'IA habille les
  menus, mais gêne la recherche d'un bouton connu.
- La feuille de ciblage sans cible ennemie n'a **aucun bouton Annuler** — il faut
  taper l'overlay, ce que rien n'indique.
- Narration asynchrone parfois décalée d'une ou deux actions, affichée par-dessus
  l'écran d'une action différente.
- Le total projeté du groupe bouge tout seul quand l'autre joueur remplit son
  panier — cohérent en multijoueur, déroutant sans explication.
