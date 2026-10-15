# Verdict — campagne d'agents sous le thème Jungles of Delthrak (2026-10-09/10)

> ⚠ **Document DATÉ**, comme les autres `docs/verdict-*.md` : un constat, pas tenu à jour.
> Les corrections retenues remontent dans `docs/regles/`.

## Le banc

Harnais `browser-shots/campagne/` (`BOITES=jungles_delthrak LONGUEUR=tres_courte`), groupe
jetable `harnais-jungle-2325-lsf0`, une quête (boss : Gruulob). Trois agents Haiku (effort
max) : Tamsin explorateur, Oriane druide, Brokk nain, 23:26 → 00:32. Aucune scène montée à la
main. Premier banc après le commit `b03fbfa` (correctifs du verdict Morcar). Nettoyé par
`nettoyer.sh` ; comptes réels inchangés (3 groupes / 9 personnages / 4 joueurs avant et après).
**Aucune erreur serveur** (`ERROR`/`CRITICAL`) dans `laravel.log` pendant toute la partie.

## Ce qui marche

- **Partie complète de bout en bout** : exploration, pièges, coffres, Gruulob vaincu en deux
  formes, objectif accompli, **sortie par l'escalier** (Brokk sur l'escalier propose,
  `sortie_donjon` voté 3/3), retour au hub, 300 po, niveau 2 pour les trois.
- **Effet global de Gruulob** annoncé dès le départ, avec sa description (fil,
  `quete.effets_globaux`, narration) ; gobelins à 3 dés d'attaque.
- **Terrain de jungle** : Jungle et Sable entravants coûtent 2, reliquat exact ; table bloquante
  contournée.
- **Sorts de la druide** annoncés et chiffrés (Luciole, Force vitale +2 PV, Métamorphose).
- **« Le menu se met à jour »** jamais rencontré ; **aucun 4xx** sur une option proposée.
- Effets Morcar corrigés : fouille `rien`, leviers, sorts de soutien au fil.

## Défauts trouvés — par gravité

### 1. Effets automatiques encore NON ANNONCÉS (règle dure)
Le registre `JournalCombat::TYPES` couvre les **types d'action** ; ce qui suit passe à côté :
- **Tics de poison** (Piège de coffre sur Tamsin) : −1 PV deux fois, et la fin de l'effet,
  sans ligne. Seule la pose est annoncée. Même chose pour la **fin** de Métamorphose (Body
  rendu au max) et la **rupture** de « Renforcé » au premier dégât.
- **Changement de forme de Gruulob** : ligne de repli brute « Un effet automatique…
  (changement_phase) », écrite **deux fois**, parfois avant le coup qui la provoque ; les
  nouvelles stats (attaque 3→4, défense 4→5) ne sont pas dites ; **apparition** de Gruulob
  sans ligne.
- **Objets** : « Tamsin utilise Fiole de soin » sans cible ni PV rendus ; Potion de défense
  sans cible (et 5 dés de défense lancés ensuite, rien ne l'explique) ; Potion d'héroïsme sans
  effet visible.
- **Sixième sens** (explorateur) : la repioche n'est dite que dans la réponse HTTP ; la 1re
  fois elle a écarté une carte « errant » (pas un piège), la 2e le piège est tombé quand même —
  règle à relire. **Sens du piège** : le piège pressenti n'est visible que dans la réponse.
- **Votes** : la clôture du vote de retraite (« continuer », non appliqué) et celle du vote de
  sortie n'ont aucune ligne ; le fil est vidé au hub avant qu'on lise la résolution.
- « Gobelin surgit du coffre ! » sans le nom du fouilleur.

### 2. Menu qui propose l'inutile
- **Dalle descellée** reproposée après sa bourse ; le jet réussi dit « rien ne vient »
  (`docs/regles/epreuves-et-attributs.md` exclut ce cas du menu).
- **Force vitale** proposée sur un héros à PV max.
- Une fois, un menu partiel (3 options sur 9) pendant ~30 s (23:30:45).

### 3. Textes faux ou absents
- **« Renforcé »** est la condition par défaut de TOUS les buffs d'objet (attaque, défense,
  relance, déplacement doublé — `ObjetSeeder` l. 267-931) mais sa description ne parle que
  d'attaque. Deux entrées identiques sur Oriane.
