# Front — manette et écran de table

> Extrait de `CLAUDE.md` le 2026-09-06, **verbatim**. Ce sont les **règles en
> vigueur** — pas un document daté comme `docs/plan-*.md` / `docs/verdict-*.md`.
> Index : `CLAUDE.md` §« Règles établies ».

**The controller's map is zoomed, and panned by a D-pad** (René, 2026-08-28). Cells were **22 px** — below any touch-target guideline, so players hit the neighbour, and the markers on them shrank to a smudge. They are **38 px** now, which no longer fits the screen: the four arrows plus a **recentre-on-my-hero** button in their middle are the *counterpart* of the zoom, not a separate feature. The finger is for **choosing a cell**; making it also drag the map makes both gestures ambiguous, since a short drag reads as a tap and sends the hero somewhere unwanted — native scrolling still works, the pad only makes it explicit. The pad hides itself when nothing can scroll, and each arrow greys out at its own edge (a dead button reads as a frozen map). ⚠ **Marker glyphs did not follow the cell**: `62 %`/`64 %` resolve against the *inherited font-size* (~16 px, so ~10 px whatever the cell), furniture was pinned at 14 px, and traps sized on `1.3vw` — the *screen's* width. Nothing showed while the map was 22 px; the first zoom would have given big cells with tiny symbols. `--dg-cell`'s companion `--dg-icone` is now set by the parent alongside the cell size, defaulting to the old values for the table (whose cells are `1fr` and cannot state pixels). ⚠ And wrapping the scroller to host the pad **exposed a latent overflow**: `.dep-ov`'s grid track sized itself on the sheet's content, so the sheet reached its 520 px `max-width` on a 412 px screen — measured — putting the header's close cross and half the pad off-screen. `overflow: auto` had masked it as long as the scroller was the sheet's *direct* child (a scroll container has zero minimum size, a plain `div` does not); `grid-template-columns: minmax(0, 1fr)` fixes it at the root. It is the §2.2 defect returning through another door.

**The table shows what is IN the active hero's room** (René, 2026-08-29). A second button beside the legend: the legend explains the **symbols**, the preview enumerates the **contents** — figures (a dressed monster carries its catalogue `nom_base` underneath, since "Le Noyé de Gorrim" does not say *goblin*), furniture, épreuves, known traps, levers and exits, each with its illustration. It follows the turn order by itself. ⚠ It **remembers** the last active hero: during the monster phase no hero has the hand, and the panel would blink out at every turn's end — exactly when the table is watching what happens in the room. `EtatGroupe.carte` gains `salles: [{index, x, y, largeur, hauteur}]`, ⚠ **discovered rooms only** — `cases` is already fogged, and publishing every rectangle would hand over the number, size and position of rooms never opened, the fog walked round by the back door. ⚠ `App\Partie\Salles::indexDe()` is now the single point of passage for "which room holds this cell?": the question was asked in **six** places, each with its own loop (two in `ResolveurTour`, one in `DemarreurQuete`, one in `AssembleurCarte`, the fog closure and the lever filter in `EtatGroupe`). They all said the same thing, which is precisely the risk — the rule is too simple for anyone to notice one copy drifting, and a single `<=` for `<` moves a border cell to another room depending on who is asking.

