# Plan — ciblage en deux étapes & armurerie 2021

Deux chantiers demandés ensemble, de coût très inégal. Le A est petit et
autonome. Le B est bloqué sur une donnée que je n'ai pas pu retrouver, et son
vrai poids n'est pas le catalogue mais le **moteur manquant** derrière les
pouvoirs.

---

## Chantier A — ciblage en deux étapes

### Ce qui existe déjà (vérifié)

Le gros du travail est **déjà fait**, des deux côtés :

- **Client** — `ManetteView.choisirOption()` (≈ 415) ouvre `CibleSheet` pour
  *n'importe quelle* option portant `parametres.cibles`, et traite déjà
  `option.type === 'attaque'` comme offensive (confirmation de tir ami).
  `ciblesVersListe()` (`store/game.js` 626) normalise la liste.
- **Serveur** — `ResolveurTour::resoudreAttaque` (494) lit déjà
  `$option['cible_id'] ?? $parametres['cible_id']`.
- **Fusion IA** — `GenererMenu::fusionner` (183) construit sa liste blanche par
  `array_column($moteur['options'], 'id')` : dynamique, rien à y toucher.

Il ne reste donc qu'un point : **`MenuMoteur` émet N options au lieu d'une**
(boucle ≈ 322-345, `attaquer_{id}` / `lancer_{id}`).

### Le piège à ne pas rater

Aujourd'hui **le menu EST la liste blanche** : une option par cible légale, et
`ChoixController` (76) valide l'`option_id` contre le dernier menu proposé. En
repliant les options, la légalité de la *cible* n'est plus portée par
l'identifiant — `resoudreAttaque` ne vérifie que « monstre actif et révélé ».

⚠ **Sans garde ajoutée, un joueur pourrait attaquer n'importe quel monstre de la
quête**, hors portée et hors ligne de vue. La liste blanche doit migrer vers
`parametres.cibles`, et le résolveur doit vérifier l'appartenance. C'est l'étape
la plus importante du chantier, pas un détail.

### Étapes

1. **`MenuMoteur`** — remplacer la boucle par deux options agrégées :
   - `attaquer` → `type: 'attaque'`, `parametres.cibles = [{id, type: 'monstre',
     nom, nom_base, ami: false, distance: bool}]` ;
   - `lancer` → seulement si arme jetable et cibles à distance ;
     `parametres: {cibles, lancer: true}`, libellé « Lancer {arme} (perdue) ».

   Le rappel du nom de catalogue (habillage IA) et le suffixe « (à distance) »
   descendent dans l'entrée de cible, plus dans le libellé.

2. **`ResolveurTour::resoudreAttaque`** — lire `lancer` depuis `parametres`
   *aussi*, et **rejeter (422) une `cible_id` absente de
   `$option['parametres']['cibles']`**. Un test dédié pour cette borne.

3. **Retour à la liste d'actions** — c'est la demande explicite. `CibleSheet` ne
   se referme aujourd'hui qu'en tapant l'overlay : peu découvrable. Ajouter un
   bouton « ← Retour aux actions » (le `emit('close')` existe déjà).

4. **`CibleSheet`** — afficher le badge « à distance » par ligne de cible.

5. **Tests** — 14 fichiers postent `attaquer_{id}` en dur → `option_id:
   'attaquer'` + `parametres: {cible_id: N}`.

6. **`docs/contrat-api.md`** — forme de l'option.

**Coût** : 1 fichier serveur, 2 fichiers client, 14 fichiers de test. Contenu.

---

## Chantier B — armurerie 2021

### Blocage : il me faut ta liste

J'ai cherché la liste officielle 2021 : je ne suis tombé que sur des annonces de
vente, aucune source fiable donnant coût **et** pouvoir carte par carte. Je
préfère te le dire plutôt que de semer un catalogue aux valeurs devinées.

**Étape B0 — tu me donnes la liste** (ou tu confirmes/corriges le tableau
ci-dessous, qui est notre catalogue actuel). Tu me l'avais proposé ; j'aurais dû
dire oui tout de suite.

### État réel du catalogue

