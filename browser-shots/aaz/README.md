# Test « de A à Z » — création de compte jusqu'à la fin de la première quête

Complément du harnais `browser-shots/campagne/`, qui commence une fois la
partie montée. Ici on part de **rien** : deux comptes, deux héros, une
campagne, un second joueur qui rejoint par code, puis la quête jouée jusqu'au
vote de sortie.

```bash
# 1. Création par les VRAIS écrans (Playwright) — rend le code du groupe
docker run --rm --network host -v "$PWD:/work" -w /work \
  -e PLAYWRIGHT_BROWSERS_PATH=/ms-playwright -e PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
  mcr.microsoft.com/playwright:v1.48.0-jammy \
  bash -c 'npm i playwright@1.48.0 --no-save --no-audit --no-fund --silent && node browser-shots/aaz/creation.mjs'

# 2. Ouvrir la table, marquer les joueurs prêts (voir campagne/preparer.sh),
#    puis jouer la quête sur les routes réelles
python3 browser-shots/aaz/jouer.py
```

## Ce que ça a trouvé

⚠ **Le menu offrait « Quitter le donjon » pendant que le vote qu'il venait
d'ouvrir attendait des bulletins** (2026-08-30) : le résolveur répondait 422
« Un vote est déjà en cours », dix-sept fois de suite. C'est l'anti-patron que
le projet traque partout — une option cliquable qui répond toujours non n'est
pas une option, c'est un piège. Corrigé, `MenuVoteOuvertTest` le verrouille.

## Ce qu'il vérifie, et pourquoi il le vérifie là

`jouer.py` rend un **rapport de conformité** : types d'option rencontrés,
options distinctes exercées, et refus du résolveur par type. L'invariant qu'il
éprouve est celui que le projet tient partout — *le menu ne doit jamais offrir
ce que le résolveur refusera*.

⚠ Le client n'envoie que **six clés** (`x`, `y`, `cible_id`, `cible_type`,
`sort_id`, `cle` — la dernière arrivée avec les sous-choix, cf. `ChoixController`) ; tout le reste — index d'épreuve, de meuble,
de levier — voyage dans l'option elle-même, que le serveur relit de son cache.
C'est ce qui rend le test concluant : une fois ces six clés correctes, **tout
422 restant accuse le menu**, pas le client.

⚠ Le contrôle « un vote ouvert retire les options de vote » se fait **dans la
branche du vote**, bulletins non encore déposés. Placé dans la boucle de menu,
il ne s'exécutait JAMAIS : dès qu'un vote est vu, la boucle vote et repart sans
relire de menu. Un garde-fou inatteignable est pire qu'aucun — il rassure.

⚠ Deux refus ne sont PAS des non-conformités, et le pilote les distingue plutôt
que de crier au loup :
- *« Choisis d'abord une case »* — « Se déplacer » est légitimement offerte même
  sans case accessible ; la manette ouvre alors sa feuille avec « tu es bloqué ».
- *« Destination inaccessible »* — le pilote calcule sur un instantané, puis la
  phase des monstres se joue dans la requête d'un AUTRE joueur. Il relit donc
  l'état avant d'accuser : si la case est prise entre-temps, c'est sa course à
  lui. Mesuré : 3 occurrences sur une quête, 0 sur les deux suivantes.

## `navigation.mjs` — les trois niveaux et leurs retours

Depuis le 2026-09-01 le menu est à trois niveaux (action → liste → cibles), et
c'est la seule partie que les tests Pest ne peuvent pas prouver : elle vit dans
la pile de feuilles de la manette. `navigation.mjs` la descend et la remonte —
une liste de sorts groupée par élément, un ciblage, puis les retours, en
vérifiant que **chaque bouton nomme sa destination** et qu'un tap sur le fond
ne dépile que d'**un cran**.

```bash
docker run --rm --network host -v "$PWD:/work" -w /work \
  -e PLAYWRIGHT_BROWSERS_PATH=/ms-playwright -e PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
  -e ID="<identifiant du joueur>" mcr.microsoft.com/playwright:v1.48.0-jammy \
  bash -c 'npm i playwright@1.48.0 --no-save --no-audit --no-fund --silent && node browser-shots/aaz/navigation.mjs'
```

⚠ Le menu est mis en CACHE : un objet donné au héros après la génération du
menu n'y figure pas. Regénérer (`GenererMenu::dispatchSync`) avant de vérifier
une liste qu'on vient de garnir, sinon on croit à un défaut qui n'existe pas.

## Deux choses à savoir avant de s'en servir

⚠ **Un lanceur de sorts ne se crée pas sans ses éléments.** Le bouton « Créer
le personnage » reste désactivé tant que le magicien n'a pas choisi ses **3
éléments de magie**. Ce n'est pas un défaut — le libellé le dit —, mais un
script qui l'ignore boucle trente secondes sur un bouton grisé.

⚠ **Un pilote sans cap tourne en rond.** La première version choisissait une
case adjacente libre : 381 actions jouées, pas une porte ouverte. `jouer.py`
vise donc la porte fermée la plus proche par un BFS qui ne traverse pas les
portes closes — sans quoi le test ne voit jamais ni combat, ni fouille, ni fin
de quête.

⚠ Et il faut savoir **voter** : « Quitter le donjon » ouvre un vote sans délai
ni bulletin par défaut, et le proposeur ne vote pas d'office. Un pilote muet
laisse le groupe dans un donjon vide, à un bulletin près.
