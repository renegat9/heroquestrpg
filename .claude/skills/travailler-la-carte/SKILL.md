---
name: travailler-la-carte
description: >-
  Utiliser pour tout travail sur la CARTE du donjon : génération (salles,
  couloirs, seuils, boucles, passage secret), une couche de grille (pièges,
  leviers, mobilier, épreuves), les portes, le brouillard, la ligne de vue, les
  symboles et la légende. Déclencheurs : « la carte », « le donjon », « une
  salle / un couloir / une porte », « le mobilier bloque », « le brouillard »,
  « place un levier / une épreuve / un piège », « les symboles de la carte »,
  « AssembleurCarte », « DungeonGrid », « ligne de vue ».
---

# Travailler la carte du donjon

Deux invariants durs, tous deux verrouillés par des tests, et tous deux payés en
partie réelle : **la connectivité** (une pièce mal posée coupe l'accès à un
coffre ou à un levier et fige le groupe) et **le brouillard** (publier plus que
ce qui est découvert, c'est contourner le brouillard par la porte de derrière).

## Anatomie

- `app/Partie/AssembleurCarte.php` — arbre de salles, couloirs, puis les couches :
  `placerPieges()` · `placerLeviers()` · `placerMobilier()` · `placerEpreuves()`.
  **Toute couche neuve suit ce patron exact** : pas de nouveau type de case, pas
  de migration de tuile, une clé dans `cartes.grille[...]`.
- `app/Partie/FabriqueGrille.php::pour()` — **la seule** boucle de mobilier du
  moteur. Un second chemin ferait diverger déplacement, ciblage et ligne de vue.
- `app/Partie/Grille.php` — **trois** ensembles distincts : `$occupees`
  (figures seules, le seul que `figuresBloquent` consulte), `$obstacles`,
  `$opaques`. Le mobilier est du décor, pas une figure.
- `app/Partie/Salles.php::indexDe()` — **le** point de passage pour « quelle
  salle contient cette case ? ». La question était posée à six endroits, chacun
  avec sa boucle : un `<=` pour `<` déplace une case-frontière selon qui demande.
- `app/Partie/MoteurPortes.php` — `ouvrir()` n'ouvre **que le seuil poussé**,
  jamais l'autre bout de la jonction.
- `resources/js/components/carte/` — `DungeonGrid.vue` (rendu, table **et**
  manette), `symboles.js` (icônes, lu par le rendu **et** la légende),
  `LegendeCarte.vue`, `ApercuSalle.vue`.

## Ajouter une couche — checklist

1. **Placement** dans `AssembleurCarte`, sur le patron de `placerMobilier()` :
   jamais dans la salle 0, jamais sur un piège, et **re-vérifier la connectivité
   depuis UN seul seuil** (un BFS multi-sources rate les poches : chaque seuil
   est « atteint » du seul fait d'être sa propre source). Abandonner la pose
   plutôt que de la forcer.
2. **Publication** dans `EtatGroupe` — ⚠ **filtrée par le brouillard**. Le piège
   à connaître : les leviers ne portent pas d'index de salle (`{x, y, levier_id}`),
   il faut le **dériver** des coordonnées ; un levier de **couloir** s'affiche
   toujours (un couloir n'a pas d'index et n'est jamais « découvert »).
   ⚠ Une difficulté publiée passe par `DifficulteBody::plafonnee()` — publier la
   valeur brute afficherait « difficulté 3 » sur une carte dont le menu offre 2.
3. **Rendu** : une **silhouette par famille** (figures rondes, pièges carrés,
   épreuves losange or, leviers octogone bleu, mobilier bloc plein). Déclarer
   l'icône dans `symboles.js` — jamais dans un composant seul.
4. **Illustrations dans la LÉGENDE, jamais sur la carte** : à 22-38 px une image
   devient une bouillie et efface la silhouette, qui est ce que le joueur lit.
5. **Test** : `SymbolesCarteTest` confronte `symboles.js` au catalogue **dans les
   deux sens** et vérifie que les deux composants importent le même fichier ;
   `CouloirsTest` verrouille les invariants de génération ;
   `LeviersVisiblesTest` / `BrouillardTest` la publication.

## Pièges déjà payés
- **Une couche publiée nulle part** : `leviers` n'a jamais été dessiné sur aucune
  des deux cartes — gratuit tant qu'aucun levier n'était posé, un donjon
  verrouillé le jour où il y en a eu un.
- **Un seuil fait UNE case** (le plateau n'a que des portes d'une case). Le tank
  qui bouche la ligne de vue se règle par l'**attaque diagonale**, pas en
  élargissant le seuil.
- **Le mobilier adossé au mur ne se déduit PAS de `bloque_vue`** : un pilier
  bloque la vue et se tient au **milieu** d'une salle. `bloque_mouvement`,
  `bloque_vue` et `adosse_au_mur` sont trois faits indépendants → trois colonnes.
- **Les pièges ne se déclenchent que sous un héros** (`MoteurPieges::declencher()`
  prend un `Personnage`) : une créature sur un piège est une embuscade, pas un bug.
- **Toute ouverture de porte révèle la salle** — les trois chemins
  (`ouvrir_porte`, levier, gardiens vaincus) passent par `revelerDerriere()`.

## Lore
`docs/regles/carte-donjon.md` · `epreuves-et-attributs.md` ·
`exploration-et-fouille.md`

## Definition of done
- [ ] Placement refusé proprement s'il casse la connectivité (jamais forcé)
- [ ] Publication filtrée par le brouillard, difficulté plafonnée
- [ ] Silhouette déclarée dans `symboles.js`, illustration en légende seulement
- [ ] `CouloirsTest` / `SymbolesCarteTest` / `BrouillardTest` verts
- [ ] Rendu vérifié aux **deux** échelles (table `1fr`, manette 38 px)
