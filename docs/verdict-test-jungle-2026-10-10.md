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