| Objet | Prix | Effet déclaré | Support moteur |
|---|---|---|---|
| Dague | 25 | 1 dé, jetable | ✅ |
| Bâton | 100 | 1 dé, **diagonale** | ❌ décoratif |
| Épée courte | 150 | 2 dés | ✅ |
| Hachette | 200 | 2 dés, jetable | ✅ |
| Lance | 250 | 2 dés, **diagonale + second rang** | ❌ décoratif ×2 |
| Épée large | 350 | 3 dés | ✅ |
| Arbalète | 350 | 3 dés, distance, `ligne_de_vue`, non adjacent | ✅ sauf `ligne_de_vue` (mort) |
| Hache de bataille | 450 | 4 dés, 2 mains, **diagonale** | ⚠ partiel |
| Casque | 125 | +1 déf | ✅ |
| Bouclier | 150 | +1 déf, incompatible 2 mains | ✅ |
| Cotte de mailles | 500 | +1 déf | ✅ |
| Armure de plates | 850 | +2 déf, **sans d6 de déplacement** | ❌ décoratif |

Comptage des lecteurs hors seeders (`grep app/`) :
`des_attaque` 16 · `des_defense` 14 · `portee` 9 · `incompatible_deux_mains` 2 ·
`jetable` 2 · `deux_mains` 1 · `inutilisable_adjacent` 1 —
**`attaque_diagonale`, `attaque_second_rang`, `ligne_de_vue`,
`deplacement_sans_d6` : 0 lecteur.**

### Lots, dans l'ordre

- **B1 — `deplacement_sans_d6` (petit, à faire en premier).** Le moteur le
  supporte déjà : `Deplacement::calculer(int $base, bool $armureDePlates =
  false)` et `ResultatDeplacement::$armureDePlates`. Mais **les deux appelants**
  (`MenuMoteur` 137, `ResolveurTour` 345) appellent `calculer($base)` : le
  malus n'a jamais tiré. Le commentaire de `MenuMoteur` 249 (« null si Armure de
  plates ») décrit un comportement inexistant. Deux lignes, et l'Armure de plates
  devient enfin un arbitrage au lieu d'un achat évident.

- **B2 — supprimer `ligne_de_vue`.** Redondant : `MenuMoteur` appelle
  `Grille::ligneDeVue` pour toute arme à distance, quelle que soit la clé. Même
  traitement que `perdue_au_lancer` la semaine dernière — une clé qui ment est
  pire qu'une clé absente.

- **B3 — `attaque_diagonale` (moyen).** L'adjacence est orthogonale partout
  (`MenuMoteur` 56, `ResolveurTour` 2055, même test des 4 voisins). Il faut un
  prédicat d'adjacence *par arme*, partagé par la liste de cibles et la
  résolution. Décision de règle à prendre : les monstres, eux, restent
  orthogonaux (sinon on double leur menace sans contrepartie).

- **B4 — `attaque_second_rang` (moyen).** Lance : frapper à 2 cases en ligne
  droite **si un héros occupe la case intermédiaire**. Nouveau prédicat sur la
  grille, plus une règle à trancher (le héros de devant gêne-t-il ? le plateau
  dit non).

- **B5 — restrictions de classe.** Le « may not be used by the Wizard » du
  plateau se branche sur les `tag_equipement` / `tags_equipement` existants :
  aucun code neuf, seulement du semis.

- **B6 — catalogue + marché**, une fois B1-B5 en place. Jamais avant : semer un
  objet dont le pouvoir ne fait rien, c'est recréer exactement le problème qu'on
  vient de corriger avec `jetable`.

### Détail annexe

La base contient encore **« Hache à main » ET « Hachette »** : le seeder est
indexé par nom, le renommage a donc ajouté une ligne au lieu d'en modifier une.
Aucun inventaire ne la référence (0), et il n'y a ni personnage ni quête en base
— un `migrate:fresh --seed` la nettoie sans coût.

---

## Recommandation

Faire **A** en entier, puis **B1 + B2** (petits, et ils réparent des mensonges
du catalogue), et n'attaquer **B3/B4** qu'avec ta liste 2021 en main — leur
intérêt dépend des armes qu'on gardera.
