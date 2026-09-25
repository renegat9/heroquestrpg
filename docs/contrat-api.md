# Contrat API & temps réel — prototype vertical

> Contrat partagé entre le serveur (Laravel) et le front (Vue). Toute évolution
> se fait ici d'abord. Réfs : doc 11 §4 (flux d'un tour), §7 (canaux).

## Authentification

Session Laravel (cookie) — **connexion par NOM seul** (jeu LAN entre amis, pas de
mot de passe). `POST /api/connexion` `{identifiant}` retrouve le joueur par son
identifiant, sinon par son pseudo (insensible à la casse) → `{joueur: {id, pseudo}}`.
Routes protégées par middleware `auth` sauf connexion.

## Endpoints

| Méthode | Route | Corps | Réponse |
|---|---|---|---|
| POST | /api/connexion | {identifiant} | {joueur} (nom seul, sans mot de passe) |
| GET | /api/guide | — | **PUBLIC** — compendium de référence : {classes (chacune avec **`depart: {arme, pieces[], des_attaque, des_defense}`** — l'attaque et la défense **avec l'équipement de départ**, décidées par le serveur (`EquipementDepart::valeurs()`, mêmes règles que `recalculerCombat()`), à côté des `des_attaque`/`des_defense` de base à mains nues, 2026-09-24), competences, monstres, objets, sorts, pieges, **cartes**} (catalogues seedés, effets bruts mis en forme côté front). `cartes` = les **trois** paquets sources (`config/cartes.php`, **69 cartes** : `equipement` 20 + `potions` 15, photos du matériel officiel Hasbro, + `artefacts` 34) : `{cle, libelle, source, url, cartes: [{carte, nom, paquet, porte, texte, manque}]}` — provenance de chaque pièce ET liste des cartes du plateau **pas encore jouables**, chacune avec la mécanique qui lui manque. Page /guide, ouverte depuis l'accueil sans compte. |
| POST | /api/deconnexion | — | 204 |
| GET | /api/moi | — | {joueur, personnages: [...]} |
| POST | /api/groupes | {nom, theme, longueur, ton} | {groupe} + dispatch squelette |
| POST | /api/groupes/{identifiant}/joueurs | {personnage_id} ou {nom, classe} | {personnage} (rejoint le groupe) |
| GET | /api/groupes/{identifiant}/etat | — | **EtatGroupe** (voir ci-dessous) |
| POST | /api/groupes/{identifiant}/quetes | — | {quete} — démarre la quête suivante (assemble carte, spawn monstres, initiative) |
| PUT | /api/groupes/{identifiant}/ordre | {ordre:[personnage_id,…]} | réordonne l'ordre du tour (ordre_initiative) — **HUB seulement**, permutation exacte des héros actifs, **membre OU table** ; rediffuse `.prets.maj` réordonné |
| POST | /api/groupes/{identifiant}/choix | {option_id, parametres?} | 202 — le moteur résout, l'état et la narration arrivent par Reverb |
| POST | /api/groupes/{identifiant}/deplacement/apercu | {x, y} | {atteignable, raison?, chemin: [{x,y}], cout, restant, restant_apres, pieges: [{x,y,nom,etat}]} — **le trajet EXACT** que le héros parcourrait, AVANT de valider (voir §Aperçu du trajet) |
| GET | /api/groupes/{identifiant}/menu | — | {menu, personnage_id} \| {menu: null} — rattrapage du menu courant (régénéré si c'est le tour du héros) |

## EtatGroupe (GET etat + broadcast `.groupe.etat`)

```json
{
  "groupe": {"identifiant": "...", "nom": "...", "phase": "hub|quete", "or": 0, "etat": "en_cours",
             "theme": "Cryptes maudites sous la cité|null",
             "theme_bestiaire": "horreur_des_glaces", "theme_bestiaire_libelle": "The Frozen Horror",
             "prets": [{"personnage_id": 1, "pret": false}],
             "mercenaires": [{"id": 3, "mercenaire_id": 2, "nom": "...", "type": "archer",
                              "animal": false, "pv_body": 1, "pv_body_max": 1}],
             "prologue": {"texte": "prémisse...", "url": "/audio/.../...wav|null",
                          "menace": {"nom": "...", "description": "..."}, "auto": true}},
  "quete": {"id": 1, "titre": "...", "type_jalon": "normale", "etat": "en_cours",
            "objectif": "atteindre_et_recuperer|vaincre_sous_boss|vaincre_boss_final|quitter_donjon|null",
            "objectif_libelle": "phrase sans vocabulaire de jeu | null",
            "objectif_accompli": true,
            "objectif_majeur": false,
            "image_url": "/img/.../....webp|null"} ,
  "carte": {"largeur": 12, "hauteur": 10, "cases": [["m","s","b"]],
            "portes": [{"x": 4, "y": 3, "cote": "e|s", "etat": "fermee|ouverte|verrouillee|secrete",
                        "embrasure": {"x": 5, "y": 3}, "verrou": "cle|monstres_vaincus|levier"}]},
  "entites": [
    {"type": "heros", "id": 1, "nom": "...", "classe": "nain", "x": 2, "y": 3,
     "pv_body": 6, "pv_body_max": 8, "pv_mind": 4, "pv_mind_max": 4, "tombe": false},
    {"type": "monstre", "id": 9, "nom": "<habillage IA ou nom_base>", "nom_base": "<type catalogue>", "x": 5, "y": 4,
     "pv_body": 2, "pv_body_max": 2, "etat": "actif"}
  ],
  "initiative": [{"entite": "heros|monstre", "id": 1, "nom": "...", "a_joue": false, "tombe": false}],
  "narration": "dernier texte du MJ",
  "narration_sequence": 42,
  "mj_reflechit": false
}
```

**Objectif de quête.** `objectif_libelle` dit *où aller* sans vocabulaire de
jeu ; `objectif_accompli` dit *si on y est* — c'est le **verdict du moteur**,
celui-là même qui ouvre `quitter_donjon` et déclenche la montée de niveau, et un
client ne doit jamais le recalculer. `null` (les deux) quand le gabarit ne
déclare aucun objectif : on n'annonce pas « accompli » là où rien n'était
demandé. `objectif_majeur` marque une quête **ordinaire** qui fait monter d'un
niveau si son objectif est accompli (doc 01 §5, troisième déclencheur) — un
jalon, lui, s'annonce déjà par son boss.

**Deux thèmes, deux natures** (2026-09-24) : `groupe.theme` est le thème
NARRATIF libre saisi à la création (« crypte »…), déjà un texte humain —
jamais un identifiant à traduire, `null` tant qu'aucun n'a été donné.
`groupe.theme_bestiaire` est la boîte d'extension qui fournit le bestiaire
(`groupes.theme_bestiaire`), FIGÉE pour toute la campagne dès la première
quête et **jamais** `null` dans ce payload — `EtatGroupe` passe toujours par
`DemarreurQuete::themeBestiaireDuGroupe()`, qui retombe sur le calcul
historique tant que la colonne n'a pas encore été écrite, plutôt que
d'exposer le `null` brut d'une campagne antérieure à cette colonne.
`theme_bestiaire_libelle` est son libellé lisible, **déjà décidé côté
serveur** (`DemarreurQuete::LIBELLES_BOITES`) : le client n'a jamais à
traduire un identifiant de boîte, même règle que `objectif_libelle`.

`groupe.prets` et `groupe.mercenaires` ne sont présents **qu'en phase hub**
(statuts « prêt » des héros actifs ; alliés déjà recrutés — voir §Alliés).
`quete`/`carte`/`entites`/`initiative` sont `null`/`[]` en phase hub —
**sauf après un TPK** : tant que la dernière quête est `echouee` (ni reprise,
ni nouvelle quête), elle reste exposée (avec sa carte et ses entités) pour le
bandeau « recharger / abandonner » de la table et l'écran d'attente de la
manette, qui testent `quete.etat === "echouee"`.
**Révélation par salle** : les monstres d'une salle restent DORMANTS (absents de
`entites` et `initiative`, ne jouent pas) tant que la salle n'a pas été découverte
par un héros. À la première entrée dans une salle (déplacement ou sort *Traverser
la Pierre*), ses monstres sont révélés et le MJ décrit la salle (narration). Un
piège déclenché par un déplacement est lui aussi décrit (narration `piege_declenche`).
`groupe.prologue` (hub uniquement) porte la prémisse de campagne + la menace pour
l'écran de prologue de la table ; `auto` est vrai tant qu'aucune quête n'a eu lieu
(ouverture automatique au lancement). `url` = vraie voix de narrateur si générée,
sinon `null` → lecture Web Speech. Absent si aucun squelette de campagne.

`initiative[].tombe` (héros uniquement ; toujours `false` pour un monstre) :
un héros **tombé** est SAUTÉ par le moteur (`verifierInitiative`) — l'acteur
courant côté client est le premier de l'ordre avec `a_joue=false` **et**
`tombe=false`, jamais un héros à terre (sa manette affiche « à terre »,
pas un menu).

`narration_sequence` = numéro de séquence (journal) de la dernière narration —
**anti-inversion** : plusieurs narrations partent en jobs asynchrones de durées
différentes (cérémonie de lancement instantanée, narration IA sur file lente…) ;
rien ne garantit qu'elles arrivent dans l'ordre où elles ont été déclenchées. Le
client (store `setNarration`) ignore toute narration (`.narration.diffusee` comme
`EtatGroupe.narration`) dont la `sequence` est ≤ à la dernière déjà affichée,
plutôt que d'inverser l'ordre perçu (ex. narration de la quête suivante entendue
avant celle du coup fatal qui a provoqué le TPK).

## Canaux Reverb (préfixe d'événement = broadcastAs)

| Canal | Événement | Payload | Écouté par |
|---|---|---|---|
| `groupe.{identifiant}` (private) | `.narration.diffusee` | {texte, ambiance?, quete_id?, url?, sequence?} | table (joue `url` = vraie voix de narrateur si présente, sinon lit `texte` en Web Speech) — `sequence` ignorée si ≤ à la dernière affichée (anti-inversion) |
| `groupe.{identifiant}` | `.bark.diffuse` | {profil, evenement: "attaque\|touche\|rate\|mort", nom, texte?, url?} | table (joue `url` si présente, sinon lit `texte` en TTS) |
| `joueur.{id}` (privé) | `.reaction.proposee` | {groupe, reaction: {personnage_id, sort, description, source, degats, expire_dans}} | **manette du joueur concerné** — réaction HORS TOUR (Dark Wings, Twisting Torrent) proposée pendant la phase des monstres. Voir §Réactions hors tour |
| `groupe.{identifiant}` | `.combat.journal` | {lignes: [{texte, ton, des?}], sequence} | **manettes** — fil mécanique du tour (attaques, dégâts, chutes, tour des monstres/alliés, résultat de fouille) dérivé du résultat moteur, **aucun LLM** : comble le « combat instantané » où seule la table avait un retour (barks). `ton` ∈ `degats\|mort\|subit\|chute\|pare\|succes\|echec\|info\|talent` (`talent` : voir §« Un talent qui s'active tout seul se VOIT ») ; `sequence` (max `Evenement.sequence`) sert de garde-fou anti-rediffusion ; lot ignoré si `sequence` ≤ au dernier appliqué. **`des`** (optionnel) porte le JET qui a produit la ligne — `{atk[], def[], touchante, defensive, attaquant, defenseur, touches, boucliers}` — et sert d'HISTORIQUE : le fil garde ses jets, y compris **ceux des monstres** (l'overlay de la manette ne révélait que sa propre action, 3 s). ⚠ `touchante`/`defensive` sont la **face gagnante de chaque volée**, publiée par le moteur et jamais redéduite côté client : un bouclier blanc pare pour un héros et **rien** pour un monstre, un crâne touche **sauf** contre un éthéré (`bouclier_noir`). Absent quand aucun dé n'a été lancé (dégâts fixes). ⚠ **Depuis le 2026-09-24, un jet peut être UNILATÉRAL** — un dé rouge de résistance, un jet de Mind, un piège de sol : SEULE la cible (ou le piège) lance, `atk`/`def` ne porte alors qu'UNE volée. `touchante`/`defensive` valent soit une face unique (`'crane'` — Mind, piège), soit un **ENSEMBLE** de faces gagnantes (`[5, 6]` — dé rouge, chaque 5 OU 6 compte) : le client compare une face à cet ensemble, il ne choisit jamais lequel gagne. Deux champs optionnels, `libelle_atk`/`libelle_def` (défaut `attaque`/`défend`), renomment le verbe de la ligne quand ce n'est pas une attaque (`résiste`) — décidés par le même formateur, jamais par le composant de dés. Point de passage unique des trois formes : `JournalCombat::desJetUnilateral()`, lu aussi par `SceneDeTable` pour `.table.scene` |
| `groupe.{identifiant}` | `.table.scene` | {sequence, genre, titre, sous_titre?, acteurs: [{role, nom, image_url, pv?}], jet?, deplacement?, figure?, objets: [{nom, image_url, detail?}], issue: {ton, libelle}} | **écran de table SEUL** — la SCÈNE illustrée de l'événement qui vient d'être résolu : portraits de l'attaquant et du défendeur, volée de dés, objet trouvé, piège déclenché, contenu d'une salle révélée. Émise en synchrone par le résolveur depuis le **même résultat moteur** que `.combat.journal`, sans LLM. ⚠ Le journal APLATIT ce résultat en texte : les identités y meurent, donc aucune image ne peut plus y être résolue — d'où un événement PARALLÈLE plutôt qu'une ligne enrichie (une ligne de journal est un résumé destiné à défiler, lu aussi par les manettes). `genre` ∈ `attaque\|jet\|piege\|fouille\|salle\|sort\|chute\|objet\|deplacement\|reaction` (`SceneDeTable::GENRES`, testé dans les deux sens). **`deplacement`** (2026-09-16) annonce le **début du tour d'un héros** : son portrait et le jet de déplacement **du tour**, `deplacement: {des: [int], calcul, de_annule, de_annule_par}` — `des` les faces réellement tombées (deux avec les Bottes elfiques), `calcul` la phrase DÉCIDÉE par le serveur (« 5 + 4 = 9 cases », dé annulé par l'armure, Raquettes, Vent Véloce et potion compris), identique à la `portee` de l'option `se_deplacer`. `de_annule`/`de_annule_par` (2026-09-24, voir §« L'Armure de plates FAIT PERDRE LE DÉ » plus bas) sont ce qui laisse la table barrer le dé d'un ✕ — `de_annule_par` vaut `null` dès que le dé compte. ⚠ Cette phrase a porté `malus`/`malus_source` quelques heures, le temps que René tranche que la plate retire le dé entier plutôt que deux cases : si un lecteur les cherche encore, il cherche une forme abandonnée. `deplacement` vaut `null` sur tous les autres genres, comme `jet` hors d'un coup. ⚠ Le dé est lancé **au tour du héros**, plus au début du round pour tous : c'est ce qui fait partir la scène au bon moment, et une fois seulement — la garde est la colonne `deplacement_tour`, pas un cache. ⚠ **Toutes les `image_url` sont RÉSOLUES CÔTÉ SERVEUR** (`BibliothequeImages`, repli jusqu'à l'emblème SVG) : jamais un identifiant que le client devrait joindre, jamais un cadre vide — les scènes marchent sans clé d'IA. `jet` reprend exactement la forme de `des` ci-dessus. `sequence` est **le même compteur que le journal** (anti-inversion) ; ⚠ elle ne passe PAS par le garde de `.narration.diffusee`, qui choisit un texte de bandeau et n'a pas à décider si une image s'affiche. **`figure`** (`heros:{id}`\|`monstre:{id}`, même clé que les `mouvements` de l'état, sinon `null`) veut dire **« cette figurine vient de marcher : attends la fin de son trajet »** — publiée SEULEMENT si elle a marché dans la même résolution (`ResolveurTour::figuresEnMarche()`). La table n'affiche la scène qu'une fois ce trajet joué, et garde l'ordre d'arrivée (la tête de file bloque les suivantes) : le coup d'un monstre ne s'affiche plus pendant qu'il marche encore vers sa cible. ⚠ Elle attend le trajet **même s'il n'est pas encore arrivé** (3 s au plus) : la scène, petit message, précède couramment l'état qui porte les trajets, gros message publié par l'autre worker — mesuré, 756 ms d'avance. **`reaction`** (2026-09-17) : la réaction hors tour ACCEPTÉE depuis une manette (`POST reaction`), souvent pendant le tour d'un monstre, qui ne s'arrête pas pendant que le joueur réfléchit — portraits de celui qui réagit et de celui qu'il protège, l'artefact et son dé de perte le cas échéant ; une riposte (*Représailles*) réutilise la scène d'`attaque`, nom de la réaction en sous-titre. ⚠ La scène ne retarde JAMAIS l'offre de réaction : celle-ci part sur `joueur.{id}` à l'instant de l'attaque, avec son compte à rebours. ⚠ L'écran de table les **enchaîne dans l'ordre d'arrivée** (une file, et non plus une seule place d'attente qui écrasait la précédente : une chute suivie d'un début de tour perdait la chute), et une scène arrivée pendant la carte d'ouverture ou le prologue **attend** qu'ils se ferment au lieu de s'écouler dessous. **La DURÉE n'est pas dans le payload** : le retour à la carte se fait au clic sur l'écran du narrateur, ou après un délai réglé dans ses paramètres (défaut 5 s, préférence d'APPAREIL comme le volume, persistée en `localStorage`) |
| `groupe.{identifiant}` | `.groupe.etat` | EtatGroupe + `mouvements?` | table + manettes. **`mouvements`** (diffusion seule, jamais dans `GET /etat`) : `[{type: heros\|monstre, id, depart: {x, y}, chemin: [{x, y}]}]`, les trajets de la résolution qui a produit cet état, que la table rejoue case par case AVANT de poser les positions finales. ⚠ **Dans le même message que l'état** depuis le 2026-09-17 : ils partaient dans un `.mouvement.anime` séparé « juste avant », mais la file `temps-reel` a DEUX workers et l'ordre de publication n'était pas garanti. ⚠ La table **tient toutes les figurines du lot sur leur case de départ dès réception**, puis les fait marcher une à une : tenue une seule à la fois, la suivante sautait à l'arrivée pendant que la première marchait, puis revenait au départ pour refaire le trajet (mesuré, deux gobelins). La caméra ne suit pas le héros actif tant que des monstres marchent |
| `groupe.{identifiant}` | `.mj.reflechit` | {actif} | table + manettes |
| `joueur.{id}` (private) | `.menu.propose` | {menu: {contexte, options: [{id, libelle, type: "action|dialogue|jet|attaque|deplacement", parametres}]}} | manette du joueur |

Un tour de héros = **deux créneaux** (doc 03 §28) : un **déplacement** et une
**action**, jouables **dans n'importe quel ordre et entrelacés** — agir n'annule
plus le déplacement restant (on peut agir PUIS se déplacer, ou fractionner son
déplacement autour de l'action). Le menu offre les créneaux encore libres (le
déplacement à la portée **restante**), plus « Terminer le tour » (`attendre`). Le
tour ne passe au héros suivant / aux monstres **que sur décision du joueur**
(`attendre`, ou une action terminante : concentration, relever) — plus de fin
automatique quand les deux créneaux sont pris. **Boire une potion** est une action
gratuite jouable **à tout moment** (onglet Sac, `POST /potions`), même après avoir
déplacé ET agi ; elle ne consomme aucun créneau et ne termine pas le tour.

