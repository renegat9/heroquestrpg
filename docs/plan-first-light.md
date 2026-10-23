# Plan — Implanter First Light

> ⚠ **Document DATÉ du 2026-09-30**, comme les autres `docs/plan-*.md` : un
> instantané de décision, pas tenu à jour. Les règles en vigueur iront dans
> `docs/regles/` ; les sources sont `reference/18_extensions.md` § First Light
> (§6 = livret de quêtes, pages photographiées par René).

## 1. Décisions de René (2026-09-30)

| Sujet | Décision |
|---|---|
| Le **Dragon** | Implanté. **2 cases de large** (photo de la figurine à côté d'un héros, 2026-09-30) — grand monstre comme l'Ogre. |
| Sorts Dread « **at will** » | **Pour le Dragon seul** : *Ball of Flame* sans limite (une action par lancer). Le budget global par rencontre reste la règle de tous les autres ; le Spectre reste bridé (divergence déjà assumée, `MoteurDread::USAGES_BASE`). |
| Où joue le Dragon | Nouvelle boîte de bestiaire **`first_light`**, cochable en manuel et dans la rotation automatique. Dragon = boss final de la boîte. **Qwindrak documenté seulement** : ses formes (*Synchroforms*) ne sont pas chiffrées, sauf sa forme de sorcier. |
| **Oracle** (bénédiction / malédiction) | Une **épreuve « Oracle »** posée sur la carte comme les autres épreuves. |
| **Cor des Hearthkin** | Rejoint le **pool d'artefacts** des coffres. |
| **Déplacement sans menace** | Sans monstre actif révélé, le **d6 de déplacement compte 4** au lieu d'être lancé. |

## 2. Ce qui est déjà en place (audit 2026-09-30)

- *Large Monsters* : `monstres.grande_taille`, emprise 1×2, attaque sur les cases environnantes diagonales comprises (`Grille::adjacenteAEmprise(..., diagonales)`).
- *Monsters Without Mind Points* : `App\Engine\SortMental` (Mind 0 → pas de cible).
- *Item Exchange* : `SeanceEchange`.
- Mobilier qui bloque vue et passage (règle optionnelle FL p. 8) : `FabriqueGrille::pour()`.

## 3. Bloqué faute de source — NE PAS implanter

> **Levé en partie le 2026-10-15** : *Healing Hearth* et *Glyph Key Scroll* ont désormais leur règle, relue sur le Drive (livret de quêtes p. 6-7) — voir « Décisions de René (2026-10-15) » en fin de document. *Qwindrak* et la règle des glyphes restent bloqués.

- **Healing Hearth** : la cheminée n'est pas au catalogue `mobiliers` et son emprise n'est mesurée nulle part. *(Levé le 2026-10-16 : emprise 1×3 décidée par René, règle construite — voir « Exécution (2026-10-16) ».)*
- **Glyph Key Scroll** : la règle des glyphes n'est pas sur les pages photographiées.
- **Qwindrak jouable** : formes non chiffrées (sauf *Warlock*), séquence de la confrontation finale partiellement illisible.
- **Hache du nain** (FL p. 6 : « handaxe instead of a shortsword ») : contredit le jeu de base 2021 que le projet suit — non porté sans décision.
- ~~Partage du mouvement du Dragon~~ (*Draconic Flight*) : **levé le 2026-10-16** (décision de René du 2026-10-15, « CONSTRUIRE »). Voir « Exécution (2026-10-16) » en fin de document.

## 4. Lots

- **A — Dragon et boîte First Light** : `MonstreSeeder`, `GabaritQueteSeeder` (rencontre finale), `DemarreurQuete::BOITES_THEMATIQUES` / `LIBELLES_BOITES`, `MoteurDread` (à volonté, vol draconique), registres et tests.
- **B — Règles de salle** : *Sly Storage* (fouille trésor), déplacement sans menace (`MenuMoteur::deplacementDuTour()`).
- **C — Oracle et Cor** : épreuve Oracle (bénédiction, malédiction *Mark of Zargon*, levée à 800 po au marché), artefact *Hearthkin Horn* et ses squelettes alliés.

> **Mise à jour 2026-10-05 — vérification des annexes du livret** : les
> 4 premières et 4 dernières pages du PDF **G0978** (livret de règles,
> distinct du livret de quêtes photographié) relues à l'image. Rien de
> neuf : la dernière page utile (p. 20-21, « Component Reference ») est la
> **même table** que celle déjà photographiée et transcrite en
> `reference/18_extensions.md` §6.2 — accessible directement dans ce PDF
> (texte extrait complet, noms + types ; seule la colonne « Map Symbol /
> Art » reste une icône non-OCRisable). Aucune carte de sort/objet/artefact
> reproduite ailleurs dans ce PDF : il réutilise le jeu de base à
> l'identique (§3 ci-dessus). Rien à retirer de la liste de photos — ce
> plan n'en tenait pas.

## Décisions de René (2026-10-15)

| # | Décision | Conséquence |
|---|---|---|
| 1 | **Draconic Flight fractionné : à CONSTRUIRE.** Texte du livret (p. 7) : « The dragon may take partial movement, perform an action, and then finish its movement. » | Lecteur à étendre : `MoteurDread::tentativeVolDraconique()` ne fait aujourd'hui qu'un déplacement d'un bloc, puis l'attaque. Il faut une **action entre deux moitiés du mouvement**. La dette nommée de §3 est levée. La citation de `docs/regles/sorts-dread.md` (l. 61) ne reprend pas ce texte : à remplacer par la phrase exacte (hors de ce plan). |
| 2 | **Healing Hearth** : règle sourcée sur le Drive (livret de quêtes p. 7) : « When a hero would draw a treasure card in a room containing a fireplace, they may instead heal 1 Body point. » | Sort du « bloqué » : portable à partir du texte. Restent à mesurer l'emprise de la cheminée et à l'ajouter au catalogue `mobiliers`. |
| 3 | **Glyph Key Scroll** : règle sourcée (livret p. 6) : détruit un glyphe magique découvert, sans action ; sans effet sur les glyphes déclenchés par le pillage ; disparaît après usage. | La règle des **glyphes** elle-même n'est pas sur ces pages : elle attend la photo de la ligne 4. |
| 4 | **Formes de Qwindrak et règle des glyphes : photo des notes de quête (quêtes 2 à 10) nécessaire.** | Qwindrak reste non jouable (§3) tant que les photos ne sont pas reçues. |

## Exécution (2026-10-16)

Chantier mené sur la décision 1 (Draconic Flight, à construire) et la décision 2 (Healing Hearth). La 3 (Glyph Key Scroll) reste non portée.

**Draconic Flight — CONSTRUIT (tour fractionné).** Texte exact de la carte (FL-Q p. 7, photo rendue) : « The dragon may take partial movement, perform an action, and then finish its movement. The dragon's powerful wings allow it to move through squares occupied by heroes and monsters, though it cannot end its movement on an occupied square. »
- Lecteur : `MoteurDread::tourDraconique()`. Un budget de déplacement en points, coupé en deux jambes autour d'UNE action. **Règle de coupure** (écrite dans sa docblock et dans `docs/regles/sorts-dread.md`) : le trajet d'approche est suivi case par case et coupé à la première case libre d'où le tour a une action (un sort lançable de cette case, ou un héros au contact après au moins un pas) ; le sort prime ; après un coup, pas de jambe 2.
- Jamais d'arrêt sur une case occupée ni interdite à l'arrêt : `Grille::empriseArretPossible()`, sur toute l'emprise du Dragon (1 × 2).
- Payloads à trois temps (`deplacement_monstre` avec `vol` et `jambe` 1 ; l'action ; `jambe` 2) — contrat dans `docs/contrat-api.md`, fil « prend son vol » / « achève son vol », table : `ResolveurTour::animerTrajetsDread()` enregistre les jambes, et `SceneDeTable` fait attendre la scène d'un sort la marche qui le précède.
- Tests EN JEU : `tests/Feature/Partie/DragonFirstLightTest.php` (11 tests). Sur le code de `HEAD`, 8 échouent (les « AVANT » — le sort seul sans mouvement, l'absence de jambe, la téléportation à la table, le fil et la scène).
- Limite connue : la table ne sépare pas la jambe 1, le sort et la jambe 2 dans le temps. Quand une marche PRÉCÈDE un sort, la scène du sort attend la fin de toutes les jambes de la figurine (la file de trajets est par figurine). Une séquence stricte demanderait un trajet par segment de scène : non fait.
- Le dossier `docs/regles/sorts-dread.md` portait une citation fausse de la carte (« the Dragon may interrupt its movement… ») : remplacée par le texte exact.

**Healing Hearth — CONSTRUIT le 2026-10-16 (emprise tranchée par René : 1×3).** Le texte qui suit décrit l'état AVANT cette levée, conservé tel quel ; la suite de la section dit ce qui a été posé. Trois faits, tous écrits :
- `reference/18_extensions.md` (§6, l. ~2149) : « l'emprise de la **Fireplace** … reste ⚠ non trouvé ».
- `reference/17_mobilier.md` (§1, ligne Cheminée) : emprise « ⚠ non établi par le livret » ; seule la règle est sourcée.
- `database/seeders/MobilierSeeder.php` (en-tête) : la cheminée, la table du sorcier et le portant ne sont pas au catalogue parce que « leur emprise n'a aucune mesure indépendante et resterait une invention si on la codait ».
Donc la donnée (une ligne `mobiliers`) ne peut pas être semée sans inventer une valeur. Et la clé de vocabulaire (`MotsClesMobilier`) ne peut pas être déclarée d'abord : son test « dans les deux sens » exige un porteur au catalogue. Ordre respecté : rien n'est écrit tant que la donnée manque.
- **Design prêt pour quand l'emprise sera tranchée** (rien de codé) : *scope* — la règle est propre à la boîte First Light, donc `BestiaireGroupe::duGroupe($groupe)->contient('first_light')` (même patron que `horreur_des_glaces` dans `Equipement`) ; *lecteur* — calqué sur la Mare (`boire_mare`, `MoteurTerrain`, `docs/regles/carte-donjon.md`) : une option « soigner 1 Body au lieu de piocher » offerte au MÊME endroit que `fouiller_tresor`, seulement dans une salle qui contient une cheminée, et seulement à un héros qui a perdu au moins un point (le menu n'offre pas ce que le résolveur refuse ; à plein Body, la carte reste la seule option) ; elle dépense la fouille du héros dans la salle et ne tire aucune carte.
- **Décision demandée à René** : (a) mesurer l'emprise de la cheminée sur une occurrence (la planche Component Reference, FL p. 20-21, ne montre qu'un rectangle allongé sans grille, analogie seulement) ; (b) accepter 1 × 2 comme divergence NOMMÉE et provisoire (⚠ non acquise) ; (c) attendre. Tant que ce n'est pas tranché, aucune ligne de catalogue.

**Tranché par René (2026-10-16) — 1×3** (« 3-Space Furniture (1x3 Grid) », et la cheminée validée à
1×3 sur les cartes de quête de F2847, mesurée ≈ 2,6-2,7 cases). Ce qui est posé :
- **Donnée** : la Cheminée au catalogue, en JEU DE BASE (aucune boîte : RB p. 4 la compte parmi les 15
  pièces de la boîte de base ; seule la RÈGLE est First Light), 3×1, `bloque_vue` et `adosse_au_mur`
  (convention « famille bibliothèque », non sourcée), ni fouillable ni destructible
  (`difficulte_destruction` absente = indestructible, faute de source). `MobilierSeeder` ; les autres emprises passent aux décisions de René du même jour (bibliothèque, armoire, râtelier en 1×3 ; table, tombeau, établi en 3×2) par la migration `2026_10_16_130000_mobilier_trois_cases`.
- **Lecteur** : `App\Partie\MoteurCheminee` — point de passage unique, lu par `MenuMoteur` (l'option
  `soin_cheminee`, au même endroit que `fouiller_tresor`) ET par `ResolveurTour::resoudreSoinCheminee()`
  (le point rendu, la fouille dépensée, aucune carte). Scope : `BestiaireGroupe::contient('first_light')`.
  Seulement un héros qui a perdu un point.
- **Annonce** : `JournalCombat` (`soin_cheminee`, registre et phrase). Pas de scène de table, comme la Mare.
- **Contrat** : `docs/contrat-api.md`, sous la Mare.
- **Tests** : `FoyerFirstLightTest` (les conditions du menu, le geste, le journal) et
  `MobilierTroisCasesTest` (catalogue, migration, emprise figée d'une quête en cours, plancher et connexité
  sur 30 graines × 3 bestiaires, cheminée posée sous tout bestiaire, adossement).
- **Restent ouverts** : la table du sorcier (3 cases, hors catalogue) ; le portant ; l'image de la cheminée
  (aucune génération lancée, l'emblème SVG de repli la remplace) ; les illustrations existantes de la
  bibliothèque, de l'armoire, du râtelier, de la table, du tombeau et de l'établi ont été générées pour les anciennes emprises (`config/images.php`
  les décrit « occupant N sur M cases ») et ne sont pas régénérées.

**Glyph Key Scroll — non porté, dette nommée.** Texte lu sur la photo FL p. 6 (rendu `FL1_p01`, `reference/18_extensions.md` §6) : « Use this scroll at any time to destroy a discovered magical glyph, no action required. This scroll has no effet on magical glyphs disturbed by looting. After you use this scroll, it disappears. » La règle des **glyphes magiques** elle-même n'est sur aucune page photographiée : sans elle, le parchemin détruirait une case dont on ignore ce qu'elle fait. Aucun glyphe n'existe au code (`grep` vide sur `app`, `config`, `database`).

**Qwindrak, formes et règle des glyphes** : inchangés (décision 4, photo des quêtes 2 à 10 toujours attendue).

## Exécution (2026-10-17) — Qwindrak

Décision de René (2026-10-17) : porter **Qwindrak**, boss de First Light, avec la règle officielle des Synchroformes et le tirage de la carte de Banjo (validé : « c'est le même fonctionnement »). Règle en vigueur : `docs/regles/bestiaire-et-rencontres.md`, section « Qwindrak the Warlock ».

- **Pioche** : Qwindrak commence en Warlock (6/3/6/3/6). À sa première mort, cinq formes existantes (Abomination, Gargouille, Guerrier du Chaos, Gobelin, Orque) sont mélangées au dé injecté, le **Dragon au fond**. Chaque forme vaincue cède la place, à la même case, à la suivante. L'ordre est stocké en base (`instances_monstres.formes_a_venir`) et jamais publié.
- **Sorts actifs** : ils cessent à chaque changement de forme, et la table le dit. Les compteurs de la rencontre restent. La chaîne des phases (Gretzl…) garde ses sorts, comme avant.
- **Flamme du Dread à l'arrivée du Dragon** : chaque héros de la même salle lance un dé rouge, 1-3 lui coûte 1 Body. **Source du texte** : `reference/18_extensions.md` §6.1, notes de quête photographiées (« le dernier vaincu, Qwindrak prend sa forme de Dragon et souffle la flamme du Dread : chaque héros dans la même salle que lui lance un dé rouge ; sur 3 ou moins, il perd 1 Body Point »). Ce texte ne figurait pas dans le présent plan ; il est reporté ici tel quel de la référence. Elle voyage dans `changement_phase.flamme`, avec une scène de table et une ligne de journal par héros.
- **Place du Dragon** (1 × 2) : la case sûre la plus proche si l'ancre ne tient pas, jamais sur un héros.
- **Boss du thème** : Qwindrak remplace le Dragon dans la rencontre finale « Confrontation finale ». Le Dragon n'est plus acheté seul. Il n'existait qu'une boîte `first_light` ; son boss est désormais Qwindrak.
- **Non porté, nommé** : répertoire de sorts du Warlock (⚠ non trouvé : ni la note ni la carte ne le listent) ; tuiles Skull du Dragon (⚠ non trouvé, le Dragon entre à pleins Body) ; issues « escape » et « true warlock appearance » de la note de quête (hors de la décision de René).
- **Seeders et migration à jouer** : migration `2026_10_17_100000_formes_a_venir_sur_instances_monstres` (colonne nullable, additive), puis `MonstreSeeder` (Qwindrak, flamme du Dragon) et `GabaritQueteSeeder` (rencontre finale). Les deux seeders sont `updateOrCreate` et ne suppriment rien.
- **Tests** : `tests/Feature/Partie/QwindrakFirstLightTest.php` (15), registre `AdversairesNommesTest`, `SorciersNommesTest` (exemption des formes de pioche).

## Exécution (2026-10-22)

Lu sur le livret de quêtes First Light (p. 7 et p. 31, note C), pas sur la photo des notes. Chantier QWINDRAK, branche `qwindrak-wip`, **non committé** (l'orchestrateur joue la migration et les seeders après sauvegarde).

- **Répertoire sourcé, dans toutes les formes.** « In all forms, Qwindrak knows the Dread spells Tempest, Cloud of Dread, and Lightning Bolt » : archétype `qwindrak` (`config/archetypes_lanceurs.php`) = Tourmente, Nuée d'Effroi, Éclair de Chaos. Une forme de pioche ne porte rien d'elle-même : elle garde l'**ORIGINE** de la figure (`instances_monstres.origine_monstre`, écrite AVEC la pioche, rejouée par le snapshot). Un seul point de passage, `MoteurDread::repertoireDe()` — l'origine ∪ la fiche courante, pour que le Dragon garde sa Boule de Flammes à volonté. Le palier et le budget se lisent sur l'origine (boss) : Nuée d'Effroi reste lançable sous une forme de tier base. Qwindrak entre au gabarit par son archétype, sur le modèle de Festral et de l'Archimage elfe (il sortait de `creatures`).
- **Le météore** (mot-clé `meteore`, `des_attaque` 2, lecteur `MoteurMeteore::declencheur()`) : une attaque ou un sort d'un HÉROS qui vise la figure, réussi ou non, lui vaut 2 dés d'attaque contre ce héros. Il part après la résolution de l'action (file jouée dans la transaction de `resoudre()`, et à la fin d'une riposte), seulement si la figure est encore en jeu : la chute du Dragon n'en déclenche aucun. La résolution est `resoudreAttaqueMonstre()` (dés, défense réelle, `infligerAHeros()`, journal). Annoncé : fil, scène « Météore de … » (genre `attaque`), liste plate `meteores` (contrat : `docs/contrat-api.md`).
- **Coût** : `cout` 27 → **38**, méthode mesurée (formes 28,2 ; météore × 5/3 ; trois sorts +3 ; droite des boss). Le calcul complet est dans `docs/regles/bestiaire-et-rencontres.md`, et la valeur est une **mesure par proxy de dés**, à valider en partie. À quatre héros de niveau 1, il ne reste que 2 points d'escorte à l'arc 1 (13 avec l'ancien coût).
- **Sources levées** : tuiles Skull (aucune pour la quête 10 : le Dragon entre à pleins Body), répertoire (ci-dessus), et « Dread Warrior » = notre `Guerrier du Chaos` (nom de 1989).
- **Non porté, nommé** : les issues « escape » et « true warlock appearance » concernent les quêtes 1 à 9, pas le combat final ; les règles propres à la quête 10 (relance de Mind, Hall of the Dead) ne s'appliquent pas aux quêtes générées. La Boule de Flammes à volonté et la flamme du Dragon ne sont pas chiffrées dans le coût.
- **Migrations et seeders à jouer** : la migration `2026_10_17_100000_formes_a_venir_sur_instances_monstres` gagne la colonne `origine_monstre` (nullable, additive ; elle n'a jamais été jouée sur la vraie base, donc on l'a modifiée plutôt que d'en écrire une seconde). Puis `MonstreSeeder` (Qwindrak : archétype, `meteore`, `cout`) et `GabaritQueteSeeder` (rencontre finale : `archetypes` et `creatures`). Les seeders sont `updateOrCreate` et ne suppriment rien. `config/barks.php` (voix `sorcier`, `BarksArchetypesTest`).
- **Tests** : `QwindrakFirstLightTest` (27), `SorciersNommesTest` (repli « leader de coût » sans plafond de budget : la rencontre finale s'achète même au-dessus du budget), `AdversairesNommesTest`, `BarksArchetypesTest`, `AnnoncesEffetsAutomatiquesTest`.
