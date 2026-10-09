# Plan — Finaliser *Jungles of Delthrak* (exécution, 2026-10-09)

> ⚠ **Document DATÉ du 2026-10-09**. Il ordonne la fin du portage de la boîte ; les
> règles citées sont dans `docs/plan-delthrak.md` (lots A-I) et les textes dans
> `reference/18_extensions.md` § Jungles of Delthrak (dont « Artefacts TRANSCRITS »,
> livret p. 50) — source unique des valeurs, rien d'inventé. Livret F9907 :
> `~/heroquest-livrets/texte/F9907_en-us/`.

## Déjà fait

Thème `jungles_delthrak` actif ; les 9 monstres et leurs mots-clés (Spawn, Venomous,
Agile, Clever Tactician, Entangling Roots) ; Explorateur et Berserker ; **Gretzl**,
boss à 3 phases (chantier 2) ; mobilier attaquable et **Amas de cristal** (chantier 1) ;
objectif « détruire un élément de la carte » (Haut Autel, 2026-10-09).

## Décisions de René (2026-10-09)

| Sujet | Décision |
|---|---|
| Modes de mort (Q1) | **Aligner sur le mode Story** : on garde « tombé, jamais mort » et on ajoute ce qui manque — un héros tombé regagne **1 Body** si aucun monstre n'est actif et qu'au moins un autre héros est debout (livret p. 5) ; un lanceur de sorts qui tombe peut se soigner aussitôt avec un sort de soin disponible. Pas de mort permanente, pas de réglage. Heroic et Standard écartés (mort permanente refusée). |
| Campagne ramifiée (Q2) | **Écartée** par une phrase écrite ; l'idée d'un graphe de gabarits reste notée pour Spirit Queen's Torment. |
| Boss (Q3), mobilier (Q4), Basin (Q5) | Déjà tranchés et faits : Gretzl ; lecteur commun ; trois entrées distinctes. |

## Trois chantiers en parallèle

**A. Butin** — les six artefacts (*Emberwrought Diadem*, *Bracers of the Wild*,
*Fangwarden Armlet*, *The Sapphire Skull*, *Girdle of Might*, *Emerald Heart of
Delthrak*) et l'*Ancient Dwarven Relic* (livret p. 50) ; les potions de l'Alchimiste
(livret p. 2-3, à lire à l'image) ; l'*Armlet* appelle un Raptor allié joué par son
joueur, une fois par quête, dormant deux quêtes s'il meurt.

**B. Carte** — terrain gênant de la jungle (lot B) ; le Basin en trois meubles —
*Pool of Water*, *Crystal Cluster* (fait), *Bonfire* (lot D) ; *Cocoon* et *Grasping
Vine Trap* (lot E).

**C. Règles** — le mode Story (ci-dessus) ; **Gruulob**, second boss à phases du thème
(lot A, ses sorts déjà sourcés) ; l'ordre de tour choisi par les joueurs (lot I) s'il
reste mineur, sinon nommé et reporté ; la campagne ramifiée écartée par écrit.

## Fin

Suite Pest complète, seeders/migrations sur la vraie base après
`./image-tools/sauvegarder.sh`, workers redémarrés, guide et livret mis à jour,
commit et push.