⚠ `POST /potions` accepte `parametres.sort_ids` — quels sorts récupérer, pour les
potions qui en rendent un **nombre borné** (Potion de magie 3, Potion de rappel
1) ; sans choix, les premiers épuisés. Et il **refuse en 422** une potion
réservée à une autre classe : trois au Barbare, deux à l'Elfe sur les cartes
officielles, la première restriction de classe jamais portée par un consommable
(`Equipement::estAccessible()`). `/moi` la **badge** `utilisable: false` sans la
filtrer — un héros a le droit de PORTER la potion d'un compagnon, la manette
grise seulement le bouton — et `MoteurReactions::soinsDisponibles()` la **filtre**
de l'offre de soin d'urgence, parce que proposer un soin que la résolution
refusera est pire que ne rien proposer.

L'option `deplacement` (id `se_deplacer`) porte dans `parametres` l'allonce du
tour, **lancée une seule fois par tour et mémorisée** (doc 03 §3 : base + 1d6) :
`{base, de (résultat du d6), portee (cases max ce tour, Vent Véloce inclus)}`. La
manette affiche le dé puis une mini-carte tappable des cases accessibles ; le
choix part en `POST choix {option_id: "se_deplacer", parametres: {x, y}}`, que le
moteur revalide contre `portee` (réservé re-lancé en repli si absent).

#### Le malus d'armure se VOIT sur le dé (2026-09-24)

⚠ **`de` mentait depuis l'origine.** Le malus de l'armure était absorbé dans le
total, puis `de` était **reconstitué** comme `total − base`. Avec l'Armure de
plates (`malus_deplacement: 2`), un vrai 5 s'affichait « dé 3 » — la face
montrée était le jet MOINS le malus — et un jet de 1 ou 2 faisait tout
simplement disparaître le dé (`de: null`). Le malus n'était nommé qu'en texte
sur la table (« − 2 (armure) »), et nulle part sur la manette. Il ne pouvait pas
en être autrement : seul `deplacement_tour` (le TOTAL) était persisté, si bien
qu'au premier menu régénéré en cours de tour la face réelle était perdue.

Le détail du jet est désormais **persisté** au moment du lancer (colonne,
jamais un cache — la règle consolidée du projet), et publié tel quel :

`{base, des: [int], de_annule, de_annule_par, portee}`

- `des` — les faces **réellement tombées**, jamais reconstituées ;
- `de_annule` — la **DÉCISION** : le dé de mouvement ne compte pas ce tour ;
- `de_annule_par` — le **nom** de la pièce qui l'annule (« Armure de plates »),
  pour que l'écran dise *pourquoi* ;
- `de` reste publié pour les lecteurs existants, **égal à la face réelle**.

#### L'Armure de plates FAIT PERDRE LE DÉ (René, 2026-09-24)

⚠ **La valeur précédente venait de la mauvaise source.** `malus_deplacement: 2`
(« a 2 square movement penalty ») sortait de la conversion FAN Sjeng
(`reference/16_armurerie.md` §2.2, « historique »). La carte **officielle** 2021
— celle que René a photographiée, et dont le §2.1bis dit qu'elle **PRIME** sur
Sjeng — écrit : *Plate Mail*, « +2 dés de défense, mais **1 seul dé rouge de
mouvement** ». La règle dure du projet (« ne jamais semer une valeur que les
livrets ou les cartes ne sourcent pas ») aurait dû l'emporter ; un paragraphe
défendait pourtant −2 au motif que retirer le dé coûtait « −3,5 cases en moyenne
et rendait le déplacement déterministe » — argument juste, mais posé sur un
texte qui n'était pas la règle.

Au plateau, un héros lance DEUX dés et la plate lui en retire UN. Chez nous
(base de classe + UN seul d6, écart assumé de René), « retirer un dé » retire
**le seul dé** : le héros en plate avance de sa **base**, point. C'est coûteux —
3,5 cases en moyenne — et c'est voulu : c'est ce que valent 850 or et +2 dés de
défense. Et c'est ce qui donne tout leur sens au **Chevalier** et à la Forge
**Allégée**.

⚠ **Le dé est quand même LANCÉ, puis barré d'un ✕** — la manette et la table le
montrent tomber, puis le rayent, avec la pièce qui l'annule. Supprimer le lancer
aurait rendu la pénalité invisible, exactement le défaut qu'on corrige : le
joueur doit voir ce qu'il aurait eu.

⚠ **Pas de ✕ quand le dé compte — et c'est la DÉCISION serveur qui le dit,
jamais le client.** Deux exemptions, tranchées au même point de passage dans
`Equipement` : le **Chevalier** (« les armures ne nuisent pas à son
mouvement ») et l'amélioration **Allégée**, sur son propre exemplaire. Le client
lit `de_annule`, il ne regarde ni la classe ni la forge.

⚠ Le changement de la ligne de catalogue existante passe par une **migration**
(jamais un re-seed destructeur), et l'arbitrage de `reference/16_armurerie.md`
est réécrit pour dire quelle source fait foi.

#### Aperçu du trajet (2026-09-17)

⚠ **Le trajet a des conséquences mécaniques** : `MoteurPieges::controlerChemin()`
contrôle les pièges **case par case** le long du chemin, et une chausse-trappe ou
des racines l'écourtent. Le joueur ne désignait pourtant qu'une DESTINATION — la
route était choisie par le serveur (Dijkstra, la moins chère) et découverte à
l'animation. René, 2026-09-17 : « que la figure utilise le vrai chemin ».

`POST /api/groupes/{identifiant}/deplacement/apercu {x, y}` rend **ce chemin-là**,
calculé par le MÊME code que la résolution (`ResolveurTour::grilleDeplacement()`,
point de passage unique de « sur quoi ce héros marche-t-il ? ») : la manette le
dessine sur sa mini-carte, avec les pièges CONNUS qu'il traverse, et le joueur
confirme. C'est un appel serveur et non un second BFS client : un chemin
re-dérivé en JS pourrait désigner une autre route de même coût, donc d'autres
pièges — la sixième dérive de miroir de cette famille.

⚠ **L'aperçu ne révèle RIEN** : `pieges` ne liste que les pièges déjà publiés dans
`EtatGroupe.carte.pieges` (détectés / désarmés / déclenchés). Les pièges cachés,
les chausse-trappes et les racines qui tronqueraient la course n'y figurent pas —
les publier ferait de l'aperçu un détecteur de pièges gratuit.

⚠ C'est un **aperçu**, pas une réservation : le résolveur recalcule tout au moment
du choix (une réaction hors tour a pu déplacer une figurine entre les deux).

### Une action, puis un sous-choix (2026-09-01)

Le menu ne porte plus **une option par sort** : il porte **une option qui porte
la liste des sorts**. Mesuré en partie réelle, le menu d'un magicien niveau 1
comptait **14 options dont 9 sorts**, là où le doc de conception fixe « 2 à 5
options claires » (doc 13 §3.1). C'est la leçon du ciblage, un cran plus haut :
*l'option ne doit pas ÊTRE le sort, elle doit PORTER la liste des sorts.*

| option | liste | entrée |
|---|---|---|
| `lancer_sort` (`type: sort`) | `parametres.sorts[]` | `{cle, sort_id, nom, element, sort_type, disponible, cibles?, mode?, porte?}` |
| `lire_parchemin` (`type: parchemin`) | `parametres.parchemins[]` | idem + `inventaire_id` |
| `utiliser_objet` (`type: objet_libre`) | `parametres.objets[]` | `{cle, inventaire_id, nom, detail, cout: gratuit\|action, quantite, cibles?}` |
| `se_concentrer` · `sacrifier_pour_sort` | `parametres.sorts[]` | `{cle, sort_id, nom, …}` |

Le client répond **à plat** : `POST choix {option_id, parametres: {cle, cible_id?, cible_type?}}`.

- ⚠ **La liste EST la liste blanche.** `entreeChoisie()` vérifie l'appartenance
  de `cle` et répond 422 sinon. Sans cela, un client lancerait un sort de son
  répertoire **avec les cibles d'un autre** — hors ligne de vue et hors du
  typage de cible que `ciblesLegales()` avait calculé pour ce sort-là.
- ⚠ **`cibles` reste PAR ENTRÉE.** Un sort de dégâts vise monstres et héros, un
  soin les héros seuls, un sort sur soi personne : une liste unique au niveau
  de l'option serait fausse pour cinq des neuf sorts d'un magicien.
- ⚠ **La profondeur suit la donnée** : le troisième niveau (ciblage) ne s'ouvre
  que si l'entrée porte des `cibles`. *Traverser la Pierre* et une potion de
  soin partent du deuxième.
- ⚠ **Un sort épuisé reste dans la liste**, `disponible: false`, pour être
  **grisé** — le faire disparaître laissait croire au joueur qu'il l'avait
  perdu. Le résolveur le refuse.
- ⚠ **Le coût d'un objet dépend de l'OBJET**, pas du type d'option : la liste
  mêle le gratuit (potion, chausse-trappes, fumigène) et le payant (eau bénite,
  « instead of attacking »). L'option est `objet_libre` et `resoudreUsageObjet()`
  dépense l'action lui-même. La liste ne contient que du gratuit quand le héros
  a déjà agi.
- ⚠ **La navigation est une PILE** côté manette, et chaque retour **nomme sa
  destination** (« Retour aux sorts », « Retour aux actions »). Un retour qui
  ramènerait au menu d'action depuis le ciblage ferait recommencer le choix du
  sort. Un tap sur le fond dépile d'un cran ; un nouveau menu vide la pile.

⚠ **`POST /potions` est RETIRÉE.** Boire passe par `utiliser_objet`, comme tout
le reste — une voie, une validation, un journal. Conséquence assumée : on ne
boit plus hors de son tour, ni au hub. Le cas d'urgence reste couvert par
`MoteurReactions`, qui propose les potions du sac quand un héros tombe.

**Les dés de RÉSISTANCE se dessinent (2026-09-24).** ⚠ Ils étaient calculés,
publiés, et **dessinés nulle part** (René : « pour les sorts d'attaque avec un
lancer de dés pour résister, on ne voit pas le lancer de dé »). Deux payloads
muets :
- sort de héros à `resistance: des_rouges` (*Boule de Feu*, *Trait de Feu*) : la
  cible lance des dés rouges, publiés en `des_resistance` — que seul le
  compendium mentionnait, en texte ;
- sort du MJ contre un héros : le jet de Mind du héros, publié en `faces` (+
  `mind_cible`, `issue`) — que le fil ne lisait QUE pour briser une condition
  après coup, en texte brut (« · crane, bouclier_blanc »).
Le fil du combat et la scène de table les dessinent désormais **comme les dés
d'une attaque** (même composant de dés, même ton), avec le nom de celui qui
résiste. ⚠ **Aucun payload ne change** : les faces y étaient déjà. C'est un
défaut de RENDU — « un payload muet est le même défaut qu'aucun payload ».

### Un talent qui s'active tout seul se VOIT (2026-09-25)

René : « on devrait afficher un popup quand un pouvoir s'active de manière
passive ». Un effet automatique que rien n'annonce est injouable (règle dure) ;
jusqu'ici la plupart des talents passifs jouaient **en silence** — une porte
secrète apparaissait, un poison glissait, un dé raté était relancé, sans que le
joueur sache que c'était SON talent.

**Qui est concerné — liste fermée**, décidée avec René. Les mécaniques sont
celles de `App\Engine\MotsClesTalent`, et le registre des talents annoncés est
testé **dans les deux sens** (toute mécanique de la liste a un point
d'annonce ; aucune annonce ne part d'une mécanique hors liste) :

- *événement* : `detection_pieges_adjacents` (Œil du mineur),
  `detection_portes_secretes` (Parler à la pierre, Lecture des lieux),
  `alerte_pieges_adjacents` (Sens du piège), `bonus_des_defense` à condition
  `premiere_attaque_du_combat` (Garde tenace, Refrain vaillant, Écorce, Esquive,
  Garde haute), `resistance_condition` (Sang robuste, Sève tenace, Cicatrices),
  `garde_sort_qui_tue` (Chant runique, Appel de la forêt), `resistance_degats_type`
  (Chair impie), `inflige_condition_sur_touche` (Lame vénéneuse),
  `bonus_des_attaque_flanc` (Frappe opportuniste), `ignore_terrain_entravant`
  (Ronces complices), `bonus_or_tresor` (Chasseur de trésor),
  `rarete_butin_amelioree` (Œil du prix) ;
- *actifs qui partent seuls* : `relance_des_attaque_rates` (Coup puissant, Bras
  d'acier, Coup sauvage), `attaque_supplementaire_apres_kill` (Sang qui bout,
  Coup de grâce, Soif de sang), `annuler_effet_magique` (Contresort, Verbe
  ancien), `repiocher_carte_piege` (Sixième sens).

Les réactions **proposées** (Inébranlable, Parade au bouclier, Défi du
chevalier, Représailles) n'en font pas partie : elles ont déjà leur offre sur
`joueur.{id}`.

**Payload moteur.** Toute action (héros, monstre, piège, fouille, déplacement…)
dont la résolution a fait jouer un de ces talents porte
`talents_declenches: [{personnage_id, heros, talent, mecanique, icone, effet}]` —
`heros` le nom du porteur, `talent` le nom du nœud (`Competence.nom`), `icone`
celle de `MotsClesTalent`, `effet` la phrase DÉCIDÉE par le serveur (« révèle
une porte secrète », « relance 2 dés ratés », « résiste à Empoisonné »,
« +25 pièces d'or »). Un seul collecteur côté serveur, point de passage
unique : aucun site d'effet ne formate sa propre annonce.

**Fil (`.combat.journal`).** Chaque entrée devient une ligne
`{texte: "<talent> — <heros> : <effet>", ton: "talent", talent: {personnage_id,
heros, nom, icone, effet}}`. `ton` gagne la valeur `talent`. C'est cette ligne
qui porte le popup : elle part par le canal déjà diffusé à tous, y compris
pendant la phase des monstres résolue dans la requête d'un autre joueur.

**Popup.** À la réception d'une ligne `ton: "talent"` :
- l'**écran de table** l'affiche pour tout héros (icône, nom du talent, héros,
  effet), ~3,5 s, fermable ; plusieurs à la suite s'enchaînent en file ;
- la **manette** ne l'affiche que pour SON héros (`personnage_id`), même forme.
Aucune règle n'est redéduite côté client : le texte vient tel quel du serveur.

**Modificateurs de jet (groupe 3) — `des.modificateurs`.** Les talents qui
modifient un jet SANS événement propre (Frénésie et ses pairs sous la moitié des
PV, Élan, Charge du destrier, Tir précis, Flèche perçante, défense contre les
tirs, Bannière, Léger sur ses pieds, malus des monstres au contact, dégâts de
sort, réduction de dégâts subis, +1 dé de Mind ciblé) ne déclenchent **pas** de
popup — un popup à chaque coup serait du bruit. Le jet qu'ils ont modifié porte
`modificateurs: [{source, valeur, sur}]` (`source` le nom du talent, `valeur`
signé, `sur` ∈ `attaque|defense|degats|mind`), publié dans le résultat moteur
et recopié par `JournalCombat` dans `des` (fil et `.table.scene`) ;
`JetDes.vue` l'affiche sous la volée (« +1 Tir précis », « −1 Regard qui
glace »).

**Sort épargné — `sort_preserve` (2026-09-25).** Le résultat d'un `lancer_sort`
porte `sort_preserve` quand le sort lancé **reste disponible** : `"anneau_de_sort"`
(une charge de l'Anneau de sort) ou `"talent"` — *Chant runique* / *Appel de la
forêt*, mécanique `garde_sort_qui_tue` : **le sort qui abat un monstre n'est pas
épuisé** (René ; remplace l'ancien regain au bouclier noir). `sort_preserve_par`
nomme alors le talent. Le fil l'annonce (« Boule de Feu reste disponible (Chant
runique) ») : un sort resté allumé sans explication est un effet automatique muet.

**Ciblage en deux temps.** Une option qui vise (`attaque`, `sort`, parchemin)
n'en désigne **pas** la cible : elle joint les cibles légales dans
`parametres.cibles` — `[{id, type: "monstre"|"heros", nom, nom_base?,
distance?}]` — et la manette ouvre sa feuille de ciblage. Le choix part en
`POST choix {option_id, parametres: {cible_id, cible_type?}}`, **à plat**.
⚠ **Le dual-wielding a changé de forme le 2026-09-18** — voir §« Équiper,
ranger et attaquer passent au sous-choix » plus bas, qui fait foi. Il émettait
jusque-là **une option par arme en main** (`attaquer` / `attaquer_secondaire`,
et leurs jumelles `lancer`), chacune portant `parametres.arme` et ses propres
cibles. C'est désormais **une** option `attaquer` portant `parametres.armes[]`,
chaque entrée gardant **ses propres** `cibles` — car la portée, les diagonales
et le jet appartiennent à l'arme, pas au héros, et c'est cela qui n'a pas
bougé.

⚠ **La profondeur suit la donnée**, ici comme ailleurs : avec **une seule** arme
en main (ou les mains nues), il n'y a rien à choisir et l'option reste **à
plat** — `parametres.arme` + `parametres.cibles`, sans `cle`. La liste
n'apparaît que lorsqu'un choix existe vraiment.

