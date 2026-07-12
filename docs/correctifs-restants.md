# Correctifs restants — test de campagne complet (2026-07-11)

> Issu du test de bout en bout « Les Piliers de Karak » : campagne 3 quêtes jouée
> par l'UI réelle (2 joueurs nain + elfe + table narrateur, clés IA actives),
> quêtes 1-2 gagnées, boss final ×2 TPK, reprise snapshot, clôture par abandon.
> Les **5 bloquants corrigés pendant le test** (initiative/héros tombé, ouverture
> du marché par la table, stock « épuisé » des objets communs, bandeau TPK +
> reprise table, ciblage des sorts à plat) ne figurent pas ici — voir le diff.
> Ce fichier liste ce qui RESTE à faire, par priorité.

---

## 1. Majeurs — fonctionnalités incomplètes

### 1.1 ~~Armes et armures sans aucun effet mécanique~~ — FAIT
- **Livré** : service `App\Partie\Equipement` (équiper/déséquiper) qui déplace la
  ligne d'inventaire sac ⇄ slot naturel (`arme_principale`/`arme_secondaire`/
  `armure`) et applique/révoque les deltas `des_attaque`/`des_defense` sur les
  **colonnes** du héros — même patron que `CompetenceController` pour les nœuds
  passifs, donc `ResolveurTour`, la fiche et `ScorePuissance` lisent l'équipement
  sans calcul « effectif » séparé. Auto-swap de l'occupant, garde-fou
  deux-mains/bouclier (`incompatible_deux_mains`), capacité de sac au retour.
  Endpoints `POST`/`DELETE /groupes/{id}/equipement` (hub only, 422 en quête) ;
  `/moi.equipement` enrichi (chaque pièce porte son `inventaire_id`, chaque objet
  du sac un flag `equipable`). Front : boutons « Équiper »/« Déséquiper » dans
  `SacTab` (au hub), re-`GET /moi` après chaque manip. Tests : service+endpoint
  7/7, suite Feature 214/214. Vérifié en jeu : Épée large sac → équiper → dés
  d'attaque 3→6 sur la fiche → déséquiper → retour à 3.
- **Reste éventuel** : « équiper » comme **action de tour** en pleine quête
  (doc 01 §149) — au MVP c'est hub-only.

### 1.2 Mercenaires : aucune UI de recrutement
- **Constat** : serveur complet (POST `/groupes/{id}/mercenaires`, phase alliés,
  `EtatGroupe` type `allie`, catalogue seedé) mais zéro composant front — grep
  « mercenaire » dans `resources/js` : aucun résultat, pas même `services/api.js`.
  Fonctionnalité inatteignable en jeu (testée par API : recrutement, or déduit,
  rendu carte et purge OK).
- **Piste** : panneau au hub (manette et/ou table) listant le catalogue avec
  prix + bouton recruter (bourse commune), et affichage de l'allié actif.

### 1.3 ~~Aucun retour de combat sur la manette~~ — FAIT (commit de8d6f0)
- **Livré** : journal mécanique `.combat.journal` diffusé à toutes les manettes
  (`App\Partie\JournalCombat` + `App\Events\JournalCombatDiffuse`, dispatché par
  `ChoixController`). Panneau « Fil du combat » dans `ActionTab`, coloré par ton
  (dégâts/mort/subit/chute/paré/succès/échec/info). Couvre attaques, dégâts
  subis, chutes, morts, tour des monstres/alliés et **résultat de fouille**
  (auparavant muet). Aucun LLM. Tests : formateur 9/9, diffusion 2/2.
- **Reste éventuel** : le détail des dés (crânes/boucliers) n'est pas dans le
  journal (seul le total de dégâts) — la révélation des faces existe déjà côté
  acteur (`revelerDesResultat`) ; à étendre aux autres joueurs si souhaité.

## 2. Majeurs — moteur / cohérence

### 2.1 Menus en cache survivant à la reprise (rejeu d'options périmées)
- **Constat** : après `POST reprise`, un choix contre le menu d'AVANT le TPK a
  été accepté — un sort a blessé un monstre NON RÉVÉLÉ à ~30 cases (l'option
  gardait la liste de cibles de l'ancien état du monde). `Sauvegarde::restaurer`
  redispatch `GenererMenu` mais ne purge pas immédiatement `partie:menu:*`.
- **Piste** : `Cache::forget` des menus du groupe dans la transaction de reprise
  + revalidation moteur (cible révélée/actif) au moment de résoudre un sort.

### 2.2 Menu de tour incohérent avec le plateau
- **Constat** : un tour avec 2 monstres ADJACENTS où le menu ne proposait AUCUNE
  attaque — seulement « Se déplacer » (0 case accessible = option morte) et
  « Terminer le tour » : joueur au contact forcé de passer. À investiguer :
  moment de génération du menu vs déplacements de la phase des monstres.
- **Piste** : régénérer/valider le menu moteur contre l'adjacence RÉELLE à
  l'affichage (GET /menu fait déjà une régénération — l'étendre au cas où l'état
  a bougé depuis la mise en cache), et masquer « Se déplacer » quand 0 case.