**The quest objective is visible at last** (`EtatGroupe.quete.objectif` / `objectif_libelle`, from `Quete::objectif()`). The engine had read `structure.objectif` since it became the real victory condition, but showed it to nobody: a party explored 8 rooms of 10 drifting 38 squares away from the boss it had to kill. An unknown objective renders nothing rather than inventing a brief — same caution as `objectifAccompli()`, which treats the unknown as accomplished. ⚠ **It was published and then rendered on NO screen at all** — `grep objectif resources/js` returned nothing, store included — so « visible at last » was true of the payload and false of the game (René, 2026-09-05). The table's header now carries it **permanently**, never behind a button: the objective is what the whole group steers by. ⚠ `objectif_accompli` joins it, because the label says *where to go* and never *whether you got there* — a party left the dungeon without knowing whether it had succeeded, and since the third level-up trigger that is also the only thing saying whether the level is earned. It publishes the **engine's own verdict**, the one that opens `quitter_donjon` and fires `MonteeNiveau`: two answers to one question would drift, and a screen lies with a screen's authority. `null` with no declared objective — never announce « accomplished » where nothing was asked. `objectif_majeur` rides along so an **ordinary** quest can say a level is at stake, which nothing else on screen would betray (a milestone announces itself by its boss). ⚠ The label **wraps** rather than truncating: « Atteindre la salle la plus profonde et en ram… » no longer says where to go, which is the one thing it is for. ⚠ **On the controller it REPLACED the narration band** (René, 2026-09-05: « on n'a pas vraiment d'espace sur la manette »). The récit is read aloud *and* stands on the table screen, so on a phone it was a duplicate holding the one band of space there is; the objective was visible nowhere and is what a player steers each move by. ⚠ **The GM's text now lives on the narrator display ALONE** — René's call, confirmed 2026-09-05 when the consequence was put to him: a game played with **no table screen** shows no narration at all on the phones. That is accepted, not an oversight; do not re-add a narration band to the controller to « fix » it. The récit is a shared, spoken moment, and the phone is for acting. ⚠ And the Action tab is now **two independent scroll panes** — actions above, `Fil du combat` below, capped at 40 % so a chatty log never pushes the buttons off-screen. They shared the page's scroll: reading the log scrolled the actions away, and acting hid what had just happened. ⚠ The log pane **auto-scrolls to its newest line** (`nextTick` + `scrollTop = scrollHeight`): the shared scroll used to put new entries at the bottom for free, and a log that does not follow its own latest entry is worse than no log.

**The quest opens on a full-screen card, and says what it is doing while it builds** (`OuvertureQuete.vue`, `App\Events\EtapePreparation`, René 2026-08-21). The scene illustration existed but only ever appeared as a **56 px thumbnail** in the top bar; and building a quest takes one to two minutes during which the table said *nothing* — the group waited in front of a mute dungeon with no way to tell progress from a freeze. One panel now covers both moments: the announced step while it builds (`habillage → scene → recits → voix → pret`, a segmented gauge rather than a percentage — step durations differ by an order of magnitude), then the scene image full-bleed with the opening text. ⚠ It closes on **end of reading**, the same signal that thaws the controllers (B1), never on a timer: cutting mid-sentence while the narrator speaks aloud would be worse than nothing. ⚠ `pret` is broadcast from a **`finally`** in the last job, which exits five different ways (no TTS key, setting off, quest gone, quota exhausted, normal end) — miss one and the table stays "loading" forever. The step is also **cached and exposed in `EtatGroupe.preparation`**, so a table opened or reloaded mid-sequence finds where things stand.

⚠ **The preparation is a HEADER STRIP, not a veil** (René, 2026-09-05: « on est capable de jouer alors qu'il y a un popup »). The card was born when the group genuinely waited; since the 2026-08-18 switch a quest is **playable the instant it is created** — map, monsters and menus all exist, the scripted ceremony is read in seconds and the controllers thaw — so the full-screen veil opened *after* play had begun and covered the dungeon for one to two minutes while people were acting on it. Only the **opening** (scene image + text, read aloud) still takes the screen. ⚠ The step labels name **what is being built** (« L'illustration du lieu ») instead of three sentences starting with « Il »; and `index` is the rank of the step **in progress**, 1..4 — it was `array_search()`, i.e. 0 for the first, so the gauge ran one step behind its own caption and **never reached its last notch**. The current notch pulses: a slow step must not look like a frozen screen, which is the one thing this gauge exists to answer. ⚠ This is why `GenererImagesQuete` takes a `volet`: the **scene** is dispatched *before* the récits and the **boss portraits** after. Splitting it is a sequencing decision, not a tidying one — without it the opening card would never have its image on a first quest, which is the whole point of the card. The price is stated: the pack lands at ~2 min instead of ~1, so a little more generic fallback at the very start of a quest.