**Capacités de carte** (classes d'extension, 2026-08-12). Elles ajoutent des
options de type `attaque` — donc avec feuille de ciblage et liste blanche — que
le MENU seul peut émettre, leur coût vivant dans `parametres` :

| Option | Type | `parametres` | Carte |
|---|---|---|---|
| `furie_1` / `furie_2` | `attaque` | `{furie: n, cibles}` | *Furie* (Berserker) : n PV de Body contre n dés. Une option par montant — « up to 2 » est un choix, et le plafond est `pv_body - 1` |
| `style_poing` | `attaque` | `{style, cibles}` | *Force de la Montagne* (Moine) : +2 dés, **à mains nues** |
| `frappe_balayee` | `attaque_balayee` | — | *Frénésie sanguinaire* / *Œil du Cyclone* : **aucune cible à choisir**, elle prend tout ce qui touche le héros |
| `toucher_brasier` | `degat_differe` | `{cibles}` | *Toucher du Brasier* : 1 PV maintenant, 2 à la fin du tour suivant de la cible |
| `rayon_{direction}` | `rayon` | `{direction}` | *Esprit Ardent* : un rayon droit ou diagonal, une option par direction où il y a quelqu'un |
| `style_vague` | `style` | `{style}` | *Vague Montante* : **interaction gratuite**, aucun créneau |
| `fouiller_pierre` | `jet` | `{style}` | *Parler à la Pierre* : la fouille de zone sans risque d'échec |
| `franchir_dragon_{x}_{y}` | `franchissement` | `{piege, cout, style}` | *Dragon Bondissant* : le saut de fosse réussit d'office |

⚠ Le type `style` est une **interaction** au sens des créneaux (comme
`ouvrir_porte`). Et les libellés de ces options ne sont **jamais** habillés par
l'IA — ils portent le prix (« sacrifier 2 PV », « à mains nues »), qu'une
paraphrase effacerait.

### Équiper, ranger et attaquer passent au sous-choix (2026-09-18)

Trois options portaient encore **une pièce chacune**, dernières survivantes
d'avant la règle « une action, puis un sous-choix » (René, 2026-09-01) : elles
datent de juillet et n'avaient jamais été converties.

| Avant | Après |
|---|---|
| `equiper_{id}` · `equiper_{id}_gauche`, une par pièce et par main | **une** option `equiper` portant `parametres.pieces[]` |
| `desequiper_{id}`, une par pièce portée | **une** option `ranger` portant `parametres.pieces[]` |
| `attaquer` **et** `attaquer_secondaire` quand deux armes sont en main | **une** option `attaquer` portant `parametres.armes[]` |

⚠ **La mesure qui a fondé la règle se reproduisait à l'identique.** Le menu d'un
magicien de niveau 1 portait 14 options dont 9 sorts, là où le doc 13 §3.1 borne
à « 2 à 5 options claires » ; la réponse fut que *l'option ne doit pas ÊTRE le
sort, elle doit PORTER la liste des sorts*. L'équipement faisait pire : une arme
à une main produit **deux** entrées (droite, gauche), si bien qu'un sac de deux
armes, un casque et une armure, plus trois pièces portées, donnait **neuf**
boutons d'équipement. Relevé en jeu le 2026-09-18 : dix options au menu de Grom
avec **une seule** pièce au sac.

**`pieces[]`** — par entrée : `cle` (`"piece:{inventaire_id}"`), `inventaire_id`,
`nom`, et pour `equiper` les `slots` légaux (une entrée qui en porte **deux**
ouvre le troisième niveau — le choix de main — exactement comme une entrée qui
porte des `cibles`) et `remplace` (le nom de l'occupant, quand il y en a un).
⚠ `Equipement::echangeUtile()` reste le point de passage qui écarte l'échange de
deux pièces IDENTIQUES : proposer un geste qui coûte l'action et ne change rien
est une option morte. Il filtre désormais les **entrées**, plus les options.

**`armes[]`** — par entrée : `cle` (`"arme:{slot}"`), `slot`, `nom`,
`des_attaque`, et **ses propres `cibles`**.
⚠ **`cibles` PAR ENTRÉE, et pour une raison mécanique, pas par mimétisme** : la
portée dépend de l'arme. Une arme longue frappe en **diagonale**
(`attaque_diagonale`, voisinage de Tchebychev) là où une arme courte se limite
aux quatre cases orthogonales — `MenuMoteur` le lit déjà pour composer les deux
listes séparément. Une liste commune au niveau de l'option offrirait, avec la
dague, une cible que seule la hallebarde atteint.

⚠ **`lancer` reste une option À PART**, et ce n'est pas une inconséquence :
jeter son arme n'est pas frapper avec: la pièce est **détruite**, et son libellé
porte ce fait mécanique qu'une paraphrase effacerait. Elle porte néanmoins la
même liste quand plusieurs armes sont jetables.

### `creneau` — chaque option dit ce qu'elle coûte

Depuis 2026-09-18, **toute option de menu porte son `creneau`** :
`"mouvement"`, `"action"`, `"interaction"` (gratuit) ou `"tour"` (terminant).
C'est la valeur de `ResolveurTour::creneauOption()`, publiée telle quelle.

La manette affiche **∞** à la suite des options `interaction` — un geste qui ne
dépense rien doit se voir, sans quoi le joueur l'économise comme s'il coûtait.
Ouvrir une porte, se délester, activer un style, proposer la retraite : tout
cela est répétable et n'entame aucun créneau.

⚠ **Le client LIT ce champ, il ne le re-dérive pas.** C'était jusqu'ici une
règle serveur recopiée en JS dans `ActionTab.creneauConsomme()`, et ce miroir a
menti **trois fois** : `actionner_levier` s'y croyait gratuit après que le
serveur lui eut donné un prix (2026-08-24), `objet_libre` y manquait tout à
fait, et l'attaque y ignorait le bonus d'héroïsme. Une quatrième a été évitée
de justesse en rendant `jeter` gratuit. Publier la décision supprime la cause :
`creneauConsomme()` bascule sur `option.creneau` au lieu du `switch` sur
`option.type`.

⚠ Ce que le champ NE remplace PAS : les deux exceptions qui dépendent de l'ÉTAT
du héros et non du type d'option — `attaque_supplementaire` (Potion d'héroïsme,
Rage guerrière) et `sort_bonus_disponible` (Réserve arcanique, Baguette de
Rappel). Elles restent publiées à part dans `entites[]`, parce qu'une même
option d'attaque est tantôt permise tantôt refusée selon qui la regarde. Le
`creneau` dit le prix ; ces drapeaux disent qui a déjà payé.

⚠ `parametres.cibles` **est la liste blanche** : l'identifiant d'option ne
porte plus la légalité de la cible, donc la valider contre le menu ne la valide
plus. Le résolveur vérifie l'appartenance et répond 422 sinon — sans quoi un
client pourrait viser n'importe quel monstre de la quête, hors portée et hors
ligne de vue.

Autorisations (routes/channels.php) : `groupe.{identifiant}` → le joueur a un
personnage actif dans ce groupe ; `joueur.{id}` → id === joueur connecté.

## Phase marché (doc 04 §5 — au hub uniquement)

La phase vit en cache serveur (comme les menus) ; rien n'est appliqué avant la
confirmation de TOUS les joueurs membres, puis application **atomique** en
transaction. Le MJ IA choisit le profil de lieu ; sans LLM, profil `bourg`.

| Méthode | Route | Corps | Effet |
|---|---|---|---|
| POST | /groupes/{identifiant}/marche | {profil?} | ouvre la phase (422 si pas au hub ou déjà ouverte) — **membre OU table** (bouton sur l'écran de table, même règle que la clôture) |
| GET | /groupes/{identifiant}/marche | — | EtatMarche, ou `{marche: null}` (200) si aucune phase ouverte — sonde de rattrapage à chaque chargement de hub, donc pas de 404 |
| PUT | /groupes/{identifiant}/marche/panier | {achats:[{objet_id,quantite}], ventes:[{inventaire_id}]} | remplace le panier du joueur, annule sa confirmation |
| POST | /groupes/{identifiant}/marche/confirmation | — | confirme ; si tous confirmés → application + clôture |
| DELETE | /groupes/{identifiant}/marche | — | annule la phase (rien appliqué) — **membre OU table** |

**EtatMarche** : `{profil, multiplicateur, inventaire: [{objet_id, nom, categorie,
rarete, prix, stock}], paniers: [{joueur_id, pseudo, achats: [...], ventes: [...],
confirme, inventaire: [{inventaire_id, personnage_id, objet_id, nom, categorie,
rarete, emplacement, quantite, revente}]}], total_projete, or_courant}` — le
`inventaire` de chaque panier liste ce que ce joueur peut vendre (héros actifs),
avec le prix de revente M1 déjà calculé. Prix d'achat = prix_base × multiplicateur
du profil (doc 04 §3) ; revente = 50 % du prix marchand courant (M1) ; rareté
`unique` jamais en stock. Garde-fous à l'application : total ≥ 0, stock, objets
vendus réellement possédés, **capacité de sac** (PV Body max ÷ 2 arrondi
inférieur + bonus_sac de classe — doc 01) respectée pour chaque personnage.

Précisions serveur : stocks de départ playtest par rareté (commun = illimité,
peu_commun = 3, rare = 1) ; chaque ligne d'achat accepte un `personnage_id`
optionnel (un des héros du joueur — son premier par défaut) ; les achats non
consommables vont au **sac**, les consommables s'empilent hors capacité.

Broadcasts canal `groupe.{identifiant}` : `.marche.ouvert` (EtatMarche),
`.marche.maj` (EtatMarche, à chaque panier/confirmation), `.marche.finalise`
({applique: bool}) suivi de `.groupe.etat`.

## Alliés — mercenaires (doc 14 §3.5 — au hub uniquement)

| Méthode | URL | Corps | Effet |
|---|---|---|---|
| GET | /mercenaires | — | catalogue recrutable : `[{id, nom, type, prix, deplacement, attaque, portee, attaque_distance, defense, pv_body, animal, description}]` (group-agnostique, comme `/competences`) |
| POST | /groupes/{identifiant}/mercenaires | {mercenaire_id} | recrute un allié contre l'or de la **bourse commune** (422 si pas au hub, or insuffisant, ou 2ᵉ compagnon animal) |

PNJ **scriptés** (hors roster), **consommés en fin de quête** (purgés à la
victoire comme à l'échec). Au démarrage de quête ils sont instanciés sur les
cases de spawn restantes, à côté des héros. Ils jouent en **phase dédiée**, juste
AVANT les monstres et **hors initiative des héros** : ils ciblent les monstres
(tir avec ligne de vue pour un allié à distance, sinon corps-à-corps). Réponse :
`{recrue:{id,nom,type,animal}, or}` ; broadcast `.groupe.etat`.

Le front calcule la disponibilité (or suffisant, animal déjà pris) **côté
client** à partir de l'état vivant : `EtatGroupe.groupe.or` + le bloc **hub**
`EtatGroupe.groupe.mercenaires` (voir plus bas). La manette montre le panneau de
recrutement au hub, la table liste les renforts embauchés.

Dans **EtatGroupe.entites** (en quête), un allié posé apparaît avec `type:'allie'`
(`{id, nom, x, y, pv_body, pv_body_max, animal}`). **Au hub** (carte absente, donc
hors `entites`), les recrues actives sont exposées dans le préambule sous
`groupe.mercenaires: [{id, mercenaire_id, nom, type, animal, pv_body,
pv_body_max}]` (mis à jour en direct par `.groupe.etat` après un recrutement). La
résolution d'un tour de choix peut porter `resultat.tour_allies.actions`
(déplacements/attaques alliées), en regard de `resultat.tour_monstres.actions`.

`EtatGroupe` porte aussi **`journal_combat`** : les 24 dernières lignes de la
quête en cours, **rejouées depuis les événements** par le même formateur que la
diffusion temps réel. Le client ne s'en sert que si son fil local est VIDE
(chargement, reconnexion, joueur arrivé en retard) — le réappliquer à chaque
`.groupe.etat` écraserait les lignes reçues en direct par un instantané plus
ancien. Sans cela, un simple rafraîchissement du téléphone effaçait tout
l'historique des jets. Vide hors quête.

Tout `resultat` d'attaque porte les quatre clés de jet — `faces_attaque`,
`faces_defense`, **`face_touchante`**, **`face_defensive`** (valeurs de
`App\Engine\Des\FaceDeCombat`). Les deux dernières disent quelle face
RÉUSSIT pour chaque volée ; sans elles un client ne peut pas afficher un jet,
puisqu'un dé n'est un succès que relativement à qui le lance. La manette s'en
sert pour entourer les dés gagnants en vert (`JetDes.vue`), et les mêmes
valeurs repartent sous `des` dans `.combat.journal`.

La réponse `202` de `POST /groupes/{id}/choix` porte, à côté de `resultat`, une
clé **`des`** (2026-09-24) : le jet **unilatéral** de l'action (dés rouges de
résistance, jet de Mind, dés d'un piège), déjà mis en forme par
`JournalCombat::desJetUnilateral()` — exactement la forme `des` du fil, faces
gagnantes décidées. `null` quand l'action n'en a pas. Sans elle, la manette
restait muette sur la Boule de Feu que le joueur venait de lancer lui-même.

## Réactions hors tour

`POST /api/groupes/{identifiant}/reaction` — `{personnage_id, accepte}` →
`{reaction: {…}}`. **Membre uniquement**, et seulement pour **ses propres**
héros (422 sinon).

C'est la **seule action du jeu qui arrive en dehors du tour de son auteur**,
d'où sa route dédiée : elle ne peut passer ni par le menu (il n'y en a pas à ce
moment-là) ni par `/choix`, qui suppose que c'est votre tour. Deux cartes
officielles la réclament — *Dark Wings* (Warlock, « Reduce that damage to
zero ») et *Twisting Torrent* (Moine, « cancel that damage »), toutes deux
déclenchées **quand leur porteur encaisse**, donc pendant le tour d'un monstre.

**Cinq actions** de réaction existent aujourd'hui (`App\Engine\ReactionEffet`),
et la proposition porte laquelle dans `action` — le libellé du bouton en
dépend, « annuler les dégâts » étant faux pour trois d'entre elles :

| `action` | Carte | Ce qu'elle fait |
|---|---|---|
| `annule_degats` | *Dark Wings*, *Twisting Torrent* | rend les PV du coup |
| `plancher_pv` | *Inébranlable* (Chevalier) | les PV tombent à **1**, pas au-dessus — proposée **seulement** sur un coup mortel |
| `annule_degats_voisin` | *Parade au bouclier* (Chevalier) | annule le coup d'un héros **au contact** : la proposition va au PROTECTEUR, les PV rendus à la victime (`victime_id`) |
| `riposte` | *Représailles* (Berserker) | **n'annule rien** : le Berserker encaisse et attaque aussitôt le monstre (`instance_id`), adjacence revérifiée à la résolution |
| `defi_errant` | *Défi du chevalier* | détourne sur soi le monstre errant qui vient de surgir : il se place au contact et frappe immédiatement |
| `soin_urgence` | — (potion / sort du héros) | le héros vient de **tomber** : il dépense une potion ou un sort de soin pour rester debout |

⚠ `defi_errant` a un **déclencheur à part** (`errant_revele`) : c'est la seule
réaction qui ne parte pas d'un coup encaissé, mais d'une carte de fouille qui
fait surgir un errant dans la salle. `degats` y vaut 0 — la manette doit donc
lire `action` avant d'écrire « tu viens d'encaisser… ».

⚠ **`soin_urgence` est la seule qui ne DÉFAIT rien : elle paie.** Elle n'est
proposée que si le héros tombe **à 0 PV** et qu'il lui reste un remède, et elle
arrive **après** toutes les autres — une capacité comme *Inébranlable* ne coûte
aucun objet, autant qu'elle passe d'abord. Deux conséquences :

- la proposition porte **`soins: [{cle, type, nom, soin}]`** — `cle` vaut
  `potion:{inventaire_id}` ou `sort:{sort_id}`, potions d'abord. La manette
  affiche **un bouton par remède** (le choix compte : une potion se consomme
  pour de bon, un sort revient à la quête suivante), et la réponse porte
  `soin` : `POST /reaction {personnage_id, accepte, soin?}`. ⚠ `soins` **est la
  liste blanche** — une clé absente retombe sur la première entrée proposée,
  jamais sur l'inventaire d'un compagnon ;
- elle vaut **quelle que soit la cause de la chute**, y compris `piege` et
  `rejeton`, hors de `SOURCES_REACTIVES`. Cette liste répond à « quel coup
  peut-on annuler ? » ; se soigner n'annule rien, et refuser la potion à un
  héros tombé dans une fosse n'aurait aucun sens à la table.

**Ordre des opérations, et il est délibéré.** La phase des monstres se résout
dans la requête HTTP d'un *autre* joueur, à l'intérieur d'une transaction :
rien ne peut l'y suspendre le temps d'un aller-retour vers un téléphone. Le
coup est donc **appliqué**, puis la question posée — l'ordre même de la table,
où l'on annonce les dégâts avant que le joueur dise « j'annule ». Accepter
**défait** le coup.

1. `MoteurDegats` applique les dégâts, puis `MoteurReactions::proposer()` dépose
   `etat_personnage_quete.reaction_en_attente` si le héros a un sort réactif
   **disponible**.
2. `.reaction.proposee` part sur le canal **privé** `joueur.{id}` :
   `{groupe, reaction: {personnage_id, sort, description, source, degats,
   expire_dans}}`. C'est sa décision — ni la table ni les autres manettes ne la
   reçoivent.
3. La proposition est **aussi** exposée dans `EtatGroupe.entites[]` du héros
   sous `reaction_en_attente` : une manette rechargée entre-temps perdrait
   sinon la proposition, et avec elle le pouvoir du joueur, sans qu'aucun écran
   ne le dise.
4. Le joueur répond. `accepte: true` → PV rendus, héros **relevé** s'il était
   tombé de ce coup, sort passé à `disponible = false`, entrée `combat` au
   journal, `.groupe.etat` rediffusé. `accepte: false` → rien, le sort est
   conservé. Dans les **deux** cas la proposition est consommée.

La ressource dépensée dépend de la source : un **sort** (`disponible = false`),
une **capacité de carte** « once per quest » (`capacites_utilisees`), ou un
**Style Élémentaire** du Moine (`styles_epuises`, cf. §Capacités de carte). Une
réaction dont la cible a disparu entre-temps — le monstre abattu, éloigné — ne
dépense rien et répond `active: false` avec une `raison`.

Sources réactives : `attaque_monstre` · `sort_dread` · `tir_ami`. ⚠ **Pas**
`rejeton` : les cartes parlent d'un coup encaissé, alors que les jetons de
rejeton sont une hémorragie automatique en fin de son propre tour — les faire
annuler viderait la mécanique du jeton. Fenêtre de décision : **45 s**
(`ReactionEffet::FENETRE_SECONDES`) ; passée, la réponse `true` est refusée en
422 et la manette répond `false` d'elle-même plutôt que d'afficher une feuille
morte.

**Le TPK attend la réponse** (2026-08-13). Un coup qui achève le **dernier**
héros debout concluait le round en `echouee` avant que le téléphone ait sonné :
la potion arrivait sur une quête déjà perdue. Le verdict de fin de round est
désormais **suspendu** tant qu'une proposition capable de relever quelqu'un
attend — `annule_degats`, `plancher_pv`, `annule_degats_voisin`, `soin_urgence`
(ni `riposte` ni `defi_errant` : ils frappent, ils ne relèvent personne). La
quête reste `en_cours`, tout le monde à terre, et c'est la **réponse** qui
tranche : accepter la relève, refuser prononce le TPK.

⚠ Un round suspendu ne prend **aucun snapshot** `nouveau_tour` : un instantané
« tout le monde au sol » deviendrait faux dès la potion bue, et c'est lui que
`/reprise` rechargerait.

⚠ Si personne ne répond — téléphone verrouillé, appli fermée — l'expiration
côté serveur **n'atteint aucun client**. Le rattrapage
(`MoteurReactions::rattraperExpiration()`) purge les propositions périmées et
reprend le verdict ; il est appelé au **battement de cœur de la table**
(`POST /table/ping`, le seul ticker fiable) et à chaque **`GET /etat`** pour une
partie jouée sans écran de table. Sans lui, un groupe entièrement à terre
resterait en quête pour toujours.

## Votes de groupe (doc 05 §5)

Un seul vote actif par groupe (cache + journal). Types MVP : `retrait_joueur`
(en quête ; le joueur visé ne vote pas ; majorité requise, **égalité = il
reste**) et `choix_groupe` (question + options posées par le MJ ou un joueur ;
résolution à complétude des votants, majorité simple — **égalité = la première
option au décompte stable** (ordre de déclaration), choix déterministe MVP à
raffiner en playtest).

| Méthode | Route | Corps | Effet |
|---|---|---|---|
| POST | /groupes/{identifiant}/votes | {type, question?, options?: [{id, libelle}], cible_joueur_id?} | lance le vote (422 si un vote est actif) |
| POST | /groupes/{identifiant}/votes/bulletin | {option_id} | vote du joueur ; à complétude → résolution |
| GET | /groupes/{identifiant}/votes | — | vote actif ou null |

Résolution `retrait_joueur` : option `oui` majoritaire → le joueur quitte le
groupe avec **sa part de l'or d'avant la quête** (`quetes.or_initial` ÷ membres,
doc 05 §5) versée à son personnage ; personnages détachés (groupe_actif_id null).
Hors quête, pas de vote : `POST /groupes/{identifiant}/depart` (part du pot
commun ÷ membres présents).

Broadcasts canal `groupe.{identifiant}` : `.vote.lance` ({vote}), `.vote.maj`
({decompte, exprimes, attendus}), `.vote.resultat` ({option_id, applique}) puis
`.groupe.etat` si l'état a changé.

## Pièges (doc 10 — tout passe par les menus, pas de nouvel endpoint)

### Les trois pièges de sol, enfin tels que le livret les décrit (2026-09-24)

⚠ **Constat qui a motivé cette section** (René : « je semble toujours avoir des
trous ») : `AssembleurCarte::placerPieges()` posait **le premier piège du
catalogue pour CHAQUE piège** — `Piege::orderBy('id')->value('id')`, soit la
Fosse. Mesuré sur toutes les cartes en base : 6 pièges, 6 fosses. La Chute de
blocs et le Piège à lances n'étaient **jamais** placés. Et même posée, la Chute
de blocs n'aurait rien bloqué : `bloque_passage` n'avait **aucun lecteur**.

**Tirage** : chaque piège posé tire son type parmi les pièges **de sol** du
catalogue (jamais un piège de coffre/meuble, dont le déclencheur est
`ouverture_tresor`), par le PRNG déterministe de l'assemblage.

**Valeurs sourcées** — livret de Zargon **p. 14** (photo de René, 2026-09-24,
« Pit / Spear / Falling Block Traps »), recoupé par `reference/16_armurerie.md`
§716 et `reference/17_mobilier.md` §121. Le catalogue les avait aplaties à
« 1 PV » :

| Piège | Déclenchement | Après |
|---|---|---|
| Fosse | 1 PV de Body | la tuile reste ; **le tour du héros se termine** |
| Piège à lances | **1 dé de combat** : un crâne = 1 PV de Body | pas de tuile (*« there are no spear trap tiles »*) → **détruit** ; **le tour se termine** |
| Chute de blocs | **3 dés de combat**, 1 PV par crâne, **aucune défense** | la case devient un **BLOC PERMANENT** ; le héros avance ou recule ; **le tour se termine** |

⚠ **« Their turn immediately ends »** figure sous les TROIS pièges : le héros
qui déclenche un piège ne garde **ni son déplacement restant ni son action**.

Le payload d'un déclenchement (`declenchement`, `pieges_declenches[]`) porte les
faces du jet quand il y en a un : `faces: [str]` (faces de dé de combat) et
`touches` (crânes comptés). ⚠ C'est ce qui permet au fil et à la scène de table
de DESSINER les dés du piège, comme ceux d'une attaque.

**Le bloc tombé** (René, 2026-09-24) :
- il bloque le **passage** (sourcé : « *the trap space is now a permanent block
  in the game* ») **et la VUE**, comme un mur — lu par la boucle unique de
  `FabriqueGrille::pour()`, jamais par une seconde ;
- il est publié dans `EtatGroupe.carte` pour que la table et la manette le
  dessinent comme un bloc de pierre, **pas** comme un trou ;
- ⚠ **Le héros sur la case CHOISIT : avancer ou reculer** (René ; livret p. 14 :
  *« the hero then decides to move ahead or move back to an empty square »*).
  **Au plus deux cases** : **reculer** = la case d'où il venait (libre par
  construction, donc la liste n'est jamais vide) ; **avancer** = la case suivante
  dans le sens de sa marche, si elle est vide et praticable. Tant qu'il n'a pas
  choisi, son menu ne contient QUE l'option `s_ecarter_du_bloc`, qui porte
  `parametres.cases: [{x, y, sens: "avancer"|"reculer"}]` — la **liste blanche**
  que le résolveur revalide. Corps : `{option_id: "s_ecarter_du_bloc",
  parametres: {x, y}}`. L'état « doit s'écarter » vit en **colonne** (jamais en
  cache : un téléphone rechargé doit retrouver le choix en attente). Une fois le
  choix fait, **son tour se termine** (livret) — le livret ajoute que choisir
  d'avancer peut l'isoler à jamais du groupe : la manette l'annonce.

Cycle : **caché** (placé à l'assemblage) → **détecté** (action Fouiller réussie
sur la zone ; auto pour un héros adjacent possédant le nœud *Œil du mineur*) →
**désamorcé** / **franchi** / **déclenché**. L'état des pièges vit dans la carte
de la quête.

- **Déclenchement** : un héros qui entre sur la case d'un piège **caché**
  (déplacement traversant inclus) le déclenche : effet du tableau ci-dessus, puis **fin du tour** du héros (livret p. 14) ;
  `piege_a_lances`/`chute_de_blocs` à usage unique. Journal + narration.
- **Désamorcer** (option de menu si adjacent à un piège détecté) : jet de Body
  difficulté 1, réservé au Nain OU à un porteur de la Trousse à outils ; échec
  → le piège se déclenche sur le désamorceur (choix MVP, question ouverte n°3).
- **Franchir une fosse détectée** (option de menu si adjacente) : jet de Body
  difficulté 2 (départ playtest) ; échec = chute (effet de la fosse).
- **EtatGroupe.carte** gagne `pieges: [{x, y, etat: "detecte|desarme|declenche",
  nom}]` — les pièges **cachés n'y figurent jamais** (la table ne les montre
  pas). EtatGroupe.entites héros gagne `niveau`.

## Mobilier (doc 17 — catalogue de référence, aucun nouvel endpoint)

Table, coffre, trône, établi d'alchimiste, tombeau, bibliothèque, râtelier
d'armes, armoire (8 types de `mobiliers`, emprise 1×1 ou 1×2 mesurée sur les
cartes de quête officielles) posés dans les salles (jamais en salle de départ,
jamais en couloir) par `AssembleurCarte::placerMobilier()`, densité 0 à 3 par
salle.