- Fiche : compétences « Disponible » sans nom ni description.
- `entravant` (terrain) n'a aucun texte joueur.
- Fouille : échec « rien » / réussite « rien de suspect » ; bloc `des` avec `boucliers: 1`
  sans bouclier.
- Images de terrain et de piège en `/api/placeholder/…`.

### 4. À vérifier
- **PV de Gruulob** : 3 vus en phase 1 (livret p. 27 et seeder : B4), puis un maximum à 2 en
  forme démoniaque (B3). Mise à l'échelle volontaire ou défaut ?
- **Héros arrêtés sur la case d'une porte fermée** ((44,30), (35,37), (45,42)) avec
  `ouvrir_porte` offert depuis cette case ; une porte fermée bloquée par un allié a coûté un
  détour de 44 points. Rapport case de porte / embrasure à relire (`Grille::caseEmbrasure()`).
- **Escalier** jamais signalé au joueur : ni la situation, ni le fil, ni la bannière d'objectif
  ne disent « rejoignez l'escalier » une fois l'objectif accompli ; `quitter_donjon` n'apparaît
  que sur la case.

### 5. Règle à arbitrer
- **Sortie** : un seul héros sur l'escalier propose, le vote fait sortir **tout le groupe**,
  y compris des héros à 10 cases de l'escalier (Tamsin, Oriane). Voulu ?

### 6. Harnais (pas le jeu)
- `vue.py` calcule ses destinations lui-même : il ignore le **bloc tombé** d'une chute de blocs
  (annonce 6 points, le serveur en compte 8) et le passage par un allié ; il n'affiche ni
  l'escalier ni une carte proche.
- Le brief parle de « vote oui » : le vote de retraite n'a que recommencer / arreter /
  continuer — et **« arreter » clôt toute la campagne**. Le brief doit le dire.
- Le fil (`journal_combat`) est plafonné à 24 lignes : un agent arrivé tard ne voit plus
  l'annonce de l'effet global (elle reste dans `quete.effets_globaux`).

## Non testé
Leviers, relèvement, Repousser, établi, Gretzl, mission « secourir », TPK en mode Story.

## Décision de René (2026-10-10, après le verdict)

