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

- **Healing Hearth** : la cheminée n'est pas au catalogue `mobiliers` et son emprise n'est mesurée nulle part.
- **Glyph Key Scroll** : la règle des glyphes n'est pas sur les pages photographiées.
- **Qwindrak jouable** : formes non chiffrées (sauf *Warlock*), séquence de la confrontation finale partiellement illisible.
- **Hache du nain** (FL p. 6 : « handaxe instead of a shortsword ») : contredit le jeu de base 2021 que le projet suit — non porté sans décision.
- Partage du mouvement du Dragon (*Draconic Flight* : « interrompre son mouvement pour agir puis le finir ») : seulement si un lecteur réel l'utilise (IA du monstre) ; sinon dette NOMMÉE, jamais une clé décorative.

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