Chaque meuble porte **deux drapeaux INDÉPENDANTS** (`mobiliers.bloque_mouvement`,
`mobiliers.bloque_vue` — portage, aucun livret ne traite la question) : une
table bloque le passage mais on voit par-dessus, une bibliothèque bloque les
deux. Les deux sont lus par **`FabriqueGrille::pour()`, source unique de
cette occupation ET de cette opacité**, partagée par le déplacement, le
ciblage et la ligne de vue :
- `bloque_mouvement` → `Grille::obstruer()` → infranchissable
  (`estTraversable()`), tous les 8 types aujourd'hui.
- `bloque_vue` → `Grille::occulter()` → coupe `ligneDeVue()`
  **inconditionnellement, comme un mur** — indépendant de `figuresBloquent`
  (qui ne concerne que les FIGURES interposées : héros, monstres, alliés).
  Vrai pour Bibliothèque, Râtelier d'armes, Armoire (mobilier haut) ; faux
  pour Table, Coffre, Trône, Établi d'alchimiste, Tombeau (mobilier bas).

Historique : un meuble `bloquant` unique occupait la même case qu'une figure,
ce qui coupait aussi la ligne de vue dès qu'un appelant passait
`figuresBloquent: true` (le cas de toute arme à distance, `MenuMoteur`) — une
table arrêtait donc les flèches. Corrigé en séparant les deux propriétés.

Invariant garanti à l'assemblage : aucun meuble ne peut isoler une case par
ailleurs atteignable (placement abandonné plutôt que posé si la connexité de
la salle romprait) — verrouillé par `tests/Feature/Partie/CouloirsTest.php`.

⚠ **Une salle PEUT désormais n'avoir qu'un seul accès, secret** (René, 2026-08-24) :
`AssembleurCarte` rend une arête de l'arbre couvrant `secrete` — **au plus une**, une
feuille, jamais celle qui sort de la salle de départ, et **un donjon sur deux**
pour que la découverte reste une surprise plutôt qu'une routine. ⚠ Le taux est
tenu par un **compteur de pitié** sur le groupe (`groupes.chance_passage_secret`) :
50 % de base, **+10 points** par carte sèche, retour à 50 dès qu'un passage tombe —
un tirage pur peut sécher toute une campagne, et cette série-là ne se lit pas comme
du hasard. Jamais plus de cinq cartes sèches d'affilée. L'invariant « jamais tributaire
d'une porte secrète » est retiré : sa justification (« un jet raté figerait le groupe »)
est périmée depuis que « Fouiller la zone » est offerte à chaque tour sans limite et que
`battre_en_retraite` n'a aucune condition. Conséquence assumée, salle-objectif comprise :
une quête peut se perdre faute d'avoir cherché.

### Épreuves — les ancrages à JET D'ATTRIBUT (2026-08-24)

