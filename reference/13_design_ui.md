# 13 — Guide de design des interfaces (brief pour Claude Design)

> **But du document.** Brief destiné à **Claude Design** pour concevoir les interfaces du jeu. Il couvre l'**UI/UX** (rôles, écrans, composants, direction artistique, contraintes). Les **règles et les chiffres** vivent dans les autres docs (Personnages, Combat, Sorts, Market…) ; ici on conçoit *comment ça se voit et se manipule*, pas *comment ça se calcule*.

---

## 1. Le produit en bref

Un **jeu de rôle tactique** inspiré du dungeon crawler de plateau, avec un **maître de jeu IA**. Multijoueur, en **temps réel**, **auto-hébergé** (cadre privé). Le moteur du jeu fait autorité sur toute mécanique ; l'IA narre et propose des **choix en menus** (jamais de texte libre).

Deux clients web (SPA), pensés comme deux expériences distinctes :
- **Écran de table (hôte)** — affichage partagé, contemplatif : narration, carte, combat, ambiance.
- **Manette (joueur)** — un appareil par joueur, tactile et rapide : fiche, actions, votes.

**Rôle ≠ matériel** : chaque client est une page dans un navigateur ; tablette, ordinateur ou téléphone peut tenir l'un ou l'autre rôle. À distance, chaque joueur peut afficher *sa propre* vue de table (pas de partage d'écran).

---

## 2. La dualité centrale : table vs manette

Tout le design découle de cette séparation. Ne pas faire « la même UI en deux tailles » — ce sont deux intentions opposées :

| | Écran de table (hôte) | Manette (joueur) |
|---|---|---|
| Intention | Regarder ensemble, ressentir | Agir vite, en privé |
| Distance de lecture | À ~2 m | Dans la main |
| Densité | Faible, cinématique | Focalisée sur l'action du moment |
| Interaction | Quasi nulle (affichage + TTS) | Cœur de la saisie (menus, votes) |
| Orientation | Paysage, grand | Portrait, mobile d'abord |
| Ton visuel | Atmosphère, profondeur | Clarté, gros boutons |

---

## 3. Principes UX directeurs

1. **Le menu est l'unité d'interaction.** Le joueur ne tape jamais de texte : il choisit parmi 2 à 5 options claires. Les options **invalides ne s'affichent pas** (filtrées par le moteur) ; si une est montrée, elle est exécutable.
2. **L'état du jeu est toujours lisible.** À tout instant : de qui est le tour, l'ordre d'initiative, les PV (Body/Mind), les conditions actives, le résultat du dernier jet.
3. **Le temps réel se ressent.** Les mises à jour (dégâts, déplacement, nouvel état) arrivent instantanément et sont **animées sobrement** pour être comprises, sans bloquer.
4. **L'attente IA n'interrompt rien.** Pendant un job (« le MJ réfléchit… »), l'interface reste vivante et non bloquée ; un indicateur discret suffit.
5. **Chaque rôle ne montre que ce qui lui sert.** La table privilégie l'ambiance ; la manette, l'action. Pas de redondance encombrante.
6. **Cohérence inter-clients.** Même langage visuel (couleurs, icônes, typo) sur table et manette, pour que l'un soit la continuation de l'autre.

---

## 4. Direction artistique

**Ambiance.** Dark fantasy de donjon : pierre, torches, parchemin, métal, ombre. Évoquer le plaisir tactile du jeu de plateau (grille, figurines, dés) en version numérique soignée — **pas** un tableau de bord SaaS plat et générique.

**Palette.**
- Base sombre (pierre/charbon, bleus-gris profonds) pour laisser respirer la carte et la narration.
- Accents chauds (torche : ambre, cuivre, braise) pour l'attention et l'action.
- **Couleurs d'élément** pour la magie : feu (rouge/orange), eau (bleu), terre (vert/brun), air (cyan/blanc).
- Couleurs d'**état** distinctes et cohérentes (poison, peur, sommeil, renforcé…), **jamais seules** porteuses du sens (toujours doublées d'une icône/d'un texte).

**Typographie.** Un **titrage** à caractère (fantasy mais lisible) pour les en-têtes/narration ; un **corps** très lisible et neutre pour les données de jeu. Éviter les polices génériques par défaut. Tailles généreuses sur l'écran de table.

**Matières & détails.** Parchemin pour les menus/fiches, cadres de pierre/métal gravé, légère granulation/vignettage — toujours subordonnés à la **lisibilité** et à la **performance**.

**Iconographie.** Un jeu d'icônes net et cohérent : **dé de crâne** (attaque/dégâts) et **dé de bouclier** (défense), éléments de magie, emplacements d'inventaire, conditions, votes. Style unifié (épaisseur de trait, coins, remplissage).

---

## 5. Inventaire des écrans à concevoir

### Écran de table (hôte) — paysage
- **Vue de quête / carte** : grille de tuiles, figurines héros + monstres, brouillard de guerre, surbrillance des déplacements/portées.
- **Bandeau de narration** : texte du MJ, lié au **TTS/ambiance** ; entrée/sortie fluide.
- **Ordre d'initiative & tour courant** : qui agit, qui suit (initiative figée par quête).
- **Résolution de combat** : animation des dés (crânes/boucliers), dégâts appliqués, états infligés.
- **Panneau d'état du groupe** : pour chaque héros, PV **Body** et **Mind**, conditions, statut « tombé ».
- **Fenêtre de clôture de campagne** : répartition d'équipement, partage d'or, résumé (moment solennel).

### Manette (joueur) — portrait, mobile d'abord
- **Fiche de personnage** : classe, niveau, attributs Body/Mind (dés de jet), PV Body/Mind (jauges), dés attaque/défense, conditions.
- **Menu d'action contextuel** : déplacer, attaquer, lancer un sort, fouiller, parler… en **cartes de choix** tappables.
- **Sac à dos / inventaire** : emplacements (arme, armure, sac, consommables), quantités.
- **Sorts** : par élément (feu/eau/terre/air), parchemins, récupération ; cible et portée claires.
- **Votes** : kick, TPK (recharger/abandonner), abandon, quête suivante — décompte en temps réel.
- **Phase marché** : panier étiqueté à son nom, achats/ventes, marchandage, **total projeté**, confirmation.
- **Forge (Nain)** : choisir un objet, une amélioration du catalogue, voir le coût.
- **Indicateur « le MJ réfléchit… »** et état de (re)connexion.

### Écrans communs / hub
- **Hub** (ville persistante) : accès marché, forge, choix de la prochaine quête ; évolue avec la campagne.
- **Créer / rejoindre un groupe** : identifiant, thème (fantasy), longueur.
- **Roster** : la liste des personnages d'un joueur, leur or et équipement persistants.

---

## 6. Système de composants à produire

- **Carte de choix** (l'élément roi) : états normal / sélectionné / désactivé / en cours ; supporte 2–5 options ; lisible d'un pouce.
- **Jauges PV** Body et Mind (distinctes visuellement) ; état « tombé ».
- **Badge d'état/condition** : icône + libellé + durée ; palette dédiée.
- **Dé de combat** : faces crâne/bouclier, animation de lancer et de résultat.
- **Tuile de carte & figurine** : héros (4 classes) et monstres ; surbrillance de sélection/portée/menace.
- **Bandeau d'ordre d'initiative** : pastilles ordonnées, tour courant mis en avant.
- **Module de vote** : options + décompte live + état « en attente des autres ».
- **Ligne d'objet** (inventaire/marché) : icône, nom, rareté (Commun/Peu commun/Rare/Unique), prix.
- **En-tête de narration** + indicateur TTS.
- **Indicateur de chargement IA** non bloquant.

Chaque composant doit définir ses **états** (normal, survol/pression, sélectionné, désactivé, chargement, erreur).

---

## 7. Modèles d'interaction

- **Choix** : taper une carte → envoi → le moteur valide → retour visuel (résultat de jet, conséquence). Jamais de saisie libre.
- **Votes** : déclenchés pour les décisions de groupe ; afficher le décompte et qui manque ; résultat clair.
- **Feedback du moteur** : un jet de dés, un dégât, l'application d'un état → animation courte et explicite (le joueur comprend *quoi* et *pourquoi*).
- **Attente IA** : « le MJ réfléchit… », l'UI reste réactive ; à l'arrivée, la narration et le nouveau menu apparaissent.
- **Reconnexion** : reprise transparente là où on en était (une seule session active par joueur ; une nouvelle connexion remplace l'ancienne).

---

## 8. Contraintes & exigences

- **Deux gabarits responsives distincts** : grand paysage (hôte) et portrait mobile (joueur) ; fonctionnels sur tout navigateur.
- **Lisibilité à distance** sur l'écran de table : grandes tailles, fort contraste, peu de texte simultané.
- **Cibles tactiles généreuses** sur la manette (pouce), espacement suffisant.
- **Accessibilité** : contraste suffisant, tailles confortables, **l'information n'est jamais portée par la seule couleur** (icône + texte en doublure), focus visibles.
- **Temps réel** : transitions qui aident à comprendre les changements d'état, mais **sobres et performantes** (l'appli tourne sur du matériel modeste auto-hébergé).
- **Cohérence** stricte du langage visuel entre les deux clients.
- **Langue** : interface en **français**.
- **Le ton narratif est configurable par groupe** (héroïque, sombre, comique) : il joue sur la **narration**, pas sur l'ossature de l'UI, qui garde un cadre fantasy stable.

---

## 9. Anti-patterns à éviter

- Look « dashboard SaaS » générique, plat et sans âme.
- Murs de texte sur l'écran de table.
- Petites cibles tactiles, listes denses sur mobile.
- Information codée **uniquement** par la couleur.
- Spinners bloquants pendant les jobs IA.
- Iconographie incohérente (styles mélangés).
- Mettre les mêmes contrôles partout : la table n'a presque pas besoin de boutons (les décisions passent **de préférence par le vote** côté joueur).

---

## 10. Livrables attendus de Claude Design

1. Une **direction visuelle** : palette, typographie, matières, exemples d'ambiance.
2. Un **système de composants** (section 6) avec leurs états.
3. Les **écrans clés** maquettés pour chaque rôle (section 5), au minimum : vue de quête + combat (hôte), fiche + menu d'action + marché (joueur), hub.
4. Les **règles responsives** des deux gabarits.

---

## Renvois

- Rôles, clients, temps réel, connexions → **doc 11 (Technique §1, §7)**.
- Attributs/PV Body-Mind, classes, conditions, inventaire → **doc 01 (Personnages)**.
- Dés, tour, initiative, « tombé » → **doc 03 (Combat)**.
- Sorts & éléments → **doc 02 (Sorts)**. Marché & forge → **doc 04 (Market)**.
- Hub, quêtes, menus, génération → **doc 06 (Quêtes & MJ IA)**.
- Cycle de vie d'une partie (création → clôture/TPK) → **doc 05 (Session)**.
