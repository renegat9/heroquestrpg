---
name: porter-une-carte-officielle
description: >-
  Utiliser quand on PORTE du matériel officiel dans le jeu : une carte
  d'équipement, de potion, d'artefact, de sort, de sort de Dread, ou un bloc de
  stats de monstre tiré d'un livret Hasbro / d'une extension. Chaîne complète :
  source → §doc 16/18 → registre `config/cartes.php` → lecteur moteur → test
  DANS LES DEUX SENS → seeder → migration si un élément SORT du catalogue.
  Déclencheurs : « porte cette carte », « voici les photos des cartes »,
  « intègre le sort/l'artefact/l'arme X », « cette carte n'est pas encore
  portée », « ce monstre vient de l'extension Y », « on retire tel objet ».
---

# Porter une carte officielle

**La règle du projet** : rien du catalogue n'est inventé. Une pièce sans carte
sort du jeu ; une carte portée à moitié est une règle promise au joueur et jamais
tenue. Le portage se juge sur un critère unique : **la carte fait-elle en jeu ce
que son texte dit ?**

## Pourquoi le registre existe

**The card registry is TESTED, not declared** (`config/cartes.php`, 61 cards across both decks). `CartesSourcesTest` checks it against the catalogue **both ways**: every card marked ported must exist in the DB, and no weapon, armour or artefact may exist without a card — two named exceptions, the tool kit (booklet, LR p. 19) and the healing vial (treasure deck). So "the catalogue comes from the cards" is a property, not a documentation claim. The same registry feeds `GET /api/guide`, hence the **/guide → "Cartes sources" tab**: players see where each piece comes from *and* the 26 board cards that have no mechanic yet, each with the exact thing it needs. When you port one, move it from `manque` to `objet` in the config — the test then forces the seeder to follow. Reference docs to keep in step: `reference/16_armurerie.md` §2.2/§9.1 (card by card), `04_market.md` (catalogue tables), `01_personnages.md` (slots + mastery tags), `docs/contrat-api.md` (/moi payload, /api/guide). `docs/plan-*.md`, `docs/verdict-*.md` and `docs/correctifs-restants.md` are **dated records** — they carry a banner saying so and are deliberately not updated.

## Procédure

### 1. La source, et rien d'autre
- Transcrire la carte **dans le doc de référence** avant de coder :
  `reference/16_armurerie.md` (§2.1bis équipement/potions, §3bis sorts, §9.1
  artefacts) ou `reference/18_extensions.md` (créatures et traits des boîtes).
- Les livrets **priment** sur les paquets fan quand les deux se contredisent
  (l'Ice Gremlin a 3 Body au livret, 2 sur la carte Sjeng : le livret gagne).
- Rien à sourcer → `⚠ non trouvé`, jamais une valeur devinée.

### 2. Le registre — `config/cartes.php`
Sections : `equipement` · `potions` · `artefacts` · `parchemins` · `dread`.
- Carte **portée** → elle nomme l'`objet` (ou le `sort` pour un parchemin).
- Carte **écartée** → elle reste inscrite avec `manque`, **la mécanique qui lui
  manque nommée**. Une carte non portée est une dette nommée, pas un oubli.
- `GET /api/guide` expose les deux : le joueur voit ce qui existe au plateau et
  ne tourne pas encore ici.

### 3. Le lecteur — avant la donnée
Un mot-clé neuf ne se seede pas avant d'avoir sa branche moteur et son test.
→ skill `ajouter-mecanique-moteur`.
Beaucoup de cartes ne demandent **aucune** mécanique neuve : chercher d'abord la
couture existante (`activable` + `charges`/`frequence`, `degats_fixes`,
`des_attaque_contre`, `App\Partie\Rayon`, `MoteurReactions`…).

### 4. Le test — dans les deux sens
| Domaine | Test |
|---|---|
| Équipement / potions / artefacts | `tests/Feature/Partie/CartesSourcesTest.php` |
| Conformité de l'armurerie | `ArmurerieConformeTest.php` (liste « hors source » — doit rester **vide**) |
| Sorts de Dread + archétypes | `SortsDreadSourcesTest.php` |
| Bestiaire (gel + divergences nommées) | `BestiaireSourceTest.php` |
Le test doit refuser **les deux dérives** : une carte déclarée portée absente du
catalogue, ET un objet du catalogue sans carte.

### 5. Le seeder
`ObjetSeeder` · `SortSeeder` · `SortDreadSeeder` · `MonstreSeeder` (voir la skill
`ajouter-element-de-jeu` pour la checklist transverse image/son/front).

### 6. ⚠ Si un élément SORT du catalogue
Tous les seeders écrivent en `updateOrCreate` et **ne purgent jamais** : retirer
la ligne du seeder ne supprime rien en base. Il faut **une migration** qui
supprime la ligne *et ses lignes d'inventaire* — un artefact retiré du catalogue
mais laissé dans un sac est une ligne ni équipable, ni vendable, ni affichable.
Précédents : les 5 artefacts sans carte (2026-09-03), le Trait de Chaos
(2026-09-04), les 12 pièces du paquet fan (2026-08-15).

#### 6bis. ⚠ Une carte de départ montre un TOTAL ÉQUIPÉ, pas une stat de base

La carte de classe du Chevalier annonce **3 dés de défense**. Ce n'est pas sa défense de
base : c'est **2 + 1 pour le bouclier qu'il porte déjà**. Recopier le chiffre de la carte
dans `classes_heros.des_defense` le fait compter **deux fois** dès que le bouclier est
équipé — le héros défendait à 4 dés en partie réelle (René, 2026-09-11 : « la carte de
départ compte déjà le bouclier dans la fiche »).

⚠ **La même faute avait déjà été corrigée pour l'ATTAQUE**, et son commentaire disait
« les quatre classes ont 2 dés de base, aucun double compte à corriger » — un commentaire
devenu **faux** sans que rien ne le signale, et qui a servi à écarter le soupçon la
seconde fois. Avant de porter une stat de carte de départ, se demander **ce que le
personnage tient en main sur l'illustration**, et corriger le commentaire voisin quand il
ment.

⚠ Et ce n'est **pas** une divergence assumée : c'est une erreur de portage. La distinction
compte — une divergence se déclare au §7 avec sa raison, une erreur se corrige.

## 7. Divergence assumée
Une valeur qu'on change sciemment (la défense de l'Ombre du Dread) se déclare
dans la **liste de divergences nommées** du test, avec sa raison. Jamais un écart
silencieux dans la table de gel.

## Lore
`docs/regles/equipement-et-armurerie.md` · `artefacts.md` · `sorts-heros.md` ·
`sorts-dread.md` · `bestiaire-et-rencontres.md`

## Definition of done
- [ ] Carte transcrite dans `reference/16_armurerie.md` ou `18_extensions.md`
- [ ] Entrée dans `config/cartes.php` (portée → `objet`/`sort` ; sinon `manque` nommé)
- [ ] Lecteur existant réutilisé, ou mécanique câblée + testée **avant** la donnée
- [ ] Test deux sens vert, liste « hors source » vide
- [ ] Seeder à jour + **migration** si un élément sort du catalogue
- [ ] Divergence volontaire déclarée avec sa raison
- [ ] Stat de carte de DÉPART lue comme un total équipé, pas comme une base
- [ ] La carte fait en jeu ce que son texte dit — vérifié, pas supposé