**EtatGroupe.carte** gagne `epreuves: [{x, y, nom, description, attribut,
difficulte, tentee_par: [ids]}]`, quatrième couche de `cartes.grille` au même
niveau que `leviers`/`pieges`/`mobilier`, soumise au **même brouillard** que le
mobilier (une salle non découverte n'expose rien).

Une épreuve est un ancrage auquel un héros **au contact** tente un jet d'attribut.
⚠ **C'est par elles que le moteur émet enfin des jets de contexte `savoir` et
`social_peur`** : depuis la suppression de `MenuChoix` (2026-08-18) il n'en
produisait plus qu'un seul, « Fouiller la zone » (Mind, `perception`), si bien
que six talents de la grille — *Intimidation*, *Érudition*, *Prestance*, *Beau
parleur*, *Méditation*, *Cartographe* — ne se déclenchaient **jamais** en partie.

- Option `epreuve_{index}`, de **type `jet`** — c'est délibéré : `resoudreJet()`
  applique alors gratuitement l'avantage de contexte (`avantage_jet_mind`) et la
  relance (`relance_jet_mind_rate`).
- **Une tentative par héros**, réussie ou non (`tentee_par`) : le prix réel est le
  créneau d'ACTION, et les compagnons gardent la leur.
- Six effets, vocabulaire fermé `App\Engine\MotsClesEpreuve` (registre vérifié
  dans les deux sens, lecteur déclaré confronté à son fichier) : `or`, `objet`,
  `parchemin`, `soin_groupe`, `retire_condition`, `desarme_pieges_salle`.
- `epreuves.exige_placement` est une **précondition de POSE**, distincte de
  l'effet : l'*Autel fêlé* ne se pose que dans une salle contenant un piège.

### Symboles de la carte et légende (2026-08-27)

**EtatGroupe.carte** gagne `leviers: [{x, y, difficulte}]`.

⚠ **`levier_id` N'EST PLUS PUBLIÉ** (arbitrage de René, 2026-09-11). Il l'était,
alors que la PORTE, elle, n'annonce que le *type* de son verrou (`verrou: "levier"`)
et jamais lequel l'ouvre. Le joueur lisait donc des identifiants qui ne se
raccordaient à rien — la moitié d'un appariement, ce qui est pire que zéro ou que
deux. On retire la moitié visible : on découvre quel levier ouvre quelle porte
**en l'actionnant**, comme au plateau. L'appariement reste côté serveur
(`ResolveurTour::resoudreActionnerLevier()` lit `verrou.levier_id`), où il est
re-validé — la liste blanche n'a jamais eu besoin de passer par le client.

⚠ Cette couche n'était publiée **nulle part** : aucun levier n'était donc dessiné
sur aucune des deux cartes. Sans conséquence tant qu'aucun n'était posé, mais
depuis qu'en forcer un demande un jet de Body et qu'une salle peut ne tenir qu'à
cette porte, c'était un mécanisme **invisible** qui verrouille le donjon —
l'option n'apparaît qu'au contact, et rien ne disait où aller le chercher.

- **Brouillard** : une entrée de levier ne porte pas sa salle (`{x, y}` publiés,
  format d'origine), elle est **déduite des coordonnées**. Un levier de **couloir**
  est toujours montré — un couloir n'a pas d'index de salle et n'est jamais
  « découvert », le cacher rendrait le mécanisme introuvable.
- ⚠ `difficulte` est la difficulté **effective**, passée par
  `DifficulteBody::plafonnee()` exactement comme dans `MenuMoteur`. Publier la
  valeur brute ferait annoncer « difficulté 3 » sur la carte à un groupe à qui le
  menu proposera « difficulté 2 ».

**Mur de Glace (2026-09-17).** **EtatGroupe.carte** gagne `glace: [{x, y, cranes}]`
— la couche `carte.grille['glace']` posée en cours de quête par le sort du boss
(`MoteurDread::sortDreadMurDeGlace()`), **jamais** le catalogue `terrains`.

⚠ Elle répétait à la lettre le défaut des leviers : une couche **posée, lue par le
moteur, et publiée NULLE PART**. Un mur de glace bloquait le mouvement
(`FabriqueGrille::pour()` le range dans `$obstacles`) sans être dessiné sur aucune
des deux cartes ni connu du calcul d'accessibilité de la manette : le joueur tapait
une case derrière un mur invisible et le serveur refusait. `cranes` est le compteur
de crânes encaissés (5 le brisent — carte *Ice Wall*, doc 18 §4) : sans lui,
frapper le mur n'aurait aucun retour visible. Brouillard : même critère que les
leviers et le terrain — la case est publiée si elle n'est pas brouillée.

**Illustrations.** Pièges et épreuves publient une `image_url` depuis toujours ;
elle n'était **affichée nulle part**. Le **mobilier** n'en avait aucune — ni
gabarit de prompt, ni génération, ni champ — alors qu'une pièce se fouille et se
fracasse comme un piège se désamorce : `config/images.php` gagne le gabarit
`mobilier`, `BibliothequeImages::urlMobilier()` l'accesseur,
`images:generer --type=mobiliers` l'itération, `EtatGroupe.carte.mobilier[]` le
champ, et `PlaceholderController` son emblème (un plateau sur deux pieds — un
coffre ou un trône auraient nommé UNE pièce là où l'emblème les remplace toutes).

⚠ Les illustrations vont dans la **légende**, pas sur la carte : à 22 px sur la
manette une illustration devient une bouillie, et elle effacerait la silhouette,
qui est ce que le joueur lit d'un coup d'œil. **La forme dit la famille, l'image
dit le contenu.** Un levier n'en a pas — il n'a pas de table de catalogue.

**Levier et porte sont illustrés aussi** (2026-08-29). Ce sont les deux seuls
éléments d'une salle **sans table de catalogue** — un levier n'est qu'un id dans
la grille, une porte une arête —, donc ils sont nommés comme les **classes**, par
un libellé fixe (`catalogue/leviers/levier.png`, `catalogue/portes/{etat}.png`)
et non par `{id}-{slug}` : il n'y a aucune ligne à numéroter.

⚠ **Une image PAR ÉTAT de porte** (close, ouverte, verrouillée, dérobée) : c'est
l'état qui porte l'information, une image unique les rendrait indiscernables. Les
clés de `config('images.portes')` sont celles de `MoteurPortes::ETAT_*`, et un
test les confronte **dans les deux sens** — un état ajouté au moteur sans
illustration retomberait en silence sur l'emblème SVG, une entrée orpheline ferait
générer une image que rien n'irait chercher.

`EtatGroupe.carte.leviers[]` et `carte.portes[]` gagnent `image_url`. Chaque
entrée de `carte.portes[]` gagne aussi `embrasure: {x, y}` (§Portes &
exploration plus bas) — la case sur laquelle `DungeonGrid.vue` centre l'image.
`images:generer --type=leviers|portes` les produit.

⚠ **Une porte secrète non révélée n'a plus d'existence à illustrer** (2026-09-11,
§Portes & exploration plus bas) : elle ne figure plus du tout dans `portes[]`,
sa case se peint directement `m` dans `carte.cases` — un mur n'affiche jamais
d'`image_url`, il n'y a donc plus de cas particulier à documenter ici (l'ancien
déguisement `etat: "mur"` du 2026-09-10, qui vivait dans cette liste sans
illustration, est retiré avec lui).

**Une silhouette par famille**, parce que les marqueurs se côtoient dans une même
salle : figurine **ronde**, piège **carré**, épreuve **losange** doré, levier
**octogone** bleu, meuble **bloc plein** sur son emprise. Le premier marqueur
d'épreuve était un disque doré de la taille d'un jeton — sur la table il se lisait
comme une quatrième figurine entre deux héros.

Les icônes vivent dans `resources/js/components/carte/symboles.js`, lu à la fois
par le **rendu** (`DungeonGrid`) et par la **légende** (`LegendeCarte`) — une
légende tenue à part se périme au premier symbole ajouté, et ment alors avec
l'autorité d'une légende. `SymbolesCarteTest` confronte la table au catalogue dans
les **deux sens** (pièges, épreuves, mobilier) et vérifie que les deux composants
importent bien le même fichier.

**Le bouton « i »** ouvre la légende sur les deux écrans (coin de la carte à la
table, en-tête de la feuille de déplacement sur la manette). Elle ne liste que ce
qui est **sur cette carte** : une légende qui décrit sept pièges quand la salle en
contient un se lit comme une documentation, et on cesse de l'ouvrir.

### Aperçu de salle (table, 2026-08-29)

**EtatGroupe.carte** gagne `salles: [{index, x, y, largeur, hauteur}]`.

⚠ **Les salles DÉCOUVERTES seulement.** `cases` est déjà masqué par le
brouillard ; publier tous les rectangles donnerait le nombre, la taille et la
position des salles jamais ouvertes — le brouillard contourné par la porte de
derrière.

**Le contour de salle a été retiré** (René, 2026-09-11, le jour même où il avait été
posé — front seul, **payload inchangé dans les deux sens**). `salles[]` reste publié et reste
filtré par le brouillard : il sert le rendu des salles, pas un trait de contour. Ce qui rend
une salle lisible est le CONTRASTE sol/roche, pas un cerne.

La table gagne un second bouton à côté de la légende : la légende explique les
**symboles**, l'aperçu énumère le **contenu** de la salle où se tient le héros
actif — figurines (avec le `nom_base` de catalogue sous le nom habillé par
l'IA), mobilier, épreuves, pièges connus, leviers et issues, chacun avec son
illustration. Il suit le tour de jeu tout seul ; le narrateur ne sélectionne
rien.

⚠ Il **retient** le dernier héros actif : pendant la phase des monstres aucun
héros n'a la main, et le panneau clignoterait à chaque fin de tour, précisément
quand la table regarde ce qui se passe dans la salle.

⚠ `App\Partie\Salles::indexDe()` est le **point de passage unique** de « quelle
salle contient cette case ? ». La question était posée à **six** endroits, chacun
avec sa boucle (deux dans `ResolveurTour`, une dans `DemarreurQuete`, une dans
`AssembleurCarte`, la closure du brouillard et le filtre des leviers dans
`EtatGroupe`). Toutes disaient la même chose, ce qui est le risque : la règle est
trop simple pour qu'on remarque qu'une copie a dérivé.

### Jets de Body — trois emplois neufs, et un plafond

⚠ **Aucune difficulté de Body ne dépasse jamais le meilleur `attribut_body` des
héros engagés** (`App\Partie\DifficulteBody`, René 2026-08-24) : un jet que la
compagnie ne peut mathématiquement pas gagner n'est pas un obstacle, c'est une
impasse déguisée en choix. ⚠ La valeur **brute** est stockée (catalogue, levier),
le plafond s'applique à la **génération du menu** — il monte quand un héros
achète *Colosse*, il descend quand le costaud s'en va.

- **Repousser** — option `repousser_{instance_id}`, type `poussee`. Difficulté =
  les **PV de Body du CATALOGUE** de la créature (jamais ses PV courants : un boss
  blessé n'est pas plus facile à bousculer). Réussite : la figure recule d'une
  case sur l'axe héros → monstre. ⚠ **Aucun piège n'est déclenché** sous elle.
  Une tentative par héros et par monstre.
- **Fracasser un meuble** — option `detruire_mobilier_{index}`, type `jet` Body,
  difficulté `mobiliers.difficulte_destruction` (⚠ `null` = **indestructible**).
  La pièce cesse de bloquer mouvement ET vue ; si elle était **fouillable**, elle
  rend **une dernière fouille** à son destructeur, même déjà vidée par le groupe.
- **Forcer un levier** — l'option `actionner_levier` demande désormais un **jet de
  Body** (difficulté du levier, 1-3) et **coûte le créneau d'ACTION** : ce n'est
  plus une interaction gratuite. ⚠ **Retentable sans limite**, contrairement aux
  épreuves — c'est ce qui autorise une salle à ne tenir qu'à ce levier sans jamais
  se sceller.

- **EtatGroupe.carte** gagne `mobilier: [{x, y, l, h, nom, bloque_mouvement,
  bloque_vue}]` — l'ancre `(x, y)` est le coin haut-gauche de l'emprise (l×h),
  même convention que `cellulesEmprise()`. Contrairement aux pièges, un
  meuble n'a pas d'état « caché » : il est simplement soumis au même
  **brouillard de guerre** que le reste de la salle (une salle non découverte
  n'expose aucun de ses meubles).
- `fouillable` (catalogue) **n'est lu par aucun système aujourd'hui** — la
  fouille du mobilier est un chantier séparé (doc 17 §4) : `DeckFouille`
  raisonne en salle, pas en case, et le piège de coffre impose un ordre
  fouille-pièges-avant-trésor que le moteur ne vérifie pas encore. Le champ
  n'apparaît donc volontairement pas dans le payload — pas de fonctionnalité
  à laisser croire livrée côté client.

## Portes & exploration (doc 14 §3.1/3.2/3.3 — tout passe par les menus)

L'état des portes vit dans la carte de la quête (`cartes.grille.portes` :
`{x, y, cote: "e|s", etat: "fermee|ouverte|verrouillee|secrete", verrou?, revele?}`).
Une porte vit sur une **ARÊTE** (cloison) entre la case `(x,y)` et sa voisine EST
(`cote:"e"` → `(x+1,y)`) ou SUD (`cote:"s"` → `(x,y+1)`), **activable des deux
côtés** — ce champ ne change pas.

⚠ **Une porte NON `ouverte` bloque désormais sa CASE, EN PLUS de son arête**
(René, 2026-09-11, après avoir joué : « la porte doit être centrale à sa case,
bloquant l'entrée dans sa case tant qu'elle n'est pas ouverte »). Des deux cases
que sépare l'arête, UNE est l'**embrasure** — celle qui appartient à l'anneau de
mur d'une salle (`carte.salles`, mur compris), c'est-à-dire la case que le mur a
réellement cédée pour ouvrir le seuil ; l'autre reste un simple sol de couloir
(ou, pour une jonction MITOYENNE, l'intérieur immédiat de l'autre salle) et n'a
jamais été un mur. `Grille::caseEmbrasure()` tranche laquelle des deux avec ce
rectangle ; le calcul n'a PAS lieu deux fois — chaque entrée de `portes[]` publie
directement `embrasure: {x, y}` (ci-dessous), pour que `DungeonGrid.vue` (rendu)
et `DeplacementSheet.vue` (miroir client du pathfinding) lisent la même case au
lieu de re-dériver la règle chacun de son côté.
Non `ouverte`, l'embrasure devient **inoccupable** (pathfinding) et **opaque**
(ligne de vue) **des DEUX côtés** — corollaire assumé (René, 2026-09-11) : avant
ce correctif, seule l'arête protégeait le pas VENU DU COULOIR ; rien ne protégeait
le pas venu de L'INTÉRIEUR de la salle, et un héros pouvait s'y tenir. `ouverte`,
elle redevient un sol ordinaire : on la traverse et on voit à travers, et **la
salle derrière se révèle** (comme toute ouverture de porte, `revelerDerriere()`).
Aucun coût de déplacement n'est introduit par l'embrasure elle-même.

**Codes de case (`carte.cases`)** : `m` mur, `s` sol, `b` **brouillard** (plus de
case `p` : la porte est une arête, rendue sur le bord entre deux cases).
`EtatGroupe.carte` applique un **brouillard de guerre** : ne sont dévoilées que les
salles **découvertes** (on y est entré — `decouvrirSalle`) plus ce qu'on atteint
depuis elles par des portes **ouvertes** ; tout le reste (salles non découvertes,
couloirs derrière une porte fermée) est renvoyé en `b`. Les portes dont la case est
sous brouillard sont **retirées de `portes`** (pas de cadenas/jeton à travers le
voile). Purement cosmétique — le moteur travaille toujours sur la carte réelle. La
disposition est un **arbre 2D branchu** (couloirs à 2 voies, une seule porte par bord
de salle) ; `cartes.grille` porte aussi `salles[]` et `aretes[]` (métadonnées de
tracé, non servies dans le payload).

⚠ **Salles ACCOLÉES par défaut, un seul battant (René, 2026-09-11)** —
`AssembleurCarte::accolerSallesMitoyennes()` glisse désormais PAR DÉFAUT chaque
salle-feuille mur contre mur avec sa parente (« dans le jeu original il n'y a
pas de case pour relier deux salles ») ; les vrais couloirs ne subsistent que
pour les salles non-feuilles et celles qu'un chevauchement empêche d'accoler.
Sur une jonction ainsi devenue MITOYENNE, `portes[]` ne compte plus qu'**UNE**
entrée au lieu de deux — la case de seuil que se partageaient les deux anciens
battants encadrants n'existe plus, un seul suffit sur l'arête commune, et c'est
elle qui EST l'embrasure des deux salles à la fois (elles se touchent
exactement sur cette case). Le format d'une entrée de `portes[]` ne change pas ;
seul le **nombre** d'entrées partageant un `jonction` passe de 2 à 1 pour ce cas.
`carte.aretes[i].porte_a` et `.porte_b` valent alors les MÊMES coordonnées (pas
de second bout à publier). Une porte SECRÈTE peut désormais elle aussi être
mitoyenne — un passage dérobé directement dans un mur partagé entre deux
salles, plus fidèle au plateau qu'un cul-de-sac de couloir ; sa case se peint
en roche (ci-dessous) identiquement tant qu'elle n'est pas trouvée.

- **EtatGroupe.carte** gagne `portes: [{x, y, cote, etat, embrasure: {x, y}, verrou?, image_url?}]` —
  une porte connue est rendue comme une porte, centrée sur sa case `embrasure`
  (§Symboles de la carte et légende plus haut), une `verrouillee` porte un
  cadenas (`verrou` = type du verrou).
  ⚠ **Une porte `secrete` non révélée n'est TOUJOURS PAS retirée du payload
  sans rien pour la remplacer** (ça, c'est le défaut du 2026-09-04 — toujours
  corrigé) — mais depuis que la porte bloque sa CASE (2026-09-11), le correctif
  a changé de forme. La version du 2026-09-10 la publiait déguisée en mur
  (`etat: "mur"`, un état d'affichage jamais persisté). **Cette version-là est
  retirée à son tour** : une porte `secrete` non révélée n'a maintenant
  **aucune entrée dans `portes[]`** — ni `mur`, ni rien —, exactement comme un
  mur de roche ordinaire. Ce qui portait l'information est désormais
  `carte.cases` lui-même : sa case d'embrasure y est peinte **`m`**, au même
  titre que n'importe quel mur, **avant** l'application du brouillard (pour
  qu'elle en suive exactement les mêmes règles d'affichage — silhouette d'un
  mur qui borde une case visible, mur qu'un héros touche…). ⚠ Ancienne
  distinction résiduelle, désormais **éliminée** : le mur-déguisement gardait
  une entrée dans `portes[]` qu'un mur ordinaire n'a jamais ; en ne publiant
  plus AUCUNE entrée pour ce cas, il n'y a plus rien à comparer — la porte
  secrète non trouvée est rigoureusement indiscernable d'un mur, y compris en
  comptant les entrées.
- Une fois la fouille réussie, `MoteurPortes::revelerSecretes()` passe l'entrée à
  `{etat: "fermee", revele: true}` — **pas** `"ouverte"` (arbitrage de René,
  2026-09-11 : « un passage secret trouvé devrait l'afficher comme une porte
  fermée, on peut maintenant interagir avec pour l'ouvrir » — trouver et
  franchir sont deux actes distincts, comme au plateau ; sans ça, un seul jet
  de Mind révélait la salle ET son coffre sans qu'on ait eu à s'en approcher,
  puisque toute ouverture de porte révèle la salle derrière). `revele: true`
  reste posé : c'est lui qui distingue une porte trouvée d'une porte
  ordinaire, une fois l'état sorti de `secrete`. La publication suit alors la
  branche normale (`portes()`) et l'affiche comme une porte fermée, avec sa
  case d'embrasure de nouveau franchissable dès qu'elle s'ouvre.
- **Fouiller la zone** (option `fouiller`, type `jet`, Mind difficulté 1) : un seul
  jet réussi révèle dans le rayon de fouille les **pièges cachés** ET les **portes
  secrètes** (qui deviennent des portes fermées, ouvrables). Echo : `pieges_reveles`,
  `portes_revelees`.
- **Verrous** (doc 14 §3.3) :
  - `cle` : option `ouvrir_porte` (id `ouvrir_porte_{x}_{y}`) au contact d'une porte
    verrouillée, offerte si le héros possède l'objet-clé → la porte s'ouvre (persistant) ;
  - `monstres_vaincus` : ouverture **automatique** quand les instances désignées sont
    vaincues (hook post-combat) — aucune action joueur ;
  - `levier` : option `actionner_levier` (id `actionner_levier_{x}_{y}`) au contact d'un
    levier (`cartes.grille.leviers`) → **jet de Body** (difficulté du levier, plafonnée) ;
    réussi, il ouvre la/les porte(s) liée(s) par `verrou.levier_id` et révèle ce qu'il y
    a derrière. ⚠ Coûte le créneau d'**action** depuis le 2026-08-24 (c'était une
    interaction gratuite), et se **retente** sans limite.
- **Fouiller — trésor** (option `fouiller_tresor`, type `fouille_tresor`) : action
  SÉPARÉE, offerte dans une **salle « vide »** (rencontres nettoyées) **non encore
  fouillée**. On **pioche une carte de fouille**, à la HeroQuest : le deck est bâti
  au démarrage de la quête depuis `gabarits_quete.structure.deck_fouille` et pioché
  **sans remise** (`quetes.deck_fouille`, index 0 = sommet) — la composition est donc
  **garantie**, là où l'ancien tirage pondéré (`tresor_a_risque`, supprimé) n'en
  donnait qu'une espérance biaisée. `issue` ∈ :
  - `tresor` — `or` versé au groupe (montant figé dans la carte) ;
  - `potion` — consommable rangé chez **le fouilleur** (`objet`) ;
  - `artefact` — arme **unique** du coffre désigné (`objet`, `coffre: true`) ;
  - `errant` — monstre du bestiaire instancié au contact, qui joue au tour des monstres — **sans plafond**, sa carte revenant sous le
    paquet comme les autres ;
  - `piege` — effet du « Piège de coffre » appliqué **tout de suite** au fouilleur
    (`declenchement`), jamais posé sur la grille ;
  - `rien`.

  Clés additionnelles du payload : `deck_restant` (cartes encore en pioche),
  `deck_vide` (deck épuisé → rétrogradé en `rien`), `coffre`, `sac_deborde`
  (l'objet a été remis **au-delà** de la capacité du sac — cf. ci-dessous),
  `objet_indisponible` (carte pointant un objet absent du catalogue).

  Le monstre errant ne survient **que** par cette action (jamais par « Fouiller la zone »).
- **Coffre à artefact** : chaque quête désigne **une** salle — la plus profonde dans
  l'arbre des couloirs (`quetes.salle_artefact`) — abritant **au plus un** artefact de
  rareté `unique` (`quetes.artefact_objet_id`) : une **arme ou une armure**, jamais un
  consommable. Il **ne consomme aucune carte** du deck : c'est un bonus net, et le
  héros qui fouille le reçoit. Le tirage **écarte tout artefact qu'aucune classe
  ACTIVE du groupe ne pourrait porter** (croisement `tag_equipement` × maîtrises de
  classe × nœuds `acces_equipement`) — sinon l'unique artefact d'une quête pouvait
  être du butin mort. Plus aucun artefact disponible (tous déjà détenus, ou tous
  hors de portée du groupe) → le coffre verse `deck_fouille.or_coffre`. La salle-coffre n'est **jamais** exposée dans `EtatGroupe`
  (la table ne doit pas savoir où chercher).
- **Artefacts (rareté `unique`)** : **ni achat ni revente**. Ils n'apparaissent dans
  aucun profil de marchand, sont retirés de la liste vendable d'un panier, et une
  vente forcée est un **422** (« Un artefact ne se revend pas. »). La Forge les refuse
  également. Sac plein à la découverte : l'objet est remis **quand même**, en
  dépassement, avec `sac_deborde: true` — le refuser le perdrait à jamais ; le héros
  régularise en équipant la pièce (elle quitte le sac) ou en écoulant au marché.
  `/moi` expose désormais `equipement.capacite` / `equipement.occupation`, cette
  dernière pouvant dépasser la première.

## Montée de niveau (doc 01 §5 — par jalons)

Déclencheur : quête `sous_boss` ou `boss_final` **terminée** (victoire). Chaque
héros actif : **+1 niveau** ; à chaque niveau **pair**, +1 PV max (Body pour
barbare/nain, Mind pour elfe/magicien — départ playtest). Les **points de
compétence ne sont pas stockés** : `points_competence = (niveau − 1) −
nb de nœuds acquis` (dérivé, toujours juste).

| Méthode | Route | Corps | Effet |
|---|---|---|---|
| POST | /groupes/{identifiant}/competences | {personnage_id, competence_id} | acquiert une case de la grille (422 : pas son héros, classe différente, prérequis manquant, aucun point) |
| GET | /api/moi | — | personnages enrichis : `niveau, points_competence, competences: [{id, statut, libelle, raison, cadence}]` (voir ci-dessous) |
| GET | /api/competences | — | catalogue des grilles : `[{id, classe, nom, description, type, innee, categorie, categorie_icone, colonne, rang, effet, avantage, avantage_icone, prerequis_id}]` |

**La GRILLE de talents** (René, 2026-08-23) — chaque classe a **3 colonnes ×
3 lignes**. La colonne est une **catégorie propre à la classe** (`categorie`,
libellé libre : « Furie » chez le barbare, « Traque » chez l'explorateur), et
acquérir la ligne *n* exige la ligne *n−1* **de la même colonne**. `prerequis_id`
porte toujours cette règle et ne change pas de sens : le seeder le **calcule**
depuis `(classe, colonne, rang − 1)` au lieu de le nommer, ce qui rend
impossible une chaîne traversant deux colonnes. Neuf cases pour 4 à 7 points de
campagne : on descend une colonne, on renonce à une autre.

Les **capacités de carte** (`innee: true`) sont **hors grille** — `categorie`,
`colonne`, `rang` et `prerequis_id` à `null` : elles viennent avec la figurine
et ne coûtent aucun point.

⚠ **Deux textes par nœud, et les deux sont affichés.** `description` est la
phrase de jeu, écrite à la main (le gain, sa condition, sa cadence) ;
**`avantage` est DÉRIVÉ de `effet`** par `App\Engine\MotsClesTalent` et n'est
jamais saisi — c'est ce qui garantit qu'un talent ne promette pas autre chose
que ce qu'il fait. `avantage_icone` est son icône Material Symbols. Le front
n'a donc plus de table de correspondance à tenir : la sienne était keyée sur des
noms de *colonnes* du personnage et ne produisait **jamais** la moindre puce
d'effet pour une compétence.

⚠ **`/moi.competences` publie la DÉCISION d'usage, pas ses ingrédients**
(René, 2026-09-14 : « afficher si une abileté est disponible ou non et pourquoi
il n'est pas disponible quand c'est le cas »). Chaque entrée acquise —
nœud de grille **et** capacité de carte — vaut
`{id, statut, libelle, raison, cadence}` :

- `statut` ∈ **`permanent`** (passif toujours actif, aucune fenêtre),
  **`disponible`**, **`indisponible`** — c'est lui qui porte le style ;
- `libelle` est le texte du statut (« Toujours actif », « Disponible »,
  « Indisponible ») : le vocabulaire d'affichage vit côté serveur ;
- `raison` n'est renseignée **que** sur `indisponible`, et nomme la règle qui
  ferme la capacité — « Déjà utilisée cette quête », « Déjà utilisée ce tour »,
  « Utilisable en quête seulement », « Exige 5 PV de Body ou moins (tu en
  as 8) », « Exige un bouclier équipé » ;
- `cadence` est la fenêtre lisible (`une fois par quête`/`tour`/`attaque`),
  `null` pour un passif permanent.

La source est **`App\Partie\Talents::fiche()`**, le même point de passage que
`Talents::disponible()` — le booléen du moteur et la phrase du joueur sortent
d'une seule évaluation, et ne peuvent donc pas se contredire. ⚠ La condition
« **requires shield** » y est entrée à cette occasion : elle vivait dans un
`MoteurReactions::bouclierSiRequis()` privé, empilé **après** `disponible()`,
si bien que le moteur refusait *Inébranlable* sans bouclier pendant que toute
autre lecture la croyait ouverte.

⚠ **Les talents se lisent par MÉCANIQUE, jamais par nom de nœud.**
`effet.mecanique` appartient au vocabulaire fermé `MotsClesTalent::MECANIQUES`,
où chaque entrée déclare son lecteur moteur. Le câblage par nom laissait une
quinzaine de nœuds des classes d'extension parfaitement muets (*Esquive*,
*Garde haute*, *Prestance*, *Coup sauvage*, *Second couplet*, *Communion*…).

**Création d'un personnage** (`POST /api/personnages`) — `{nom, classe,
elements?, sorts_elfiques?}`. `elements` vaut pour les lanceurs à écoles
(Magicien 3, Elfe 1) ; **`sorts_elfiques` (3 identifiants) est la seconde voie de
l'Elfe**, exclusive de `elements` (422 si les deux, 422 pour toute autre classe,
422 si un identifiant n'est pas du répertoire `elfique`). Ne rien envoyer reste
permis et vaut l'école par défaut. Le catalogue du répertoire se lit dans
`GET /api/guide` (`sorts[]`, filtrés sur `element === 'elfique'`, `id` inclus).

**Suppression d'un personnage CRÉÉ PAR ERREUR** (`DELETE /api/personnages/{id}`,
René 2026-09-18 : « on n'est pas en mesure de supprimer un personnage créé en
erreur »). Rien ne le permettait : le roster ne savait qu'ajouter.

⚠ **Trois conditions, toutes vérifiées serveur**, et la troisième est le cœur du
garde-fou :
1. le personnage appartient au **joueur authentifié** (404 sinon — on ne dit pas
   à un joueur que le héros d'un autre existe) ;
2. il est **`disponible`**, c'est-à-dire `groupe_actif_id === null`. On réutilise
   la décision **déjà publiée** par `/moi` plutôt que d'en réinventer une : un
   héros engagé se retire de son groupe d'abord ;
3. il **n'a JAMAIS JOUÉ** — aucune ligne dans `etat_personnage_quete` (jamais
   entré en quête) **ni** dans `personnage_historique` (jamais fini de
   campagne). 422 nommé sinon.

⚠ **Le vétéran est délibérément EXCLU, et c'est un choix écrit** (René,
2026-09-18). Un héros entre deux campagnes porte des niveaux, de l'or, un
équipement et un historique : c'est de la **donnée de campagne**, que la règle
dure du projet interdit de détruire. Ce point d'entrée ne couvre que l'erreur de
saisie — un roster qui se range (archiver un vétéran) est un **autre** chantier,
nommé ici pour ne pas être confondu avec un oubli.

Suppression **atomique**, en une transaction : inventaire, compétences, sorts,
conditions et appartenance de groupe partent avec la ligne. ⚠ Un `DELETE` nu
laisserait ces lignes orphelines — c'est la leçon de `ClotureCampagne::purger()`,
qui existe précisément parce qu'effacer un groupe sans ses annexes en laissait
partout. Réponse `204`.

| Méthode | Route | Corps | Effet |
|---|---|---|---|
| DELETE | /personnages/{id} | — | supprime un personnage **jamais joué** du roster ; 404 : pas le sien ; 422 : engagé dans un groupe, ou a déjà joué |
| PUT | /groupes/{identifiant}/sorts-elfiques | {personnage_id, sorts: [3]} | **rechoix** des 3 sorts elfiques — **hub uniquement**. 422 : en quête, héros d'un autre joueur, classe ≠ elfe, sorts hors répertoire, ou **Elfe parti sur une école** (ce choix-là est définitif) |

À l'acquisition, les effets **passifs chiffrés** du nœud (`effet` JSON :
`attribut_body/attribut_mind/des_attaque/des_defense/pv_body_max/pv_mind_max/
deplacement_base/bonus_sac` +n) sont appliqués au personnage ; les nœuds
`actif`/`deblocage` sont seulement enregistrés (résolution ultérieure). *Œil du
mineur* (Nain) est lu par le moteur de pièges dès acquisition.

⚠ Un passif portant une **`condition`** (Frénésie « sous la moitié des PV »,
Garde tenace « à la première attaque du combat ») n'est **pas** un bonus
permanent : il n'entre dans aucune colonne et se résout en situation — l'y
mettre le ferait valoir tout le temps. La règle est unique
(`Competence::estBonusPermanent()`). Et les deux colonnes de dés
(`des_attaque`, `des_defense`) appartiennent à `Equipement::recalculerCombat()`,
qui les **reconstruit** depuis toutes leurs sources — classe + nœuds permanents
+ équipement + Forge — à chaque changement d'équipement : elles ne reçoivent
donc jamais de delta à l'acquisition, sous peine d'être comptées deux fois.

Broadcast canal `groupe.{identifiant}` : `.niveau.monte`
({personnages: [{id, nom, niveau, points_competence, gains: [...]}]}) émis à la
clôture victorieuse d'une quête à jalon, avant `.groupe.etat`.

## Équipement (doc 01 §7 — au hub uniquement)

Ferme la boucle économique : marché → sac → **équiper** → plus fort en quête.
**AU HUB** seulement (422 en quête au MVP). Les effets chiffrés de l'objet
(`des_attaque`/`des_defense`) s'appliquent aux **colonnes** du héros à
l'équipement et sont révoqués au déséquipement — même patron que les nœuds de
compétence, donc combat, fiche et budget de rencontre lisent l'équipement sans
calcul « effectif » séparé.

| Méthode | Route | Corps | Effet |
|---|---|---|---|
| POST | /groupes/{identifiant}/equipement | {personnage_id, inventaire_id, emplacement?} | équipe une pièce du **sac** ; auto-swap de l'occupant vers le sac ; 422 : pas au hub, pas son héros, objet non montable, main occupée (voir ci-dessous), ou **maîtrise manquante** (`objets.tag_equipement` absent des tags de la classe et de ses nœuds) |
| DELETE | /groupes/{identifiant}/equipement | {personnage_id, inventaire_id} | déséquipe (retour au sac, dés révoqués) ; 422 : pas au hub, objet non équipé, ou **sac plein** |
| POST | /groupes/{identifiant}/dons | {personnage_id, inventaire_id, vers_personnage_id, quantite?} | **donne** un objet à un autre héros actif du groupe ; 422 : pas au hub, `personnage_id` pas son héros, `vers_personnage_id` hors du groupe ou = donneur, objet **équipé** (à déséquiper d'abord), `quantite` > pile, ou **sac du receveur plein** |

Réponse (POST/DELETE) : `{personnage: {id, des_attaque, des_defense}}` (dés à
jour, équipement inclus). La source complète reste `GET /moi` — le front
re-`GET /moi` après chaque manip pour rafraîchir fiche + sac. Slots :
`arme_principale`, `arme_secondaire` (bouclier), `armure`. Journal `systeme` :
`equipement_equipe` / `equipement_retire`.

**Les deux mains** (dual-wielding, 2026-08-12). `emplacement` n'a de sens que
pour une arme à **une** main, qui va en `arme_principale` **ou** en
`arme_secondaire` ; omis, c'est l'emplacement naturel de la pièce. `/moi` expose
`equipement.sac[].slots` — un seul pour toute pièce ordinaire, **deux** pour une
arme à une main —, ce qui permet à la manette d'afficher « Main droite » / « Main
gauche » plutôt qu'un « Équiper » aveugle ; et `equipement.armes[]` porte
`emplacement`, `bouclier`, `deux_mains` et les `des_attaque` **de cette arme**
(la colonne du héros ne connaît que la main droite).

Quatre tenues sont légales, et quatre seulement : deux armes à une main · une
arme à deux mains · une arme à une main + un bouclier · une arme à une main
seule. Une arme à deux mains prend donc les deux : rejet explicite (jamais
d'auto-déséquipement croisé), c'est au joueur de choisir ce qu'il pose. ⚠ La
seconde arme **n'apporte aucun dé** — elle apporte une option d'attaque de plus
(voir §Ciblage en deux temps). En quête, l'action d'équipement se dédouble de la
même façon : `equiper_{id}` (main droite) et `equiper_{id}_gauche`.

### Gérer son inventaire EN QUÊTE — trois gestes, deux créneaux

Doc 01 §7 nomme trois gestes d'inventaire. Tous passent par `POST /choix`,
**jamais** par les routes REST ci-dessus, qui restent **hub-only** : manipuler
son sac en quête est un geste **de tour**, pris dans l'ordre d'initiative, pas un
appel hors-tour.

⚠ **ÉCART ASSUMÉ AVEC LE CANON, et il faut le lire comme tel** (René,
2026-09-17). Doc 01 §7 écrit « Gérer son stuff coûte **l'action du tour** » et
range les trois gestes sous cette phrase. **`jeter` en sort** : il ne coûte rien
et se répète. La raison est une tension que le canon crée lui-même au paragraphe
juste au-dessus — « sac plein → il faut **jeter un objet** pour le prendre
(tension de gestion) » : au prix d'une action par pièce, se délester devant un
coffre coûtait le tour entier, et la tension devenait une punition. Les deux
autres gestes restent au prix fort. Ce n'est pas une omission ni une simplification
d'implémentation : c'est une règle maison, écrite ici et dans
`docs/regles/equipement-et-armurerie.md`.

| Geste | Option | Porte | Créneau |
|---|---|---|---|
| Équiper / ranger | `equiper_{id}` · `equiper_{id}_gauche` · `desequiper_{id}` | une pièce par option | action |
| **Échanger avec un allié adjacent** | `echanger` | `parametres.allies[]` | **action** (la séance entière) |
| **Jeter un objet du sac** | `jeter` | `parametres.objets[]` | **interaction — GRATUIT** |

⚠ **Les deux nouvelles suivent « une action, puis un sous-choix »** (§Ciblage en
deux temps) : l'option ne porte pas un objet, elle porte **la liste** des objets.
Un sac de six pièces aurait sinon ajouté douze boutons au menu d'action, que le
doc 13 §3.1 borne à « 2 à 5 options claires ».

**`jeter` — GRATUIT** (créneau `interaction`, René 2026-09-17 : « jeter des items
ne prend pas d'action, permettant de jeter plusieurs choses dans le même tour »).
Chaque entrée de `objets[]` porte `cle` (`"objet:{id}"`), `inventaire_id`, `nom`
et `quantite`. ⚠ **L'option est émise HORS de la garde `! $aAgi`** : gratuite
mais masquée dès que le héros a agi, elle le serait pour rien — or c'est
justement après avoir agi qu'on veut se délester. ⚠ La leçon d'`actionner_levier`
(retiré des gratuits le 2026-08-24 : un jet retentable sans coût se relance à
l'infini) **ne s'applique pas** — chaque jet **retire** une pièce, la suite est
finie et décroissante ; la répétition est le but, pas la faille.

⚠ **L'objet est DÉTRUIT**, il ne tombe pas au sol : le moteur n'a aucune couche
d'objets posés, et en inventer une serait une mécanique entière. Précédent de
l'arme lancée (`consommerArmeLancee()` **supprime** la pièce). La manette
confirme avant d'envoyer — seul geste du jeu qui détruit de la valeur sans rien
rendre. → arbitrage `docs/regles/equipement-et-armurerie.md`

**Quantité.** Quand `quantite > 1`, un palier de saisie numérique s'ouvre (`min`
1, `max` = la pile). Corps : `{option_id: "jeter", parametres: {cle, quantite}}`.
⚠ Le `max` publié est **re-validé** à la résolution contre la ligne en base : un
champ numérique est la plus facile des whitelists à contourner. Une `quantite`
absente vaut 1.

**`echanger` — la SÉANCE du canon**, doc 01 §7 : « transférer armes/armures
**entre les deux inventaires**, dans la limite des **capacités respectives** ».
Bidirectionnel et multiple, pour **une** action. L'option porte
`parametres.allies[]`, un par héros **orthogonalement adjacent** (Manhattan = 1)
et **debout** ; chaque entrée porte `cle` (`"heros:{id}"`), `nom`, les deux sacs
(`mon_sac[]`, `son_sac[]` — par ligne : `inventaire_id`, `nom`, `quantite`,
`encombrant`) et les deux capacités (`ma_capacite`, `sa_capacite` :
`{occupation, max}`).

Corps : `{option_id: "echanger", parametres: {cle, transferts: [{inventaire_id,
vers_personnage_id, quantite}]}}`. ⚠ **La capacité se juge sur l'ÉTAT FINAL**, et
c'est la raison d'être de la séance : deux sacs pleins qui **échangent** deux
armures est légal au canon, alors qu'aucun ordre d'application ne passerait un
contrôle pièce par pièce. Le serveur calcule, pour chacun des deux héros,
`final = occupation − encombrants sortants + encombrants entrants`, refuse en 422
**en nommant le sac qui déborde**, et n'applique rien — la séance est atomique,
une seule transaction.

⚠ **Le seuil n'est PAS `final ≤ capacité`, mais `final ≤ capacité` OU
`final ≤ occupation de départ`.** Un sac peut être **légitimement en
dépassement** — « un butin de quête passe outre la capacité », dit
`DonObjet`, « et donner est justement la façon de régulariser ». Avec le seuil
naïf, le héros au sac débordant serait le seul à ne **jamais** pouvoir s'en
servir pour se délester : refusé pour un état qu'il vient précisément
d'améliorer. La règle exacte est donc « on ne finit pas au-dessus de la
capacité, **ou** on ne s'est pas aggravé ». Le receveur ordinaire reste vérifié
strictement, puisque recevoir augmente son occupation.

⚠ **Chaque `inventaire_id` est re-validé** comme appartenant à l'un des deux
héros, non **équipé**, et `vers_personnage_id` comme étant l'autre : la liste
publiée est la whitelist, et un transfert est un triplet qu'un client peut
inventer entièrement.

⚠ **Aucun accord n'est demandé à l'allié** — le canon dit « échanger avec un
joueur adjacent » et la table le fait de vive voix. Choix assumé : on peut vider
le sac d'un allié sans le lui demander.

⚠ **`encombrant` est publié PAR PIÈCE** pour que la séance affiche un total qui
bouge en direct sans re-dériver de règle serveur : le client **additionne des
entiers**, il ne juge jamais « un consommable ne compte pas ». L'aperçu peut se
tromper ; le refus du serveur fait foi.

Règles communes : une pièce **équipée** ne part pas (la ranger d'abord — ses dés
doivent être révoqués proprement) ; l'option n'est **pas émise** sans entrée
jouable (`echanger` sans allié adjacent, `jeter` sur un sac vide).

⚠ Les deux gestes sont **annoncés** : journal `action` **et** ligne dans
`.combat.journal`. Le fil de combat était muet sur `equiper`/`desequiper`, qui
retombaient sur son `default` — corrigé en même temps, même règle.

`achats[].personnage_id` est accepté mais **jamais envoyé par la manette**, qui
n'expose aucun sélecteur de destinataire : **chacun achète pour soi**, et corrige
après coup par un don au hub (`POST /dons`) si la pièce échoit au mauvais héros.
Le défaut est donc le premier héros du joueur. Ce n'est pas une fonctionnalité
inachevée mais un choix de flux.

L'étal (`EtatMarche.inventaire[]`) porte `tag_equipement` par pièce, et `/moi`
expose `equipement.maitrises` (les tags du héros) — rendus par **le service qui
fait aussi le contrôle**, pour qu'un badge ne puisse pas annoncer autre chose que
ce que le moteur appliquera. La manette en déduit un badge **« Non maîtrisé »**,
purement informatif : **l'achat n'est jamais bloqué**, puisque la bourse est
commune et que le don entre héros existe — acheter une pièce pour un coéquipier
est légitime. `maitrises` absent (ancien client) → aucun badge, plutôt qu'un badge
faux sur tout l'étal.

**Emplacements équipés** : cinq (`App\Partie\Equipement::SLOTS`) —
`arme_principale`, `arme_secondaire` (bouclier), `casque`, `armure`, `talisman`.
Casque, armure de corps et bouclier se **cumulent** jusqu'aux 6 dés de défense du
plateau (LR p. 7) ; le talisman porte les bijoux d'artefact, qui relèvent les PV
maximum au lieu de donner des dés.

**Maîtrises d'équipement** (doc 01 §7) : chaque arme/armure porte un
`tag_equipement`, chaque classe un `tags_equipement` de base
(`classes_heros`), et les nœuds `{mecanique: acces_equipement, tags: […]}` en
ajoutent. Les tags traduisent, une pour une, les restrictions de classe écrites
sur les cartes (`reference/16_armurerie.md` §2.2) : barbare/nain/elfe prennent
tout sauf le lourd (nœud *Maîtrise lourde*) ; le **magicien** est limité aux
armes légères et d'érudit, et ne porte aucune armure ordinaire — ses nœuds *Cuir
d'apprenti* et *Escrime de fortune* levant chaque limite —, mais il est le
**seul** à pouvoir porter brassards et cape (`armure_magicien`). Quatre tags
`talisman_*` réservent de même un bijou d'artefact à chaque classe.
`deux_mains` reste **orthogonal** au tag (il interdit le bouclier, rien d'autre). Le message d'erreur **nomme le nœud** à prendre quand il en existe
un dans l'arbre de la classe, et dit « hors de portée » sinon. Classe sans tags
déclarés → **aucune restriction** (échec ouvert). La vérification n'a lieu qu'à
l'équipement : une pièce déjà portée n'est jamais retirée rétroactivement.

### La Forge du Nain devient ATTEIGNABLE (2026-09-18)

`GET /api/forge` et `POST /api/groupes/{identifiant}/forge` existaient, testés et
verts, **depuis des mois — et aucun écran ne les appelait**. Le seul « forge »
de `resources/js` était le verbe, dans une phrase de l'écran narrateur. Une
règle du canon (doc 01 §Forge) entièrement implémentée et **injouable** : ni
clé décorative ni lecteur manquant cette fois, mais l'écran.

⚠ **Et les améliorations déjà posées n'étaient publiées nulle part** : un objet
forgé ne se distinguait d'un objet ordinaire sur aucun écran. Le projet
protégeait pourtant soigneusement cette donnée — la séance d'échange déplace la
ligne d'inventaire plutôt que de la recréer *précisément* pour ne pas perdre les
`ameliorations`, et un test l'épingle — alors que personne ne pouvait en créer
une seule en jouant.

`/moi` porte désormais, sur chaque ligne d'inventaire arme/armure (portée **et**
au sac) :

| Champ | Contenu | Pourquoi côté serveur |
|---|---|---|
| `ameliorations` | `[{nom, avantages}]`, traduites par `MotsClesEquipement::avantages()` | un **fait sur l'objet**, visible par tous — même un joueur sans nain voit qu'une pièce est forgée |
| `forgeable` | la **DÉCISION** : ce joueur peut-il forger CETTE pièce MAINTENANT | hub, forgeron actif lui appartenant, rareté ≠ `unique`, pas déjà améliorée — quatre ingrédients qu'un client recombinerait de travers |
| `forge_catalogue` | `[{id, nom, prix, avantages}]` | **la liste blanche exacte** que `POST /forge` acceptera, même filtre `Forge::ameliorationsApplicables()` |
| `charges` (2026-09-25) | `{restantes, max}` pour un objet à charges, `null` sinon ; et la ligne de charges d'`avantages` dit « 2 utilisations restantes sur 4 » | le restant de **CET exemplaire** (`MoteurCharges::restantes()`, `null` en base = neuf). Le sac affichait le chiffre du CATALOGUE — l'arc de Sylvan disait « 4 » avec 2 flèches. Le fil ajoute « N flèches restantes à X » après chaque tir de Vindication (`fleches_restantes`, publié depuis toujours, jamais lu) |

⚠ `App\Partie\Forge` est le **point de passage unique** entre le 422 réel
d'`appliquer()` et la décision publiée : un test les confronte **dans les deux
sens**, parce qu'un bouton offert sur une pièce refusée est le même défaut qu'un
bouton manquant sur une pièce forgeable.

⚠ **Le prix n'entre PAS dans `forgeable`**, et c'est délibéré : l'or commun bouge
à chaque tick, et par amélioration choisie. Le front grise une option trop chère
en comparant deux nombres **déjà publiés** (`EtatGroupe.or` et le `prix` de
l'entrée) — une arithmétique, pas une règle re-dérivée ; exactement ce que
`RecrutementHub` fait déjà pour les mercenaires.

⚠ `POST /forge` diffuse `EtatGroupeDiffuse` comme `/dons` et `/mercenaires` :
sans cela, ni la bourse commune ni la pièce forgée d'un compagnon ne se
mettaient à jour en direct sur son propre écran.

⚠ **Non couvert, et nommé** : l'API autorise le Nain à forger l'équipement d'un
**autre** héros, mais aucun écran ne le propose — `SacTab` ne montre que le sac
du joueur connecté. L'affichage de l'amélioration posée, lui, est bien visible
dans ce cas. Un sélecteur de compagnon reste à faire.

**Don d'objets** (`POST /dons`) — répartition du butin au hub. Autorisation
**asymétrique** : on donne depuis SES héros, vers **n'importe quel héros actif**
du groupe (même logique que la Forge, où le Nain travaille l'équipement de ses
compagnons). Le receveur ne confirme rien, mais sa capacité de sac est vérifiée ;
le **donneur** peut être en dépassement — se délester est justement la façon de
régulariser un sac saturé par un butin de quête. Les consommables se transfèrent
par pile et **fusionnent** chez le receveur ; tout le reste **change de
propriétaire sans changer de ligne d'inventaire**, ce qui préserve les
améliorations de Forge de l'exemplaire. Un artefact `unique` circule aussi : il
appartient au groupe, et donner n'est pas vendre. Réponse :
`{don: {objet, quantite, vers: {personnage_id, nom}}}` ; journal `systeme`
`objet_donne` ; broadcast `.groupe.etat` (la manette du receveur re-`GET /moi`).

## Sorts des héros (doc 02 — tout par les menus)

**Connaissance par éléments** (connaître un élément = ses 3 sorts, pivot
`personnage_sorts.disponible`) — parité HeroQuest de base à la création : le
**Magicien** choisit **3 éléments** (9 sorts ; `POST joueurs`/`POST personnages`
acceptent `elements: ["feu","eau","terre"]` — défaut feu+eau+terre), l'**Elfe**
**1 élément** (3 sorts ; défaut eau). La taille de `elements` est validée selon
la classe (Magicien 3, Elfe 1, autres 0). Barbare/Nain : parchemins seulement.
Éléments SUPPLÉMENTAIRES via l'arbre (même mécanique `element` sur
`POST competences`) : *Écoles* (répétable) du Magicien, *Première magie* /
*Second élément* de l'Elfe.

**Récupération (S5/S6)** : chaque sort est lançable **1×/quête** ; tout
redevient disponible au démarrage d'une quête ; aucun repos en cours de quête.
*Concentration* (nœud Magicien) : option de menu « Se concentrer » si le nœud
est acquis, qu'un sort est épuisé et qu'elle n'a pas servi cette quête —
sacrifie le tour, récupère UN sort au choix (`parametres: {sort_id}`).

**Résolution (moteur, jamais l'IA)** — options de menu `type: "sort"`
(`parametres: {sort_id, cible?}`) proposées au héros en quête :
- `degats` (Boule de Feu 2 dés, Trait de Feu 1 dé, Génie 4 dés — départ
  playtest) : dés de combat vs défense de la cible (règles de combat de base) ;
  **tir ami possible (S3)** : les héros figurent dans les cibles légales.
- `mental` (Sommeil, Tempête) : jet de Mind de la cible, binaire (S2), Mind 0
  immunisé ; effet = condition (`endormi` : hors combat jusqu'à attaque ;
  `tempete` : n'attaque pas à son prochain tour).
- `utilitaire` : Soin du Corps / Eau de Guérison +4 PV Body (plafonné au max) ;
  Courage +2 dés d'attaque (prochaine attaque) ; Peau de Pierre +2 dés de
  défense (fin du combat) ; Voile de Brume inattaquable (prochain tour) ;
  Vent Véloce déplacement ×2 (ce tour) ; Traverser la Pierre franchit un mur
  (vaut le déplacement). Les effets temporaires vivent en
  `personnage_conditions` (durée en tours) et sont appliqués par le moteur.

**Parchemins (S1/S4)** : option `type: "parchemin"` (`parametres:
{inventaire_id, cible?}`) si le héros en a au sac — lanceur (magicien/elfe) :
réussite auto ; non-lanceur : jet de Mind à la difficulté du sort (1-3) ;
**consommé dans tous les cas**, échec = gaspillé.

`GET /api/moi` : chaque personnage expose `sorts: [{sort_id, nom, element,
type, disponible}]` (et l'onglet Sorts de la manette s'en nourrit ; rafraîchi
aussi via `.groupe.etat` → re-GET).

## Clôture de campagne (doc 05 §6)

Fenêtre de clôture (cache, comme le marché) ouverte : automatiquement à la
**victoire du boss final** (broadcast `.cloture.ouverte`), ou par un membre au
**hub**. À l'ouverture manuelle, l'`issue` est **dérivée de l'état de la
campagne** (jamais du seul corps de requête, pour qu'une fin gagnée/perdue ne
soit jamais mal étiquetée) : `victoire` si le boss final est vaincu, `echec` si
la dernière quête est **échouée** (TPK doc 05 §6 : l'or à partager est alors
`quetes.or_initial` de la quête échouée, plafonné à l'or restant), sinon
`abandon` (fin décidée saine, pot complet). Le drapeau `abandon: true` reste
réservé à une campagne réellement échouée (422 sinon). 422 si une quête est en cours.

| Méthode | Route | Corps | Effet |
|---|---|---|---|
| POST | /groupes/{identifiant}/cloture | {abandon?: bool} | ouvre la fenêtre |
| GET | /groupes/{identifiant}/cloture | — | EtatCloture, ou `{cloture: null}` (200) si aucune fenêtre ouverte (même sonde de rattrapage que le marché, pas de 404) |
| PUT | /groupes/{identifiant}/cloture/repartition | {inventaire_id, personnage_id} | réassigne un équipement (annule les confirmations) |
| POST | /groupes/{identifiant}/cloture/confirmation | — | confirme ; tous confirmés → finalisation |
| DELETE | /groupes/{identifiant}/cloture | — | annule la fenêtre (rien appliqué) |
| POST | /groupes/{identifiant}/cloture/urgence | — | **arrêt d'urgence** : finalise IMMÉDIATEMENT, sans confirmation d'aucun joueur — **membre OU table**, à tout moment (y compris en pleine quête). Menu d'urgence du narrateur, écran de table. Issue `abandon`, or_a_partager = or courant, aucune réassignation d'équipement (chacun garde ce qu'il porte) — sinon même finalisation que ci-dessous (job, historique, purge, `.cloture.terminee`) |

**EtatCloture** : `{issue: "victoire|echec|abandon", or_a_partager,
parts: [{personnage_id, nom, joueur_id, montant}] (parts égales, reste réparti
unité par unité aux premiers), equipements: [{inventaire_id, nom, categorie,
rarete, personnage_id}], confirmations: [{joueur_id, pseudo, confirme}]}`.

**Finalisation** (job, atomique côté données) :
1. réassignations d'équipement appliquées ; 2. or commun réparti vers
`personnages.or` ; 3. **résumé de campagne généré AVANT la purge** (skill MJ
`ResumeCampagne` depuis le journal ; repli sans LLM : résumé factuel — quêtes,
boss, or, issue) ; 4. une ligne `personnage_historique` par héros (groupe_nom,
theme, resume, issue, niveau_atteint, termine_le) ; 5. détachement
(`groupe_actif_id` null), **personnages remis à plein** (pv_body/pv_mind au
max, sorts tous `disponible`, conditions/buffs effacés — victoire, échec ou
abandon referment l'ardoise) puis **purge complète** : quetes, cartes,
instances_monstres, etat_personnage_quete, evenements, snapshots, caches de
phase, le groupe lui-même, et les points **Qdrant** du group_id (best-effort
si Qdrant est joignable). Broadcast final `.cloture.terminee`
({resumes: [{personnage_id, resume}]}) — les clients retournent à l'accueil.

**Groupe vide** (doc 05 §6) : quand le dernier joueur quitte (départ libre ou
retrait voté), même purge automatique, sans cérémonie ni résumé.

Broadcasts canal `groupe.{identifiant}` : `.cloture.ouverte` (EtatCloture),
`.cloture.maj` (EtatCloture), `.cloture.terminee`.

## Snapshots & reprise (doc 12 §4, doc 05 §6 TPK)

Le moteur **snapshotte automatiquement** l'état vivant dans la table
`snapshots` (`groupe_id, sequence_evenement, etat JSON`) : au **démarrage de
chaque quête** (étiquette `debut_quete`) et à chaque **nouveau tour** (étiquette
`nouveau_tour`, après la phase des monstres). L'état sérialisé contient tout ce
qu'il faut pour rejouer : groupe (or, phase, quete_courante_id), quête, carte
(grille + état des pièges), instances de monstres (PV, positions, états,
conditions), etat_personnage_quete, et pour chaque héros actif : PV, sorts
(disponible), conditions, inventaire (lignes + quantités). Rétention : les
snapshots d'une quête sont **purgés à la fin de la quête** (seul celui de
`debut_quete` de la quête courante et le dernier `nouveau_tour` sont
conservés pendant la quête — départ playtest).

| Méthode | Route | Corps | Effet |
|---|---|---|---|
| GET | /groupes/{identifiant}/snapshots | — | liste : [{id, etiquette, sequence_evenement, created_at}] |
| POST | /groupes/{identifiant}/reprise | {snapshot_id?} | restaure l'état (défaut : snapshot `debut_quete` de la dernière quête échouée — le « recharger » après TPK) — **membre OU table** (le bouton « Recharger la quête » est sur l'écran de table, même règle que la clôture) |
| POST | /groupes/{identifiant}/quete/redemarrer | — | **redémarrage volontaire** de la quête EN COURS depuis son snapshot `debut_quete` — **membre OU table**, à tout moment, contrairement à `/reprise` AUCUNE condition d'échec n'est exigée. Menu d'urgence du narrateur, écran de table (« ça va très mal »). 422 si aucune quête en cours ou snapshot introuvable |

**Reprise** : 422 si une quête est en cours ET non échouée (on ne recharge pas
en pleine partie réussie) ; restauration atomique en transaction : l'état
vivant est réécrit depuis le snapshot, la quête repasse `en_cours`, le journal
reçoit un événement `systeme` `{action: "reprise", snapshot_id}` (le journal
n'est JAMAIS tronqué — source de vérité, doc 07), broadcast `.groupe.etat` +
re-dispatch narration/menus. Le TPK (doc 03/05) devient donc : quête `echouee`
→ le groupe **vote ou choisit** : `POST reprise` (recharger) ou
`POST cloture {abandon: true}` (abandonner).

**Redémarrage volontaire** (`/quete/redemarrer`) : même restauration
atomique que la reprise (même méthode moteur, `App\Partie\Sauvegarde::restaurer`),
journal `{action: "quete_redemarree", snapshot_id}` pour le distinguer d'une
reprise après TPK — mais **sans la garde de phase** de `/reprise` : utilisable
au milieu d'une quête saine, pas seulement après un échec. C'est le bouton
« Recommencer la quête actuelle » du menu d'urgence du narrateur.

## Sorts de Dread & capacités des boss (doc 09 §4 — tout dans le tour scripté)

Aucun nouvel endpoint : le **comportement scripté (C2)** des sous-boss/boss
s'enrichit. Priorité d'un lanceur à son tour : sort de Dread s'il reste des
**usages** (colonnes `instances_monstres.usages_dread` /
`invocation_dread_utilisee` / `fuite_dread_utilisee`, réarmées au démarrage de
quête — départ playtest : sous-boss 2, boss 3) et qu'une cible vaut le coup,
sinon capacité, sinon déplacement+attaque normal. Le moteur décide et résout ;
l'IA ne fait que narrer (les payloads de narration portent le détail).

Quatre règles cadrent le choix, et elles sont symétriques de celles des héros :

- **Ligne de vue obligatoire** pour tout sort à cible unique, figures
  interposées bloquantes — même filtre que les sorts de héros (LR p. 14). Un
  héros dans une salle jamais ouverte n'est pas une cible. ⚠ Un sort de **zone**
  ne la demande pas : « all heroes in the same room » n'épargne pas celui qui se
  tient derrière une armoire. La Fuite fait aussi exception — elle s'éloigne de
  TOUS les héros debout, visibles ou non.
- **Le boss final tourne dans un pool** : `gabarits_quete.structure.rencontre_finale.archetypes`
  est une **liste** de clés d'archétype, parcourue par ROTATION sur
  `(id du groupe + position d'arc)` — pas un tirage : un boss est un placement,
  il doit rester le même après un « Recommencer la quête » ou une reprise. Le
  singulier `archetype` reste lu. Sans pool exploitable, repli historique sur le
  leader de coût du palier. ⚠ Le champ existait depuis la 3.8 et n'avait jamais
  été rempli : le Seigneur fermait toutes les quêtes et aucun lanceur nommé
  n'apparaissait jamais.
- **`sorts_dread.palier` est un tier MINIMUM**, et il en existe **trois** :
  `base` · `sous_boss` · `boss`. Le palier `base` est né des extensions, qui
  donnent la magie à des créatures ordinaires (Cultiste du Dread, Spectre,
  Tisseur putride — doc 18). Un monstre de tier `base` qui porte un répertoire
  reçoit **1 usage** par rencontre.
- **Le choix se fait par MÉCANIQUE, jamais par nom de sort.** `sortUtilisable()`
  filtre (zone vide, couloir interdit, personne à soigner, verrou consommé) puis
  `scoreSort()` note par famille — une zone qui prend plusieurs héros passe
  devant tout, puis les dégâts, le contrôle, les renforts, le soin, et la fuite
  en dernier. À égalité, l'ORDRE DU RÉPERTOIRE tranche.
- **Une zone se choisit sur sa zone réelle**, jamais sur le nombre de héros
  debout : `casesDeZone()` est le seul point de passage du choix ET de la
  résolution.

**Sorts de Dread — 22 sorts, un par CARTE OFFICIELLE** (`dread_spells.pdf`,
29 cartes, doc 09 §4bis). ⚠ Le *Trait de Chaos* a été **supprimé** le
2026-09-04 : il n'existait sur aucune carte. Les sept cartes non portées sont
recensées dans `config/cartes.php` (section `dread`) et exposées par
`GET /api/guide`, chacune avec la mécanique qui lui manque.

| Type | Sorts | Résolution |
|---|---|---|
| `degats` | Boule de Flammes, Tempête de feu, Éclair de Chaos, Morsure de Froid, Canaliser l'Effroi, Tempête de Glace | montant **fixe** réduit par des d6 bruts (`des_rouges`), paliers sur un d6, ou dés de combat |
| `controle` | Sommeil, Frayeur, Tourmente, Commandement, Nuée d'Effroi, Choc Mental, Feux de l'Effroi, Étreinte des Ronces | pose une condition ; sortie par **rupture**, pas par compteur |
| `invocation` | Invocation de morts-vivants / d'orques / de loups / de spectres, Réanimation | composition tirée sur un **d6** (`table_d6`) |
| `soin` | Apaisement, Restauration de l'Effroi | rend au plus ce qui a été **perdu** |
| `fuite` | Fuite | case libre la plus éloignée |
| `destruction` | Rouille | **détruit** une pièce de métal portée, définitivement |

⚠ **La résistance a changé de nature.** Aucune carte du paquet n'accorde un jet
de Mind **au lancer** : le sort prend, et c'est sa **poursuite** qui est
contestée. Cinq cartes donnent « 1 d6 par point de Mind, un 6 libère »
(`rupture_6_par_mind`), une sixième « 1 d6, 5 ou 6 » (`rupture_5_6_un_de`,
*Dreadlights* — elle ne parle pas du Mind du tout). La rupture est tentée
**immédiatement**, puis **à l'ouverture de chaque tour**.

⚠ **Payload unifié.** Un sort de Dread rend toujours
`{type: "sort_dread", sort, resultats: [...]}` — une entrée par victime, même
quand il n'y en a qu'une. Les clés par victime (`cible`, `degats`,
`pv_body_apres`, `cible_tombee`, `effet_applique`, `contresort`,
`rupture_immediate`, `absorbe`) vivent **dans `resultats[]`**, plus au niveau
racine : un sort de contrôle peut désormais prendre toute une salle, et deux
formes de payload pour la même famille finissent par diverger. S'y ajoutent
`cases_affectees` (zones uniquement), `monstres_touches` (tir ami du MJ),
`invoques`+`de`, `releves`, `soin`+`sur_soi`, `vers` selon la famille.

⚠ **Deux nouveaux payloads d'ouverture de tour**, remontés dans
`resultat.tour_monstres.actions` et journalisés :
`{type: "rupture_sort_dread", personnage_id, nom, condition, rompu, faces, seuil}`
et `{type: "tour_perdu", personnage_id, nom, cause}`. Un jet de dés que
personne ne voit n'a pas eu lieu pour la table.

⚠ **La Rouille est le seul sort dont l'effet SURVIT À LA QUÊTE.** « Not
effective against artifacts » : `effet.detruit` déclare la matière
(`objets.metallique`), les emplacements visés (mains + casque) et l'exemption
des artefacts. La pièce quitte l'inventaire et `Equipement::recalculerCombat()`
remet `des_attaque`/`des_defense` à jour — ce sont des **colonnes**, pas un
calcul à la volée. Payload : `resultats[0]` porte `objet_detruit`,
`emplacement`, `des_attaque_apres`, `des_defense_apres`.
⚠ `objets.metallique` a changé de portée avec elle : la colonne marque
désormais **toute pièce dont un lecteur peut lire la matière** (épées, haches,
brassards compris), et les deux lecteurs de classe sont **bornés à la catégorie
`armure`** — sans quoi le Druide et le Rogue auraient perdu toute arme de métal.

⚠ **Nouveau type d'option de menu** : `liberer_entraves` (*Étreinte des
Ronces*) — coûte l'**action**, porte `parametres.cibles` (soi + voisins
entravés) comme `soin_allie`, et le résolveur revalide contre cette liste
blanche. Payload : `{type: "liberer_entraves", cible, sur_soi}`.

⚠ **Cible adjacente pour les potions** (René, 2026-09-11, en jouant — « il
faudrait pouvoir cibler le joueur actuel ou un joueur adjacent »). Nouveau mot
`heros_adjacent` dans le vocabulaire `cible` de `MotsClesSort`/
`MotsClesEquipement::CIBLE` — le porteur OU un héros ORTHOGONALEMENT adjacent
(`Grille::sontAdjacentes()`, diagonales exclues, même convention que relever un
allié tombé). Vingt-et-une potions le portent dans `effet.cible` (toutes sauf
l'Élixir de Vie et la Poudre d'Invisibilité, déjà `heros`/`activable` et
inchangées) ; l'entrée `utiliser_objet` d'une potion ainsi ouverte publie donc
désormais `parametres.objets[].cibles` — **rien de neuf côté forme**, c'est le
même patron que les artefacts activables (`parametres.cibles` par entrée, 3ᵉ
niveau de la manette qui ne s'ouvre que si l'entrée en porte). Conséquence
assumée : une potion `heros_adjacent` demande désormais TOUJOURS un `cible_id`
— même pour se la boire soi-même quand personne d'autre n'est à contact —,
exactement comme la Poudre d'Invisibilité le demandait déjà.
⚠ **La restriction de classe d'une potion suit désormais qui BOIT, jamais qui
la sort du sac** : `MoteurPotions::boire()` prend un 4ᵉ paramètre `$cible`
(le buveur, `$personnage` par défaut) et vérifie `estAccessible()` contre LUI.
Un Barbare peut tendre sa Potion de rage guerrière (réservée au Barbare) à un
magicien adjacent — refusé, c'est le magicien qui boirait — et à l'inverse un
magicien peut PORTER la même potion (trouvée en fouille) et la tendre à un
Barbare adjacent — acceptée, c'est le Barbare qui boit. `ciblesObjet()` filtre
déjà la liste blanche par cette même règle, donc le cas refusé n'apparaît même
pas dans le menu. Payload `resultat.potion` gagne `porteur_id` (nullable,
présent seulement quand potion et buveur diffèrent) pour que la table/le
journal distinguent « X boit » de « X tend sa potion à Y ».

**Capacités** (`monstres.capacites` JSON) : Invocation (comme le sort, sbires
de base — payload `{type: "capacite_dread", capacite: "invocation", invoques}` ;
elle ne coûte **aucun** usage de Dread, partage le verrou 1×/rencontre du sort
et ne se déclenche pas au contact d'un héros) ; Frappe de zone (l'attaque touche TOUS les héros adjacents, un jet
par cible) ; Régénération (+1 PV Body au début de son tour, plafonné) ;
Résistance magique (+2 dés de défense contre les sorts de dégâts des héros) ;
Charge (si hors contact et joignable : déplacement + attaque à +1 dé).

**EtatGroupe** : `entites` (héros ET monstres) gagnent
`conditions: [{nom, duree}]` — la table et la manette affichent les états ;
un héros `endormi`/`commande` voit son menu remplacé par un message d'état.
⚠ Quatre conditions de plus depuis les cartes officielles : *Esprit brisé*
(Choc Mental — ne bouge ni ne frappe, défend à **1 dé**), *Désigné* (Feux de
l'Effroi — **les monstres** gagnent 1 dé contre lui), *Immobilisé* (Étreinte des
Ronces — se libère par une action), et *Apeuré*, qui **plafonne** désormais
l'attaque à 1 dé au lieu de la diminuer de 1.

⚠ **`entites[].attaque_supplementaire`** (héros seulement, 2026-09-11) — signalé
en partie réelle : « j'ai pris une potion d'héroïsme après avoir attaqué mais
l'action d'attaque était grisée ». La veille, `menu.situation` avait été corrigé
pour ANNONCER le bonus ; le bouton, lui, restait grisé pour de bon, faute d'une
donnée pour le miroir client — deux moitiés d'une seule réparation. `EtatGroupe`
publie donc ce drapeau à côté de `a_agi`/`a_deplace`/`a_joue`, et
`ManetteView.creneauxDuTour` le relaie dans `{a_joue, a_deplace, a_agi,
attaque_supplementaire}` passé à `ActionTab`. `ActionTab.creneauConsomme()`
cesse de griser une option `type: "attaque"` quand il vaut vrai — miroir exact
de la garde serveur `$bonusHeroisme` (`ResolveurTour::resoudreOption()`) :
« le menu n'offre jamais ce que le résolveur refusera » vaut aussi dans
l'autre sens, un bouton grisé ne doit jamais refuser ce que le résolveur
accepterait. ⚠ **Divergence signalée, non corrigée ici** : la Réserve
arcanique / Baguette de Rappel (second sort du tour, `etat.bonus_sort_utilise`)
souffre du même défaut — ni publiée par `EtatGroupe`, ni lue par
`creneauConsomme()`, qui grise donc « Lancer un sort » après un premier sort
alors que le résolveur accepterait le second.

⚠ **`entites[].franchit_figures`** (héros seulement, 2026-09-11) — signalé en
partie réelle : « la mobilité de combat du Rogue ne permet pas de se déplacer
à travers les ennemis ». Le moteur avait raison depuis toujours
(`ResolveurTour::resoudreDeplacer()` lève les figures pour un héros qui porte
le talent `franchit_figures` ou le buff *Voile de Brume*), mais
`DeplacementSheet.vue` refait son PROPRE parcours de surbrillance et traitait
tout monstre comme un mur inconditionnellement — un client ne peut pas deviner
qu'un héros précis porte ce talent ou ce buff, donc il ne pouvait matériellement
pas savoir qu'il devait cesser de bloquer. `EtatGroupe` publie la DÉCISION,
calculée par `MoteurSorts::mobiliteCombatDisponible()` — désormais le seul
point de passage de cette expression, relu par `ResolveurTour` et par
`MenuMoteur::peutSeDeplacer()` (qui en avait besoin pour la même raison : ne
pas retirer « Se déplacer » à un héros qui franchirait un monstre bloquant ses
4 voisins). `DeplacementSheet.vue` traite un monstre comme une case ALLIÉE
(traversable, jamais une destination) quand `franchit_figures` vaut vrai pour
CE héros, au lieu de toujours l'exclure du parcours.

## Modèle de session : Narrateur (table) vs Joueur (compte)

Deux rôles d'entrée distincts (doc 11 §7).

### Narrateur / table — sans compte, par code
La table « tient » la partie en ligne. Pas de compte : on saisit le **code du
groupe**.

| Méthode | Route | Corps | Effet |
|---|---|---|---|
| POST | /api/table | {code} | ouvre une SESSION DE TABLE (cookie) pour ce groupe ; 404 si code inconnu. Réponse : {groupe: EtatGroupe} |
| POST | /api/table/ping | — | heartbeat : rafraîchit « table active » (cache `table:active:{groupe_id}`, TTL 30 s). À envoyer toutes les ~15 s |
| POST | /api/table/quitter | — | ferme la session de table |

**Narrateur actif** = `Cache::has('table:active:{groupe_id}')` (heartbeat frais).
C'est la condition pour qu'une partie soit jouable/reprenable.

### Joueur — compte + roster
| Méthode | Route | Corps | Effet |
|---|---|---|---|
| POST | /api/inscription | {pseudo, identifiant} | crée le compte et connecte ; 422 si identifiant pris (sans mot de passe) |
| POST | /api/connexion | {identifiant} | (existant) — nom seul |
| GET | /api/moi | — | {joueur, personnages: [...]} — chaque perso : `disponible` (pas de groupe), et si engagé `groupe: {identifiant, nom, phase, narrateur_actif}` ; `attribut_body/attribut_mind/des_attaque/des_defense` (fiche perso, invariants hors quête) ; `equipement: {armes: [{inventaire_id, nom, emplacement, bouclier}…], casque, armure, talisman: {inventaire_id, nom}\|null, sac: [{inventaire_id, nom, categorie, rarete, quantite, equipable}], capacite, occupation, maitrises: [tag…]}` (chaque pièce équipée porte son `inventaire_id` pour déséquiper ; `equipable` = objet du sac montable dans un slot — voir §Équipement) |
| POST | /api/personnages | {nom, classe, elements?} | crée un perso du roster (libre) |
| POST | /api/groupes | {nom, theme, longueur, ton?, personnage_id} | crée un groupe DEPUIS un perso LIBRE du joueur (le perso le rejoint comme fondateur) ; 422 si perso déjà engagé |
| POST | /api/groupes/{identifiant}/joueurs | {personnage_id} | rejoint par code avec un perso libre (existant, + accepte {nom,classe}) |

Le `personnages[].groupe.narrateur_actif` (bool) pilote le bouton « Reprendre »
côté joueur : on ne peut reprendre que si une table est active sur le groupe.

### Statut « prêt » et démarrage de quête (au hub)
Une nouvelle quête démarre quand **TOUS les joueurs membres sont prêts** ET
qu'un **narrateur est actif** (remplace le démarrage manuel par la table).

| Méthode | Route | Corps | Effet |
|---|---|---|---|
| POST | /api/groupes/{identifiant}/pret | {personnage_id, pret} | (dé)marque un perso prêt (cache `partie:pret:{groupe_id}`) ; si tous les membres actifs sont prêts ET narrateur actif → **démarre la quête** (DemarreurQuete) et réinitialise les statuts. ⚠ **422 si CE joueur a un panier de marché non vide et non confirmé** — l'application du marché est atomique, donc rien n'est débité, mais ses achats se perdraient au départ (constaté en partie réelle, 2026-08-17). On refuse à ce joueur seul, jamais au groupe : bloquer le départ pour tous referait le piège du vote de sortie, où un joueur distrait enferme les autres. Le démarrage de quête referme la phase de marché **explicitement** (`marche_ferme_par_quete` au journal + `.marche.finalise`), au lieu de la laisser expirer en silence. |
| POST | /api/groupes/{identifiant}/quetes | — | démarrage MANUEL (bouton « Lancer la quête » de la table) — autorisation **membre OU table** (le narrateur sans compte peut lancer). Sans ça la table prenait un 401 → faux « session expirée » → redirection vers le login joueur |

> Un 401 sur l'écran **narrateur/table** ne renvoie PAS au login joueur : la
> table se rouvre par **code** (`/narrateur`), pas par compte (App.vue).

`EtatGroupe.groupe` gagne `narrateur_actif` (bool) et, au hub, `prets:
[{personnage_id, pret}]` pour l'affichage. Broadcast `.prets.maj` ({prets})
sur changement. (Le `POST /quetes` direct reste pour les tests/outillage.)

### Autorisations
Les routes de LECTURE/jeu d'un groupe (`/etat`, `/snapshots`, broadcasting
des canaux `groupe.{identifiant}`) acceptent **soit** un joueur membre (au moins
un perso actif), **soit** la session de table de ce groupe. Les actions de
joueur (choix, panier, vote, prêt…) exigent un joueur membre.

## Paramètres globaux (réglages serveur — écran de Narrateur/table)

Panneau **Réglages**, ouvert depuis la table (`/table/:groupe`) et depuis
l'écran de saisie du code (`/narrateur`, **avant même l'ouverture d'une
table**) : pilote en direct ce qui, avant, exigeait d'éditer `.env` puis de
recréer les conteneurs (fournisseur/modèle IA), ou n'était pas exposé du tout
(RAG, synthèse vocale IA, illustrations, voix du narrateur, équilibrage des
rencontres). Portée **GLOBALE** (tout le serveur, PAS par groupe/table) —
même comportement que `LLM_PROVIDER` aujourd'hui, rendu éditable en direct.

| Méthode | Route | Corps | Réponse |
|---|---|---|---|
| GET | /api/parametres | — | **PUBLIC** — **ParametresIA** (voir ci-dessous). Accessible sans compte ni session de table, depuis /narrateur (avant même l'ouverture d'une table) et /table/:groupe. |
| PUT | /api/parametres | ParametresIA éditable, mise à jour PARTIELLE (voir plus bas) | **PUBLIC** — **ParametresIA** à jour |
| POST | /api/parametres/test | {fournisseur: "anthropic"\|"gemini", modele?} | **PUBLIC** — test de connectivité RÉEL du fournisseur : un mini-appel LLM synchrone (timeout court) avec le `modele` fourni (celui du formulaire, même non enregistré — c'est ce qu'on veut valider) sinon la chaîne surcharge → défaut. Réponse 200 : `{ok: true, fournisseur, modele, duree_ms, extrait}` ou `{ok: false, fournisseur, modele, duree_ms, erreur}`. 422 si fournisseur inconnu ou sans clé serveur. Vise le fournisseur PRÉCIS (jamais le repli croisé) et ne touche PAS `statut_ia` (qui reflète les appels du JEU, pas les tests manuels). |
| POST | /api/parametres/test-voix | {voix?} | **PUBLIC** — écoute d'une voix de NARRATEUR Gemini : synthétise une phrase d'exemple FIXE avec la `voix` demandée (celle du formulaire, même non enregistrée), sinon surcharge → défaut config. **Cache par voix** (`public/audio/narration/test/{voix}.wav`, gitignoré) : réécouter la même voix ne consomme pas le quota TTS. Réponse : `{ok: true, voix, url}` (le panneau joue `url`) ou `{ok: false, voix, erreur}` ; 422 si `GEMINI_API_KEY` absente. La voix du NAVIGATEUR se teste côté client (Web Speech local, débit/volume réglés) — aucun endpoint. |

**PUBLIC** : aucune autorisation, ni compte joueur ni session de table —
exactement le même statut que `GET /api/guide`. Nécessaire pour que le bouton
Réglages fonctionne depuis `/narrateur` avant même la saisie d'un code, à un
moment où `session()->get('table_groupe')` est forcément vide. Le modèle de
confiance reste celui d'un LAN entre amis sans mot de passe narrateur (aucune
clé API n'est jamais renvoyée par `GET`, seulement leur présence/absence).

`PUT` accepte une mise à jour **PARTIELLE** : seuls les champs présents dans
le corps sont modifiés (le panneau actuel envoie tout d'un bloc via son
unique bouton « Enregistrer », mais le contrat n'impose pas de corps
complet). Un champ modèle/voix envoyé en chaîne vide remet la surcharge à
`null` (retour au défaut `.env`/`config`). `llm_provider` choisi sans clé API
serveur correspondante → 422.

**ParametresIA** :
```json
{
  "llm_provider": "anthropic",
  "fournisseurs_disponibles": ["anthropic", "gemini"],
  "modele_anthropic": null, "modele_anthropic_defaut": "claude-sonnet-4-6",
  "modele_gemini": null, "modele_gemini_defaut": "gemini-3.1-flash-lite",
  "rag_actif": true,
  "voix_dynamique_active": false,
  "bible_semantique": "voyage",
  "statut_ia": {"etat": "nominal", "fournisseur": "anthropic", "a": "2026-07-23T10:00:00+00:00"},
  "consommation_ia": {
    "tokens_entree": 128430, "tokens_sortie": 41207, "tokens_cache": 0,
    "appels": 312, "appels_retries": 18, "nb_quetes_mesurees": 6,
    "moyenne_par_quete": {"appels": 2.1, "tokens_entree": 21405.0, "tokens_sortie": 6867.8},
    "depuis": "2026-08-18T09:00:00+00:00"
  },
  "images_actif": true,
  "narration_voix": null, "narration_voix_defaut": "Iapetus",
  "narration_voix_options": ["Puck", "Fenrir", "Charon", "Orus", "Iapetus"],
  "rencontres": {"forts_par_quete": 1, "forts_escalade_arc": 2, "seuil_cout_fort": 3, "boss_pv_adaptatif": true, "taille_reference": 4},
  "rencontres_defaut": {"forts_par_quete": 1, "forts_escalade_arc": 2, "seuil_cout_fort": 3, "boss_pv_adaptatif": true, "taille_reference": 4}
}
```

Sépare l'éditable du calculé : `modele_anthropic`/`modele_gemini`/
`narration_voix` portent la **surcharge actuelle** (`null` = suit le défaut),
leurs pendants `_defaut` la valeur `.env`/`config` (placeholders côté
formulaire) ; `llm_provider` est en revanche la **valeur effective**
(surcharge sinon `.env`) ; `rencontres` regroupe les 5 valeurs **effectives**
(`surcharge ?? défaut`), `rencontres_defaut` le même sous-objet en défauts
seuls. `fournisseurs_disponibles` et `bible_semantique` sont calculés depuis
les clés serveur présentes — jamais éditables (voir plus bas).

`statut_ia` (lecture seule) reflète le **repli automatique inter-fournisseurs
à l'exécution** : si l'appel au fournisseur PRINCIPAL échoue vraiment (panne
API, clé révoquée…), une seule retentative avec l'AUTRE fournisseur (s'il a
une clé) avant d'abandonner à l'IA (repli menu/narration générique — le jeu
reste jouable). Distinct de `llm_provider` (qui reste la PRÉFÉRENCE choisie,
pas forcément ce qui a effectivement répondu au dernier appel) :
`{etat: "nominal"|"repli"|"indisponible"|"inconnu", fournisseur?, depuis?,
raison?, a?}` — `inconnu` = aucun appel IA depuis le dernier redémarrage du
cache. Alimenté par tous les jobs IA (conteneur `queue`), lu par la table/le
narrateur (conteneur `app`) : partagé via le cache (`CACHE_STORE=database`).

`consommation_ia` (lecture seule) est la TÉLÉMÉTRIE de consommation LLM
(`App\Agent\TraceurConsommation`, table `consommation_ia`) — absente jusqu'ici,
elle permet de VÉRIFIER dans la durée le gain du lot « récits pré-générés »
(~145 appels/quête → 2) plutôt que de l'espérer. Une ligne en base = UNE
réponse HTTP réellement facturée par un fournisseur (jamais un skill
complet) : les retries (`Skill::MAX_RETRIES`, jusqu'à 3 appels facturés pour
une seule sortie) et le failover croisé (`ClientLLMAvecRepli`, qui rejoue
l'appel COMPLET chez l'AUTRE fournisseur) sont donc comptés — c'est
`appels_retries` (appels dont `tentative` > 1). `tokens_cache` est la lecture
de cache du fournisseur quand il la distingue (`cache_read_input_tokens`
Anthropic, `cachedContentTokenCount` Gemini), 0 sinon. `moyenne_par_quete`
divise les totaux par `nb_quetes_mesurees` (les quêtes ENCORE en base) — un
ratio VIVANT sur les campagnes actuellement ouvertes, pas un historique exact
toutes campagnes confondues : `consommation_ia` (la table) est volontairement
SANS clé étrangère sur `groupe_id` (contrairement à `evenements`/`snapshots`)
et survit donc à la clôture/purge d'une campagne, alors que ses quêtes, elles,
sont purgées avec elle. `depuis` est la date du plus ancien enregistrement
(`null` table vide). Un appel de test (`POST /api/parametres/test`) est
compté avec `groupe_id` NULL, étiqueté `skill: "test_connectivite"`.

Portée et persistance : **IA, illustrations, voix du narrateur et
équilibrage des rencontres** sont persistés en BASE (table `parametres`,
ligne unique — singleton), globaux au serveur, appliqués **au prochain
job/quête** (`ClientLLM`/`Embeddings` sont résolus à CHAQUE job — pas de
redémarrage de conteneur nécessaire). L'**audio** (volume/coupure voix +
musique, débit de la voix, **choix de la voix Web Speech** parmi celles du
navigateur — francophones d'abord, `voiceURI` persisté, repli automatique
sur la première française si la voix choisie disparaît —, et « narration par
la voix du navigateur » — la NARRATION, textes IA comme répliques
pré-enregistrées du même narrateur, est lue par Web Speech au lieu de la
voix Gemini générée ; les barks de monstres gardent leurs fichiers audio)
est une préférence de **l'appareil qui tient la table**, donc volontairement
PAS ici : persistée côté client en `localStorage`, jamais envoyée au serveur. Aucune rediffusion temps réel de
`parametres` : un second narrateur ne verra un changement qu'en rouvrant le
panneau (acceptable, un seul narrateur actif par table à la fois).

Les réglages `rencontres_*` ne s'appliquent qu'à la **prochaine quête
lancée** (`DemarreurQuete::demarrer`) — aucun recalcul rétroactif d'une quête
déjà en cours (les monstres déjà spawnés ne bougent pas). `narration_voix`
n'affecte immédiatement que la narration IA **dynamique** (synthétisée au
vol) ; les répliques scriptées déjà pré-générées
(`public/audio/narration/{cle}/{i}.wav`) gardent l'ancienne voix jusqu'à
relancer manuellement `php artisan narration:generer`. Le fournisseur
d'**embeddings** (Voyage vs repli lexical, `bible_semantique`) reste
volontairement non éditable : les deux ont des dimensions vectorielles
différentes, en changer casserait la collection Qdrant existante.

