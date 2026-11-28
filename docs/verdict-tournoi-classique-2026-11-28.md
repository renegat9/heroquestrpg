# Verdict — le tournoi du mode classique joué par des agents (2026-11-28)

Document DATÉ (comme les autres `docs/verdict-*.md`) : il n'est pas mis à jour ensuite. Les règles qui en sortent vivent
dans `docs/contrat-api.md` (section du tournoi de World's End) et `docs/plan-tournoi-classique.md`.

## La partie

- Campagne : `browser-shots/campagne/essai-tournoi.json` (slug `essai-la-joute-des-cendres`), INVENTÉE : une lice, trois
  quêtes `tournoi` — des vagues (2 gobelins, puis un orque, puis un gobelin archer et un gobelin), un champion seul avec un
  coffre, une finale (ogre guerrier et deux orques, deux coffres). Validée sans refus.
- Groupe : Albrecht (barbare) et Brunehaut (naine), chacun joué par un agent Haiku (effort max) sur les vraies routes de
  la manette. Environ 20 minutes, ~145 appels d'outils à eux deux.
- **Résultat : campagne finie, trois quêtes gagnées.** Albrecht tombé en finale (hors jeu, ni tué ni relevé), Brunehaut
  seule achève l'ogre par sa manœuvre de combattante seule. Ont joué en vrai : l'ouverture du Defender, l'alternance des
  activations, les trois vagues, le ramassage des trophées et la réserve partagée, la réaction « Crâne blanc ×2 » après
  le jet, la manœuvre du combattant seul (des deux côtés), le héros tombé hors jeu, `tournoi_gagne`, le hub entre les
  quêtes.
- Nettoyage : `nettoyer.sh` puis `campagne-essai.sh retirer` ; personnages, groupes et comptes recomptés avant et après
  (10 / 4 / 4).

## Défauts relevés

| # | Relevé | Verdict | Suite |
|---|---|---|---|
| 1 | Quête 1, round 1 : Albrecht et Brunehaut jouent dans la MÊME seconde (20:49:49, journal 36926-36928) ; le second gobelin n'agit qu'après les deux. | **Défaut réel** : la main partagée se lit « personne n'a commencé » sans verrou ; deux choix simultanés passent tous les deux. | **Corrigé** (classique) : verrou éphémère par groupe dans `ChoixController::choisir()`. ⚠ L'arène a la même course, non touchée (branche isolée) : signalé à René. |
| 2 | Quête 1 : dès la vague 1 tombée, `objectif_accompli: true`, sortie ouverte, « quittez le donjon par l'escalier », un orque encore debout. | **Défaut réel** : les vagues n'étaient pas des cibles de la fin `vaincre`. | **Corrigé** : `TournoiClassique::vagueSuivante()` pose `objectif: true`. Test. |
| 3 | Un escalier « sortie du donjon » sur la tuile des héros dans la lice. | **Défaut réel** (le livret n'en a pas). | **Corrigé** : `carte.escalier: null` en tournoi. Test. |
| 4 | Potion de trophée et élan jamais proposés. | **Défaut réel** : le menu les gardait sur `estArene()` (cycle de vie) au lieu de `estEnTournoi()` (règle) — oubli du classement TC1. | **Corrigé**. Test par les routes (menu, puis potion bue). |
| 5 | « Bouclier noir » jamais utilisable par un héros. | **Pas un défaut** : le bouclier noir est la défense des MONSTRES (« a white shield tile to add one shield to their Defend roll », p. 14). Un héros qui le ramasse ne peut que le défausser pour l'élan. | — |
| 6 | Menu offrant `attaquer` puis refus « Albrecht a pris la main ». | Menu lu juste avant que le coéquipier prenne la main ; le refus nomme qui joue. Attendu. | — |
| 7 | Le monstre seul frappe deux fois (manœuvre puis activation). | **Règle** : « Any tournament combatant who is the sole member of their respective team » (p. 13), monstres compris. | — |
| 8 | Or gagné sans fouiller les coffres. | Récompense de quête de la campagne d'essai (`recompense.or`), partagée. Les coffres n'ont pas été fouillés : non éprouvés en partie (le test TC5 les couvre). | — |
| 9 | « Déplacement : dé 6 → 11 points ». | **Pas un défaut** : le classique lance deux dés rouges ; le journal ne cite que le premier dé (`de`), le total est juste. | — |
| 10 | « Ogre guerrier pare le tir de Brunehaut » pour une hachette lancée. | Attendu (c'était un lancer). | — |
| 11 | « Fouiller la zone » et « Battre en retraite » offerts dans la lice. | Classés « cycle de vie de l'arène » au lot TC1 (le livret ne les interdit pas) ; sans piège ni passage, la fouille ne trouve rien. | — |