### 2.3 Expiration de session en pleine partie
- **Constat** : session Laravel expirée plusieurs fois en cours de quête (pauses
  longues) → toute action affiche « Unauthenticated. » BRUT (anglais) dans la
  zone narration, sans redirection. Le parcours de secours (login →
  « Reprendre la partie ») fonctionne très bien, mais rien n'y invite.
- **Piste** : intercepter le 401 dans le client API → message français + bouton
  « Se reconnecter » (ou re-login silencieux) ; allonger la durée de session
  (`config/session.php`) pour le cadre LAN.

## 3. Équilibrage — données de playtest (design à trancher, ne pas « corriger » en silence)

- **Budget glouton** : `DemarreurQuete::acheterMonstres` remplit en
  `orderByDesc('cout')` → la quête 1 d'une campagne met systématiquement les
  monstres de base les PLUS CHERS (2 Gargouilles att 4/déf 5 vs héros niv. 1
  nus, att 2/déf 2). Espérance : ~0,2 PV/attaque héros→gargouille contre
  ~2,6 PV/tour subis. Pistes : mélange de tiers, malus premier arc, plancher
  d'équipement de départ, ou coût de la Gargouille.
- **Boss final à 2 joueurs** : 2 TPK consécutifs malgré stratégie optimale
  (PV pleins, focus faibles, sorts) — Seigneur 10 PV + 2 serviteurs, ~7 dégâts
  encaissés en une ronde. Injouable à 2 sans équipement/mercenaire.
- **Prix vs revenus** : potion de soin 100 or, quête ~50 or de butin — un groupe
  de 2 ne peut rien s'offrir d'utile après la quête 1. Relevage à 1 PV → boucle
  « relevé/retombe » (9 cycles observés en quête 1).
- **Reprise et alliés** : le mercenaire payé 150 or n'est pas restauré par le
  snapshot `debut_quete` (purgé à l'échec, hors périmètre du snapshot). Inclure
  les alliés dans le snapshot, ou rembourser (doc 14 §3.5 à préciser).

## 4. Modérés

- **Or personnel invisible** : la part versée à la clôture n'apparaît nulle part
  (ni roster, ni fiche) et `/api/moi` n'expose pas de champ `or`. Exposer l'or
  personnel dans `/moi` + l'afficher sur la carte roster.
- **Compétences acquises invisibles sur la manette** : aucune section
  Fiche/talents ne liste les nœuds acquis (« Garde tenace » invérifiable).
- **Panier insolvable confirmable** : le front laisse confirmer un panier avec
  total projeté NÉGATIF affiché (garde seulement à la finalisation serveur).
  Bloquer « Confirmer » quand `total_projete < 0`.
- **Noms des coéquipiers non résolus** : « Perso n°59 » au lieu de « Borin » dans
  la liste des prêts (hub, manette) — le payload prêts ne porte que
  `personnage_id`, le front n'a pas les noms des personnages des autres joueurs.

## 5. Mineurs / cosmétiques

- 404 `GET /marche` et `GET /cloture` en console à CHAQUE chargement de manette
  au hub (sondes de rattrapage) — renvoyer 200 `{actif: null}` ou ne sonder que
  sur signal.
- Narration IA : vocabulaire méta (« boss final ») et objets inventés (« sa
  hache » alors que l'inventaire est vide) — enrichir le contexte des skills
  (inventaire réel, habillages, interdits doc 08) ; épilogue de défaite qui
  omet les quêtes GAGNÉES et contredit le bloc partage (« vivante » vs « ont
  péri » — l'abandon n'est pas une mort).
- « Gravement blessée — à protéger » en dur au féminin (`GroupPanel.vue`).
- Onglet Sorts d'un Elfe niv. 1 : « Le Elfe ne manie pas la magie » — élision
  (« L'Elfe ») et message trompeur (sa magie s'éveille via l'arbre).
- Sous-titre « Connectez-vous… » toujours affiché sur `/joueur` une fois connecté.
- Feuille de déplacement : bouton fermer (`.dep-close`) hors viewport en
  412×915 ; « Touche une case éclairée » affiché même quand AUCUNE case n'est
  accessible ; cadavres de monstres vaincus qui bloquent le pathing (à trancher).
- Feuille de ciblage d'un sort offensif : alliés listés au même niveau que les
  ennemis (le garde-fou anti-tir-ami rattrape, mais un tri/section aiderait).
- Option « Relever X » encore proposée quand X est déjà debout (menu non filtré
  sur l'état de la cible).
- Arbre de compétences : pas de lignes/connexions visuelles de prérequis.
- « 0 joueurs connectés » au hub : le compteur table compte les héros de la
  quête, pas la présence réelle — libellé ou source à revoir ; panneau
  « LE GROUPE » vide au hub (le narrateur ne voit ni roster ni prêts).
- Narration systématiquement en retard d'une action (piège narré après le menu
  suivant) — jamais inversée grâce à l'anti-inversion, mais le rythme surprend.

## 6. Non couvert par ce test (à valider une prochaine fois)

Votes de groupe (`retrait_joueur`, `choix_groupe`) · potions bues en jeu (aucune
achetée) · états Dread sur la manette (Endormi/Commandé jamais lancés par les
boss) · audio réel (barks, ambiance, voix TTS — non vérifiable en headless) ·
multi-personnages par joueur · portes verrouillées clé/levier · monstre errant
(fouille de trésor) · clôture en VICTOIRE (boss jamais vaincu).