| Sujet | Décision |
|---|---|
| Sortie du donjon | **Tous dans la salle de départ** (précisé par René dans la foulée, au lieu de « tous sur l'escalier ») : la sortie ne s'offre que si TOUS les héros debout sont dans la salle de l'escalier d'entrée ; le **captif** d'une extraction, lui, doit toujours être amené **sur l'escalier** (précision de René, même jour). **C'est la décision de René, qui accepte qu'elle diffère des règles officielles** (dans le livret, chaque héros quitte le donjon en marchant sur l'escalier) : une divergence délibérée, à ne pas « corriger » vers le livret. |
| Sixième sens (explorateur) | **Piège seulement** : une carte « monstre errant » tirée reste tirée (fidèle à la description de la compétence ; les livrets distinguent « hazard » et « wandering monster »). |
| Sens du piège | Les cases pressenties sont montrées à **tout le groupe** (comme à une vraie table). |
| Changement de forme d'un boss | Le fil annonce le **nom ET les nouvelles stats**, au prix du mystère voulu par le livret. |

## Partie de vérification ciblée (2026-10-10, 11:07 → 11:53)

Deux agents Haiku (Tamsin explorateur, Brokk nain), `BOITES=jungles_delthrak`, une quête courte,
**échouée** (les deux héros à terre face à Gretzl). Coût API mesuré : 3 appels Sonnet, ~0,09 $ US.
Nettoyée ; comptes réels inchangés (3/9/4).

**Déplacement publié par le serveur : conforme.** ~45 trajets, aucun 422 sur une case listée, coût
débité = coût annoncé, reliquat juste, brouillard jamais franchi ; la manette éclaire exactement les
33 cases publiées (capture Playwright). Un défaut, **corrigé** dans la foulée : un menu calculé avant
le tour du héros (dé de déplacement pas encore lancé) restait servi ~12 s avec des destinations à la
seule portée de base → `GenererMenu` marque `au_tour`, `MenuCourant::pour()` recalcule.

**Trouvé, non corrigé :**
- **Porte inatteignable** : la seule case d'accès à la porte (21,31) était un **Brasier** (on le
  traverse, on ne s'y arrête pas — Jungles p. 4) → aucune case d'où l'ouvrir. Défaut de **placement du
  terrain** (« Connected is not playable »), antérieur au chantier destinations.
- **Fil** : « touche Brokk (−2 PV) » puis « ignore intégralement le coup » (dégâts annoncés avant la
  réaction qui les annule) ; esquive d'un Piège de lianes pendant un déplacement sans ligne ; pose du
  venin sans ligne (seule la fin est dite) ; tir à distance de Gretzl (`tir_au_choix`) à 12 cases sans
  dire « tire » ; le fil nomme le monstre par son nom de catalogue (« Gretzl la Porte-Fléau »,
  « Demonspider ») quand l'écran montre son nom d'habillage (« Le Cœur-Liane Éveillé »).
- **Levier** (29,18) : « le passage est déjà ouvert » alors que la porte verrouillée (21,26) reste
  close, et le levier reste proposé.
- Harnais : `vue.py` n'affiche que 14 destinations sur 42 ; le README dit « embrasure comprise » pour
  les portes fermées (faux : l'embrasure d'une porte close n'est pas une case d'arrêt).

**Non vérifié** (objectif jamais atteint) : la sortie depuis la salle de départ et son vote. → vérifiée le 2026-10-15, voir plus bas.

## Partie de vérification de la sortie (2026-10-15)

Un agent Sonnet pilote deux héros (Brakka barbare, Durin nain) sur les routes réelles, dans une quête `vaincre_boss_final`. L'objectif a été **mis en scène** : boss passé `vaincu` par tinker, sur le seul groupe du harnais (même écriture que `MoteurDegats`). Coût API : 3 appels claude-sonnet-4-6 (9 114 jetons en entrée, 3 442 en sortie). Nettoyée par `nettoyer.sh` ; comptes réels inchangés (3/9/4).

**Conforme :**
- Un héros hors de la salle de départ : `quitter_donjon` absent des deux menus ; `quete.sortie` = `{ouverte: true, rassemble: false, absents: ["Brakka"], consigne: « Rejoignez la salle de départ pour quitter le donjon — il manque : Brakka. »}`, consigne visible dans `vue.py`.
- Les deux héros dans la salle de départ, aucun sur les cases d'escalier : `quitter_donjon` offert, vote 2 oui / 0 non, quête `terminee`, 300 or, niveau 2, phase `hub`. Aucun 422, aucun menu partiel.
- Vérifié par le code seulement (transaction annulée) : un héros à terre hors de la salle ne bloque pas (`rassemble: true, absents: []`).
- Extraction (captif sur l'escalier) : non applicable, pas de captif.

**Trouvé, corrigé :** la narration de repli qui suivait la proposition de sortie disait « Les héros avancent… » (temps fort `progression`). Corrigé : temps fort `sortie_proposee`.

**Observé, laissé tel quel :** une case de PORTE ouverte de la salle de départ compte comme « dans la salle » (`Salles::indexDe()` englobe la couronne de murs), cohérent avec « la porte occupe sa case ».

**Non vérifié faute de combat :** les lignes « tire » des attaques à distance des héros (couvertes par `tests/Feature/Partie/FilEtTableTirEsquiveFinsTest.php`).