**Narrator emergency menu.** A second button next to the gear icon (`.status-urgence`, table screen only — no group context on `/narrateur` yet) opens `UrgenceNarrateurPanel.vue`: two destructive actions, each behind an inline confirmation step (no separate modal). "Recommencer la quête actuelle" → `POST /api/groupes/{id}/quete/redemarrer` (`Sauvegarde::redemarrerQuete`) restores the current quest's `debut_quete` snapshot **at any time**, not just after a TPK — unlike `/reprise` it has no failure-state guard, since it's meant for "this is going very badly, reset it" mid-quest. "Arrêter la campagne" → `POST /api/groupes/{id}/cloture/urgence` (`ClotureCampagne::arreterImmediatement`) ends the campaign **immediately with no per-player confirmation ritual** (unlike the normal `/cloture` flow): it builds a phase with every confirmation pre-set to `true` and dispatches the same `CloturerCampagne` job as a normal closure — same gold split, `personnage_historique` entry, `.cloture.terminee` broadcast, full purge — just skipping the wait. Both routes are **member OR table** auth (same trait as `/reprise`), so the button works from the table's session-only login, and both are meant strictly for the table screen, not the manette.

⚠ **Tout miroir client d'une règle serveur est une SECONDE COPIE de cette règle** — et il ne dérive pas le jour où on l'écrit, il dérive le jour où la règle bouge sans lui. `DeplacementSheet.vue` recalcule en JS les cases atteignables (pour la surbrillance et le tap), et il a dérivé **trois fois en deux jours** : il ignorait `cout_deplacement` quand la Rivière gelée est arrivée (il comptait en cases quand le serveur comptait en points), il ignorait la case d'embrasure quand les portes ont cessé d'être des arêtes, et il **traitait un compagnon comme un mur** alors que le moteur laissait le dépasser depuis le 2026-09-04.

⚠ **Le cas du compagnon mérite d'être retenu** (René, 2026-09-11 : « on n'arrive toujours pas à se déplacer à travers d'autres héros, je croyais que ça avait été corrigé »). Il avait raison sur les deux points : c'était corrigé, et ça ne marchait pas. Le moteur porte la règle du plateau (« on peut traverser la case d'un autre héros, pas s'y arrêter » — LR p. 12, doc 16 §5) avec son quatrième jeu de cases `Grille::$alliees`, **et un test la verrouille** (`DÉPASSE un compagnon sans pouvoir s'arrêter sur sa case`, vert depuis le premier jour). Le client, lui, fondait alliés et monstres dans un seul `occupees` et s'arrêtait sur les deux. **Une règle serveur juste, testée, et inatteignable par le joueur pendant une semaine** : la suite verte ne dit rien de ce que l'interface autorise.

Le miroir sépare désormais deux ensembles, comme le serveur : les **monstres** bloquent, les **alliés** se traversent mais ne sont jamais proposés comme destination — sinon le joueur taperait une case que le résolveur refuserait, ce que « le menu ne propose jamais ce que le résolveur refusera » interdit dans l'autre sens.
**Un compagnon porte ses TROIS PREMIÈRES LETTRES sur la carte de la manette** (René, 2026-09-11 : « peux-tu identifier les joueurs sur la carte de la manette », puis « mets les 3 premières lettres »). Avant, la case d'un allié était un aplat bleu sans le moindre signe : on voyait qu'il y avait quelqu'un, jamais qui. Une initiale seule ne se rattachait pas assez vite à un nom prononcé autour de la table ; trois lettres, si. ⚠ **Pas de portrait** : à **38 px** une illustration devient une tache — même leçon que les images de carte, qui vivent dans la **légende** et jamais sur la grille, parce que la silhouette est ce que le joueur lit d'un coup d'œil. ⚠ Trois lettres ne tiennent pas dans un **disque** : la pastille est un rectangle arrondi, sans quoi il faudrait descendre sous 8 px pour tenir dans la corde. ⚠ Et la teinte se dérive de l'`id` parce que **deux héros peuvent porter le même nom** — constaté en partie, deux « Thrakor » : `THR` et `THR` se distinguent alors par la couleur, stable d'un tour à l'autre.

