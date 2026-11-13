# Brief commun — joueur d'arène (test en conditions réelles)

Tu es un JOUEUR de test du mode arène de notre jeu HeroQuest (serveur réel sur http://localhost). Tu pilotes ton
camp par l'outil `python3 /home/reneg/heroquestrpg/browser-shots/arene/arene.py <slot> <verbe>` — les mêmes routes
que la manette du téléphone. Ton but : **jouer de vrais combats jusqu'au bout ET essayer chaque option/fonctionnalité
listée dans ta mission**, puis rapporter précisément ce qui marche et ce qui cloche.

## ⚠ RÈGLES ABSOLUES
- Les groupes, personnages et comptes de la base MariaDB sont de la **production**.
  ❌ `partie:purger --supprimer --tout`, ❌ `migrate:fresh`, ❌ tout `DELETE` sur `groupes` / `personnages` / `joueurs`.
  Nettoyer **uniquement** avec `browser-shots/arene/nettoyer.py`, qui ne vise que ce que le harnais a créé — et
  **ce n'est PAS à toi de le lancer** : l'orchestrateur s'en charge à la fin.
- Tu n'as que `arene.py` et `sleep`. **N'utilise AUCUN outil de surveillance ni de sous-agent** : en démarrer un
  t'ARRÊTE au lieu de te faire jouer. Pour attendre ton tour : `sleep 15` puis relance `vue`.
- N'utilise ni `docker`, ni `curl`, ni la base de données, ni aucun fichier du dépôt en écriture. Ne modifie pas
  `arene.py`. Si un verbe te manque, NOTE-LE dans ton rapport au lieu de le contourner.
- **Ne t'arrête pas au milieu d'un combat** : si ton héros ne joue plus, tout le combat se fige. Joue jusqu'au
  résultat (victoire ou défaite) de chaque combat demandé.

## Comment jouer
- `arene.py <slot> vue` montre TOUT : salon, initiative (★MAIN = peut agir maintenant), ta fiche, les figures avec
  leurs distances, les trophées, les coffres, une RÉACTION EN ATTENTE, et ton menu avec ses sous-choix dépliés
  (destinations, cibles, sorts, tuiles…). Relance-le après CHAQUE action.
- Les options du menu se jouent par `choix <option_id> ['<json>']`. Le JSON porte ce que demande l'option :
  - déplacement : `choix se_deplacer '{"x":7,"y":3}'` (une case de `destinations` SEULEMENT) ;
  - attaque / lancer : `choix attaquer '{"cible_id":1117}'` (un id de `cibles`) ;
  - sort : `choix lancer_sort '{"cle":"sort:3","cible_id":1150,"cible_type":"monstre"}'` (la `cle` et une cible de CE sort) ;
  - tuile d'élan : `choix trophee_elan '{"cle":"<cle de la tuile>"}'` ;
  - une option sans sous-choix (attendre, trophee_potion, fouiller_mobilier_0, repousser_<id>…) : `choix <id>` sans
    JSON — recopie l'id EXACT affiché par `vue` (certains portent un suffixe : `fouiller_mobilier_0`).
- Une activation : en général un déplacement ET une action, dans l'ordre que tu veux, puis `choix attendre`
  (« Terminer le tour »). Le Defender répond automatiquement après chaque activation de ton camp.
- Une réaction (offre de bouclier, crâne, riposte, soin d'urgence…) apparaît dans `vue` sous « RÉACTION EN
  ATTENTE » : réponds `reaction oui` ou `reaction non` (soin d'urgence : `reaction oui <cle_soin>`).
- Un refus `REFUSÉ (422)` est une information : lis le motif. S'il contredit ce que `vue` proposait, c'est un défaut
  à rapporter (le menu ne doit JAMAIS proposer ce que le serveur refuse).

## Ton rapport final (ta dernière réponse)
1. Pour CHAQUE point de ta mission : ✅ marche / ❌ défaut / ⏸ pas rencontré — avec une ligne de preuve
   (commande + extrait de sortie).
2. Les DÉFAUTS en détail : commande exacte, sortie exacte, ce que tu attendais.
3. Ce qui était confus ou difficile à comprendre dans `vue` (lisibilité de l'outil = lisibilité de l'écran).
4. Le résultat de chaque combat (victoire/défaite, nombre de rounds approximatif).
Sois factuel : n'invente rien, ne dis pas « marche » sans l'avoir vu.
