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

### 1.2 ~~Mercenaires : aucune UI de recrutement~~ — FAIT
- **Livré** : nouveau `GET /mercenaires` (catalogue recrutable, group-agnostique
  comme `/competences`) ; `EtatGroupe` expose au hub `groupe.mercenaires` (recrues
  actives, mis à jour en direct par `.groupe.etat` après un recrutement). Front :
  composant `RecrutementHub` dans l'onglet Action du hub de la manette (cartes
  catalogue avec stats/prix, bouton « Recruter » désactivé si or insuffisant ou
  2ᵉ animal, liste des alliés recrutés) ; la table affiche les renforts embauchés
  au hub (`hub-allies`). Le recrutement débite la bourse commune (POST existant).
  Tests : Mercenaires 9/9 (dont catalogue + exposition hub), suite 307/307.
  Vérifié en jeu : 3 cartes → Recruter Hallebardier → bourse 500→280, recrue
  listée sur la manette et renfort affiché sur la table.
- **Reste** : équilibrage des prix/stats (voir §3, données de playtest).

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

### 2.1 ~~Menus en cache survivant à la reprise (rejeu d'options périmées)~~ — FAIT
- **Livré** : `Sauvegarde::restaurer` **oublie** les menus en cache
  (`GenererMenu::cleMenu`) de tous les héros actifs avant de les régénérer — la
  fenêtre de rejeu est fermée (POST choix renvoie « aucun menu » jusqu'à l'arrivée
  du menu neuf). Défense en profondeur : `ResolveurTour::cibleSort` exige
  désormais une cible monstre `actif` **ET** `revele` — un sort ne peut plus
  frapper un monstre redevenu dormant, même si un menu périmé le liste encore.
  Tests : purge de menu à la reprise (Queue::fake pour observer la fenêtre async)
  + résolveur refuse la cible non révélée (CoherenceMenuTest).

### 2.2 ~~Menu de tour incohérent avec le plateau~~ — FAIT (partiel)
- **Livré** : `MenuMoteur` masque « Se déplacer » quand le héros est TOTALEMENT
  bloqué (aucune case orthogonale traversable — murs / portes fermées / figures),
  au lieu de proposer une option morte à 0 case ; le plateau est reconstruit avec
  la même occupation que `ResolveurTour` (héros, monstres actifs avec emprise,
  alliés), donc jamais masqué à tort (« Terminer le tour » reste toujours offert).
  Le filtre d'attaque du menu exige aussi `revele` (aligné sur le résolveur : pas
  d'attaque proposée sur un dormant). Tests : masquage boxed-in + présence dès
  qu'une case est libre + attaque cachée sur dormant (CoherenceMenuTest).
- **Reste** : le symptôme « 2 monstres adjacents, aucune attaque proposée » tient
  probablement à un décalage génération-menu / phase des monstres — non reproduit
  ici (le menu est régénéré après la phase des monstres). À réobserver en
  playtest ; si confirmé, étendre `GET /menu` à une régénération quand l'état a bougé.

### 2.3 ~~Expiration de session en pleine partie~~ — FAIT
- **Livré** : le client API (`useApi`) intercepte le `401`, remplace
  « Unauthenticated. » par un message français et émet `api:session-expiree` ;
  `App.vue` superpose un bandeau « Session expirée » avec un bouton
  « Se reconnecter » qui route vers le login → « Reprendre la partie ». Durée de
  session allongée pour le cadre LAN : `SESSION_LIFETIME` par défaut 120 → **1440**
  (24 h) dans `config/session.php` et `.env.example` (les longues pauses n'expirent
  plus la séance).

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