⚠ **Ce même correctif a réparé une régression vieille d'une heure** : `surcouche()` ne testait que `occupees`, qui venait de perdre les alliés quand on les a rendus traversables. Ils ne tombaient plus dans `accessibles` non plus (on les traverse, on ne s'y arrête pas) — donc un compagnon ne renvoyait plus **rien du tout** et se peignait comme du sol nu. Séparer un ensemble en deux oblige à revisiter **tous** ses lecteurs, pas seulement celui qu'on corrigeait.
⚠ **`menu.situation` était produit par le serveur et rendu NULLE PART** (constaté le 2026-09-11). Le champ existe depuis longtemps dans `MenuMoteur` et porte ce que le moteur veut dire au joueur **en plus des options** : « Une attaque supplémentaire vous est offerte ce tour », « Vous ne pouvez pas agir ce tour », « Tour terminé — au tour des autres héros », et le message de secours d'un menu de repli (« Le maître du jeu a perdu le fil. Reprenez la main »). Aucune occurrence de `situation` dans tout `resources/js/` : un **champ décoratif côté client**, exactement l'image en miroir de la clé sans lecteur que ce projet chasse côté serveur.

⚠ **C'est ce silence qui a fait croire à un bug de la Potion d'héroïsme** (René : « la potion d'héroïsme ne donne pas de 2e attaque »). Elle la donnait : le drapeau survit à la première frappe, le menu rouvre le créneau d'action, le résolveur accepte. Un test en jeu le prouve de bout en bout (`PotionHeroismeTest`). Mais rien ne l'annonçait — le joueur frappait une fois, revoyait un menu d'apparence identique, et terminait son tour. **Une règle qui marche et que personne ne voit est indiscernable d'une règle cassée**, et c'est le joueur qui paie la différence.

⚠ Le test qui existait (`PotionsOfficiellesTest`) éprouvait le **drapeau**, jamais la seconde frappe. Un drapeau posé ne prouve pas qu'une option est atteignable : « le menu ne propose jamais ce que le résolveur refusera » se lit **aussi dans l'autre sens**.

⚠ **Et la correction précédente ne suffisait pas** (constaté le lendemain, en repartie : « j'ai pris une potion d'héroïsme après avoir attaqué mais l'action d'attaque était grisée »). Annoncer le bonus (`menu.situation`) et l'ACCEPTER (le résolveur) sont deux choses ; encore fallait-il que le BOUTON reste cliquable. `ActionTab.creneauConsomme()` grisait toute option d'attaque dès `a_agi`, sans jamais regarder `attaque_supplementaire` — et `EtatGroupe` ne le publiait même pas, donc le client ne pouvait matériellement pas savoir. Deux réparations pour un seul défaut à deux étages : `EtatGroupe` publie désormais `attaque_supplementaire` à côté d'`a_agi` (§ turn slots ci-dessus), et `creneauConsomme()` grise `attaque` comme `a_agi && !attaque_supplementaire` — troisième cicatrice sur ce même miroir après `actionner_levier` et `objet_libre`. ⚠ **Signalé, non corrigé** : la Réserve arcanique du magicien (second sort du tour) porte la même maladie — ni publiée ni lue côté client — et grisera « Lancer un sort » après un premier sort là où le serveur en accepterait un second.
**Le serveur publie la DÉCISION, le client ne la re-dérive jamais** — la réponse de fond aux cinq dérives de miroir de cette semaine (coût de déplacement pondéré, case d'embrasure, compagnon traversable, seconde attaque, second sort). ⚠ La règle se reconnaît à un test simple : **le client a-t-il les données pour trancher ?** S'il lui manque un talent, une charge, une pièce équipée — il ne les a pas, et lui faire deviner fabrique un miroir de plus. `EtatGroupe` expose donc `attaque_supplementaire`, `sort_bonus_disponible` et `franchit_figures` sur l'entité héros : trois **conclusions**, pas trois conditions à recomposer.

⚠ **`franchit_figures` a rendu explicite un troisième calcul en double** : l'expression `capacites->a('franchit_figures') || sorts->franchitFigures()` vivait dans `ResolveurTour`, manquait dans `MenuMoteur::peutSeDeplacer()`, et le client la devinait à l'envers. Elle est désormais `MoteurSorts::mobiliteCombatDisponible()`, **un seul point de passage** — et `MotsClesTalent` pointe son lecteur dessus, ce qui fait rougir `GrilleTalentsTest` si les deux se séparent à nouveau.

**Une carte qui se ferme sur la fin de lecture n'existe pas quand rien ne lit.**
`OuvertureQuete` — la carte plein cadre qui plante le donjon — s'ouvrait et se
refermait **dans le même tick** dès que la voix n'était pas active : `useVoix.narrer()`
appelle `apres` sur-le-champ quand `actif` est faux (autoplay non débloqué, le
narrateur n'a pas cliqué « Activer le son ») ou que `muet` l'est (préférence
persistée en `localStorage`), et c'est ce `apres` qui portait `fermerOuverture()`.
Signalé par René (« popup à la 1re quête, absent à la 2e ») : les deux diffusions
étaient bien parties côté serveur — mesuré sur la quête 99, seq 1159 et 1160,
toutes deux `ouverture: true`. Le défaut n'était ni dans le job, ni dans le
chaînage, ni dans le garde anti-inversion des séquences. `apres` reçoit désormais
**`lu`** (true = réellement lu jusqu'au bout), et la table **diffère** la fermeture
d'une durée de lecture (`useVoix.dureeDeLecture`, plancher 8 s, plafond 45 s)
quand rien ne la rythme. ⚠ Jamais de minuteur **par-dessus une voix** : couper une
phrase en cours de lecture reste l'arbitrage d'origine. Vérifié en conditions
réelles, son volontairement non activé : carte visible à t+87 s, refermée seule à
t+115 s.

**Les scènes illustrées de l'écran de table** (`.table.scene`,
`App\Partie\SceneDeTable`, 2026-09-14). Le moteur rend un résultat structuré —
attaquant, défendeur, faces de dés réellement tombées, objet tiré, piège
déclenché — et `JournalCombat::depuisResultat()` l'APLATIT en une phrase : les
identités y meurent, donc plus aucune image n'y est résolvable. D'où un
événement **parallèle** plutôt qu'une ligne enrichie — une ligne de journal est
un résumé destiné à défiler, lu aussi par les manettes, et lui faire porter la
mise en scène d'un écran qui n'est pas le sien en ferait la seule structure du
projet à servir deux métiers opposés. ⚠ Le parcours du résultat est **partagé**
(`JournalCombat::actionsDuTour()`) : deux parcours dériveraient au premier type
de phase ajouté, exactement comme `pieges_declenches` au pluriel, couvert d'un
seul côté, avait laissé un héros tomber dans une fosse sans une ligne.
⚠ **Toutes les URL d'images sont résolues côté serveur**, chaîne de repli
comprise jusqu'à l'emblème SVG : la table n'a aucun identifiant à joindre et
aucun cadre ne peut rester vide, même sans clé d'IA.
⚠ **La carte reste lisible** : la scène se pose DANS la zone carte sans la
recouvrir, et cède à toute superposition plein cadre (ouverture, prologue). Le
popup retiré le 2026-09-05 couvrait le donjon une à deux minutes pendant que la
partie était jouable — cet arbitrage n'est pas rouvert.
⚠ **La fermeture ne dépend d'aucune voix** (René, 2026-09-14) : clic sur l'écran
du narrateur, ou délai réglé dans ses paramètres — défaut 5 s, préférence
d'APPAREIL persistée en `localStorage` comme le volume, pas une règle de jeu. La
file est bornée à une scène affichée + une en attente : une phase de monstres
produit quatre attaques en une seconde, et sans borne c'est vingt secondes de
popups pendant que plus personne ne joue.
**Trois genres ont deux déclencheurs à part** : la **salle révélée** part de
`ResolveurTour::revelerSalle()` et la **chute/relève** de `AnnonceurChute` —
parce que ce sont des EFFETS DE BORD, pas des résultats d'action, et que ces
deux endroits sont les seuls à savoir qu'une salle vient à l'instant de basculer
ou qu'un héros vient de tomber. ⚠ Le CONSTRUCTEUR reste unique
(`App\Partie\SceneDeTable`) : trois déclencheurs, une seule façon de monter une
scène. ⚠ La scène de chute part **avant** le retour anticipé d'`AnnonceurChute` :
elle ne doit pas dépendre de l'existence d'une variante de narration — accrocher
deux promesses l'une à l'autre est exactement ce qui avait rendu la carte
d'ouverture invisible. ⚠ Une salle ne montre **jamais ses pièges** : ils restent
cachés jusqu'à la fouille, et les afficher retournerait la règle. ⚠ Le « vs » se
lit sur le RÔLE publié, pas sur le nombre d'acteurs : un sort de soin en a deux
lui aussi, et « Sylvaine vs Borin » raconterait le contraire de ce qui s'est
passé.

⚠ **Les faces de dés des MONSTRES manquaient** dans `attaque_monstre`
(`ResolveurTour`) : le contrat promettait pourtant depuis toujours que le journal
les porte « y compris ceux des monstres », et les chemins de `MoteurDread` les
publiaient déjà. Une même promesse tenue d'un côté et pas de l'autre — trouvée en
regardant une scène d'attaque sans le moindre dé.

**Ce qu'une scène doit DIRE, et pas seulement montrer** (René, 2026-09-14).
Une fouille affiche les **effets** de l'objet trouvé — par
`MotsClesEquipement::avantages()`, le vocabulaire déjà servi à l'étal, au sac et
au menu d'action : en réécrire ici ferait dériver l'écran de table au premier
mot-clé qui change. Une salle affiche le **bloc de stats** de chaque créature
(PV pris sur l'INSTANCE, qui a pu être blessée ; attaque, défense et déplacement
sur le CATALOGUE, que l'habillage IA ne touche jamais). Un jet d'attribut dit ce
qu'il **rapporte** — or, objet, soin de groupe, pièges désarmés, passages
secrets — et non « réussi » tout court ; ⚠ une mécanique d'épreuve sans lecteur
retombe sur « réussi », jamais sur une phrase inventée. ⚠ Une issue ne répète
jamais ce qui est déjà à l'écran : elle disait « ÉPÉE LARGE » sous l'illustration
de l'épée large.

**L'ordre du récit : l'action d'abord, sa conséquence ensuite**
(`App\Partie\TamponScenes`). La chute part d'un OBSERVATEUR de
`EtatPersonnageQuete.tombe`, donc à l'instant où les PV touchent zéro — au
MILIEU de la résolution —, alors que la scène de l'attaque n'est diffusée
qu'une fois le tour entier résolu. La table montrait donc le héros à terre, puis
le coup qui l'y avait mis. Le tampon retient ce qui naît en cours de résolution
et le diffuse après. ⚠ Il est **singleton** — deux instances et il serait
toujours vide au moment de le vider — et il se **vide tout seul en fin de
requête** : une scène retenue et jamais diffusée serait pire que l'ordre qu'on
corrigeait. ⚠ Pourquoi pas déduire la chute de `cible_tombee` : parce qu'un héros
tombe aussi d'un piège, d'un poison, d'un sort de Dread ou d'une réaction hors
tour. L'observateur les attrape tous ; on garde la source unique et on corrige
seulement l'instant. Conséquence à l'écran : **attaque 5 s, puis chute 5 s**, et
la file ne retient qu'UNE scène en attente — une salve de six coups ne peut donc
jamais dépasser deux scènes d'affilée.

**Multi-cibles : UNE scène, une vignette par cible** (2026-09-14). Une frappe
balayée (*Fauchaison*, *Frénésie*) et un sort de zone (*Flamme hypnotique*,
*Chant de guérison*) touchent plusieurs figures en un seul geste. Trois popups
d'affilée pour une seule action noieraient la table — et la file n'en garde
qu'une en attente de toute façon. Chaque cible est donc une vignette portant
**son** issue. ⚠ `touches` ne publiait que le NOM de la créature : sans
`instance_id` aucun portrait n'était résolvable, le nom affiché venant de
l'habillage IA.

**Une issue dit tout ce qui s'est passé, et pourquoi.** Un coup fatal rend
« −2 PV · X est terrassé » et non le seul « terrassé » : le chiffre est la moitié
de l'information (René). Un coup sans effet dit **pourquoi** — « manqué — aucun
crâne » ou « paré — 2 boucliers » — sans quoi les deux se ressemblent trop pour
qu'on apprenne quoi que ce soit du jet qu'on vient de voir. Un héros mis à terre
« tombe », un monstre « est terrassé » : à 0 PV un héros reste relevable.

**Le bandeau du MJ se replie** (`NarrationBand`, René 2026-09-14) : un récit de
salle mange le tiers bas de l'écran. 86 px → 50 px, mesuré. ⚠ Il **revient tout
seul au texte suivant** : un repli qui survivrait ferait taire le maître du jeu
sans que personne ne s'en souvienne — même famille de défaut que la carte
d'ouverture invisible, un état qui persiste au-delà de ce qu'il devait couvrir.
⚠ Sa première version ne repliait RIEN : `.table-screen .narr` (spécificité
0,2,0) écrasait `.narr-replie` (0,1,0). Les blocs `<style>` sont globaux ici, et
c'est la collision que ces règles signalent depuis le début.

⚠ **Une scène ne couvre QUE la zone carte** (`.map-wrap`), jamais la racine de
l'écran. Elle capture les clics — c'est ainsi qu'on revient à la carte —, donc
posée plus haut elle avalait aussi ceux destinés au bandeau du MJ, aux réglages
et au menu d'urgence : cinq secondes d'interface inerte à chaque coup porté.
Constaté en essayant de replier le bandeau pendant qu'une scène s'affichait.

**Une seule taille de tuile, et des légendes bornées** (René, 2026-09-14).
Portraits, objets et créatures partagent `--scn-tuile` : ils faisaient 132 px
d'un côté et 88 de l'autre, ce qui laissait lire une hiérarchie qui n'existe pas.
Une légende ne dépasse **jamais** la largeur de son image et revient à la ligne —
« Chacal des Sables Éternels » débordait et décalait toute la rangée.

**La carte de scène a une LARGEUR FIXE** (600 px, quatre tuiles par rangée) et
une hauteur libre. Elle se dimensionnait sur son contenu : elle sautait d'une
scène à l'autre — une chute étroite, une salle à six créatures deux fois plus
large — et l'œil du narrateur devait la rechercher à chaque fois. ⚠ Elle ne
prend **pas** toute la carte pour autant (question de René) : la couvrir
entièrement rouvrirait l'arbitrage du 2026-09-05, qui avait fait retirer le popup
précédent — « on est capable de jouer alors qu'il y a un popup ».

**La potion dit sur QUI elle est bue.** `MoteurPotions` publiait déjà la
distinction — `porteur_id` n'existe que lorsque la potion CHANGE DE MAIN, et vaut
null sur soi — mais aucun écran ne la disait. La scène nomme le porteur et le
buveur dans cet ordre, et son issue donne le soin **effectif** (`effets.soin_pv_body`),
jamais celui promis par la carte : boire 4 PV à un point du maximum n'en rend
qu'un. ⚠ Pas de « vs » : les rôles sont `acteur` et `cible`, pas attaquant et
défenseur.

⚠ **La fiche dit la disponibilité de chaque capacité, et la raison quand elle
est fermée** (René, 2026-09-14). `/moi.competences` ne porte plus une liste
d'ids mais, par nœud, `{id, statut, libelle, raison, cadence}` — la **décision**
de `App\Partie\Talents::fiche()`, pas ses ingrédients. `FicheTab.vue` n'a donc
jamais à lire `capacites_utilisees`, `effet.frequence` ni le plafond de PV :
c'est exactement la re-dérivation qui a mordu cinq fois en une semaine dans
`DeplacementSheet.vue` / `ActionTab.vue`. Le **libellé** du statut vient lui
aussi du serveur (`Talents::STATUTS`) : seule l'icône reste au client. Une
capacité épuisée **reste affichée**, grisée, avec sa phrase — la cacher ferait
croire au joueur qu'il l'a perdue, la même règle que pour les entrées de menu.
⚠ Vérifié en partie réelle (`browser-shots/dispo-capacites.mjs`, chevalier et
berserker) : « Utilisable en quête seulement » au hub, « Exige un bouclier
équipé », « Exige 5 PV de Body ou moins (tu en as 7) », « Déjà utilisée cette
quête ». `fullPage: true` ne sert à rien sur la manette — elle scrolle DANS un
conteneur, pas dans la page ; la capture s'arrêtait au bas du viewport et
coupait la section qu'on venait valider.
