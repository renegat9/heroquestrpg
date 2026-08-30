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