## Garanties

- **Le moteur fait autorité** : `choix` valide l'option contre le dernier menu
  proposé + l'état ; option illégale → 422.
- **Cible = active ET révélée** : une attaque comme un sort n'aboutit que sur un
  monstre `actif` **et** `revele` (le menu moteur ne propose d'attaque que sur
  un monstre révélé, et `ResolveurTour` revérifie à la résolution). Un monstre
  dormant (salle non découverte) n'est jamais ciblable, même via un menu périmé.
- **Portes fermées par défaut** : les portes inter-salles sont posées `fermee`
  (close, sans verrou) — infranchissables et opaques. Un héros adjacent l'ouvre
  sans clé (option `ouvrir_porte`, `cause: main`) ; ouvrir est une **interaction
  libre** (ne consomme ni le déplacement ni l'action), donc on s'arrête devant la
  porte, on l'ouvre et on **poursuit** son mouvement s'il reste des points.
- **Déplacement fractionné** : le déplacement du tour se dépense en plusieurs
  fois (`deplacement_restant`) ; l'option « Continuer à se déplacer » porte la
  portée restante. Toute **action hors mouvement forfait** le déplacement restant.
  Sauter une fosse coûte 2 points et laisse continuer.
- **Sort offensif = ligne de vue** : un sort `degats`/`mental` ne vise qu'une
  cible VISIBLE du lanceur — un mur, une porte fermée **ou une figure interposée**
  (allié comme ennemi) coupe la vue. Le menu ne liste que les cibles en vue et
  `ResolveurTour` revérifie (422 sinon). Un monstre vaincu quitte le plateau
  partagé (`entites`) — plus affiché ni bloquant côté manette/table.
- **La reprise purge les menus en cache** : `POST reprise` oublie les menus
  mémorisés du groupe avant de les régénérer — aucun rejeu d'une option de
  l'état d'avant le TPK (cibles/coordonnées disparues).
- **La reprise restaure les alliés** : les mercenaires recrutés sont sérialisés
  dans le snapshot `debut_quete` et recréés à la reprise (le mercenaire payé
  revient après un TPK, malgré la purge de fin de quête).
- **Héros tombé** : un héros à terre ne bloque ni le passage ni la ligne de vue
  (les figures l'enjambent). Il est **secourable** (option `relever`) seulement
  si un allié est adjacent **et** qu'aucune autre figure n'occupe sa case.
- **Composition des rencontres** : le budget achète « beaucoup de faibles +
  quelques forts » (bas puis haut du tier base), réglable dans `config/jeu.php`
  (`rencontres.forts_par_quete`, `forts_escalade_arc`, `seuil_cout_fort`).
- **PV du boss adaptés au groupe** : les PV Body des **boss/sous-boss** valent
  `pv_catalogue × nb_héros / taille_reference` (référence 4, plancher à 40 %) — un
  boss ne punit plus un petit groupe. Le max est **propre à l'instance**
  (`pv_body_max`, sérialisé au snapshot, +1 élite intégré) ; fuite, régénération et
  jauge affichée l'utilisent. Réglable via `rencontres.boss_pv_adaptatif` /
  `taille_reference`. La piétaille (tier base) garde ses PV catalogue.
- **L'API ne dépend jamais du LLM** : si le job IA échoue (pas de clé, erreur),
  repli (menu générique / narration neutre) — le jeu reste jouable.
- Toute mutation d'état passe par un événement journalisé (`evenements`) puis
  un broadcast `.groupe.etat`.
- **401 en pleine partie** : le client SPA intercepte un `401` (session expirée),
  affiche un bandeau « Se reconnecter » (message français, pas « Unauthenticated »)
  et route vers le login → « Reprendre la partie ». Session LAN longue par défaut
  (`SESSION_LIFETIME=1440`).
