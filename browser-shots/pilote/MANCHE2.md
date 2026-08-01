# Manche 2 — objectif : terminer la quête

La première manche a servi à débusquer les bugs d'interface : c'est fait, ils sont
documentés. **Cette fois l'objectif est de finir la quête**, donc joue vite et
efficacement, sans t'arrêter à chaque détail.

## Condition de victoire (vérifiée dans le moteur)

La quête se termine quand **plus aucun monstre n'est actif**. Il faut donc
**nettoyer le donjon**. Carte : 48 × 44, **3 salles**, 4 portes, **11 monstres**.

- **Salle 0** (x 15-19, y 25-29) : votre point de départ, vide.
- **Salle 1** (x 26-32, y 24-29) : **6 monstres**, à ~9 cases à l'EST du départ.
- **Salle 2** (x 28-31, y 14-17) : **5 monstres**, au NORD de la salle 1.

Sur la carte, x augmente vers l'est, y augmente vers le sud. Vous démarrez vers
(16-18, 26). Allez à l'est d'abord, puis au nord.

## Consignes de jeu

1. **Avancez groupés vers la salle 1.** Ne dispersez pas le groupe.
2. **Ne perdez pas d'actions** : « Fouiller la zone » n'a jamais rien donné en
   manche 1. Privilégie déplacement + attaque. Fouille seulement s'il n'y a
   vraiment rien d'autre à faire.
3. **Ordre de marche** : Bram (8 PV) devant, Thora (7 PV) avec lui, Sylvaine
   (6 PV) derrière, Aldric (4 PV) en dernier. Aldric ne doit JAMAIS être au
   contact d'un monstre.
4. **En combat** : chaque héros au contact attaque. Aldric soigne (Eau de
   Guérison) dès qu'un héros descend sous la moitié de ses PV, sinon il lance un
   sort offensif s'il a une ligne de vue dégagée.
5. **Ne bouchez pas la porte.** Un seul héros dans une case de porte bloque la
   ligne de vue de tout le groupe derrière lui. Dès que la salle est ouverte,
   **entrez dedans et étalez-vous**, ne restez pas en file dans le couloir.
6. **Décide vite.** Un tour = un déplacement + une action. Ne relis pas tout
   l'écran à chaque fois : `montour` te donne la narration et les options,
   choisis, joue.

## Bugs connus — n'y perds pas de temps, ne les re-signale pas

- Après ton action, des options restent cliquables mais renvoient
  « Tu as déjà agi ce tour. » → prends « Terminer le tour » / « Attendre ».
- Un sort offensif dont le ciblage n'affiche que des alliés = **aucune ligne de
  vue** sur un monstre. Ne le lance pas : déplace-toi pour dégager un angle.
- Les boutons « Fermer » des feuilles sont recouverts par le rond « Hub » : pour
  refermer une feuille, **clique le fond assombri en haut de l'écran**
  (`hq <session> click '{"selector":".dep-ov","n":0}'`), jamais le bouton.
- Le fil de combat de l'écran de table est en retard : normal, ignore.

## Ce qu'on veut encore savoir

Signale seulement ce qui est **nouveau** par rapport à la manche 1, en
particulier tout ce qui touche à la **fin de quête** : montée de niveau, butin,
retour au hub, marché, mort d'un héros et relevage. Si un héros tombe, dis
précisément ce que tu vois.

Si la quête se termine, préviens l'orchestrateur immédiatement.
