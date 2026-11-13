# Plan — les trois derniers parchemins de The Frozen Horror (2026-11-13)

> Document DATÉ : un plan, pas une règle en vigueur. Les règles retenues iront dans `docs/contrat-api.md` et
> `docs/regles/` au fil des lots.

Reste à porter : **Morsure du Froid** (*Spell Scroll — Chill*), **Tempête de Glace** (*Spell Scroll — Ice Storm*),
**Pont de Glace** (*Spell Scroll — Ice Bridge*) — `config/cartes.php`, entrées `manque`. Textes officiels
(`reference/18_extensions.md:799-810`) :

- **Chill** — « 1 Body Point of damage to any monster adjacent to the spellcaster (though not diagonally adjacent).
  The victim cannot defend against the attack. »
- **Ice Storm** — « an area 2 squares wide by 2 squares long. Each monster and hero in that area is attacked
  separately by the spellcaster with 3 combat dice. There is no chance to defend. Cannot be used in corridors. »
- **Ice Bridge** — « a permanent bridge of Magic Ice tiles that enables heroes to cross over any pit, trap, chasm,
  crevasse, or icy square. »

## Ce qui existe, ce qui manque (relevé du 2026-11-13)

| Besoin | Existe | Manque |
|---|---|---|
| Géométrie de zone (4 cases orthogonales, carré 2×2, « pas en couloir ») | Côté Dread : `MoteurDread::casesDeZone()`, `meilleurCarre()`, `hors_couloir` ; couloir = `Salles::indexDe() === null` | Privé à `MoteurDread`, pensé pour un lanceur MONSTRE qui vise des héros |
| Frapper chaque figure d'une zone | Dread : `sortDreadDegats()` (dégâts fixes, ou 3 dés sans défense par figure) ; héros : `projeterRayon()` (montant fixe par figure) | Aucune résolution « dés par figure » pour un lanceur héros ; `CIBLE_MONSTRES_ZONE` déclaré NON_IMPLEMENTES |
| Choisir une case/zone sur la manette | `DeplacementSheet` éclaire une liste de cases publiée par le serveur (déplacement, s'écarter) | Aucune option « choisis une zone » ; `ciblerOption()` n'envoie que `cible_id`/`cle` |
| Poser quelque chose en pleine quête | `MoteurMobilier::poserMurMagique()` (mobilier), mur de glace du Dread (`grille['glace']`) — tous deux des OBSTACLES | Aucune écriture de `grille['terrain']` après l'assemblage ; une tuile « Glace magique » attend déjà le pont (`support_sort`, NON_IMPLEMENTES) |

**Défauts existants à corriger au passage** (relevé §H) : un sort de héros à `zone` (hors `salle_du_lanceur`) est
OFFERT sans cible puis REFUSÉ « Cible requise » (règle dure violée) ; `monstresVisesParSort()` ne voit pas une zone,
donc Dispel / Mirror Magic / Break ne réagiraient pas ; `MotsClesSort` ne déclare pas son vocabulaire de zone
(`zone`, `degats_fixes`, `des_degats`, `hors_couloir`…) que `MotsClesSortDread` déclare ; le Chant de guérison
affiche « toute la salle » mais soigne les héros en vue.

## Lot 0 — Décisions de René (2026-11-13)

1. **Morsure du Froid** : TOUS les monstres des 4 cases orthogonales.
2. **Tempête de Glace** : le joueur choisit le carré ; il tient entièrement dans la salle du lanceur ; il suffit que
   le lanceur VOIE AU MOINS UNE des 4 cases ; les héros du carré, lanceur compris, sont frappés ; le Brassard de Glace
   réduit aussi la Tempête lancée par un héros.
3. **Pont de Glace** : le livret montre les tuiles « Magic Ice » (F5815 p. 4) comme des tuiles d'UNE case. Le pont
   est fait d'autant de tuiles qu'il en faut pour couvrir l'ÉLÉMENT choisi (une fosse : 1 ; une fosse longue : 2…).
   Il s'applique à tout élément qui n'est PAS du mobilier et pour lequel un pont a du sens (liste fermée proposée
   ci-dessous). Portée : la ligne de vue. Les monstres peuvent l'emprunter.
   Proposition de liste fermée (veto possible) — pièges de sol posés sur une case : Fosse, Fosse des ténèbres, Fosse
   longue, Piège à lances, Chute de blocs (non tombée), Lame balançoire, Mare d'acide, Piège de lianes, Piège
   d'embrasement, Piège de Boule de Flammes, Piège / Trappe de téléportation ; terrains : Glace glissante, Glissière de
   glace, Rivière gelée, Sables mouvants, Sol meuble, Mare. Exclus : le mobilier, les pièges de fouille (coffre,
   aiguille, fiole : pas sur une case), une Chute de blocs tombée (un bloc de pierre, pas un trou), les murs.
4. **Difficulté de lecture** : 2 pour les trois parchemins.

## Lot 1 — Le socle : les zones des sorts de HÉROS

Un seul mécanisme, deux clients (Dread et héros) — « une règle, un point de passage ».

- **Géométrie partagée** : extraire de `MoteurDread` un service `App\Partie\ZonesDeSort` (cases de contact
  orthogonales d'une figure, carré 2×2 d'un coin, « toute la zone dans une salle », figures sur des cases). Le Dread
  le lit à la place de son code privé (aucun changement de comportement, ses tests le figent).
- **Vocabulaire** : déclarer dans `MotsClesSort` les clés de zone des sorts de héros avec leur lecteur
  (`zone: contact | carre_2x2`, `degats_fixes`, `des_degats`, `defense_applicable`, `hors_couloir`, `type_degat`) ;
  retirer `CIBLE_MONSTRES_ZONE` de NON_IMPLEMENTES. Tests du registre dans les deux sens.
- **Résolution** : `ResolveurTour::sortDeZone()` — pour chaque figure de la zone : dégâts fixes OU N dés sans
  défense, `infligerAMonstre` / `infligerAHeros` (tir ami), immunités (`absorbeDegat`, Brassard). Payload unifié
  `{resultats: [...]}` comme `sort_dread`, journalisé, phrase au fil.
- **Réactions du MJ** : `monstresVisesParSort()` rend les monstres de la zone → `ReactionsMj::auSortDeHeros()`
  (Dispel, Dissipation, Break, Mirror Magic) les voit.
- **Corriger** le défaut « zone offerte sans cible puis refusée » et le libellé du Chant de guérison.

## Lot 2 — Morsure du Froid

- Sort de parchemin `zone: contact`, `degats_fixes: 1`, `defense_applicable: false`, `type_degat: froid`.
- **Offert seulement** si au moins un monstre est sur une des 4 cases orthogonales (le menu ne propose pas un sort
  qui ne touchera rien) ; pas de sous-choix de cible (la zone EST la cible).
- Registre : `manque` → `sort` ; graphies unifiées (« Morsure du Froid » / « Morsure de Froid » du Dread).
- Tests : tous les monstres orthogonaux perdent 1 PV, jamais un diagonal ; aucune défense ; parchemin consommé ;
  immunités au froid respectées.

## Lot 3 — Tempête de Glace

- Sort de parchemin `zone: carre_2x2`, `des_degats: 3`, `defense_applicable: false`, `hors_couloir: true`.
- **Le serveur publie les carrés possibles** (selon les décisions du lot 0) : `parametres.zones: [{cle, cases:
  [4×{x,y}], touches: [noms]}]` — la liste blanche que le résolveur relit (`entreeChoisie`).
- **Manette** : choisir un carré. D'abord une liste lisible (« Carré en 7,4 — touche Gobelin, Orque, Grombar ») dans
  la feuille de choix existante ; puis, si utile, un mode « zone » de `DeplacementSheet` qui éclaire les 4 cases au
  tap. Contrat d'abord.
- Lanceur en couloir → parchemin non offert. Brassard −1 selon la décision.
- Tests : chaque figure du carré attaquée séparément à 3 dés sans défense ; tir ami sur les héros du carré ;
  carré hors liste refusé ; non offert en couloir ; Dispel du MJ peut l'annuler.

## Lot 4 — Pont de Glace (le plus gros)

Une mécanique neuve : **poser du terrain en pleine quête**.

- **Couche** : la tuile « Glace magique » (déjà au catalogue, `support_sort: Pont de Glace`) posée dans
  `grille['terrain']` par un point de passage neuf `MoteurTerrain::poser()`, avec un mot-clé `pont` dans
  `MotsClesTerrain` (retirer `support_sort` de NON_IMPLEMENTES).
- **Lecteurs du mot-clé `pont`** (un seul prédicat, `Grille::estPont()` ou équivalent, lu partout) :
  `MoteurPieges::indexPiegeArme()` (un piège sous un pont ne se déclenche pas), le franchissement de fosse et les
  sauts du menu (plus de jet), `tronquerSurGlace()` (pas de glissade), `DeplacementHeros` (trajet et
  `destinations`), `FabriqueGrille` (coût/obstacle), et selon la décision, le déplacement des monstres.
- **Ciblage** : le serveur publie les cases éligibles (pièges de sol armés connus, glace glissante… selon le lot 0)
  à la portée décidée → feuille de cases (`DeplacementSheet` en mode cible).
- **Publication et rendu** : `EtatGroupe::terrain()` publie le pont ; symbole sur la carte (table et manette).
- Tests : franchir une fosse sous un pont sans jet ni chute ; une Chute de blocs non tombée ne tombe pas ; la glace
  glissante ne fait plus glisser ; permanent jusqu'à la fin de la quête ; reprise (snapshot) conservée.

## Lot 5 — Finitions et validation en jeu

- Illustrations des trois parchemins (+ jumeaux webp), guide (onglet Cartes sources : plus aucune carte grisée de la
  boîte), docs de référence et règles.
- **Campagne d'agents** sur le thème `horreur_des_glaces` (harnais `browser-shots/campagne/`) avec un magicien qui
  porte les trois parchemins — et une scène préparée (monstres adjacents, fosse détectée), car un rôle ne se teste
  pas tout seul.

## Ordre et charge

Lot 0 (tes réponses) → Lot 1 (socle, le plus délicat : il touche aussi le Dread) → Lots 2 et 3 (rapides une fois le
socle posé, le 3 demande le choix de zone sur la manette) → Lot 4 (indépendant des lots 1-3, peut partir en
parallèle du lot 2 si ses décisions sont prises) → Lot 5. Chaque lot : contrat d'abord, tests qui reproduisent,
suite complète, vérification en vrai, un commit.
