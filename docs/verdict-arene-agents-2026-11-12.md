# Verdict — l'arène jouée par des agents (2026-11-12)

Document daté, non mis à jour ensuite : les règles en vigueur sont dans `docs/contrat-api.md`.

## La partie

Cinq agents Haiku, chacun pilotant un joueur sur les **vraies routes** par le nouveau harnais `browser-shots/arene/`
(`arene.py <slot> vue|choix|reaction|geste…`, `preparer.py`, `nettoyer.py`, `battement.sh`, `BRIEF-AGENT.md`), juste
après le merge de la PR #2 :

| Combat | Joueurs | Résultats |
|---|---|---|
| Coop duo — barbare + magicienne, alliés Ogre mercenaire / Arbalétrier | 2 agents | 2 victoires (revanche comprise) |
| Coop solo — chevalier, puis avec un Arbalétrier | 1 agent | victoire, défaite, victoire |
| Monster vs Monster, puissance 15 (ogres, archers, Guerrier du Chaos, Gargouille) | 2 agents | 1 victoire de chaque camp |

Dix combats complets ; la base réelle inchangée (3 groupes, 9 héros, 4 joueurs) après `nettoyer.py`.

## Ce qui marchait

Salon Coop (alliés, Defender composé / ajouté / retiré, refus au-delà de la puissance, prêts, lancement), activation
libre, sorts, jet d'arme, alliés, trophées (ramassage, élan, potion, crâne, bouclier blanc, riposte), coffres et table
2d6, Inébranlable, mort sans « Relever », défaite, palmarès, statistiques, revanche ; Monster vs Monster (camps, refus
du tirage sans puissance, tirage, sélection alternée et ses refus, tirs, frappes, rounds, fin, revanche).

## Défauts trouvés, et corrigés le même jour

1. **Menu périmé après une réaction** : un Bouclier blanc accepté défaussait la tuile, l'élan la proposait encore (422).
   `ReactionController` ne relançait aucun menu → `GenererMenu::relancerApresResolution()`.
2. **Destination d'ogre non atteinte** (MvM) : (5,5) publiée, arrêt en (8,4) — la publication ne testait l'emprise qu'à
   l'arrivée, la marche à chaque pas. Une règle : `ResolveurTour::trajetDeLaMarche()`, lue par les deux.
3. **Marche du monstre non publiée** (arène ET campagne) : une figure qui marchait puis frappait ne rendait que la
   frappe ; le repli d'un tireur était jeté. Les deux actions sont rendues à plat, dans l'ordre.
4. **Contrat faux sur les coffres** : il annonçait `tresor_tournoi` / `diagonale` dans `carte.mobilier[]`. Rien ne les y
   mettait ni ne les lisait : le contrat est corrigé (la décision est l'option `fouiller_mobilier_<n>`), pas de clé
   décorative ajoutée.
5. **Fiche `/moi`** : les capacités d'un héros d'arène engagé y étaient « Utilisable en quête seulement » — lues sur le
   héros source au lieu de sa doublure.
6. **Messages** : un tir refusé disait « pas à portée de frappe » (→ « pas en ligne de vue ») ; un refus pendant
   qu'un coéquipier tient la main parlait du MJ (→ « {nom} a pris la main ») ; `GET /figures` publie l'`emprise`.

## Deux décisions de René

- **Combattant seul : la lettre du livret** (F9528 p. 12-13). La « lecture nommée » du lot T5 (sa propre activation
  tenait lieu de manœuvre) laissait un héros seul face à une figure seule sans AUCUNE manœuvre, quand la figure en
  prenait une à chaque fois. Il la reçoit maintenant après chaque activation adverse, avant comme après son activation,
  qu'il garde.
- **L'allié ramasse les trophées** (« Heroes and allies make up the Challenger team »).

## Comportements voulus, signalés à tort

Un sort ne sert qu'une fois par combat ; la ligne de vue est coupée par les deux gravats du hall et par les figures
interposées ; le contact d'un ogre passe par sa seconde case ; la Parade au bouclier exige un voisin et le Défi du
chevalier un monstre errant (absent de l'arène) ; la réserve de trophées est partagée.

## Restes

- La potion de trophée n'est pas offerte pendant une manœuvre (types permis de la manœuvre) — à arbitrer.
- `ouvrirTourDesHeros()` n'est appelé que depuis `ouvrirNouveauTour()` : Étourdi et gel ne s'appliquent pas au premier
  round (arène et campagne) — constat hors périmètre, non corrigé.
- Le repli d'un tireur du Defender peut traverser tout le hall (une seule salle) : « recul borné à la salle courante ».
