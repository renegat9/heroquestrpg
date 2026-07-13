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

## 3. Équilibrage — données de playtest

Corrigés :
- ~~**Budget glouton**~~ — `DemarreurQuete::acheterMonstres` ne remplit plus en
  `orderByDesc('cout')` : il achète d'abord **quelques FORTS** (haut du tier base,
  `forts_par_quete` + escalade d'arc) puis la **MASSE de FAIBLES** (bas du tier,
  round-robin) → « beaucoup d'ennemis faibles + quelques forts » au lieu de
  2 Gargouilles contre des niv. 1. Réglable dans `config/jeu.php` (`rencontres`).
- ~~**Reprise et alliés**~~ — les mercenaires sont **sérialisés dans le snapshot
  `debut_quete`** et **restaurés** à la reprise (delete+insert, id conservé) : le
  mercenaire payé revient après un TPK.
- ~~**Cadavres / héros tombés bloquant le plateau**~~ (était en §5) — un héros
  tombé ne bloque plus passage ni ligne de vue ; il reste secourable si aucune
  autre figure n'occupe sa case et qu'un allié est adjacent (voir §« Héros tombé »
  du contrat). Les monstres vaincus ne bloquaient déjà pas (exclus de la grille).

- ~~**PV du boss figés (Seigneur 10 PV)**~~ — les PV des **boss/sous-boss** s'adaptent
  désormais à la **taille du groupe** : `pv = pv_catalogue × nb_héros / taille_reference`
  (référence 4, plancher à 40 %). Un duo affronte un Seigneur à 5 PV, pas 10. Le max
  est porté **par l'instance** (colonne `pv_body_max`, sérialisée au snapshot) — fuite,
  régénération et jauge affichée s'en servent. Réglable via `jeu.rencontres`
  (`boss_pv_adaptatif`, `taille_reference`).

Restent (nombres de playtest, à trancher — ne pas « corriger » en silence) :
- **Serviteurs & jalon du boss** : au-delà des PV (ci-dessus), le **nombre de
  serviteurs** du boss et le facteur de jalon `boss_final` restent à régler en
  playtest. Le nouvel équilibrage (ennemis plus faibles), l'équipement (§1.1) et
  les mercenaires restaurés (ci-dessus) aident déjà.
- **Prix vs revenus** : potion 100 or vs ~50 or de butin — à recalibrer (prix des
  consommables, or de quête, ou butin de boss).
- **Relevage à 1 PV** → boucle « relevé/retombe » : à trancher (relever à N PV,
  ou plafond de relevages par quête).

## 4. Modérés — ~~FAIT~~

- ~~**Or personnel invisible**~~ — `/api/moi` expose désormais `or` par
  personnage ; le roster (carte joueur) affiche la bourse personnelle.
- ~~**Compétences acquises invisibles sur la manette**~~ — la manette charge le
  catalogue `/competences` et la Fiche liste les **Talents acquis** (nœuds nommés).
- ~~**Panier insolvable confirmable**~~ — `MarketTab` désactive « Confirmer »
  quand `total_projete < 0` (total en rouge + message), en plus de la garde serveur.
- ~~**Noms des coéquipiers non résolus**~~ — le payload prêts (EtatGroupe hub +
  broadcast `.prets.maj`) porte maintenant `nom` ; `pretsVersEtat` le préfère au
  repli « Perso n° ». Plus de « Perso n°59 » sur la manette des coéquipiers.

## 5. Mineurs / cosmétiques

Corrigés :
- ~~404 `GET /marche` et `GET /cloture`~~ — renvoient 200 `{marche|cloture: null}`
  quand rien n'est ouvert (le front sait déjà ignorer un état nul). Plus de 404
  en console à chaque chargement de hub.
- ~~« Gravement blessée » en dur au féminin~~ → « État critique — à protéger »
  (neutre, `GroupPanel.vue`).
- ~~Onglet Sorts Elfe « Le Elfe »~~ → « L'Elfe ne manie pas **encore** la magie —
  elle s'éveille dans l'arbre » ; élision correcte pour les autres classes.
- ~~Sous-titre « Connectez-vous… »~~ masqué une fois connecté (salutation à la place).
- ~~Feuille de déplacement~~ : message « Aucune case accessible » quand le héros
  est bloqué (au lieu de « Touche une case éclairée ») + bouton **Fermer** toujours
  atteignable en bas de la feuille. (Le blocage total est aussi évité en amont,
  §2.2 masque « Se déplacer ».)
- ~~Feuille de ciblage~~ : ennemis d'abord, **alliés (tir ami) dans une section à
  part** signalée en rouge (le garde-fou de confirmation reste).
- ~~« 0 joueurs connectés » au hub + panneau « LE GROUPE » vide~~ : le compteur
  reflète la taille du groupe au hub, et un panneau **roster du hub** (noms +
  statut « prêt ») remplace la carte absente.
- **Option « Relever X » quand X est debout** : déjà filtré — `MenuMoteur` ne
  propose que les alliés `tombe=true` et `ResolveurTour::resoudreRelever` rejette
  une cible non tombée. (Observé sur menu périmé — désormais purgé, §2.1.)

Restent (efforts plus lourds / design) :
- **Narration IA** : vocabulaire méta (« boss final »), objets inventés (« sa
  hache » sac vide), épilogue de défaite qui omet les quêtes gagnées — enrichir
  le contexte des skills (inventaire réel, habillages, interdits doc 08).
- **Arbre de compétences** : pas de lignes/connexions visuelles de prérequis.
- **Narration en retard d'une action** (rythme) — jamais inversée (anti-inversion),
  mais surprend ; tient à la génération asynchrone, à réévaluer si gênant.

## 6. Non couvert par ce test (à valider une prochaine fois)

Votes de groupe (`retrait_joueur`, `choix_groupe`) · potions bues en jeu (aucune
achetée) · états Dread sur la manette (Endormi/Commandé jamais lancés par les
boss) · audio réel (barks, ambiance, voix TTS — non vérifiable en headless) ·
multi-personnages par joueur · portes verrouillées clé/levier · monstre errant
(fouille de trésor) · clôture en VICTOIRE (boss jamais vaincu).
