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
| GET | /api/guide | — | **PUBLIC** — compendium de référence : {classes (chacune avec **`depart: {arme, pieces[], des_attaque, des_defense}`** — l'attaque et la défense **avec l'équipement de départ**, décidées par le serveur (`EquipementDepart::valeurs()`, mêmes règles que `recalculerCombat()`), à côté des `des_attaque`/`des_defense` de base à mains nues, 2026-09-24), competences (chacune avec **`cout`**, 2026-10-22 : 1 point, 2 pour le pouvoir final, 0 pour une capacité de carte — §« Le pouvoir final et le `cout` »), monstres (chacun avec **`boite`/`boite_libelle`**, 2026-10-09 ; `boite` reste la boîte d'ORIGINE, et `boite_libelle` nomme TOUTES les boîtes où la fiche est imprimée, séparées par « · » — l'Archer elfe et le Guerrier elfe : « The Mage of the Mirror · Rise of the Dread Moon », 2026-10-15, `monstres.boites_partagees`), objets (chacun avec **`avantages: string[]`** — ce que fait la pièce, traduit par le serveur via `MotsClesEquipement::avantages()`, le même texte que le sac ; le front ne retraduit plus la clé ; **`prix_base` est `null` pour un objet HORS MARCHÉ**, 2026-10-16 — parchemin, carte de trésor sans prix, potion de soin : `PhaseMarche::estHorsMarche()`, le prédicat de l'étal. Un prix qu'aucune transaction n'utilise n'est pas publié, et le front n'en affiche aucun), sorts, pieges, **mobiliers** (`{nom, largeur, hauteur, bloque_vue, fouillable, difficulte_destruction, pv_body, defense_dice, attaquable, detruit_par_action, boite_libelle}`) — `detruit_par_action` (2026-10-09) : le **Cocon** de Jungles of Delthrak, détruit par une action d'un héros adjacent, sans jet, décidé par le serveur depuis `effet.detruit_par_action` —, **terrains** (`[{nom, cout_deplacement, avantages: string[], boite, boite_libelle}]`, 2026-10-09 : ce que chaque tuile FAIT, en phrases, traduit par le serveur via `MotsClesTerrain::avantages()` ; une tuile qui ne produit rien dit « sans effet à ce jour » ; une clé non implémentée ne produit aucune phrase), **themes** (`[{cle, libelle}]` = `DemarreurQuete::BOITES_THEMATIQUES`), **cartes**} (catalogues seedés, autres effets bruts mis en forme côté front). `cartes` = les **trois** paquets sources (`config/cartes.php`, **69 cartes** : `equipement` 20 + `potions` 15, photos du matériel officiel Hasbro, + `artefacts` 34) : `{cle, libelle, source, url, cartes: [{carte, nom, paquet, porte, texte, manque, image_url}]}` — `image_url` (2026-10-16) : l'illustration d'une carte de **Dread**, emblème compris, `null` hors de ce paquet (§« Sorts de Dread illustrés ») — provenance de chaque pièce ET liste des cartes du plateau **pas encore jouables**, chacune avec la mécanique qui lui manque. Page /guide, ouverte depuis l'accueil sans compte. |
| POST | /api/deconnexion | — | 204 |
| GET | /api/moi | — | {joueur, personnages: [...], boites_bestiaire: [{id, libelle}]} |
| POST | /api/groupes | {nom, theme, longueur, ton, bestiaire_boites?} | {groupe} + dispatch squelette — `bestiaire_boites` : voir §Bestiaire automatique ou manuel |
| POST | /api/groupes/{identifiant}/joueurs | {personnage_id} ou {nom, classe} | {personnage} (rejoint le groupe) |
| GET | /api/groupes/{identifiant}/etat | — | **EtatGroupe** (voir ci-dessous) |
| POST | /api/groupes/{identifiant}/quetes | — | {quete} — démarre la quête suivante (assemble carte, spawn monstres, initiative) |
| PUT | /api/groupes/{identifiant}/ordre | {ordre:[personnage_id,…]} | réordonne l'ordre du tour (ordre_initiative) — **HUB seulement**, permutation exacte des héros actifs, **membre OU table** ; rediffuse `.prets.maj` réordonné |
| POST | /api/groupes/{identifiant}/choix | {option_id, parametres?} | 202 — le moteur résout, l'état et la narration arrivent par Reverb |
| POST | /api/groupes/{identifiant}/deplacement/apercu | {x, y} | {atteignable, raison?, chemin: [{x,y}], cout, restant, restant_apres, pieges: [{x,y,nom,etat}], traverse_roche} — **le trajet EXACT** que le héros parcourrait, AVANT de valider (voir §Aperçu du trajet) ; pendant le tour d'un ALLIÉ (menu porteur de `allie_id`), le trajet DE L'ALLIÉ, même forme, `pieges: []` (2026-10-17, §Un allié est joué par SON JOUEUR) |
| GET | /api/groupes/{identifiant}/menu | — | {menu, personnage_id, allie_id} \| {menu: null} — rattrapage du menu courant (régénéré si c'est le tour du héros, OU de l'allié qu'il contrôle — chantier 3a, `allie_id` alors non-null). **Même point de passage que `POST choix` et `POST deplacement/apercu`** (`App\Partie\MenuCourant`, 2026-10-09) : le menu rendu ici est exactement celui que le choix acceptera. Un menu calculé AVANT le tour du héros (cache `au_tour` à `false`) est recalculé à son tour : il n'avait pas lancé le dé de déplacement, et ses `destinations` s'arrêtaient à la portée de base (2026-10-10). **Au hub** (phase ≠ `quete`), la réponse est TOUJOURS `{menu: null}` : aucun menu de quête n'existe hors quête. Un menu resté en cache (celui de la quête qui vient de finir) n'est jamais servi, il est jeté à la lecture (`MenuCourant::pour()`, 2026-10-17). `POST choix` et `POST deplacement/apercu` y répondent 422 (`option_id` : « Aucun menu de quête au hub — le groupe se prépare entre deux quêtes. »). Le filet « Terminer le tour » (`GenererMenu::failed()`) ne publie QU'EN quête : au hub, il ne publie rien. |

**Fenêtre de régénération (2026-10-09).** Après une résolution, le menu consommé
n'est plus en cache le temps que le job `GenererMenu` le recalcule (une à deux
secondes sur la file `temps-reel`). Pendant cette fenêtre, `choix` et
`deplacement/apercu` ne refusent PLUS un choix légal : si c'est le tour du héros (ou
de l'allié qu'il contrôle) et que son menu manque, le serveur le recalcule sur place
depuis l'état déjà committé, puis valide le choix contre CE menu. Un menu en cache
qui ne désigne pas l'acteur qui a la main est jeté — jamais servi, jamais accepté.
Refus faute de menu jouable, deux messages (422, `option_id`) : « Le menu se met à
jour — réessaie dans un instant. » quand c'est le tour du joueur et que la
régénération n'a rien rendu ; « Aucun menu en attente pour ce joueur — attendez la
proposition du MJ. » quand ce n'est pas son tour.

## EtatGroupe (GET etat + broadcast `.groupe.etat`)

```json
{
  "groupe": {"identifiant": "...", "nom": "...", "phase": "hub|quete", "or": 0, "etat": "en_cours",
             "theme": "Cryptes maudites sous la cité|null",
             "theme_bestiaire": "horreur_des_glaces|null", "theme_bestiaire_libelle": "The Frozen Horror",
             "bestiaire_mode": "auto|manuel", "bestiaire_boites": ["horreur_des_glaces"],
             "prets": [{"personnage_id": 1, "pret": false}],
             "mercenaires": [{"id": 3, "mercenaire_id": 2, "nom": "...", "type": "archer",
                              "animal": false, "offert": false, "pv_body": 1, "pv_body_max": 1}],
             "compagnon_offert": {"nb_heros": 2, "seuil": 4, "consigne": "Le groupe compte 2 héros (moins de 4) : un compagnon animal vous est offert, gratuitement, pour toute la campagne. Un seul choix, une seule fois — le joueur qui le prend le mène.",
                                  "animaux": [{"mercenaire_id": 6, "nom": "Loup", "type": "compagnon", "deplacement": 10, "attaque": 3, "defense": 2, "pv_body": 5, "description": "…", "image_url": "…|null"}]},
             "gardien": true,
             "depart_possible": true, "depart_motif": null,
             "mercenaires_entretien": {"action": "mercenaire_entretien", "quete_id": 7, "cout_par_mercenaire": 10,
                                       "cout_total": 20, "or_restant": 480,
                                       "payes": [{"id": 3, "nom": "Fauchard"}], "partis": [],
                                       "sequence": 101} ,
             "faveur_hopekins": {"action": "faveur_hopekins", "quete_id": 7, "faveur": "deadeye",
                                 "faveur_libelle": "Deadeye", "faveur_effet": "Les figures ne bloquent pas votre ligne de vue…",
                                 "detail": "null pour une compétence ; sinon ce que la faveur a fait ou ce qui reste à faire",
                                 "personnage_id": 1, "personnage": "Albrecht", "sequence": 102} ,
             "hopekins": {"quete_id": 7,
                          "visites": [{"personnage_id": 1, "personnage": "Albrecht", "lieu": "dewdrop_inn",
                                       "libelle": "Dewdrop Inn", "effet": "+2 PV de Body maximum…",
                                       "detail": "Albrecht passe à 10 PV de Body maximum."},
                                      {"personnage_id": 2, "personnage": "Brynn", "lieu": null,
                                       "libelle": null, "effet": null, "detail": "Brynn ne visite aucun lieu."}],
                          "en_attente": [{"personnage_id": 3, "personnage": "Cara"}]} ,
             "prologue": {"texte": "prémisse...", "url": "/audio/.../...wav|null",
                          "menace": {"nom": "...", "description": "..."}, "auto": true}},
  "quete": {"id": 1, "titre": "...", "type_jalon": "normale", "etat": "en_cours",
            "objectif": "atteindre_et_recuperer|vaincre_sous_boss|vaincre_boss_final|quitter_donjon|secourir|detruire_element|null",
            "objectif_libelle": "phrase sans vocabulaire de jeu | null",
            "objectif_accompli": true,
            "objectif_majeur": false,
            "sortie": {"ouverte": true, "salle_depart_requise": true, "rassemble": false,
                       "absents": ["Oriane", "Tamsin"],
                       "consigne": "Rejoignez la salle de départ pour quitter le donjon — il manque : Oriane, Tamsin.|null"},
            "effets_globaux": [{"source": "Gruulob, Sorcier Gobelin Corrompu", "titre": "Les gobelins de Gruulob",
                                "texte": "Effet en jeu — Les gobelins de Gruulob : tous les gobelins de cette quête lancent 1 dé d'attaque de plus."}],
            "image_url": "/img/.../....webp|null"} ,
  "carte": {"largeur": 12, "hauteur": 10, "cases": [["m","s","b"]],
            "escalier": {"x": 2, "y": 2, "l": 2, "h": 2, "symbole_url": "/images/livrets/escalier/escalier.webp|null"},
            "portes": [{"x": 4, "y": 3, "cote": "e|s", "etat": "fermee|ouverte|verrouillee|secrete",
                        "embrasure": {"x": 5, "y": 3}, "verrou": "cle|monstres_vaincus|levier",
                        "symbole_url": "/images/livrets/portes/fermee.webp|null", "symbole_rotation": "0|90"}]},
  "entites": [
    {"type": "heros", "id": 1, "nom": "...", "classe": "nain", "x": 2, "y": 3,
     "pv_body": 6, "pv_body_max": 8, "pv_mind": 4, "pv_mind_max": 4, "tombe": false, "en_choc": false,
     "captif_porte": {"id": 5, "nom": "Le Prospecteur", "image_url": "/images/..."} ,
     "faveurs": [{"cle": "deadeye", "libelle": "Deadeye", "effet": "Les figures (héros et monstres) ne bloquent pas votre ligne de vue…"}],
     "...": "ou null — mode ESCORTÉ de la mission « secourir », voir ci-dessous"},
    {"type": "monstre", "id": 9, "nom": "<habillage IA ou nom_base>", "nom_base": "<type catalogue>", "x": 5, "y": 4,
     "pv_body": 2, "pv_body_max": 2, "etat": "actif"},
    {"type": "captif", "id": 3, "nom": "Gothar", "x": 7, "y": 4,
     "pv_body": 2, "pv_body_max": 2, "image_url": "/images/..."}
  ],
  "initiative": [{"entite": "heros|allie|monstre", "id": 1, "nom": "...", "a_joue": false, "tombe": false, "image_url": "/images/..."}],
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

**Effets globaux de quête** (2026-10-09, René — livret *Jungles of Delthrak*
q. 8, p. 27, note A : « All Goblins in this quest are elite warriors dedicated to
Gruulob and roll 1 additional Attack die »). `quete.effets_globaux` liste les
effets qu'un monstre de la quête fait peser sur TOUTE la quête : `[]` quand il
n'y en a aucun. Chaque entrée `{source, titre, texte}` est DÉCIDÉE par le serveur
(`EffetsGlobauxQuete`) : `source` est le nom de catalogue du monstre qui le porte,
`texte` la phrase affichée (« Effet en jeu — … »). Le client ne recompose ni ne
traduit rien ; il rend `texte` dans l'en-tête de la table et de la manette, à
côté de l'objectif. La liste est **figée au démarrage** (colonne
`quetes.effets_globaux`, reprise par le snapshot `debut_quete` comme
`salle_artefact`) : elle reste publiée jusqu'à la fin de la quête, même quand sa
source tombe (« in this quest »). À l'ouverture, la même phrase part au **journal**
(entrée `combat` portant `effets_globaux_annonces: [{type: "effet_global_quete",
texte, ton: "info"}]`, lue par `JournalCombat::ligneType()`) et donc sur
`.combat.journal`. Un effet ne s'établit qu'au démarrage : une source posée EN COURS
de quête n'en produit pas (aucune ne le fait aujourd'hui). Une quête ouverte AVANT
cette colonne (`effets_globaux` NULL) lit la liste sur sa roster, sans annonce.

**Objectif `detruire_element`** (2026-10-09, René — la quête finale de
*Wizards of Morcar* se gagne en **détruisant le Haut Autel**, G1504 p. 39).
Type générique « détruire un élément de la carte » : l'élément visé est un meuble
attaquable de `carte.grille.mobilier[]` désigné par la clé **`objectif: true`**
de son entrée (état durable, en base — jamais en cache). `quete.objectif`
vaut alors `"detruire_element"` **quelle que soit la valeur de
`gabarit.structure.objectif`** (la désignation est portée par la CARTE, posée à
l'assemblage ; une carte sans élément désigné — campagne en cours — garde
l'objectif de son gabarit, repli écrit). `objectif_libelle` : « Détruire :
Haut Autel. » ; `objectif_accompli` : l'élément désigné est détruit. Pas de
champ de plus : la bannière lit le libellé et le verdict, comme pour tout
objectif. **Victoire immédiate** : le coup qui détruit l'élément termine la quête
(voir §Attaquer un meuble) — pas de vote de sortie, pas d'escalier. Tant que
l'élément tient, `quitter_donjon` n'est **pas** ouvert par « donjon vidé » (seul
l'objectif ouvre la sortie, le livret ne connaît pas d'autre victoire) ;
`battre_en_retraite` reste sans condition.

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

### Bestiaire automatique ou manuel (René, 2026-09-28)

« J'aimerais une option automatique (comme actuellement) ou manuelle
(sélection possible d'une ou plusieurs extensions, et si aucune sélection,
système de base seulement). » Choisi **à la création du groupe**, figé pour la
campagne.

- **Automatique** — `bestiaire_boites` absent ou `null` au `POST /api/groupes`.
  Comportement historique : une boîte tirée par rotation à la première quête
  (`groupes.theme_bestiaire`), qui est une **préférence** (boss final et
  quelques « forts ») sur le bestiaire commun, jamais un filtre.
- **Manuel** — `bestiaire_boites` est une liste (vide comprise) d'identifiants
  de `DemarreurQuete::BOITES_THEMATIQUES` ; toute autre valeur → 422. C'est un
  **filtre** : n'apparaissent que les créatures du jeu de base (`boite =
  base`), les nôtres (`boite = null` — Troll, Champion, Seigneur, sorciers
  nommés : sans eux le jeu de base n'a ni sous-boss ni boss) et celles des
  boîtes cochées — rencontres, boss, monstre errant compris. Parmi elles, les
  boîtes cochées gardent la **préférence** pour le boss et les forts. **Aucune
  case cochée = HeroQuest Game System seul.** Terrain, équipement de glace et
  artefacts « boîtés » suivent les boîtes cochées.
  ⚠ L'invocation et la ponte (Dread, *spawn*) gardent la créature nommée sur
  la carte du lanceur : le lanceur n'est là que si sa boîte l'est.
- `GET /api/moi` publie `boites_bestiaire: [{id, libelle}]` — les boîtes
  proposables, libellés **décidés côté serveur** (noms officiels).
- `EtatGroupe.groupe` : `bestiaire_mode` (`auto|manuel`), `bestiaire_boites`
  (en auto : la boîte tirée, ou `[]` avant la première quête ; en manuel : la
  sélection), `theme_bestiaire` (la boîte tirée en auto, **`null` en manuel**)
  et `theme_bestiaire_libelle`, toujours présent : en manuel « HeroQuest Game
  System », suivi des boîtes cochées (« HeroQuest Game System + Jungles of
  Delthrak »). `App\Partie\BestiaireGroupe` est le point de passage unique.

`groupe.ambiance` — scène sonore **décidée par le serveur**
(`EtatGroupe::sceneAmbiance()`), la table ne fait que jouer la piste :
`exploration` | `combat` | `boss` en quête (monstres révélés vivants ; un
boss ou sous-boss révélé l'emporte), `hub` | `victoire` (boss final vaincu) |
`defaite` (dernière quête échouée) au hub.

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

**Les alliés figurent dans l'initiative** (René, 2026-10-01 : « ne devrait-on
pas voir les alliés dans la barre d'initiative ») : l'ordre publié suit l'ordre
RÉEL du round — héros (un par un), puis **alliés** posés (`entite: "allie"`,
`id` = `groupe_mercenaires.id`, jouent en bloc, `a_joue: false`), puis
monstres révélés. Ils jouaient déjà là (`ResolveurTour::jouerFinDeRound()` :
`phaseAllies()` avant `phaseMonstres()`), mais la barre ne les montrait jamais.

`initiative[].image_url` (René, 2026-10-01 : « afficher le portrait des unités
avec leur nom en dessous ») : le MÊME portrait que la figurine de la carte —
`urlHeros()`, `urlMercenaire()`, `urlMonstre()` (portrait habillé de
l'instance, sinon catalogue, sinon emblème) — jamais `null`. La barre de la
table et celle de la manette affichent le portrait, le nom en dessous.

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
| `groupe.{identifiant}` | `.table.scene` | {sequence, genre, titre, sous_titre?, acteurs: [{role, nom, image_url, pv?}], jet?, deplacement?, figure?, objets: [{nom, image_url, detail?}], issue: {ton, libelle}} | **écran de table SEUL** — la SCÈNE illustrée de l'événement qui vient d'être résolu : portraits de l'attaquant et du défendeur, volée de dés, objet trouvé, piège déclenché, contenu d'une salle révélée. Émise en synchrone par le résolveur depuis le **même résultat moteur** que `.combat.journal`, sans LLM. ⚠ Le journal APLATIT ce résultat en texte : les identités y meurent, donc aucune image ne peut plus y être résolue — d'où un événement PARALLÈLE plutôt qu'une ligne enrichie (une ligne de journal est un résumé destiné à défiler, lu aussi par les manettes). `genre` ∈ `attaque\|jet\|piege\|fouille\|salle\|sort\|chute\|objet\|deplacement\|reaction` (`SceneDeTable::GENRES`, testé dans les deux sens). Le genre `piege` couvre aussi l'**ESQUIVE** d'un piège (lianes esquivées sur un bouclier, `piege_esquive` — 2026-10-15) : titre « {piège} esquivé », le dé du piège sur `jet`, `issue: {ton: "succes", libelle: "{héros} esquive — aucun dégât"}` ; aucun champ ni genre neuf. `issue.ton` ∈ `degats\|mort\|tresor\|echec\|info\|succes`. Elle part pour `pieges_esquives[]` d'un déplacement, pour un `declenchement` esquivé (désamorçage, franchissement raté) et pour une action `piege_esquive` seule — les mêmes clés que le fil (`JournalCombat::piegesCroises()`). **`deplacement`** (2026-09-16) annonce le **début du tour d'un héros** : son portrait et le jet de déplacement **du tour**, `deplacement: {des: [int], calcul, de_annule, de_annule_par, sans_menace}` — `des` les faces réellement tombées (deux avec les Bottes elfiques), `calcul` la phrase DÉCIDÉE par le serveur (« 5 + 4 = 9 cases », dé annulé par l'armure, Raquettes, Vent Véloce et potion compris), identique à la `portee` de l'option `se_deplacer`. `de_annule`/`de_annule_par` (2026-09-24, voir §« L'Armure de plates FAIT PERDRE LE DÉ » plus bas) sont ce qui laisse la table barrer le dé d'un ✕ — `de_annule_par` vaut `null` dès que le dé compte. `sans_menace` (2026-09-30, voir §« Unthreatened Movement » plus bas) dit que le dé **comptait 4 sans être lancé**, faute de monstre actif révélé sur le plateau. ⚠ Cette phrase a porté `malus`/`malus_source` quelques heures, le temps que René tranche que la plate retire le dé entier plutôt que deux cases : si un lecteur les cherche encore, il cherche une forme abandonnée. `deplacement` vaut `null` sur tous les autres genres, comme `jet` hors d'un coup. ⚠ Le dé est lancé **au tour du héros**, plus au début du round pour tous : c'est ce qui fait partir la scène au bon moment, et une fois seulement — la garde est la colonne `deplacement_tour`, pas un cache. ⚠ **Toutes les `image_url` sont RÉSOLUES CÔTÉ SERVEUR** (`BibliothequeImages`, repli jusqu'à l'emblème SVG) : jamais un identifiant que le client devrait joindre, jamais un cadre vide — les scènes marchent sans clé d'IA. `jet` reprend exactement la forme de `des` ci-dessus. `sequence` est **le même compteur que le journal** (anti-inversion) ; ⚠ elle ne passe PAS par le garde de `.narration.diffusee`, qui choisit un texte de bandeau et n'a pas à décider si une image s'affiche. **`figure`** (`heros:{id}`\|`monstre:{id}`, même clé que les `mouvements` de l'état, sinon `null`) veut dire **« cette figurine vient de marcher : attends la fin de son trajet »** — publiée SEULEMENT si elle a marché dans la même résolution (`ResolveurTour::figuresEnMarche()`). La table n'affiche la scène qu'une fois ce trajet joué, et garde l'ordre d'arrivée (la tête de file bloque les suivantes) : le coup d'un monstre ne s'affiche plus pendant qu'il marche encore vers sa cible. ⚠ Elle attend le trajet **même s'il n'est pas encore arrivé** (3 s au plus) : la scène, petit message, précède couramment l'état qui porte les trajets, gros message publié par l'autre worker — mesuré, 756 ms d'avance. **`reaction`** (2026-09-17) : la réaction hors tour ACCEPTÉE depuis une manette (`POST reaction`), souvent pendant le tour d'un monstre, qui ne s'arrête pas pendant que le joueur réfléchit — portraits de celui qui réagit et de celui qu'il protège, l'artefact et son dé de perte le cas échéant ; une riposte (*Représailles*) réutilise la scène d'`attaque`, nom de la réaction en sous-titre. ⚠ La scène ne retarde JAMAIS l'offre de réaction : celle-ci part sur `joueur.{id}` à l'instant de l'attaque, avec son compte à rebours. ⚠ L'écran de table les **enchaîne dans l'ordre d'arrivée** (une file, et non plus une seule place d'attente qui écrasait la précédente : une chute suivie d'un début de tour perdait la chute), et une scène arrivée pendant la carte d'ouverture ou le prologue **attend** qu'ils se ferment au lieu de s'écouler dessous. **La DURÉE n'est pas dans le payload** : le retour à la carte se fait au clic sur l'écran du narrateur, ou après un délai réglé dans ses paramètres (défaut 5 s, préférence d'APPAREIL comme le volume, persistée en `localStorage`) |
| `groupe.{identifiant}` | `.groupe.etat` | EtatGroupe + `mouvements?` | table + manettes. **`mouvements`** (diffusion seule, jamais dans `GET /etat`) : `[{type: heros\|monstre, id, depart: {x, y}, chemin: [{x, y}]}]`, les trajets de la résolution qui a produit cet état, que la table rejoue case par case AVANT de poser les positions finales. ⚠ **Dans le même message que l'état** depuis le 2026-09-17 : ils partaient dans un `.mouvement.anime` séparé « juste avant », mais la file `temps-reel` a DEUX workers et l'ordre de publication n'était pas garanti. ⚠ La table **tient toutes les figurines du lot sur leur case de départ dès réception**, puis les fait marcher une à une : tenue une seule à la fois, la suivante sautait à l'arrivée pendant que la première marchait, puis revenait au départ pour refaire le trajet (mesuré, deux gobelins). La caméra ne suit pas le héros actif tant que des monstres marchent |
| `groupe.{identifiant}` | `.mj.reflechit` | {actif} | table + manettes |
| `joueur.{id}` (private) | `.menu.propose` | {menu: {contexte, options: [{id, libelle, type: "action|dialogue|jet|attaque|deplacement", parametres}]}, groupe_id, personnage_id, allie_id} | manette du joueur — `allie_id` non-null (chantier 3a) : c'est le tour de l'allié contrôlé par ce héros, pas le sien |

Un tour de héros = **deux créneaux** (doc 03 §28) : un **déplacement** et une
**action**. Agir AVANT de bouger laisse le déplacement entier, et l'on peut se
déplacer après l'action. Agir APRÈS avoir entamé son déplacement **confisque le
reste** (règle du plateau, 2026-08-07, maintenue par René le 2026-10-09 : elle est
annoncée par `perd_deplacement`, voir §`creneau`). Le menu offre les créneaux encore
libres (le déplacement à la portée **restante**), plus « Terminer le tour »
(`attendre`). Le tour ne passe au héros suivant / aux monstres **que sur décision du
joueur** (`attendre`, ou une action terminante : concentration) — plus de fin
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
`{base, de (résultat du d6), portee (POINTS de mouvement max ce tour — le terrain coûte —, Vent Véloce inclus)}`. La
manette affiche le dé puis une mini-carte tappable des cases accessibles ; le
choix part en `POST choix {option_id: "se_deplacer", parametres: {x, y}}`, que le
moteur revalide contre `portee` (réservé re-lancé en repli si absent).

#### `se_deplacer.parametres.destinations` — le serveur publie les cases atteignables (René, 2026-10-10)

`parametres.destinations: [{x, y, cout}]` — **toutes les cases où ce héros peut FINIR son
déplacement ce tour**, avec ce que chacune lui coûte en POINTS (`cout`, pas un nombre de pas :
Rivière gelée, sable, toile, jungle). Calculée **une fois par le serveur**, à la génération
du menu, par `App\Partie\DeplacementHeros::destinations()` — le **même code que la
résolution** (`FabriqueGrille::pour()` → `Grille::casesAtteignables()` / `parcoursPondere()`,
le même trajet que l'aperçu, les mêmes refus d'arrêt). Elle remplace le parcours pondéré que
`DeplacementSheet.vue` refaisait en JS (six dérives de miroir en un mois ; verdict Jungle
2026-10-10 : un bloc tombé enjambé à l'écran, « 6 points annoncés, 8 payés »). La manette
**éclaire exactement cette liste** et n'en recalcule rien ; le harnais (`vue.py`) la lit au
lieu d'interroger `apercu` case par case.

- **Couvre** : terrain pondéré (`cout_deplacement`, levé par `ignore_terrain_entravant`),
  mobilier et **bloc tombé** (obstacle permanent, `FabriqueGrille`), mur de glace, passage
  par un **allié** (traversé, jamais but), monstres (barrent, sauf mobilité de combat /
  Voile de Brume : traversés, jamais but), meuble franchi (Bracers, Spiderstep : traversé,
  jamais but), mare et brasier (traversés, jamais but), monstre enfumé (idem), portes closes
  et **embrasure** d'une porte non ouverte, **Traverser la Pierre** (cases de roche CONNUES
  de la carte, à portée), **reliquat** après un déplacement partiel (la liste se recalcule
  sur `portee` = points restants à chaque régénération du menu), **détour autour d'un piège
  CONNU** (le `cout` est celui du trajet que le héros prendra, pas du plus court : même
  `DeplacementHeros::chemin()` que l'aperçu). Le **Tunnel de glace** publie sa case d'ENTRÉE
  (la téléportation se joue à l'arrivée, `rep.teleportation`).
  **Trappe de téléportation** (EXT-CARTE2, 2026-10-23 ; `teleportation: 'contact'`) : c'est un PORTAIL,
  pas un tunnel. La marche ne le traverse jamais : entrer dans une trappe, c'est arriver sur sa JUMELLE,
  à coût nul, et y finir son mouvement. La jumelle figure donc parmi les destinations, son `chemin` s'achève
  sur la case d'ENTRÉE (le `cout` publié est celui de cette entrée), et la trappe elle-même n'est jamais
  une destination. Une jumelle occupée ou non découverte n'est pas une destination. Au passage, le
  payload de déplacement porte `teleportation: {de, vers, passage?}` où `passage = {nom, jet, degats,
  retenus}` (`jet` : la face du dé de combat jeté, `degats` : PV perdus sur un crâne, `retenus` : PV
  réellement retirés) ; la marche s'arrête là (`deplacement_restant = 0`). Un monstre qui entre dans une
  trappe est porté sur la jumelle et son payload `deplacement` porte `teleportation: {de, vers}` : son
  tour s'achève, sans frappe, et il ne jette aucun dé. Un ALLIÉ (mercenaire) ne franchit pas une trappe :
  sa grille lui ferme la case-portail (`Grille::fermerPortails()`) — décision, non sourcée.
- **Elle remplace les drapeaux de mouvement d'`entites[]`** (2026-10-15) : `franchit_figures`,
  `ignore_terrain_entravant`, `franchit_mobilier` et `traverse_roche` (celui de l'aperçu de trajet
  reste) — des décisions qui ne voyageaient que pour que la manette cesse de les re-déduire — ne sont plus publiés, plus aucun écran ne les lisant
  depuis cette liste (le moteur, lui, lit toujours le talent, la pièce ou le buff à la source,
  dans `DeplacementHeros::grille()`). `MobiliteCombatRogueTest` (la case derrière un monstre figure
  pour un Rogue, pas pour un barbare) et `TerrainJungleTest` (le `cout` publié d'un héros aux Ronces
  complices) épinglent la décision DANS la liste ; deux tests scellent l'absence des quatre clés
  (`MobiliteCombatRogueTest`, `TraverserLaPierreAnnonceTest`).
- **C'est la LISTE BLANCHE.** `POST choix {option_id: "se_deplacer", parametres: {x, y}}`
  est relu par le résolveur contre `DeplacementHeros::destinations()` recalculée sur l'état
  présent (pas contre le menu en cache) : toute case hors liste est refusée en 422 (« Destination
  illégale »), sans rien dépenser. `POST deplacement/apercu` **reste** pour le trajet exact et
  les pièges connus de la case touchée ; il ne décide plus quelles cases éclairer. Il refuse
  désormais aussi, comme le résolveur, une case occupée par un allié (`atteignable: false`).
- **Option vide = option absente.** Si la liste est vide (héros encerclé, tout est mur), le
  menu n'offre pas `se_deplacer` — pas de bouton mort. `destinations` vaut `[]` (jamais
  `null`) quand le serveur ne peut pas trancher (pas de carte, pas de position, héros à terre).
- **Ordre** : par ligne puis par colonne, indépendant du parcours.
- **Taille mesurée** (2026-10-10) : une entrée ≈ 25 octets JSON ; 8 points en grande salle
  dégagée = 144 cases = 3 583 octets (menu entier : 4,5 Ko).
- **Cas particulier inchangé** : `s_ecarter_du_bloc` porte déjà sa propre liste blanche
  (`parametres.cases`, au plus deux), et `se_deplacer_allie` ses `destinations` d'approche
  (forme différente : `{cle, nom}`, un ADVERSAIRE à approcher et non une case).

⛔ **AUCUNE FUITE D'INFORMATION CACHÉE** (exigence de René, 2026-10-10). La liste ne dit rien
que le joueur ne sache déjà ; ce qui est caché n'est **ni présent, ni absent, ni plus cher** :
- un **piège caché** est une case ORDINAIRE — publiée si atteignable, au coût normal, sans
  champ ni marqueur, et le trajet ne le contourne pas (la forme de la route le trahirait).
  Seuls les pièges CONNUS (`detecte`, `fosse_ouverte`, `mare_acide`, `desarme`, `declenche`, `retient` —
  ceux de `EtatGroupe.carte.pieges`) infléchissent le chemin ;
- un **passage secret non découvert** est un mur (porte non ouverte, embrasure close — la
  carte la peint en roche) ;
- le **brouillard** est un obstacle : toute case que `EtatGroupe::casesConnues()` rend `b`
  (salle non révélée, couloir derrière une porte close) n'est ni destination, ni passage, ni
  raccourci de coût. Un monstre caché (non révélé) dans une zone inconnue ne retire donc
  aucune case et n'en renchérit aucune ;
- un **faux coffre de Dreadshifter** (monstre caché SOUS un meuble) se refuse comme n'importe
  quel meuble : `DeplacementHeros::refusArret()` teste le meuble AVANT la figure.
`DestinationsDeplacementTest` pin ces quatre cas par des cartes jumelles (avec / sans le
secret : listes **strictement identiques**, et chemin d'`apercu` vers une case au-delà).

#### Unthreatened Movement — sans menace, le dé compte 4 (FL-Q p. 7, First Light, 2026-09-30)

⚠ **« without an active monster on the board, each red die for movement
counts as a 4 instead of being rolled » (2 dés → 8, 1 dé → 4).** Chez nous
(base + UN SEUL d6, écart assumé) : sans **monstre actif révélé** n'importe où
sur le plateau de la quête (`Quete::monstreActifRevele()`, même filtre que
l'ambiance sonore de la table), le dé — et celui des *Bottes elfiques* — n'est
**pas lancé** : sa valeur est directement `4` (`App\Engine\Deplacement::VALEUR_SANS_MENACE`).
`App\Models\Quete::monstreActifRevele()` est le **seul** point de passage de
cette question, lu par `MenuMoteur::deplacementDuTour()` **et** le repli de
`ResolveurTour::resoudreDeplacement()`.

`{..., sans_menace}` rejoint `de_annule`/`de_annule_par` dans `parametres` de
l'option `se_deplacer`, dans `detail_deplacement_tour` (colonne, §2.16), et
dans `deplacement.sans_menace` de la scène de table (§ ci-dessus,
`.table.scene`) — **la DÉCISION, publiée déjà prise**, jamais recalculée côté
client (`docs/regles/front-manette-et-table.md`).

⚠ **Indépendant de `de_annule`, jamais en conflit avec lui** : un dé annulé
(Armure de plates) reste annulé, menacé ou pas — `sans_menace` ne remplace que
le JET, `de_annule` décide seul si la valeur (fixe ou lancée) compte dans le
total. Les deux clés peuvent donc être vraies en même temps sans rien dire
d'incohérent : le dé vaut 4, et ne compte pas.

⚠ **Conséquences mesurées, assumées plutôt que corrigées en douce** :
*Évanescence* ne se rompt jamais sur un dé « sans menace » (4 < le seuil de
rupture, 5) — cohérent, pas un bug ; et l'usure des *Bottes elfiques* sur « dés
identiques » (`MotsClesEquipement::USURE_SUR_DES_IDENTIQUES`) est **désactivée**
quand `sansMenace` est vrai, parce que deux dés fixés à 4 seraient *toujours*
identiques là où la carte parle d'un coup de chance sur un jet réel.

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

⚠ **L'ÉTAT DE CHOC (René, 2026-10-01) lit EXACTEMENT la même décision**, sans
être une troisième exemption à écrire : « one red movement die » (AtOH p. 9)
se lit comme la Plate Mail, via le même `$deAnnule` de `App\Engine\Deplacement`
— une seule interprétation de cette phrase dans tout le projet. Mais c'est une
UNION, jamais une délégation à `Equipement::detailDeDeplacementAnnule()` : le
Chevalier et Allégée exemptent le MALUS D'ARMURE, pas le choc, qui « applies to
every creature » — un Chevalier en choc perd son dé comme n'importe qui.
`de_annule_par` nomme les deux sources si elles coïncident (`"Armure de
plates + État de choc"`).

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

⚠ **`traverse_roche`** (2026-10-09, décision de René sur le verdict Morcar) : le trajet
traverse-t-il de la ROCHE (Traverser la Pierre, `franchit_mur`) ? `ResolveurTour::apercuDeplacement()`
le décide sur la grille même de la résolution : `true` dès qu'une case du chemin est de la
roche. La manette ne le devine pas : elle lit ce booléen et prévient AVANT le second tap :
« une fois ton déplacement fini, tu ne pourras plus revenir dans la roche — et tu tombes si ton
tour finit dans la roche » (`verifierRocheMortelle()`, appelée à la fin du tour).
Ce que le moteur tient, et que l'annonce dit exactement : l'intangible vit pendant le créneau de
déplacement (sort `ce_tour`, lu par `MoteurSorts::traverseRoche()`). Un héros qui s'arrête dans
le couloir avec des points restants peut encore y rentrer ; une fois le déplacement fini
(reliquat à zéro, ou action jouée qui le confisque), plus aucun pas dans la roche ce tour : le menu
n'offre plus `se_deplacer`, et `POST choix` refuse en 422 (« Option illégale : elle ne figure pas
dans le dernier menu proposé. »). ⚠ Le verdict parlait d'une consommation « à la fin du
déplacement » ; c'est le même effet en pratique, pas un mécanisme distinct.

### Une action, puis un sous-choix (2026-09-01)

Le menu ne porte plus **une option par sort** : il porte **une option qui porte
la liste des sorts**. Mesuré en partie réelle, le menu d'un magicien niveau 1
comptait **14 options dont 9 sorts**, là où le doc de conception fixe « 2 à 5
options claires » (doc 13 §3.1). C'est la leçon du ciblage, un cran plus haut :
*l'option ne doit pas ÊTRE le sort, elle doit PORTER la liste des sorts.*

| option | liste | entrée |
|---|---|---|
| `lancer_sort` (`type: sort`) | `parametres.sorts[]` | `{cle, sort_id, nom, element, sort_type, disponible, description, cibles?, mode?, porte?, case?}` — **`description`** (2026-10-09) : ce que le sort FAIT, tiré du catalogue (`sorts.description`) et décidé par le serveur, sur CHAQUE entrée — la manette l'affiche sans rien recomposer (verdict Morcar § 5 : Voile de Brume n'était décrit nulle part) |
| `lire_parchemin` (`type: parchemin`) | `parametres.parchemins[]` | idem + `inventaire_id` (et `description`, idem) ; un parchemin de sort à emplacement (mur, Clairvoyance, voile) porte les MÊMES entrées que le sort connu, `mode` et `cases`/`salle` compris (2026-10-08) |
| `utiliser_objet` (`type: objet_libre`) | `parametres.objets[]` | `{cle, inventaire_id, nom, detail, cout: gratuit\|action, quantite, cibles?}` |
| `se_concentrer` · `sacrifier_pour_sort` | `parametres.sorts[]` | `{cle, sort_id, nom, …}` |

Le client répond **à plat** : `POST choix {option_id, parametres: {cle, cible_id?, cible_type?}}`.

- ⚠ **La liste EST la liste blanche.** `entreeChoisie()` vérifie l'appartenance
  de `cle` et répond 422 sinon. Sans cela, un client lancerait un sort de son
  répertoire **avec les cibles d'un autre** — hors ligne de vue et hors du
  typage de cible que `ciblesLegales()` avait calculé pour ce sort-là.
- ⚠ **`cibles` reste PAR ENTRÉE.** Un sort de dégâts ou mental à **cible unique**
  (`cible: monstre`) ne liste que des monstres depuis le 2026-10-09 (décision de
  René : la liste suit la carte) ; un soin liste les héros, lanceur compris ; un
  sort sur soi personne ; un sort de **zone** n'a pas de liste du tout. Une liste
  unique au niveau de l'option serait fausse pour cinq des neuf sorts d'un magicien.
- ⚠ **La profondeur suit la donnée** : le troisième niveau (ciblage) ne s'ouvre
  que si l'entrée porte des `cibles`. *Traverser la Pierre* et une potion de
  soin partent du deuxième.
- ⚠ **`mode: pose_mur_magique`** (*Wall of Stone*, 2026-10-06, **deux cases**
  depuis le 2026-10-05) : une entrée PAR PAIRE de cases libres contiguës, la
  première adjacente au lanceur, `cases: [{x, y}, {x, y}]` — jamais une
  base-entry sans case, comme `mode: ouvre_porte`/`porte` juste au-dessus pour
  le Génie. `POST choix {option_id, parametres: {cle}}` suffit : la paire est
  déjà fixée par l'entrée choisie, le client n'a rien de plus à fournir. Le
  payload rendu porte `cases` (les deux cases) et `mobilier` (une seule entrée,
  `l`×`h` = 2×1 ou 1×2 selon la paire, `pv_body: 1`, `defense_dice: 6`). Le parchemin
  `Parchemin : Mur de Pierre` (`lire_parchemin`, 2026-10-08) offre les MÊMES paires,
  `cle: parchemin:{inventaire_id}:mur:…`, et passe par le même point de passage
  (`ResolveurTour::poserMurMagiqueSort()`) : une règle, deux entrées de menu.
- ⚠ **`mode: vision_salle`** (*Clairvoyance*, 2026-10-08) : une entrée PAR SALLE
  NON DÉCOUVERTE, `salle: <index>`, nom avec le repère de la salle (« au est, à 2
  cases ») et **jamais** son contenu. Le résultat (`vide`, `monstres` par nom,
  `pieges` en nombre, `texte`) ne montre QUE cette salle ; il part au journal et
  à la scène de table « Vision à distance ». Ne modifie ni le brouillard ni
  `salles_decouvertes`. ⚠ **Rendu (2026-10-08)** : le fil porte `« {Acteur} lance
  Clairvoyance — {texte} »` (sans `texte`, la ligne ne disait que « lance »), et la
  MANETTE de celui qui lance affiche `resultat.texte` (nom : `resultat.sort.nom`)
  jusqu'à fermeture ou au choix suivant — le serveur décide le texte, le client le lit.
  Le parchemin de Clairvoyance (`lire_parchemin`, 2026-10-08) offre les mêmes salles,
  `cle: parchemin:{inventaire_id}:salle:{index}`, avec `inventaire_id`.
- ⚠ **`mode: pose_ombre`** (*Cloak of Shadows* → « Voile d'ombre », 2026-10-08) :
  une entrée PAR emplacement légal (`MoteurOmbre::emplacementsLegaux()` : les
  plus proches du lanceur d'abord, **24 au plus**), `cases: [{x, y}×6]` (un
  rectangle 3×2 ou 2×3), nom « Voile d'ombre — 3×2, au nord-est, à 4 cases ».
  `POST choix {option_id, parametres: {cle}}` suffit. ⚠ **Pas de `cibles`, pas
  d'entrée sans emplacement** ; le sort se lit aussi en parchemin (entrées
  `parchemin:{inventaire_id}:ombre:…`). Le résultat porte `ombre: {x, y, l, h,
  jetons}` et `texte` (décidé par le serveur) ; il part au journal et à la scène
  de table « Zone d'ombre ».
- ⚠ ***Vision du futur* n'a AUCUNE entrée** dans `lancer_sort` ni dans
  `lire_parchemin` : « cast at any time and does not take an action ». Elle se joue
  après un jet, par `/reaction` (§Réactions hors tour), pour le héros qui la CONNAÎT
  **ou** qui porte son parchemin au sac (2026-10-08) : le menu ne l'offre jamais, la
  relance, si.
- ⚠ **`cible: lanceur_dread`** (*Unlearn*, 2026-10-08) : `parametres.cibles` ne
  liste que des monstres **lanceurs de Dread en ligne de vue** qui gardent au
  moins un sort. Le résultat (`mode: oubli_sort`, `sort_oublie`, `texte`) part au
  journal ; le sort oublié reste **pour la quête** (`sorts_oublies_de_quete`) et
  apparaît **grisé** (`disponible: false`) dans la liste d'un héros concerné.
  ⚠ **Rendu (2026-10-08)** : même traitement que Clairvoyance — `texte` dans le fil
  et affiché à celui qui lance (`resultat.texte`). Le sort s'appelle
  **Désapprentissage** au catalogue (`sorts.nom`, migration `2026_10_08_110000`) ;
  « Unlearn » n'est plus que le nom anglais, conservé dans `config/cartes.php`.
- ⚠ **Un sort épuisé reste dans la liste**, `disponible: false`, pour être
  **grisé** — le faire disparaître laissait croire au joueur qu'il l'avait
  perdu. Le résolveur le refuse.
- ⚠ **Un sort à cible sans cible légale n'a AUCUNE entrée** (2026-10-08) — ni
  grisée, ni `cibles: []`. Une liste vide n'est pas une liste : la manette
  l'ouvrirait comme un niveau sans choix, avant que le résolveur ne refuse
  « Cible requise ». Donc `cibles`, quand il est présent, est **toujours non
  vide**. Cas réels : *Désapprentissage* sans Sorcier de Dread en vue ; *Boule de
  Feu* sans monstre en vue (cible unique, 2026-10-09 — jamais le magicien à sa
  place) ; *Conte inspirant* d'un Barde seul, qui ne se vise jamais lui-même (« excluding
  yourself »). C'est la règle des sorts à emplacement (*Mur de Pierre*,
  *Voile d'ombre*, *Clairvoyance*) : pas d'emplacement légal, pas d'entrée. Un
  sort **épuisé** reste lui grisé — il est au héros, il revient ; un sort sans
  cible **attend** une cible. Si `lancer_sort` n'a plus aucune entrée, l'option
  ne paraît pas. Même règle pour `lire_parchemin` (même liste de cibles, même
  résolveur).
- ⚠ **Le coût d'un objet dépend de l'OBJET**, pas du type d'option : la liste
  mêle le gratuit (potion, chausse-trappes, fumigène) et le payant (eau bénite,
  « instead of attacking »). L'option est `objet_libre` et `resoudreUsageObjet()`
  dépense l'action lui-même. La liste ne contient que du gratuit quand le héros
  a déjà agi.
- ⚠ **Cible d'un objet** (2026-10-17) : une entrée de `utiliser_objet` qui porte
  `cibles` exige un `cible_id` (`parametres.cle` + `parametres.cible_id`). Sans
  `cible_id`, 422 « Cible requise : parametres.cible_id … » ; avec un `cible_id`
  hors de la liste, 422 « Cible illégale pour cet objet. ». Le message dit
  donc ce qui manque, et « illégale » ne sert que quand la cible est fournie.
- ⚠ **La navigation est une PILE** côté manette, et chaque retour **nomme sa
  destination** (« Retour aux sorts », « Retour aux actions »). Un retour qui
  ramènerait au menu d'action depuis le ciblage ferait recommencer le choix du
  sort. Un tap sur le fond dépile d'un cran ; un nouveau menu vide la pile.

⚠ **`POST /potions` est RETIRÉE.** Boire passe par `utiliser_objet`, comme tout
le reste — une voie, une validation, un journal. Conséquence assumée : on ne
boit plus hors de son tour, ni au hub — **sauf** les potions de
`MotsClesEquipement::CLES_AU_HUB` (Potion of Charm, « Drink this potion between
quests »), bues par `POST /groupes/{identifiant}/potions/boire-au-hub` (lot 1b,
2026-10-08) : au hub il n'y a pas de menu, donc une route dédiée, et elle
REFUSE toute autre potion. Le cas d'urgence reste couvert par
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
  `rarete_butin_amelioree` (Œil du prix), `soin_apres_kill` (Sang chaud : une
  mise à mort réelle rend des PV, dite au point de passage `infligerAMonstre()`),
  `degat_differe_sur_sort` (Flétrissure : un sort qui blesse sans abattre ajoute
  une braise, dite au même point),
  `refaire_fouille_negative` (Flair du
  pisteur : « la fouille est refaite », une seule reprise) ;
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
⚠ **`faveurs_declenchees: [{type: 'faveur_peacekeeper', …}]`** (2026-10-08) —
même principe pour les faveurs de Hopekins Rest qui se déclenchent pendant
l'action (Peacekeeper) : `App\Partie\TamponFaveurs`, rendu par `JournalCombat`
dans les lignes du fil en direct. Absent quand il n'y en a pas.

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
distance?, mains_nues?, des_attaque?, indication?}]` — et la manette ouvre sa feuille de ciblage. Le choix part en
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
| `hurler` | `saute_tour_monstre` | `{portee: 'contact'}` | *Hurlement* (Barbare) : les monstres **à ton contact** perdent leur prochain tour. Aucune cible à choisir ; offerte seulement si un monstre au contact peut recevoir la condition |
| `mot_de_pouvoir` | `saute_tour_monstre` | `{portee: 'vue', cibles: [{id, type, nom}]}` | *Mot de pouvoir* (Barde) : **un** monstre que tu vois (ligne de vue des sorts) perd son prochain tour. `cibles` est la liste blanche que `parametres.cible_id` doit honorer |
| `fleche_{direction}` | `tir_traversant` | `{direction}` | *Flèche traversante* (Elfe) : un tir de l'arme à distance **en main** dans une direction, qui frappe chaque ennemi de la ligne, chacun se défendant aux dés de l'arme. Une option par direction qui porte une cible légale ; un ennemi au contact n'est pas visé (l'arme refuse le contact) |
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

⚠ **Une arme qui refuse le contact frappe À MAINS NUES** (décision de René, 2026-10-15) :
une arme `inutilisable_adjacent` (Arbalète, arcs, Fronde, Dague de jet magique) ne tire jamais sur un
monstre au contact — mais le héros n'est pas désarmé pour autant. Chaque monstre AU CONTACT figure
alors sous `attaquer` (jamais sous `lancer`), son entrée de cible portant la décision, déjà prise par
le serveur : `mains_nues: true`, `des_attaque` (les dés réels de ce coup, ceux des mains nues — le
`des_attaque` de l'entrée d'arme est celui du tir, il ne vaut pas ici) et `indication` (la phrase à
afficher, « à mains nues (2 dés) »). Les cibles à distance gardent l'arme : même liste, mêmes
entrées, `distance: true`, sans ces trois clés. La manette affiche `indication` telle quelle, sans
rien re-déduire. Le résolveur applique la MÊME règle (`Objet::interditAuContact()`, au point de
passage de la frappe `ResolveurTour::frapper()`) : un coup au contact d'une telle arme se résout
à mains nues, l'arme reste en main (rien n'est perdu) ; la liste portée par l'option reste la liste
blanche. Seul un `lancer` au contact d'une arme qui l'interdit reste refusé (422). Une arme jetable
visée hors contact ne figure toujours que sous `lancer` (libellé « Lancer X (perdue) »).

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

**`relever` est une ACTION, plus un tour** (décision de René, 2026-10-09, verdict Morcar) :
relever un compagnon tombé consomme le créneau d'**action**, comme une attaque. Le héros
peut encore se déplacer avant ou après. `creneauOption('relever')` rend donc `action` ; le
menu ne le propose plus une fois l'action jouée (`a_agi`) ; si une relève périmée arrive
quand même, le résolveur la refuse (« Tu as déjà agi ce tour. ») — la route répond 422 avant
cela, « Option illégale », tant que l'option n'est plus au menu.

**`liberer_captif` est GRATUIT** (décision de René, 2026-10-15 : aucun livret n'impose de coût — *The Frozen Horror*
p. 19, *The Mage of the Mirror* p. 4) : `creneauOption('liberer_captif')` rend `interaction`, comme `ouvrir_porte`.
Le payload change donc d'une valeur, pas de forme : l'option porte `creneau: "interaction"` (elle portait `tour`),
sans `perd_deplacement` ; elle n'est plus enfermée derrière l'action — le menu l'offre encore APRÈS une attaque, et la
manette ne la grise pas (`creneauConsomme()` ne grise `interaction` qu'une fois `a_joue`). La libération ne ferme plus le
tour du héros : il peut ensuite frapper, se déplacer ou « Terminer le tour ».

**`perd_deplacement`** (2026-10-09, décision de René : « garder la règle, mais l'annoncer ») :
toute option `creneau: "action"` porte `perd_deplacement: N`, le nombre de cases que CET
acte fera perdre. `N` est DÉCIDÉ par `ResolveurTour::casesPerduesParAction()`, la formule même
de `marquerCreneau()` : le reliquat `deplacement_restant` si le déplacement est entamé, `0`
sinon (rien entamé, Vague montante — `deplacement_scinde` — active, ou action déjà jouée :
un bonus d'attaque ne confisque rien). Le même chiffre vaut pour toutes les options d'action
d'un menu. La manette affiche « Tu perdras tes N points de déplacement restants si tu agis maintenant » AVANT
le geste ; elle ne recalcule jamais `N`.

⚠ **Correction du même jour.** La condition « déplacement entamé » comparait le reliquat au
total du jet (`deplacement_tour`). Or le tour s'ouvre avec `total × multiplicateur + bonus`
(Vent Véloce ×2, Potion de dextérité +5) : un héros ainsi doublé qui avait fait UN pas
(reliquat 11, total 6) ne perdait jamais son reliquat en agissant. « Entamé » se lit désormais
sur `deplacement_restant` lui-même, posé par la résolution de chaque pas.

**`perd_attaque_supplementaire`** (décision de René, 2026-10-22) : toute option `creneau: "mouvement"`
(`se_deplacer`, `franchissement`, `saut_sables`) porte `perd_attaque_supplementaire: true|false`. Il vaut
`true` quand la SECONDE FRAPPE du héros est EN ATTENTE, c'est-à-dire quand bouger maintenant la lui
fera perdre. La seconde frappe fait partie de la MÊME action que la première : un héros qui a frappé
(`a_agi`) mais n'a pas fini son tour (`a_joue` faux) et a encore `attaque_supplementaire` peut toujours se
déplacer — le déplacement, lui, la consomme. Le champ est présent sur chaque option de mouvement (`false`
compris) et n'apparaît jamais sur une option d'action. Le prédicat est UN seul,
`ResolveurTour::secondeFrappeEnAttente()`, lu par le menu (`MenuMoteur`, qui le publie) et par le
résolveur (`resoudre()`, qui fait perdre la frappe au geste). Il ne dépend PAS de la source de la frappe
armée : Potion d'héroïsme, Potion de rage guerrière, Ambidextrie, Sang qui bout / Coup de grâce / Soif de
sang, Fléau des Orques portent le même drapeau, donc la même règle.
La manette LIT ce champ et ne le recalcule pas : si `perd_attaque_supplementaire` vaut `true`, elle pose la
question « Te déplacer maintenant te fait perdre ton attaque supplémentaire. Continuer ? » AVANT d'ouvrir la
zone de déplacement (`deplacement`) ou d'envoyer le geste (`franchissement`, `saut_sables`). « Non » n'envoie
rien : le menu reste tel quel. « Oui » reprend le geste normal. Avant la première frappe (`a_agi` faux), le
champ vaut `false` : boire → bouger → frapper → frapper reste permis, sans question.
La perte est appliquée au geste RÉSOLU, dans la même transaction que le déplacement : un déplacement refusé
(422) ne fait perdre aucune frappe. Elle s'annonce par `attaque_supplementaire_perdue` (§ annonces).

**Le menu d'une seconde frappe en attente** (2026-10-22, correction du même jour). Tant que la frappe est armée,
le menu n'ouvre que ce que le résolveur accepte pendant elle : le reste du menu (mouvement, `jeter`,
`desarmer_embrasement`, `utiliser_objet`, `ouvrir_porte`, `oracle_salle`, `lacher_souris`, `liberer_captif`, `battre_en_retraite`,
`quitter_donjon`, `attendre`) et les seules options `type: "attaque"` (l'arme, la Furie, le poing nu). Ne sont PAS
offerts : toute option de créneau `action` qui n'est pas une frappe (`frappe_balayee`, `rayon_{direction}`,
`toucher_brasier`, les sorts, la fouille, `relever`…), et, dans `utiliser_objet`, toute entrée `cout: "action"`
(une fois `a_agi`, la liste ne garde que `cout: "gratuit"` ; `entreeChoisie()` refuse ce qui n'est pas listé).
La Potion d'héroïsme n'est pas offerte tant que la frappe est armée (`refusAvantAttaque()`). `menu.situation` dit ce
que le menu offre : « Une attaque supplémentaire vous est offerte ce tour. » seulement si une option `attaque` est
au menu ; sinon « Aucune cible à portée pour ton attaque supplémentaire. », ou la raison du refus
(`raisonAttaqueInterdite()` : héros invisible, voile d'ombre). Un héros Paralysé ou Évanescent garde la phrase
ordinaire du menu. Une potion gratuite (`utiliser_objet`, `cout: gratuit`) se boit sans toucher ni à l'action ni à
la frappe armée : `a_agi` et `attaque_supplementaire` restent tels quels.

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
| POST | /groupes/{identifiant}/marche/lever-malediction | {personnage_id} | don de 800 po (bourse COMMUNE) qui lève la Malédiction de l'Oracle d'un héros (First Light, lot C) — **membre**, 422 si le héros n'est pas maudit ou si l'or manque. Immédiat, HORS du panier : `{leve, personnage_id, don, or}` + broadcast `.groupe.etat` |

**EtatMarche** : `{profil, multiplicateur, inventaire: [{objet_id, nom, categorie,
rarete, prix, stock}], paniers: [{joueur_id, pseudo, achats: [...], ventes: [...],
confirme, inventaire: [{inventaire_id, personnage_id, objet_id, nom, categorie,
rarete, emplacement, quantite, revente}]}], total_projete, or_courant}` — le
`inventaire` de chaque panier liste ce que ce joueur peut vendre (héros actifs),
avec le prix de revente M1 déjà calculé. Prix d'achat = prix_base × multiplicateur
du profil (doc 04 §3) ; revente = 50 % du prix marchand courant (M1) ; rareté
`unique` jamais en stock. ⚠ **Une carte de trésor SANS PRIX** (`effet.hors_marche`,
décision de René du 2026-10-16 : « On ne peut pas vendre les potions qu'on trouve
dans les trésors » — *Potion d'héroïsme*, *Potion de force*, *Potion de défense*) — et la **Potion de soin**
(100 po, prix NON SOURCÉ, même drapeau, « la Potion de guérison sourcée, 500 po, reste en vente » — René
2026-10-16) — n'est ni à l'étal, ni dans l'`inventaire` vendable du panier : son achat répond 422 (« n'est
pas proposé par ce marchand »), sa revente 422 (« ne se revend pas »), comme un
artefact. Elle reste trouvable en fouille et se boit. ⚠ **Un PARCHEMIN** (catégorie `parchemin`, René 2026-10-16 :
« on les trouve en fouillant le mobilier ») n'est jamais à l'étal ni dans l'`inventaire` vendable : son achat répond 422
(« n'est pas proposé par ce marchand »), sa revente 422 (« Un parchemin ne se revend pas : on n'en trouve qu'en fouillant
le mobilier. »). Un achat venu d'une phase ouverte AVANT ce changement est refusé à la confirmation (422, « n'est plus à
l'étal : cet objet ne s'achète pas au marché »). Garde-fous à l'application : total ≥ 0, stock, objets
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
| GET | /mercenaires | — | catalogue recrutable : `[{id, nom, type, prix, deplacement, attaque, portee, attaque_distance, defense, pv_body, animal, description, image_url}]` (group-agnostique, comme `/competences`) |
| POST | /groupes/{identifiant}/mercenaires | {mercenaire_id, personnage_id?} | recrute un allié contre l'or de la **bourse commune** (422 si pas au hub, **groupe pas encore Gardien**, **4 mercenaires déjà engagés par ce recruteur**, or insuffisant, 2ᵉ compagnon animal, ou `personnage_id` hors des héros actifs DE CE JOUEUR) — `personnage_id` désigne qui le CONTRÔLERA en quête (chantier 3a) ; absent, le PREMIER héros actif de ce joueur, même patron que `achats[].personnage_id` au marché |
| POST | /groupes/{identifiant}/potions/boire-au-hub | {personnage_id, inventaire_id} | **boit une potion ENTRE DEUX QUÊTES** (Wizards of Morcar, Potion of Charm : « Drink this potion between quests »). 422 hors phase `hub`, pour un héros qui n'est pas actif et contrôlé par ce joueur, ou pour une potion **hors** `MotsClesEquipement::CLES_AU_HUB` (elle se boit en quête, par le menu). Résultat : `{potion: {objet, effets, …}, personnage: {id, nom, rabais_recrutement: {restants, po}}}` ; journal `systeme` `potion_bue_au_hub`. Consomme l'exemplaire comme `boire()`. Rediffuse `.groupe.etat` (la remise restante change `groupe.recrutement`). |

### Compagnon animal OFFERT (*Against the Ogre Horde*, livret F9528 p. 8-9 — René, 2026-10-15)

« Animal allies […] can be recruited **at no cost** […] so long as there are **fewer than
four hero players**. The animal ally must be recruited before the quest begins. » Décision
de René (Q2, 2026-10-02 ; interprétation confirmée le 2026-10-15) : l'animal est proposé
**UNE fois, gratuitement**, à un groupe de **moins de 4 héros**, et il **accompagne le
groupe toute la campagne**. Un animal par groupe (la règle existante), mené comme tout allié
(chantier 3a : dans le tour de son héros-maître, depuis sa manette).

**Quand.** Pas « à la création du groupe » : le fondateur y est SEUL (`POST /groupes`), les
autres rejoignent ensuite par le code — la taille n'est connue qu'au **lancement de la première
quête**, borne du livret (« before the quest begins »). La proposition est donc **ouverte au
hub tant que le groupe n'a JAMAIS lancé de quête**, son existence suivant le nombre de héros
actifs du moment (`App\Partie\CompagnonOffert::ouvert()`) : un quatrième joueur qui arrive la
retire, un départ la rend, la première quête (de n'importe quel état) la ferme. Les campagnes
déjà entamées n'ont donc jamais cette offre.

**Publiée** par `EtatGroupe.groupe.compagnon_offert` (hub) : `null` quand il n'y a rien à
proposer, sinon `{nb_heros, seuil, consigne, animaux: [{mercenaire_id, nom, type, deplacement,
attaque, defense, pv_body, description, image_url}]}`. La `consigne` est écrite par le
serveur ; `animaux` est lu dans le catalogue (`mercenaires.animal`, hors `octroi_seul`/`captif`)
et **EST la liste blanche** que le POST revalide — le client ne re-dérive ni le seuil, ni la
liste, ni « une seule fois ».

**Qui décide : le premier joueur qui touche** (mécanisme du recrutement, pas un vote — un
`VoteGroupe` n'élirait pas le MAÎTRE de l'animal, et ferait attendre tout le monde).

| Méthode | URL | Corps | Effet |
|---|---|---|---|
| POST | /groupes/{identifiant}/compagnon-offert | {mercenaire_id, personnage_id?} | **prend** l'animal pour `personnage_id` (un héros actif DE CE JOUEUR ; absent, son premier héros) qui en devient le maître (`recruteur_personnage_id`). Gratuit : la bourse ne bouge pas. 201 `{recrue: {id, nom, type, animal: true, offert: true, recruteur_personnage_id}, or}`. 422 : offre fermée (déjà tranchée, quête lancée, quatre héros, pas au hub), `mercenaire_id` hors de `animaux` (mercenaire payant, captif, inconnu), héros qui n'est pas à ce joueur, ou un autre joueur a tranché entre-temps (compare-and-set) |
| DELETE | /groupes/{identifiant}/compagnon-offert | {personnage_id?} | **refuse** l'offre, pour tous, définitivement. 200 `{compagnon_offert: "refuse"}`. Mêmes 422 |

Diffuse `.groupe.etat`. Journal `systeme` : `compagnon_offert_pris` `{mercenaire, mercenaire_id,
recruteur_personnage_id, recruteur, texte}` / `compagnon_offert_refuse` `{personnage_id, texte}`.
L'issue est **durable en base** : `groupes.compagnon_offert` (`null` / `pris` / `refuse`).

**`POST /pret`** : **422 `pret`** tant que l'offre est ouverte (`CompagnonOffert::refusDeDepart()`
— même patron que le panier de marché : on ne bloque que celui qui se déclare prêt, un seul
geste d'un seul joueur lève le refus pour tous). Se retirer des prêts n'est jamais bloqué. Le
lancement par la **table** (`POST /quetes`, « Lancer la quête ») n'est pas un « Prêt ! » : il
passe outre et l'offre expire avec le premier lancement — la table confirme avant (voir
`docs/regles/front-manette-et-table.md`).

**L'animal offert** est un `groupe_mercenaires` ordinaire marqué `offert: true`
(`mercenairesRecrutes[].offert`) : mêmes tours, mêmes soins, même purge s'il tombe (il ne
revient pas — « une fois »), mais **exempté de l'entretien de 10 po/quête**
(`FaveursHopekins::reglerEntretien()`) : « at no cost » vaut pour tout son séjour, et sans
l'exemption un groupe à sec le perdrait à la première quête. Il compte pour l'animal unique du
groupe et pour les quatre suivants de son maître. Le snapshot de reprise sérialise `offert`.

⚠ **Le Squelette Hearthkin (First Light, FL-Q p. 6, lot C) partage ce
catalogue SANS jamais y figurer** (`mercenaires.octroi_seul`). Il n'existe
que par l'action du Cor des Hearthkin (`POST .../choix {option_id:
"utiliser_objet", parametres: {cle: "objet:{id}"}}`, catégorie `outil`,
`rarete: unique`) : une action, aucune cible — chaque héros DEBOUT de la
quête (pas celui qui a soufflé seul) place un squelette **Move 8 · Attack 2
· Defend 2 · Body 1 · Mind 0** sur une case de SA propre salle ou de son
couloir ; `recruteur_personnage_id` dit qui le contrôle. Le cor se brise
(perdu à l'usage, redevient trouvable). Depuis le 2026-10-04 (chantier 3a,
ci-dessous) il joue comme n'importe quel allié, **dans le tour du héros qui
le contrôle** — la divergence « phase alliée commune, jamais juste après son
porteur » notée ici avant cette date est **close**. Reste nommée : comme
tout allié de ce projet il n'est jamais la cible d'une attaque de monstre
(« hors périmètre v1 », voir ci-dessus) — `defense` existe sur sa fiche sans
lecteur, comme pour tout mercenaire. `POST /groupes/{identifiant}/mercenaires`
avec son id répond 422.

PNJ **scriptés** (hors roster). Au démarrage de quête ils sont instanciés sur
les cases de spawn restantes, à côté des héros. Réponse :
`{recrue:{id,nom,type,animal}, or}` ; broadcast `.groupe.etat`.

### Départ de la quête — la décision est publiée (2026-10-16, fin de campagne)

`groupe.depart_possible` (bool) et `groupe.depart_motif` (string|null), publiés **au hub seulement** (absents en
quête). Décidés par `DemarreurQuete::refusDeDepart()`, le seul point de passage : `depart_possible: false` quand la
campagne est **gagnée** (boss final vaincu, arc épuisé — `Groupe::victoireFinaleAcquise()`), avec le motif écrit par
le serveur (« La campagne est gagnée (boss final vaincu) : aucune nouvelle quête ne peut démarrer. Clôturez la
campagne. »). La **manette** masque « Prêt pour la quête ! » et la **table** masque « Lancer la quête » ; le motif
s'affiche à leur place. Le client ne redérive ni la victoire ni le motif. Les mêmes motifs refusent `POST /pret`
(422 `pret`) et `POST /quetes` (422 `groupe`) : la décision publiée et le refus serveur ne peuvent pas diverger.

### Statut de Gardien et entretien des mercenaires (chantier 1c, Wizards of
Morcar, livret G1504 p. 8-9, René 2026-10-06)

⚠ **Remplace le modèle décrit plus haut dans les versions antérieures de ce
document** (« consommés en fin de quête ») — **pour TOUS les groupes**, pas
seulement le thème `wizards_of_morcar` :

- **Statut de Gardien** (`Groupe::estGardien()`, publié `groupe.gardien` au
  hub) : le recrutement n'ouvre qu'une fois **2 quêtes achevées**
  (`quetes.etat = 'terminee'`). Calculé en direct, jamais une colonne.
  ⚠ Une campagne déjà en cours garde les mercenaires déjà recrutés AVANT ce
  chantier, quel que soit ce statut — jamais retirés rétroactivement, la
  garde ne porte que sur un NOUVEAU recrutement.
- **4 mercenaires au plus par héros recruteur** (`recruteur_personnage_id`,
  mercenaires `actif`).
- **Les mercenaires RECRUTÉS persistent désormais d'une quête à l'autre**
  (plus de purge à la victoire ni à l'échec) jusqu'à leur mort (`etat:
  'vaincu'`, retiré) — seuls les CAPTIFS scénarisés (`mercenaire.captif`,
  jamais recrutés) restent consommés en fin de quête, comme avant.
- **Entretien : 10 po par mercenaire survivant, prélevé sur la bourse
  COMMUNE à la fin d'une quête RÉUSSIE** (jamais sur un TPK — un échec peut
  encore être défait par `POST /reprise`, qui restaure le snapshot
  `debut_quete` ; facturer un entretien sur un dénouement annulable le
  double-facturerait à la victoire suivante). Payés dans l'ordre d'embauche
  (le plus ANCIEN d'abord) si la bourse ne couvre pas tout le monde ; les
  mercenaires non payés **quittent le groupe** (ligne supprimée — à
  réengager plein tarif, comme un nouveau recrutement). Annoncé : journal
  `systeme` `{action: 'mercenaire_entretien', quete_id, cout_par_mercenaire: 10,
  cout_total, or_restant, payes: [{id, nom}], partis: [{id, nom}]}`, et
  republié au hub sous `groupe.mercenaires_entretien` (même forme) **seulement
  si** `quete_id` est la **DERNIÈRE quête achevée** — sinon `null` : une quête
  sans mercenaire ne relit pas l'entretien de la précédente (2026-10-08). La
  table (écran de hub) et la manette l'affichent en bandeau d'arrivée au hub,
  par le même composant `AnnonceHub` — rendu tel quel, rien recalculé.

### Faveurs de Hopekins Rest (même chantier, livret p. 22-23)

Récompense de quête **séparée, HORS arbre de talents** — une faveur **TENUE** (durée : voir « DURÉE » plus bas ; le choix : « Choix au hub » plus bas) sur la FICHE DU HÉROS (`EtatGroupe.entites[].faveurs` en quête,
`groupe.hopekins` pour le tour de hub en cours), jamais recalculé
depuis autre chose que `personnage_faveurs` (et, pour les faveurs instantanées, le sac). Les CINQ compétences portées :
*Deadeye* (les figures ne bloquent plus la ligne de vue de ce héros pour
tirer/lancer un sort), *Weapon Expert* (+1 dé d'attaque avec le **type d'arme choisi par le
joueur au hub, en recevant la faveur** — voir plus bas ; repli : le premier type d'arme
manié si rien n'a été choisi),
*Healing Hands* (un héros adjacent tombé à 0 PV peut boire UNE potion de
soin DE CE porteur), *Hold the Line* (jet de dé de combat quand un monstre
quitte les 8 cases autour de ce héros au tour de Zargon ; sur un crâne, 1
dégât fixe), *Peacekeeper* (**compté à la mise à mort, PAYÉ à la fin de la quête réussie** — 2026-10-08 : 25 po par monstre que ce héros réduit à 0 PV PENDANT CETTE quête, « at the end of that quest », ce qui tranche l'écart de timing que la version précédente laissait à René). Compteur durable `etat_personnage_quete.monstres_vaincus` (par héros, par quête, repris par le snapshot) ; crédit au seul point de passage `MoteurDegats::infligerAMonstre()` → `FaveursHopekins::compterPeacekeeper()`, braise différée comprise ; versement `FaveursHopekins::reglerPeacekeeper()`, appelé par `ResolveurTour::terminerQuete()` seulement (jamais après un TPK), AVANT l'entretien ; jamais pour un allié, un piège, un sort du Dread ou un sbire. Annoncé au hub : `groupe.peacekeeper` = `{quete_id, or_total, versements: [{personnage_id, nom, monstres, or}]}`, bornée à la dernière quête achevée comme l'entretien ; `null` si personne n'a rien à percevoir.)

Les **QUATRE faveurs mécaniques** du lieu, portées le 2026-10-15 (René) — voir plus bas :
*Mercenary Guild* (`mercenary_guild`), *Dewdrop Inn* (`dewdrop_inn`), *Witch's Hut*
(`witchs_hut`, instantanée), *Arcane Library* (`arcane_library`).

**Forme publiée d'une faveur** (2026-10-08, un seul point de passage
`FaveursHopekins::publier()`) : `{cle, libelle, effet}` — `libelle` le nom de
la carte (ou du lieu), `effet` la phrase en clair (vocabulaire `FaveursHopekins::EFFETS`,
relu à chaque publication). Publiée sur `EtatGroupe.entites[].faveurs` (en
quête, table) **et** sur `GET /api/moi` → `joueur.personnages[].faveurs` (la
fiche de la manette, au hub comme en quête).

**Choix au hub** (René, 2026-10-16 : « Choisissons la faveur au hub si les extensions qui les utilisent sont utilisées » ;
G1504 p. 21 : « each player may select a Boon of Heroism for their hero » ; p. 23 : « At the start of each quest, a hero may
select one location to visit and receive its Boon of Heroism (if available). »). **Le tirage au hasard de fin de quête est
retiré** (décisions du 2026-10-06 et du 2026-10-15) : il n'existe plus.

- **Quelle campagne** : `FaveursHopekins::campagneAvecMorcar()`, le seul prédicat — `BestiaireGroupe::contient('wizards_of_morcar')`
  (bestiaire automatique ou boîtes cochées). Une campagne sans Morcar ne reçoit AUCUNE faveur nouvelle et rien n'est affiché ;
  une faveur déjà TENUE reste tenue (aucun retrait rétroactif).
- **Quel tour** : `FaveursHopekins::tourDeHub()` — le hub (`phase: hub`) qui suit la quête ACHEVÉE la plus récente, une fois
  Gardien (deux quêtes achevées). Le tour se ferme au départ de la quête suivante. **Jamais après la victoire finale**
  (`Groupe::victoireFinaleAcquise()`, 2026-10-16 : ni fenêtre de clôture ouverte, ni arc épuisé après `DELETE /cloture`) —
  ce prédicat unique est lu par `tourDeHub()` (donc par `/moi`, `/etat` et l'annonce) et par `choisirLieu()`, qui refuse
  (`docs/plan-wizards-of-morcar.md` §Exécution (2026-10-16, fin de campagne)).
- **Qui** : CHAQUE héros actif choisit pour SON héros, UNE fois par tour, ou renonce (« may »). La décision est écrite EN BASE
  (`personnage_visites_hopekins` : `quete_id`, `lieu` — `null` = renonce). Rien de ce tour n'est gardé en cache.
- **Liste blanche** : `/moi` → `visite_hopekins: {quete_id, lieux: [{cle, libelle, effet, disponible, motif}]}`. Les neuf lieux,
  dans l'ordre de `FaveursHopekins::TOUTES` ; `disponible` et, quand il ne l'est pas, le `motif` écrit par le serveur (« Tenue par
  Brynn. », « Vous la tenez déjà. », « Réservée aux lanceurs de sorts, à qui il reste un répertoire à prendre. », « La Potion de
  guérison n'est pas au catalogue. »). `visite_hopekins` est `null` quand il n'y a rien à décider (tour fermé, décision prise,
  campagne sans Morcar, héros inactif, aucun lieu ouvert).
- **Un lieu EST la faveur** : il remplace celle que le héros tient (DURÉE, plus bas) — relâche et pose dans la même opération.
  *Witch's Hut* est instantanée (une potion dans le sac, ne relâche rien).

| Méthode | URL | Corps | Effet |
|---|---|---|---|
| PUT | /groupes/{identifiant}/faveurs/lieu | {personnage_id, lieu: string\|null} | le héros visite `lieu` (une clé de `FaveursHopekins::TOUTES`, revalidée contre la liste blanche) ou, avec `lieu: null`, renonce. **Hub uniquement, un seul choix par tour.** 200 `{personnage_id, lieu, detail, faveurs, visite_hopekins}` (`visite_hopekins` relu : `null` après la décision). 422 : hors hub ou tour fermé (`phase`) — **y compris après la victoire finale** (« La campagne est gagnée : Hopekins Rest ne reçoit plus de visite. », 2026-10-16) —, héros qui n'est pas à ce joueur (`personnage_id`), décision déjà prise (`lieu`), lieu hors de la liste blanche (`lieu`, avec le motif en message). Diffuse `.groupe.etat`. Journal `systeme` `visite_hopekins` `{quete_id, personnage_id, personnage, lieu, detail}` — `detail` : ce qui a pris fin et ce que la faveur fait ; `lieu: null` → « … ne visite aucun lieu. ». |

- **Annonce** : `groupe.hopekins` (au hub seulement, `null` hors tour) = `{quete_id, visites: [{personnage_id, personnage, lieu,
  libelle, effet, detail}], en_attente: [{personnage_id, personnage}]}`. `visites` relit le journal `visite_hopekins` de CE tour
  (un lieu, ou `lieu: null` pour un refus) ; `en_attente` liste les héros qui doivent encore choisir. Rendu par `AnnonceHub` sur la
  table et sur la manette. `groupe.faveur_hopekins` (plus bas) ne relit plus que l'historique du journal `faveur_hopekins`.
- **`POST /pret`** : 422 `pret` — « … peut visiter un lieu de Hopekins Rest avant de partir… » — pour CE héros tant que sa visite
  est à faire. La table peut toujours lancer la quête sans passer par `/pret` (comme pour le type d'arme) : le tour se ferme alors
  sans décision pour ce héros.
- **Sous-choix** : *Weapon Expert* et *Arcane Library* gardent leur sous-choix (type d'arme, répertoire, plus bas) ; `/pret`
  reste refusé tant qu'il n'est pas tranché.
- **Repli — campagnes en cours** : la migration `2026_10_16_150000_visites_hopekins_tour_de_hub` pose
  `groupes.hopekins_quete_tranchee_id` sur la quête achevée la plus récente de chaque groupe (son tour est déjà tranché par l'ancien
  tirage) : le choix s'ouvre au hub SUIVANT, jamais en plus d'une faveur déjà tirée. Une faveur tirée avant ce changement reste TENUE.
- **Écarts nommés** : la double quête 7-8 du livret (« may not visit… between quests 7 and 8 ») n'existe pas dans le modèle (une
  quête = une carte, `docs/regles/bestiaire-et-rencontres.md`) : le hub reste ouvert entre toutes les quêtes. La récompense de
  conclusion (G1504 p. 21 : « heroes may visit 2 locations and have a maximum of 2 Boons of Heroism each on all future quests »)
  n'est pas portée : décision de René à prendre.

**DURÉE — le livret** (G1504 p. 22-23 : « At the start of each quest, a hero may select one location to
visit and receive its Boon of Heroism (if available). A hero may only benefit from one boon at a time. » ;
*Mercenary Guild* : « This Mercenary returns to the guild if you ever choose another boon. ») : une faveur est
**TENUE** jusqu'à ce que son porteur en reçoive une **autre**. Recevoir une faveur **relâche** celles qu'il tient
(`FaveursHopekins::relacherFaveursTenues()`, dans la même opération) : leur effet cesse (le mercenaire offert
retourne à la guilde, le +2 PV de *Dewdrop Inn* tombe et le plafond se réconcilie, le répertoire d'*Arcane
Library* sort du livre de sorts). Une compétence relâchée revient au tas : « (1 available) » se lit sur les
faveurs **tenues** par les héros actifs. La durée n'est **pas** « une quête » : le livret ne la donne pas.
*Witch's Hut* est **instantanée** (« Gain 1 Potion… ») : elle ne se tient pas et ne relâche rien. Les lignes
héritées d'avant ce changement (plusieurs par héros) restent en vigueur jusqu'à la prochaine réception, qui les
relâche toutes. Journal `faveur_hopekins` : son `detail` annonce ce qui a pris fin (« Albrecht perd Deadeye. »,
« Albrecht perd Dewdrop Inn. Albrecht revient à 8 PV de Body maximum. ») avant ce que la nouvelle faveur fait.
Aucune clé de payload ajoutée : le hub lit déjà `detail` (`AnnonceHub.vue`).
Qui peut choisir quoi est décidé en UN point, `FaveursHopekins::lieuxPour()` (voir « Choix au hub ») :
les cinq compétences portent « (1 available) » — indisponibles tant qu'un héros actif du groupe la tient ;
*Mercenary Guild*, *Dewdrop Inn*, *Arcane Library* sont **par héros** (la bibliothèque seulement pour un lanceur à qui il
reste un répertoire à prendre) ; *Witch's Hut* est **cumulable** (« Can be gained multiple times ») et n'écrit aucune ligne
de faveur. Le journal des décisions est `visite_hopekins` (voir « Choix au hub »). L'ancien journal `faveur_hopekins`
(`{action, quete_id, faveur, faveur_libelle, personnage_id, personnage, detail}`) ne vient plus que de l'historique ; sa
republication `groupe.faveur_hopekins` ne vaut que pour la DERNIÈRE quête achevée — même garde que l'entretien.

**Les quatre faveurs mécaniques** (René, 2026-10-15 ; livret G1504 p. 23, texte de la carte cité) :

- *Dewdrop Inn* — « Raise your hero's max Body Points by 2 ». `personnage_faveurs` (ligne unique par héros) + le
  plafond passe par `Equipement::apresChangementDeJauges()` / `bonusJauges()`, le point de passage des PV max hors
  montée de niveau : +2 au maximum **et** au courant, la capacité du sac (PV max ÷ 2) suit d'office, le +2 survit
  à un talisman posé puis retiré. **Tenue** : tant qu'elle est la faveur du héros ; la recevoir relâche la ligne,
  le plafond redescend et les PV courants s'écrêtent (même point de passage).
- *Witch's Hut* — « Gain 1 Potion of Healing ». Une **Potion de guérison** (la carte officielle, `Potion of Healing`
  du registre) entre dans le sac (`RangementObjet::ranger()`, hors capacité). **Instantanée** : aucune ligne de
  faveur ; la potion est l'état durable, boissons et Healing Hands la lisent comme toute potion.
- *Mercenary Guild* — « Recruit a Mercenary without paying their cost ». Passe par le recrutement **existant**
  (`POST …/mercenaires`, mêmes garde-fous : hub, Gardien, quatre par héros, animal unique) : seul le **prix** change
  (0, `offert_par_faveur: true` dans `groupe.recrutement.offres[].decisions[]` et `recrue.offert_par_faveur` dans la
  réponse), pour un mercenaire (**pas** un compagnon animal), un seul à la fois. La faveur retient l'allié engagé
  (`personnage_faveurs.parametre` = `groupe_mercenaires.id`) : tant qu'il sert ce héros le droit est consommé ; mort
  ou parti, il se rouvre. **Tenue** : la réception d'une AUTRE faveur renvoie le mercenaire à la guilde (sa ligne
  `groupe_mercenaires` part — « This Mercenary returns to the guild if you ever choose another boon »). La Potion de charme n'est pas consommée par un recrutement offert. **L'entretien de 10 po
  reste dû** (« cost » = le prix d'engagement de la carte ; **décision de René, 2026-10-16** : il reste dû, y compris
  pour l'allié offert par la guilde) : l'allié n'est pas `groupe_mercenaires.offert`, drapeau du
  compagnon animal « at no cost ». La fiche publie `recrutement_offert: bool` sur cette faveur.
- *Arcane Library* — « an additional set of spells that you are eligible to cast ; only for spellcasting heroes ».
  Choix du joueur, au hub, comme Weapon Expert : `faveurs[]` porte `repertoire` (l'élément pris, `null` tant qu'aucun)
  et `choix` — la **LISTE BLANCHE** `[{element, sorts: [nom…]}]`, non nulle seulement dans `GET /moi` et au hub tant
  qu'il reste à choisir. La liste est `MoteurSorts::repertoiresSupplementaires()` : pour un lanceur, les trois
  répertoires optionnels (`protection`, `detection`, `tenebres`) ; pour le Magicien et l'Elfe, en plus les écoles
  élémentaires qu'ils ne connaissent pas ; jamais un répertoire déjà connu. Le répertoire pris est ensuite un
  répertoire comme un autre (remplaçable au hub, `PUT …/sorts-repertoire`) TANT QUE la faveur est tenue ; une
  AUTRE faveur reçue le retire du livre de sorts. Écart nommé et CONSIGNÉ (René, 2026-10-16) : le répertoire
  *elfique* (3 sorts parmi 8) n'est pas offert — la carte ne le nomme pas (« an additional set of spells that you
  are eligible to cast »), et les trois répertoires nommés par le livret (Protection, Detection, Darkness, p. 10)
  sont ceux qu'elle ouvre aux lanceurs.

| Méthode | URL | Corps | Effet |
|---|---|---|---|
| PUT | /groupes/{identifiant}/faveurs/arcane-library | {personnage_id, element} | attache les 3 sorts de l'élément (`personnage_sorts`) et écrit `personnage_faveurs.parametre` (EN BASE) = l'élément, définitif. **Hub uniquement.** 200 `{personnage_id, element, sorts: [nom…], faveurs}`. 422 : hors hub, héros qui n'est pas à ce joueur ou sans la faveur, choix déjà fait, `element` hors de la liste blanche. Journal `systeme` `faveur_repertoire_choisi` `{personnage_id, personnage, faveur, element, sorts}`. **`POST /pret` : 422 `pret`** pour CE héros tant qu'il porte la faveur sans répertoire choisi et qu'il en a un à prendre. |

**Weapon Expert — le joueur choisit son type d'arme** (René, 2026-10-15 ; livret G1504
p. 22-23 : « Select a type of weapon […] when you acquire this Boon »). La faveur et son
porteur restent tirés au hasard ; le type d'arme se choisit **au hub**, dès que la faveur est
reçue (fin de quête réussie → hub), par le joueur du héros :

- `faveurs[]` (`FaveursHopekins::publier()`) porte, pour `weapon_expert` seulement :
  `specialite` (le type choisi, sous son nom d'armurerie, `null` tant qu'aucun n'est lié) et
  `choix` — la **LISTE BLANCHE** du choix à faire, `[{objet_id, nom, des_attaque}]`, ou `null`
  quand il n'y a rien à choisir. `choix` n'est non nul **que dans `GET /moi` et que pour un héros
  au hub** (`EtatGroupe.entites[].faveurs` porte `specialite` mais toujours `choix: null`) : la même décision que le PUT
  applique, le client n'a ni la phase ni le catalogue d'armes à connaître. La liste est lue
  dans le catalogue : les armes d'**armurerie** (ni artefact `unique`, ni copie en os) que le
  héros **peut manier** (`Equipement::estAccessible()`).
- **« Un type » = `Equipement::typeDArme()`** : l'arme elle-même, ou l'arme ordinaire qu'elle
  est (`est_une` : le Fléau des Orques est une épée courte), ou dont elle est la copie en os
  (`os_de` : la hache de bataille en os est une hache de bataille).

| Méthode | URL | Corps | Effet |
|---|---|---|---|
| PUT | /groupes/{identifiant}/faveurs/weapon-expert | {personnage_id, objet_id} | écrit `personnage_faveurs.parametre` (EN BASE) et le **fige** (« when you acquire »). **Hub uniquement.** 200 `{personnage_id, objet_id, specialite, faveurs}`. 422 : hors hub, héros qui n'est pas à ce joueur ou sans la faveur, choix déjà fait, `objet_id` hors de la liste blanche (artefact, copie en os, arme interdite à la classe, inconnu) |

Diffuse `.groupe.etat` (chaque manette relit `/moi`). Journal `systeme` `faveur_arme_choisie`
`{personnage_id, personnage, faveur, arme}`. **`POST /pret` : 422 `pret`** pour CE héros tant
qu'il porte la faveur sans type choisi **et** qu'une liste est possible (un seul héros bloqué,
et il suffit qu'il choisisse).

**Repli — une faveur sans choix reste jouable** (acquise avant ce changement, ou dont le
joueur n'a rien choisi parce que la table a lancé la quête sans `/pret`) : le **premier type
d'arme dont le héros frappe devient sa spécialité** (l'ancien comportement), jamais « aucun
bonus ». Une faveur que l'ancien code avait liée à une arme PRÉCISE garde ce lien, **élargi à
tout son type** (une épée courte liée vaut désormais pour toutes les épées courtes).

**Fil de combat** (2026-10-08) : les deux effets qui se déclenchent pendant
une action se disent, en direct comme à la reconnexion. Peacekeeper : journal
`combat` `{type: 'faveur_peacekeeper', action: 'peacekeeper', personnage,
monstre, vaincus_quete, or_en_attente: 25}` (rien n'est encaissé à la mise à mort), et le résultat de l'action le porte dans
`faveurs_declenchees: [même forme]` (`App\Partie\TamponFaveurs`, vidé à
l'entrée et à la sortie de `ResolveurTour::resoudre()`, comme
`charges_depensees`). Hold the Line : `{type: 'faveur_hold_the_line', action:
'hold_the_line', personnage, monstre, face, touche, degats, pv_body_apres,
vaincu}` dans les actions du tour des monstres. `JournalCombat` rend les deux
types (un crâne, un raté, une mise à mort). La braise différée garde son
auteur sur `instances_monstres.degat_differe_personnage_id` (colonne durable,
`null` quand elle est éteinte) pour créditer Peacekeeper quand elle achève la
cible.

### Sir Ragnar — captif de Wizards of Morcar (même chantier)

Même gabarit que Gothar (mission « secourir », voir plus bas) : carte
sourcée M7 A3 D5 B6 Mi2, aucune capacité. Catalogué `Sir Ragnar (Wizards of
Morcar)` — nom **distinct à dessein** du « Sir Ragnar » déjà seedé comme
monstre boss de *Rise of the Dread Moon* (`App\Models\Monstre`, table
différente, aucun conflit technique, collision purement narrative entre
deux boîtes Hasbro).

### Un allié est joué par SON JOUEUR (chantier 3a, 2026-10-04)

**Décision de René, qui remplace la « phase alliée dédiée » décrite plus
haut dans les versions antérieures de ce document** : un allié (mercenaire,
compagnon animal, ou captif libéré — §« Mission "secourir" » ci-dessous) ne
joue plus dans une phase à part après tous les héros. Il joue **dans le tour
du héros qui le contrôle** (`recruteur_personnage_id`), **juste après lui**,
depuis **la manette de ce même joueur** — un SECOND menu, pas un second
personnage : `GET /menu` et `.menu.propose` restent la même route et le même
événement, sur le canal `joueur.{id}` du CONTRÔLEUR, mais quand c'est le tour
de son allié la réponse porte en plus `allie_id` (non-null) à côté de
`personnage_id` (qui reste l'identité du HÉROS, pour l'auth — c'est toujours
sa manette). Le menu de l'allié ne porte que le **déplacement** et l'**attaque**
— **ni porte, ni potion** (Ogre Horde p. 9) : sans monstre en vue, il peut
donc se déplacer ou passer son tour, rien d'autre. Les cibles/destinations
légales sont déjà décidées par le serveur, jamais recalculées côté client :
- `type: "deplacement_case_allie"` (id `se_deplacer_case_allie`, 2026-10-17) :
  **le déplacement LIBRE de l'allié**, sur la carte. `parametres.portee` = sa
  vitesse (la `deplacement` de SA carte de mercenaire, en POINTS : le terrain
  compte comme pour un héros ; pas de dé, un allié n'a pas de jet de déplacement).
  `parametres.destinations` = `[{x, y, cout}]` : toutes les cases où il peut
  S'ARRÊTER ce tour, avec leur coût. Calculée par
  `App\Partie\DeplacementHeros::destinationsAllie()` — la MÊME grille de passage
  que le héros (`DeplacementHeros::grilleAllie()`) et le même refus d'arrêt
  (`refusArret()`) : il traverse héros et alliés sans jamais s'y arrêter, ne
  traverse ni monstre, ni meuble, ni mur, ni terrain qui l'interdit, et **ne
  franchit JAMAIS le brouillard** (une salle non révélée n'est ni destination,
  ni passage). Pas de détour autour des pièges : un allié n'en déclenche aucun
  (`MoteurPieges`, scope assumé). `POST /choix {option_id: "se_deplacer_case_allie",
  parametres: {x, y}}` : le résolveur revalide `(x, y)` contre `destinationsAllie()`
  recalculée sur l'état présent, et refuse une case hors liste (422, « Destination
  illégale ») sans rien dépenser. **Un seul déplacement par tour** (`a_deplace`),
  pleine vitesse, sans reliquat. La manette ouvre la MÊME feuille que pour le héros
  (`DeplacementSheet`), partie de la case de l'allié. Son aperçu,
  `POST deplacement/apercu {x, y}`, rend le trajet DE L'ALLIÉ tant que le menu
  courant porte `allie_id` (`pieges: []`, `traverse_roche: false` : un allié ne
  déclenche rien).
- `type: "deplacement_allie"` (id `se_deplacer_allie`) : `parametres.destinations`
  = `[{cle: "vers:{instance_id}", nom: "Approcher <monstre>"}]`, une entrée
  par monstre que l'allié peut rejoindre — un RACCOURCI vers un ADVERSAIRE à
  approcher (le déplacement libre est `se_deplacer_case_allie`) ; choisi par le
  joueur, comme l'ancien pilotage automatique. ⚠ **Offerte seulement si l'approche
  ABOUTIT** (2026-10-09, `App\Partie\ApprocheAllie`, lu aussi par le résolveur) :
  une case au CONTACT du monstre où l'allié peut S'ARRÊTER (ni figure, ni terrain
  qui l'interdit — une case prise par un héros ne compte pas), atteignable, et où
  il avance réellement. Un monstre dont tout le contact est pris n'apparaît pas ;
  une destination forgée est refusée (422, « Destination illégale »).
  `POST /choix {option_id: "se_deplacer_allie", parametres: {cle}}`. Son trajet
  passe par la MÊME grille que le déplacement libre, brouillard compris (avant
  2026-10-17 l'approche ignorait le brouillard).
- `type: "attaque"` (id `attaquer_allie`) : `parametres.cibles` = la même
  forme qu'une cible de héros (`{id, type: "monstre", nom, nom_base,
  distance}`) — adjacentes, ou visibles en ligne de mire pour un allié à
  distance. `POST /choix {option_id: "attaquer_allie", parametres:
  {cible_id, cible_type: "monstre"}}`.
- `type: "attente_allie"` (id `attendre_allie`) : termine son tour sans agir.

Chaque option consomme son créneau comme un héros (`a_deplace`/`a_agi` sur
`groupe_mercenaires`), et son tour ne se termine que sur `attendre_allie` —
jusque-là, un second appel peut encore déplacer PUIS attaquer. Un second
DÉPLACEMENT (approche ou case) est refusé en 422 tant que `a_deplace` est posé
(2026-10-17 : le résolveur le garde désormais lui-même, pas seulement le menu). Le résultat
d'un tour d'allié a la MÊME forme qu'avant (`type: "attaque_allie"` /
`"deplacement_allie"` / `"attente_allie"`, `allie_id`, `mercenaire_id`) —
mais il n'est plus NICHÉ sous `resultat.tour_allies.actions` : c'est
désormais le résultat **top-level** d'un `POST /choix` à part entière, celui
de l'allié. ⚠ **`resultat.tour_allies` a disparu** avec la phase dédiée ;
`resultat.tour_monstres.actions` reste (la phase des monstres, elle, n'a pas
changé).

⚠ **Contrôleur TOMBÉ : décision nommée, jamais un allié qui bloque le
tour.** Le serveur ne suit la présence d'aucun JOUEUR (seul le narrateur a un
heartbeat, `table:active:{id}`) — « absent » n'est donc observable, pour
cette règle, qu'à travers « tombé » (0 PV Body). Un héros tombé est sauté par
l'ordre d'initiative (`OrdreDuTour::acteurActif()`), **lui et l'allié qu'il
contrôle** : l'allié n'agit pas ce round (il attend), jamais un transfert de
contrôle à un autre joueur. Dès que son héros se relève, l'allié reprend son
tour normal au round suivant, juste après lui. → `docs/regles/combat-et-tour.md`

**Décision publiée (2026-10-08).** Le front ne calcule plus la disponibilité :
`EtatGroupe.groupe.recrutement.offres` publie, au hub, pour CHAQUE allié non
octroyé seul et CHAQUE héros actif du groupe, `{personnage_id, nom, prix,
prix_catalogue, rabais_po, offert_par_faveur, recrutable, motif}` — `prix` est ce que ce héros paie
RÉELLEMENT (remise de Potion de charme comprise, ou **0** quand la faveur *Mercenary Guild* lui
offre ce recrutement : `offert_par_faveur: true`, `rabais_po` = le prix catalogue), `recrutable` la décision,
`motif` la phrase du serveur quand elle est négative (`null` sinon). Un seul
point de passage, `App\Partie\RecrutementHub` : le POST `…/mercenaires` applique
la MÊME décision (premier motif = 422, sous la clé `groupe` / `personnage_id` /
`mercenaire_id`) et débite le `prix` publié. La manette envoie `personnage_id`
(son héros, celui dont elle affiche la ligne). La table liste les renforts
embauchés (`groupe.mercenaires`).

Dans **EtatGroupe.entites** (en quête), un allié posé apparaît avec `type:'allie'`
(`{id, nom, x, y, pv_body, pv_body_max, animal, image_url, des_attaque, des_defense}` —
les dés pour la fiche de stats ouverte depuis la barre d'initiative). **Au hub** (carte absente, donc
hors `entites`), les recrues actives sont exposées dans le préambule sous
`groupe.mercenaires: [{id, mercenaire_id, nom, type, animal, pv_body,
pv_body_max, image_url}]` (mis à jour en direct par `.groupe.etat` après un recrutement).

**Illustration des alliés (René, 2026-10-01 : « des illustrations pour les
alliés quand ils font des actions »).** `image_url` vient de
`BibliothequeImages::urlMercenaire()` — `catalogue/mercenaires/{id}-{slug}`,
emblème SVG `allie` (le buste du camp des héros) en repli, jamais `null` pour un
allié. L'action `attaque_allie` porte désormais `mercenaire_id` et `allie_id`
(`groupe_mercenaires.id`) : la scène de table (`scenes[].acteurs[]`) illustre
l'attaquant par **son** image, avec **ses** PV — elle montrait jusque-là le
portrait du héros qui contrôle l'allié, sous le nom de l'allié. Elle porte aussi
les **faces des dés** (`faces_attaque`, `faces_defense`, `face_touchante`,
`face_defensive`, `ResultatAttaque::pourJournal()`, comme une attaque de
monstre) : la scène d'un allié n'affichait aucun dé.

**Deux attaques par tour (Ours polaire de guerre, 2026-10-04).** Une
`attaque_monstre` peut porter `mode: "deux_attaques"` et `repartition` :
`"une_cible"` = **une seule** action dont la volée compte le **double** des dés
d'attaque contre **un** jet de défense (« only 1 defend roll against that monster
per turn », Frozen Horror p. 9) ; `"deux_cibles"` = **deux** actions (`coup` 1
et 2) dans un `actions_composites`, une par héros au contact. Remplace
`mode: "massive" | "double"` (capacité `choix_attaque`, retirée).

**Les monstres attaquent les alliés (René, 2026-10-04).** Un monstre vise la
figure **la plus proche**, héros ou allié (à distance égale, le héros) ; un
archer, la plus **faible** en vue, allié compris. L'attaque sur un allié est
une action `attaque_monstre` dont la `cible` porte `{type: "allie",
allie_id, mercenaire_id, nom}` au lieu de `personnage_id`, plus
`pv_body_apres` et **`allie_vaincu`** (bool) : à 0 PV, l'allié passe
`vaincu`, quitte la carte et disparaît de `groupe.mercenaires` et de
`initiative`. L'allié se défend avec **ses** dés aux boucliers **blancs**,
comme un héros (errata 2021). La scène de table le montre en `defenseur`,
avec son image et ses PV. ⚠ Les capacités spéciales (étreinte, frappe de
zone, choix tactique, vol, accroche) et les sorts de Dread visent encore les
**seuls héros** — limite nommée.

### Mission « secourir » (chantier 3b, 2026-10-04 ; DEUX MODES depuis le
chantier « captifs-jetons », 2026-10-05)

Nouveau type d'objectif de quête (`quete.objectif = "secourir"`, voir
§« Objectif de quête » plus haut) : un **captif** sourcé (Gothar, *The Frozen
Horror* p. 19/37 ; le Prospecteur et la Princesse Millandriel, *The Mage of
the Mirror* p. 4) est posé dans la salle-objectif de la quête à
l'assemblage, comme un coffre. Il apparaît dans `EtatGroupe.entites` avec
`type: "captif"` (`{id, nom, x, y, pv_body, pv_body_max, image_url}` — pas de
dés d'attaque/défense, il ne se bat pas), **caché tant que sa salle n'est pas
découverte** (même garde que les monstres dormants). `quete.objectif_libelle`
nomme le captif dès qu'il est posé (« Retrouver Gothar et le ramener vivant à
l'escalier. »).

Un héros à son contact voit l'option `type: "liberer_captif"` (id
`liberer_{allie_id}`, `parametres.allie_id`) — GRATUITE depuis le 2026-10-15
(`creneau: "interaction"` : ni déplacement ni action, voir « `creneau` »). `POST /choix {option_id: "liberer_{id}"}` le libère :
le résultat porte `{type: "captif_libere", personnage, allie, allie_id,
mercenaire_id, mode: "figurine"|"escorte"}` — **la DÉCISION publiée côté
serveur**, jamais à re-dériver de `allie_id`. Deux profils, deux devenirs :

- **figurine** (Gothar) : `groupe_mercenaires.etat` passe de `"captif"` à
  `"actif"`, `recruteur_personnage_id` = ce héros, et il rejoue désormais
  comme n'importe quel allié (§ ci-dessus) — plus jamais sous
  `type: "captif"` dans `entites`, il bascule sous `type: "allie"`.
- **escorté** (le Prospecteur, la Princesse Millandriel — tuiles SANS carte,
  « acts as an ally and is controlled by the hero who finds him/her »,
  *Mage of the Mirror* p. 4) : `etat` passe à `"porte"`. Jamais une
  figurine : il disparaît purement et simplement de `entites` (ni
  `"captif"`, ni `"allie"` — aucun tour, aucune case, aucune cible pour les
  monstres). Il **se voit sur le héros qui le porte** : l'entité
  `type: "heros"` de ce héros porte désormais `captif_porte: {id, nom,
  image_url}` (`null` sinon) — rendu identique table et manette.
  ⚠ **Si ce porteur tombe** (quelle qu'en soit la cause), le captif est
  **REPRIS** — « monsters take the prospector to room D » (p. 23,
  généralisée à Millandriel) : `etat` revient à `"captif"`, SUR SA CASE
  D'ORIGINE (jamais déplacée depuis sa pose), `recruteur_personnage_id`
  redevient `null`. **Jamais un échec de quête** — à libérer de nouveau,
  comme au premier donjon. Annoncé (`{type: "captif_repris", personnage:
  "<nom du porteur déchu>", allie, allie_id, mercenaire_id}`), vérifié et
  journalisé à la fermeture du round (`ResolveurTour::ouvrirNouveauTour()`,
  même round-boundary que la détection de TPK) — le payload remonte sous
  `resultat.tour_monstres.actions` (round qui enchaînait une phase de
  monstres) ou `resultat.captifs_repris` (round sans monstre, mais où le
  porteur est quand même tombé — piège, terrain…). ⚠ **Depuis le 2026-10-09,
  `resultat.captifs_repris` porte TOUTES les annonces d'ouverture d'un round
  sans monstre** (captif repris, `rupture_sort_dread`, `tour_perdu`,
  `regain_corps`) — nom historique conservé — et le fil de combat la lit comme
  les phases de monstres (`JournalCombat::actionsDuTour()`). Avant cette date
  personne ne la lisait : ces annonces étaient muettes dans tout round où le
  groupe n'avait plus de monstre.

`quete.objectif_accompli` vaut `true` dès qu'il est **libéré, vivant, ET
ramené à l'escalier d'entrée** (chantier escalier-entrée, 2026-10-05 —
§« Escalier d'entrée et sortie du donjon » plus bas ; la sortie DU DONJON
elle-même suit ensuite le vote ordinaire, comme tout autre objectif) ;
`false` tant qu'il est captif, ou libéré mais pas encore à l'escalier. En
mode **escorté**, c'est le PORTEUR qui doit se tenir sur l'escalier — le
captif lui-même n'a pas de case à atteindre. **S'il meurt** (mode figurine
seulement — un captif escorté n'a pas de PV à perdre, il ne peut qu'être
REPRIS, jamais tué), la quête **échoue immédiatement**, même verdict et même
cérémonie qu'un TPK (retour au hub, alliés consommés, snapshots conservés
pour `/reprise`) : « escort the Barbarian… If the Barbarian dies, Gothar is
automatically captured » (Frozen Horror p. 19), généralisé à toute mort du
captif désigné — mais uniquement quand ce captif EN A (mode figurine).

⚠ **Simplification nommée** : la mission n'est pas filtrée par thème de
bestiaire (un captif de n'importe quel profil peut apparaître, habillé par
l'IA, dans un donjon d'un autre thème que le sien) — inchangé par ce
chantier. → `docs/regles/combat-et-tour.md`, `docs/regles/exploration-et-fouille.md`

### Escalier d'entrée et sortie du donjon (2026-10-05)

Chaque quête pose désormais un **escalier en colimaçon 2×2** dans sa salle de
départ (salle 0) — le repère du plateau d'origine, posé par
`AssembleurCarte::placerEscalier()` sur un bloc de sol intérieur, hors case de
porte, le plus proche du centre de la salle. **TRAVERSABLE** (on s'y tient,
contrairement au mobilier bloquant ou à un mur de glace) : il ne retire
aucune case libre à la salle — « connecté n'est pas jouable » tenu PAR
CONSTRUCTION. Toujours visible (la salle 0 est tenue pour découverte dès le
départ).

- **EtatGroupe.carte** gagne `escalier: {x, y, l: 2, h: 2} | null` — `null`
  sur une carte assemblée AVANT ce chantier (campagne EN COURS dans la vraie
  base), ou en repli défensif si la salle de départ ne contenait aucun bloc
  2×2 valide (jamais atteint avec le plancher actuel des tuiles, 2×3 minimum).
- **On ne quitte le donjon QUE tous réunis dans la SALLE DE DÉPART** (la salle
  qui contient l'escalier ; décision de René du 2026-10-10 après le verdict
  Jungle, qui remplace « tous sur l'escalier » du même jour et « un seul sur
  l'escalier » du 2026-10-05) : `quitter_donjon` n'est offert que lorsque
  **tous les héros debout** sont dans cette salle, en plus des conditions déjà
  en vigueur (objectif accompli ou donjon vidé, pas de vote déjà ouvert). Les
  héros **tombés** (mode Story) ne bloquent pas. Un seul point de passage
  (`Quete::rassemblementDepart()`, salle trouvée par `Salles::indexDe()`), lu par
  le menu, par `ResolveurTour::resoudreQuitterDonjon()` (422 « Rejoignez la salle
  de départ pour quitter le donjon — il manque : … ») et par le payload.
- **`quete.sortie`** (publié par le serveur, jamais recalculé par le client) :
  `ouverte` (objectif accompli, ou donjon vidé quand cela suffit),
  `salle_depart_requise` (`false` sur une carte sans escalier : repli),
  `rassemble` (tous les héros debout y sont), `absents` (noms des héros debout
  hors de la salle), `consigne` (phrase prête à afficher, **non nulle seulement
  si la sortie est ouverte ET que quelqu'un manque** ; la même est ajoutée à la
  `situation` du menu), `consigne_extraction` (mission « secourir » : « Amenez Gothar sur
  l'escalier d'entrée. » tant que le captif libéré n'est pas sur l'escalier ; en mode escorté, c'est
  son porteur). La bannière d'objectif (table ET manette) affiche les deux.
  ⚠ **Deux vérifications DISTINCTES** (René, 2026-10-10) : les HÉROS n'ont qu'à être tous dans la salle
  de départ ; la personne à sauver doit, elle, être menée **SUR une case de l'escalier**
  (`captifLibereEtVivant()` — inchangé).
- **`battre_en_retraite` reste SANS AUCUNE condition** (René, 2026-08-21,
  inchangé) : décrocher doit rester possible au pire moment, loin de
  l'escalier.
- **Mission « secourir » = extraction, pas seulement libération**
  (§ ci-dessus) : `Quete::captifLibereEtVivant()` exige désormais que le
  captif libéré et vivant se tienne **sur l'escalier** (inchangé ; René a confirmé le 2026-10-10 de ne PAS l'assouplir en « salle de départ ») — généralise
  « escort » (Frozen Horror p. 19) à une vraie extraction plutôt qu'à la
  seule libération. `objectif_libelle` dit « … et le ramener vivant à
  l'escalier. ». **Mode escorté** (§ ci-dessus) : c'est la position DU
  PORTEUR qui compte, le captif n'en a plus.
- ⚠ **Repli écrit et testé pour les cartes déjà assemblées SANS cette
  couche** (campagnes EN COURS dans la vraie base) : `Carte::casesEscalier()`
  rend `[]`, et chaque lecteur (`MenuMoteur`, `ResolveurTour`,
  `Quete::captifLibereEtVivant()`) retombe alors sur le comportement d'avant
  (sortie possible n'importe où, mission accomplie dès la libération seule)
  — jamais une quête en cours rendue impossible à terminer.
- Rendu : silhouette DÉDIÉE, une teinte dorée discrète sur l'emprise 2×2 — pas
  un bloc plein comme le mobilier ou la glace, puisque l'escalier se
  traverse. `ESCALIER_ICONE` (`'stairs'`) dans `symboles.js`, lue par
  `DungeonGrid` (table ET manette, prop `stairs`), par `LegendeCarte` et par
  `ApercuSalle` (section dédiée quand il touche la salle du héros actif).

**Un allié traverse les héros et les autres alliés** (2026-10-01) — pas les
monstres, pas les meubles —, sans jamais s'arrêter sur une case occupée. Dans
un couloir d'une case, un héros posté entre lui et le monstre le laissait
**immobile** (`allie_immobile`) trois rounds de suite (test en jeu) : il
s'approche désormais aussi loin que la route le permet — ou, depuis le
2026-10-04, c'est le JOUEUR qui choisit vers quel adversaire approcher
(`se_deplacer_allie` ci-dessus) ; `allie_immobile` ne peut plus survenir
(aucun monstre atteignable → aucune destination proposée, jamais une option
que le résolveur refuserait).

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

### Le fil de combat annonce TOUT effet automatique (2026-10-09, verdict Morcar §1-2)

Règle dure : **un effet automatique que rien n'annonce est injouable**. Le fil
(`.combat.journal` en direct, `journal_combat` au rejeu) est dérivé du résultat
de l'action par `JournalCombat` ; deux portes silencieuses le rendaient muet, et
toutes deux sont fermées.

- **Le registre `JournalCombat::TYPES`** liste chaque `type` d'action que le
  moteur peut rendre : `true` (une phrase existe dans `ligneType()`) ou une
  CHAÎNE (muet par choix écrit — la chaîne est la raison). Un type absent n'est
  plus le silence : le fil rend « Un effet automatique vient de se produire
  (type) » et le journal d'application consigne un avertissement.
  `AnnoncesEffetsAutomatiquesTest` parcourt les sources : tout `'type' => '…'` de
  `app/Partie` doit figurer au registre, tout type déclaré doit exister (les deux
  sens), et chaque type annoncé rend une vraie ligne.
- **Les listes d'actions du résultat** sont toutes parcourues
  (`JournalCombat::PHASES` : `tour_monstres`, `tour_allies`, `tour_sbires` ;
  `JournalCombat::LISTES_PLATES` : `captifs_repris`, `coups_de_corne`,
  `filets_silencieux`, `meteores`, `annonces_automatiques`) et les actions IMBRIQUÉES aussi :
  `action` (l'embusqué joue tout de suite), `embuscades` et `apparitions` (d'un
  déplacement ou d'une action), `actions_composites`.
  `resultat.annonces_automatiques` est neuf : le tampon générique
  (`TamponAnnonces`) pour les effets qu'aucune action ne retourne (objet volé
  perdu de vue, détection de la Potion de vision).
- **Le rejeu relit `combat`, `action` ET `jet`** (`JournalCombat::TYPES_EVENEMENT`),
  fenêtre de 100 événements, 24 lignes rendues. Il ne relisait que `combat` : un
  sort de soin, un buff, un levier, une fouille, un déplacement de monstre ou un
  sort de contrôle du MJ sont journalisés `action`/`jet` et n'apparaissaient donc
  JAMAIS à un joueur qui rafraîchissait ou arrivait en retard. Un payload
  journalisé à part ET imbriqué dans celui de son parent n'est dit qu'une fois
  (dédoublonnage par contenu).
- **Ce qui s'ÉCOULE, s'ÉTEINT ou APPARAÎT sans qu'aucune action le retourne** (verdict
  Jungle 2026-10-10 §1). Le registre couvrait les TYPES d'action, pas les effets de
  DURÉE : le tic du poison, sa fin, la rupture de « Renforcé » au premier dégât, le
  sort qui revient, la nouvelle forme d'un boss, son apparition, la clôture d'un vote
  tombaient sans une ligne. Ils passent désormais par UNE porte,
  `TamponAnnonces::annoncer($groupe, $payload, $acteur)` : écrit au journal (type
  d'événement `action`, rejoué par `journal_combat`) ET rendu au fil en direct — rangé
  dans `resultat.annonces_automatiques` pendant une résolution (`resoudre()` ouvre la
  fenêtre), diffusé tout de suite (`.combat.journal`) sinon (ouverture de menu, vote,
  réaction). Chaque payload porte sa phrase DÉCIDÉE par le moteur (`texte`) ; le client
  ne la recompose pas. Types ajoutés au registre :
  - `condition_terminee` `{personnage_id, personnage, condition, origine, pourquoi, texte}`
    (ou `{monstre, instance_id, condition, pourquoi, texte}` pour un monstre) — fin d'un
    buff (`DureeEffet::libelleFin()` : « rompu : premier dégât subi », « dépensé par son
    attaque »…) ou d'une condition à durée entière (« prend fin : la durée est écoulée »).
    Point de passage : `MoteurSorts::retirerBuff()` / `decrementerDurees()` /
    `decrementerDureesMonstres()`.
    ⚠ **`plus_de_monstre_en_vue` tombe AU MOMENT où la vue du porteur se vide** (Courage :
    « The spell is broken the moment a monster is no longer in the hero's line of sight »,
    reference/16 §3bis ; potions de rage et de peau de givre : « as soon as »). Ce n'est
    plus au début du tour du porteur : `MoteurSorts::rompreBuffsDeVue()` est lu à la fin de
    chaque résolution (après l'action ET la phase des monstres, qui déplacent les figures)
    pour TOUS les héros, et au tour du porteur. Un buff de vue qui tombe emporte la seconde
    frappe qu'il armait (`attaque_supplementaire`), sauf celle d'une gorgée de Potion
    d'héroïsme bue ce tour.
  - `breuvage_dissipe` `{personnage_id, personnage, texte}` — une Potion d'héroïsme bue ce
    tour dont la seconde frappe n'a pas eu lieu : l'effet se dissipe à la FIN DU TOUR du
    héros (`MoteurPotions::dissiperBreuvageNonUtilise()`, appelé par `marquerCreneau()`), ou
    à l'ouverture du round si le tour s'est refermé sans « Terminer le tour » (filet de
    `ouvrirNouveauTour()`). Texte : « Albrecht : l'effet de « Potion d'héroïsme » se dissipe,
    faute d'attaque » (nom du catalogue, comme le menu). Une gorgée suivie de sa seconde
    frappe n'annonce rien.
  - `attaque_supplementaire_perdue` `{personnage_id, personnage, texte}` — un héros SE DÉPLACE alors que sa
    seconde frappe est en attente (décision de René, 2026-10-22) : la frappe tombe, et le fil le dit au geste.
    Texte : « Albrecht se déplace : son attaque supplémentaire est perdue. ». Point de passage UNIQUE :
    `ResolveurTour::resoudre()`, après la résolution réussie d'une option `mouvement` et avant `marquerCreneau()`
    (qui ne redit donc plus `breuvage_dissipe` pour la même gorgée). Le drapeau tombe, la marque `breuvage:{nom}`
    s'efface sans bruit (`MoteurPotions::oublierBreuvage()`), puis l'annonce passe par `TamponAnnonces`.
  - `saignement` `{personnage_id, personnage, cause, degats, pv_body_apres, tombe, reste, texte}`
    — poison, étreinte, rejetons, Chambre forte de glace, en fin de tour
    (« Tamsin : Empoisonné — −1 PV (5/8, encore 1 tour) »). `degats` = ce que le moteur a
    RETENU (réductions et réactions comprises).
  - `sort_regagne` `{personnage_id, personnage, sort, regain, texte}` — `RegainEffet::libelle()`
    (la Métamorphose revient quand le Body repasse au maximum).
  - `changement_phase` `{instance_id, nom, phase: {avant, apres, stats, sorts_termines?, flamme?}, texte?}` — l'annonce
    AUTONOME d'un changement de forme (écrite par `MoteurDegats`, pour les appelants qui ne
    relaient pas `changement_phase`) ; le rejeu l'écarte quand le coup qui l'a provoquée la
    porte déjà (`JournalCombat::signatureDeDoublon()`).
  - `reaction_monstre` `{instance_id, nom, mecanique}` — la défense à usage unique, avec le
    NOM de la créature.
  - `sans_effet` `"source" | "invulnerable" | null` (chantier 2026-10-23, *Return of the Witch Lord*) — un
    COUP qui n'entame rien : le jet se lit, `degats` vaut 0. `source` : l'arme employée n'est pas de
    celles qui blessent cette créature (Seigneur Sorcier : Lame des Esprits, Trait de Feu, Boule de Feu,
    Dague de jet magique) ; `invulnerable` : aucune arme ne la blesse (statues, non semées). Annoncé
    (« … cette arme ne le blesse pas », « … sans l'entamer »). Un sort refusé n'a pas de payload : il n'est
    pas OFFERT par le menu (`MoteurSorts::ciblesLegales()`).
  - `arme_brisee` `string | null` — le NOM de l'arme qu'un coup a brisée contre une créature invulnérable,
    sauf exemptée (Lame des Esprits). L'arme a quitté l'inventaire ; la ligne annonce « X se brise ! ».
  - `monstres_reveles` `{boss: [nom], monstres: [nom], texte}` — la salle qui se dévoile
    (« Gruulob apparaît, entouré de 2 Gobelins ! »).
  - `vote_resolu` `{vote, option_id, applique, decompte, texte}` — voir §Votes.
  Le `changement_phase` d'une action porte désormais **`stats`** :
  `{avant: {attaque, defense}, apres: {attaque, defense, pv_body, pv_body_max}}` (dés
  EFFECTIFS, effets de quête et sorts compris) ; la ligne devient « X vacille — et se
  relève sous une autre forme : Y ! (Attaque 3 → 4 dés, défense 4 → 5 dés, Body 3/3) ».
- **Potions bues** : `resultat.potion` porte `buveur` et `porteur` (noms) et, quand un buff
  est posé, `effets.buff_texte` (la liste `MotsClesEquipement::avantages()`). Le fil dit
  « Albrecht boit Fiole de soin : +4 PV de Body (dé 4) — Albrecht est à 6/8 PV »,
  « …Potion de défense : « Renforcé » : +2 dés de défense, Durée : prochaine défense »,
  « …Potion d'héroïsme : une seconde attaque ce tour ».
  ⚠ **La Potion d'héroïsme se boit AVANT la frappe** (2026-10-16, cartes 2021 et 2024 :
  « Drink this potion before you attack to make two weapon attacks instead of one »). Une
  gorgée ni offerte (`utiliser_objet` absente du menu) ni acceptée (422) une fois le héros
  AYANT AGI ce tour, ou quand une seconde frappe est déjà armée (drapeau
  `attaque_supplementaire` posé) : une seule gorgée par frappe. C'est `MoteurPotions::refusAvantAttaque()`,
  lu par `offrable()` (menu) et `boire()` (résolveur). Une gorgée déjà bue sous l'ancien ordre
  garde sa seconde frappe (le drapeau n'a pas changé de sens, seule la prochaine gorgée est gardée).
  ⚠ Le libellé de l'entrée le dit (`detail` : « Boire avant d'attaquer : une seconde frappe, ce
  tour seulement »). Une gorgée jamais suivie d'une frappe SE DISSIPE AU FIL (`breuvage_dissipe`,
  voir les annonces) au lieu de disparaître en silence à l'ouverture du round.
  Le drapeau est remis à zéro à chaque manche (`ouvrirNouveauTour()`) : une gorgée bue puis non
  suivie d'une frappe dans le même tour est perdue — limite nommée, non tranchée.
- **Fouille** : un monstre errant nomme le fouilleur (« Gobelin surgit du coffre que fouille
  Tamsin ! ») ; `carte_ecartee` (*Sixième sens*) se dit au fil : « Sixième sens : Tamsin remet
  la carte de piège sous le paquet et en tire une autre ». Seul un **piège** s'écarte (décision
  de René, 2026-10-10) : un monstre errant tiré reste tiré, sans dépenser la capacité.
  La règle a UN seul point de passage (`ResolveurTour::piocherAvecSixiemeSens()`), commun à la fouille de
  salle (`fouille_tresor`) et à celle d'un meuble (`fouille_mobilier`) : `carte_ecartee` se publie et se
  dit pareil dans les deux cas.
  Un `deplacement` qui porte `pieges_pressentis` (*Sens du piège*) se dit : « Tamsin pressent un piège caché tout près —
  (x, y) (Sens du piège ; il reste caché) » ; le piège reste `cache` sur la carte.
- **Un coup de monstre dit ce qu'il POSE et COMMENT il frappe** (partie du 2026-10-10).
  `attaque_monstre` (et chaque entrée de `frappe_de_zone.resultats[]`) publie
  **`conditions_posees`** : `[{nom, description}]`, la liste des conditions que CE coup vient
  de poser sur le héros (`nom` et `description` du catalogue `conditions`) — le venin
  (`Envenimé`, `venin: true`) et l'étreinte du Yéti (`Agrippé`, `etreinte_etablie: true`) à
  ce jour ; vide quand rien n'a été posé (coup manqué, venin résisté). Le fil la dit à la
  suite du coup, avant « s'effondre » : « Brokk est Envenimé — paralysé par le venin : il ne
  peut pas se déplacer, jusqu'à la fin du tour suivant ». ⚠ Toute condition qu'un coup de
  monstre pose s'ajoute à cette liste, à l'endroit où elle est posée
  (`MoteurDread::conditionPosee()`). **`portee`** (`"distance"` | `"corps_a_corps"`) est lue
  par le fil : à distance, « Gretzl tire sur Brokk (−2 PV) » / « …— et le manque » / « Brokk
  pare le tir de Gretzl » ; une `charge` n'est jamais un tir.
- **Le NOM d'un monstre, partout, est celui de l'ÉCRAN** (`InstanceMonstre::nomAffiche()` :
  habillage IA, ★ d'élite) — jamais `monstres.nom_base`. Valable pour toute étiquette d'un
  payload lu par le fil ou la table (`monstre`, `cible.nom`, `engendre.nom`, `invoques[].monstre`,
  `piege_explosion.cibles[].nom`, `changement_phase.avant`). Un test balaie les sources.
  Pour `changement_phase`, l'habillage vit sur l'INSTANCE : il survit au changement de
  forme. `avant` est donc le nom affiché, et `apres` le nom de la NOUVELLE FORME du
  catalogue quand l'instance est habillée (« Le Cœur-Liane Éveillé vacille — et se relève
  sous une autre forme : Demonspider ! »), sinon le nom affiché de la nouvelle forme.
- **Nouveaux payloads rendus** (tous dans `tour_monstres.actions` sauf mention) :
  `deplacement_monstre` (« avance de N cases »), `repli_tireur`, `monstre_reveille`
  (**retourné** désormais, devant l'action du réveillé, via `actions_composites` ;
  il n'était que journalisé), `monstre_enfume`, `monstre_enchaine`,
  `etreinte_maintenue`, `frappe_de_zone`, `charge`, `capacite_dread` (porte
  `monstre`), `spawn`, `rejeton_accroche`, `regeneration`, `vol_objet`,
  `objet_perdu` (via `annonces_automatiques`), `sbire_controle`
  (`tour_sbires`), `captif_libere`, `captif_repris`, `commandement_*`,
  `soin_allie`, `concentration`, `sacrifice_sort`, `s_ecarter_du_bloc`, `retraite`,
  `sortie`, `poussee`, `ouvrir_porte`, `oracle_salle`, `lacher_souris`, `detacher_rejetons`,
  `briser_glace`, `deplacement_allie`, `attente_allie`, `pieges_detectes` /
  `portes_secretes_revelees` (`methode: "clairvoyance"` seule : la fouille et
  l'Œil du mineur se disent déjà).
- **Passif jusqu'à déclencheur (Kellar's Keep, 2026-10-23)** : `monstre_s_eveille`
  (`{monstre, id, cause}`, `cause` ∈ `attaque` | `groupe_reuni` | `fouille`) — dans
  `tour_monstres.actions` quand l'éveil a lieu à la phase du MJ, dans
  `annonces_automatiques` quand il a lieu pendant l'action d'un héros (frapper, fouiller,
  via `TamponAnnonces`) ; `attaque_mind_monstre` (`{monstre, cible: {id, nom,
  personnage_id}, des: [face…], cranes, perdus, choc}`) dans `tour_monstres.actions`, après
  l'action de la figure. Une figure INTOUCHABLE (gargouille-statue, Bellthor avant son coup)
  n'est publiée par aucun champ : le menu ne lui offre aucune attaque, et `frapper()` la refuse.
- **Sorts de héros sans dégât** : le fil dit l'EFFET, plus « X lance Y » —
  soin (`+N PV de Body`, `releve`), soin de zone (`soignes[].releve`), Mind
  restauré, tour supplémentaire, mur posé, porte ouverte à distance, sort mental
  (voir ci-dessous) et soutien. Un soutien (Courage, Voile de Brume, Traverser la
  Pierre) publie `condition`, `source` et **`duree_texte`** (« jusqu'à sa
  prochaine attaque », « pendant 2 tours »), phrase décidée par
  `DureeEffet::libelle()` — un client ne la redérive pas.
- **En-tête des sorts de Dread** : chaque payload `sort_dread` / `sort_dread_annule`
  d'un tour de boss porte **`lanceur: {instance_id, nom}`** (posé une fois par
  `MoteurDread::signerLanceur()`). Le fil dit toujours une ligne
  « `<lanceur> — <sort>` » (+ « : N héros pris dans le sort » à partir de deux),
  puis une ligne par victime. En direct, l'acteur du fil est le HÉROS qui vient
  de jouer : il ne nomme plus jamais le sorcier, ce qui donnait « Maître des
  orages — » devant Foudroiement au rejeu et rien devant Tremblement de terre.

**Résultats contradictoires corrigés** :

- **Sommeil** (héros → monstre) : `effet_applique` dit si la condition est EN
  VIGUEUR après le lancer. Rompue sur-le-champ (`rupture_immediate: true`, un 6),
  il vaut **`false`** ; `condition` reste la condition VISÉE (même convention que
  les sorts de Dread, `resultats[].effet_applique: false` + `rupture_immediate.rompu`).
  Le fil dit « X subit Sommeil (Endormi)… puis s'en libère aussitôt · dés de rupture 2, 6 ».
- **« Résiste »** : `des.libelle_def` d'un jet de dés rouges dit ce qui s'est
  passé — « résiste » (rien ne passe), « résiste en partie » (des PV passent
  malgré des 5-6), « ne résiste pas » (tout passe). Un jet de Mind lit `issue`
  (`resiste` → « résiste », `subit_effet` → « ne résiste pas »).
- **Un seul jeu de dés** : `resultat.des_resistance` (la volée brute du moteur) et
  `des.def` sont **le même jet**, octet pour octet (test épinglé). `des.defensive`
  n'est PAS un jet : c'est l'ensemble des faces qui COMPTENT (`[5, 6]` pour le dé
  rouge, `FaceDeCombat` pour un jet de combat). La manette ne lit que `des`.
- **Fouille de zone** : `issue` ne dit plus « réussite » quand rien n'est trouvé —
  voir la décision du même jour plus bas (`reussite` = trouvé, `rien` = recherche
  réussie sans rien trouver, `echec` = jet raté ; `a_trouve` et `succes` inchangés).
  Le fil dit « bien cherché — aucun piège ni passage secret ici » (réussite sans trouvaille) ou « jet raté — rien n'est révélé (la zone peut encore cacher… ) » (échec) — deux phrases de sens opposé (2026-10-10). Dans `des`, `boucliers` d'un jet de Mind de compétence est désormais le COMPTE des faces gagnantes (crânes), plus un drapeau 0/1.
- **Levier** : un levier dont toutes les portes sont déjà ouvertes n'est plus
  proposé (`MoteurPortes::levierAOuvrir()`) ; le fil du forçage réussi sans porte
  dit « le passage est déjà ouvert ».

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

**Neuf actions** de réaction existent aujourd'hui (`App\Engine\ReactionEffet`),
et la proposition porte laquelle dans `action` — le libellé du bouton en
dépend, « annuler les dégâts » étant faux pour la plupart d'entre elles :

| `action` | Carte | Ce qu'elle fait |
|---|---|---|
| `annule_degats` | *Dark Wings*, *Twisting Torrent* ; **talents achetés** *Roc* (Nain), *Évanescence* (Rogue), *Bouclier du serment* (Chevalier), une fois par quête, proposés au PORTEUR (`capacite` nomme le talent, sans `reaction: true`) | rend les PV du coup |
| `plancher_pv` | *Inébranlable* (Chevalier), *Cendres du Phénix* | les PV tombent à **1**, pas au-dessus — proposée **seulement** sur un coup mortel ; côté artefact, un dé de 5-6 détruit la pièce (`de_artefact`, `artefact_perdu`) |
| `annule_degats_voisin` | *Parade au bouclier* (Chevalier) | annule le coup d'un héros **au contact** : la proposition va au PROTECTEUR, les PV rendus à la victime (`victime_id`) |
| `riposte` | *Représailles* (Berserker) | **n'annule rien** : le Berserker encaisse et attaque aussitôt le monstre (`instance_id`), adjacence revérifiée à la résolution |
| `defi_errant` | *Défi du chevalier* | détourne sur soi le monstre errant qui vient de surgir : il se place au contact et frappe immédiatement |
| `soin_urgence` | — (potion / sort du héros) | le héros vient de **tomber** : il dépense une potion ou un sort de soin pour rester debout |
| `relance_attaque` | *Bouclier de l'Aube* (artefact, 2026-09-16) | rend les PV puis force le MONSTRE à relancer TOUTE sa volée d'attaque, défense rejouée — « en mieux comme en pire » (`des_attaque`/`des_defense` du `contexte` de la proposition) |
| `reflet_sort` | *Bâton Ancien* (artefact, 2026-09-16) | renvoie un sort de Dread (dégâts compris, et les sorts de CONTRÔLE sans dégât via `MoteurDread::sortDreadControle()`) au lanceur et à sa salle ; le porteur et ses compagnons y sont immunisés |
| `relance_benediction_oracle` | **Bénédiction de l'Oracle**, option (b) (First Light, FL-Q p. 6, lot C 2026-09-30) | rend les PV puis rejoue TOUT le jet de Défense avec des dés neufs — le nouveau résultat remplace l'ancien SANS CHOIX (« keeping the second result obligatorily », un gamble, pas une relance du meilleur) ; la Bénédiction se consomme qu'elle serve ou non. ⚠ SCOPÉ à la Défense — Attaque et Mouvement sont des dettes nommées, voir `docs/regles/artefacts.md` |
| `relance_jet` | **Vision du futur** (*Future Sight*, Wizards of Morcar, 2026-10-08) | relance TOUS les dés d'UN jet du héros — attaque, défense ou déplacement — juste après qu'il est tombé ; voir §Vision du futur |

⚠ `relance_attaque` et `reflet_sort` existaient déjà (2026-09-16) mais étaient
absentes de ce tableau — corrigé au passage du lot First Light C.

⚠ `defi_errant` a un **déclencheur à part** (`errant_revele`) : c'est la seule
réaction qui ne parte pas d'un coup encaissé, mais d'une carte de fouille qui
fait surgir un errant dans la salle. `degats` y vaut 0 — la manette doit donc
lire `action` avant d'écrire « tu viens d'encaisser… ».

⚠ **Le défi ne décide pas seul de la frappe du surgissement** (2026-10-16, carte
2024 *Wandering Monster* : « The monster immediately attacks »). Tant que la
proposition est ouverte, le monstre **n'a pas frappé** et ne joue pas au tour des
monstres (`MoteurReactions::instancesEnAttenteDeDefi()`). La réponse tranche :
- **accepté** → le monstre se place au contact du Chevalier et le frappe (`frappe`,
  comme aujourd'hui) ; rien n'est dû au fouilleur ;
- **refusé**, **expiré** (rattrapé à la lecture d'état) ou **impossible à placer** → le
  monstre frappe le **fouilleur**, si celui-ci est toujours à son contact : la
  réponse porte `active: false` et `frappe` (l'`attaque_monstre` avec
  `apres_defi`). Sans frappe due (le monstre n'était pas au contact au surgissement, ou
  le fouilleur s'en est éloigné), la réponse n'a pas de `frappe` : le monstre jouera au
  tour des monstres, comme tout errant.

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
attend — `annule_degats`, `plancher_pv`, `annule_degats_voisin`, `soin_urgence`,
`relance_attaque`, `reflet_sort`, `relance_benediction_oracle`, `relance_jet` (ni `riposte` ni
`defi_errant` : ils frappent, ils ne relèvent personne). La
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

### Vision du futur (*Future Sight*, 2026-10-08) — `action: relance_jet`

« This spell may be cast at any time and does not take an action. You may re-roll
all dice for any one attack, defense or movement roll. Discard after use. »
**Décision de René : la relance est proposée JUSTE APRÈS le jet** — le résultat
est montré au héros qui connaît le sort (non encore dépensé cette quête), le
serveur attend sa réponse **avant de l'appliquer**, et s'il relance, TOUS les dés
de ce jet sont relancés et le nouveau résultat s'applique. Le sort est défaussé
quand il sert, jamais quand on le refuse. Neuvième action de réaction ; son champ
**`jet`** dit lequel des trois.

**Deux sources, une seule offre** (2026-10-08, `MoteurReactions::sourceVisionDuFutur()`) :
le héros reçoit la relance s'il **connaît** le sort (grimoire, non épuisé et non oublié
pour la quête), ou s'il **porte** son parchemin `Parchemin : Vision du futur` au sac. Le
grimoire passe d'abord — le sort se défausse par son `disponible`, le parchemin se
**retire du sac** quand il sert. Une relance dont le parchemin a disparu entre l'offre et
la réponse est refusée : l'action reprend avec son jet d'origine, jamais une relance
gratuite.

| `jet` | Quand | Ce qui est suspendu | Ce que la relance remplace |
|---|---|---|---|
| `attaque` | le héros frappe (`POST /choix`) | **l'action entière** : `resultat.type = "jet_en_attente"`, rien n'est écrit (ni dégâts, ni mort, ni butin, ni créneau) | les dés d'attaque du héros — la défense du monstre, déjà tombée, est gardée |
| `deplacement` | le d6 du tour tombe, à l'ouverture du tour du héros | le héros ne peut RIEN jouer (`/choix` → 422) ; les effets du jet (usure des Bottes, rupture d'Évanescence) ne s'appliquent qu'à la réponse | tous les dés de déplacement du tour |
| `defense` | un monstre blesse le héros, pendant la phase des monstres | **rien** — voir ci-dessous | les dés de défense du héros — l'attaque du monstre, déjà tombée, est gardée |

⚠ **Pourquoi la défense n'est pas suspendue** : la phase des monstres se résout
dans la requête d'un *autre* joueur, à l'intérieur d'une transaction (voir
« Ordre des opérations »). Le coup est donc appliqué puis défait si on relance — la
même couture que la Bénédiction de l'Oracle. L'attaque et le déplacement, eux, se
jouent dans la requête de leur auteur : l'attaque s'**interrompt** au milieu de son
jet (`ResolveurTour::frapper()` → `JetEnAttente`), la transaction est annulée, et la
réponse **rejoue** l'action avec la volée que le joueur a vue (refus) ou des dés
neufs pour son seul camp (acceptation).

**La proposition** (`.reaction.proposee` sur `joueur.{id}`, et
`EtatGroupe.entites[].reaction_en_attente`) porte, en plus des champs communs :

- `jet` ∈ `attaque | defense | deplacement`, `sort` (« Vision du futur » ; « parchemin de Vision du futur » quand la relance vient du sac) ;
- `parchemin` : `true` si la relance vient du sac (le parchemin sera retiré à l'acceptation), `false` si elle vient du grimoire ;
- `des` : les dés **que le héros peut relancer** (faces de `FaceDeCombat`, ou des
  entiers pour le déplacement) ; `des_adverses` : la volée d'en face, qui ne sera
  **pas** relancée ; `touchante` / `defensive` : la face qui compte pour chacune
  (décidées par le moteur, jamais redéduites) ;
- `resume` : la phrase du résultat, **décidée par le serveur** (« Tu touches 3 fois,
  Gobelin pare 1 : 2 points de dégâts. »).

⚠ `EtatGroupe` ne publie **jamais** `reprise` (l'action à rejouer : option du menu,
paramètres, jets tombés) — ce n'est pas une information de jeu — ni `inventaire_id`
(la ligne du sac d'un parchemin de relance : identifiant interne, relu par le serveur
seul à l'acceptation).

**La suspension d'une attaque.** `POST /choix` répond `202` avec
`resultat: {type: "jet_en_attente", jet: "attaque", sort, faces_attaque,
faces_defense, face_touchante, face_defensive, resume, expire_dans}` et **`des: null`**.
Le menu reste en cache (la reprise le consomme) ; aucune narration, aucun menu
suivant, aucune ligne de combat : l'action n'a pas eu lieu. Tant que l'offre attend,
toute autre action du héros est refusée en 422.

**La réponse** — `POST /reaction {personnage_id, accepte}`, comme toute réaction —
rend `{reaction: {type: "reaction", action: "relance_jet", jet, active, sort, …}}` :

- `attaque` : `reaction.resultat` et `reaction.des` sont ceux qu'aurait rendus
  `/choix` (la manette les révèle comme après n'importe quel choix) ;
- `deplacement` : `reaction.des_deplacement`, `reaction.total` (le jet retenu) ; un
  nouveau menu part avec la portée décidée par ce jet ;
- `defense` : `degats_annules`, `degats_relance`, `pv_body_apres`, `faces_attaque`,
  `faces_defense`, `texte`.

**Refus par défaut, jamais un groupe figé.** Fenêtre `FENETRE_SECONDES` (45 s).
Une réponse **tardive** vaut un refus (et non un 422, contrairement aux autres
réactions : jeter l'action suspendue la perdrait). Si personne ne répond,
`rattraperExpiration()` **reprend** l'action avec le jet d'origine — mêmes appelants
que ci-dessus (battement de cœur de la table, `GET /etat`).

`relance_jet` compte parmi les actions qui suspendent le TPK (la défense relancée
peut relever un héros). Hors périmètre, nommé : les jets de défense contre un sort
de Dread, un piège ou un tir ami (ils n'ont pas la volée d'un monstre à rejouer), la
flèche de Vindication et la Dague de jet magique (aucun jet d'attaque), et les
attaques d'un allié ou d'un mercenaire.

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
({decompte, exprimes, attendus}), `.vote.resultat` ({option_id, applique, **texte**}) puis
`.groupe.etat` si l'état a changé.

**Résolution d'un vote — une LIGNE, et elle survit au retour au hub** (verdict Jungle
2026-10-10 §1). `resultat.texte` est la phrase décidée par `VoteGroupe::annoncerResolution()`
(« Vote de retraite (0 recommencer, 0 arreter, 3 continuer) : on continue — rien ne change, la
quête se poursuit » ; « Vote de sortie (3 oui, 0 non) : le groupe quitte le donjon et rentre au
hub »). Elle est journalisée (`action`, payload `vote_resolu`) et diffusée au fil
(`.combat.journal`) AVANT d'agir — un « continuer » ne change rien sur le plateau, c'est
pourquoi il faut le dire. Pour le vote de SORTIE appliqué, le fil de la quête n'est plus lu une
fois au hub : l'état du hub porte `groupe.vote_sortie` = `{quete_id, texte, decompte, applique}`
(`null` sinon), borné à la dernière quête achevée comme les autres annonces de fin de quête,
rendu par `AnnonceHub`.

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

**Les pièges croisés en chemin se DISENT tous, par leur type** (partie du 2026-10-10).
Un `deplacement` porte `pieges_declenches[]` (ce qui a blessé, retenu, téléporté…),
`pieges_esquives[]` (les lianes esquivées sur un bouclier : le héros CONTINUE, le jet a
eu lieu et le piège est devenu connu de tous) et `pieges_reveles[]`/`pieges_pressentis[]`
(détection). Le fil rend `pieges_esquives` PUIS `pieges_declenches`, chaque entrée par
sa phrase de type (`piege_esquive`, `piege_teleporte`, `piege_ignore`…, jamais toutes par
celle de la fosse) — « Piège de lianes jaillit — Tamsin esquive les lianes et poursuit sa
route ». La scène de table (`.table.scene`, genre `piege`) lit les MÊMES clés dans le même ordre
(`JournalCombat::piegesCroises()`) : l'esquive y a sa scène (« Piège de lianes esquivé »). Même règle pour un déplacement FORCÉ : `sort_dread.repousse.declenchements[]`
(Ouragan) et `possession_deplacement.pieges_declenches[]` (nouveau) portent aussi les
fosses ignorées et les lianes esquivées croisées sur la route.

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
**désamorcé** / **franchi** / **déclenché** — ou, pour la Fosse, **fosse
ouverte** (`fosse_ouverte`), et pour la Chute de blocs, **bloc** (`bloc`). L'état des pièges vit dans la carte
de la quête.

- **Déclenchement** : un héros qui entre sur la case d'un piège **caché OU
  détecté** (déplacement traversant inclus) le déclenche : effet du tableau ci-dessus, puis **fin du tour** du héros (livret p. 14) ;
  `piege_a_lances`/`chute_de_blocs` à usage unique. Journal + narration.
  ⚠ **Détecté compris depuis le 2026-09-27** (René : « il est plutôt facile de
  contourner les trappes ») : un piège connu se foulait sans rien subir, ce qui
  vidait le saut et le désamorçage. Le **trajet** (`deplacement/apercu` et la
  résolution, même point de passage `ResolveurTour::cheminDuHeros()`) évite les
  pièges détectés quand un détour est **payable** ; sinon il les traverse, et
  l'aperçu les liste dans `pieges`. Viser la case d'un piège, c'est choisir d'y
  marcher. Aucun champ ne change.
- **Désamorcer** (option de menu si adjacent à un piège détecté) : jet de Body
  difficulté 1, réservé au Nain OU à un porteur de la Trousse à outils ; échec
  → le piège se déclenche sur le désamorceur (choix MVP, question ouverte n°3).
- **Franchir un piège détecté franchissable** (`franchir_{x}_{y}`, option de
  menu si adjacent) : la **Fosse**, et depuis le 2026-09-27 la **Chute de
  blocs** tant qu'elle n'est pas tombée (livret p. 14, « sautée avant
  déclenchement ») — jamais le Piège à lances. Jet de Body difficulté 2
  (départ playtest) ; échec = le héros atterrit sur le piège et le déclenche.
  Sur une Chute de blocs ratée il se retrouve **sur le bloc** : `piege_a_ecarter`
  est posé et le menu ne contient plus que `s_ecarter_du_bloc`, comme après un
  déclenchement en marchant.
- **Couloirs** : une ou deux voies (LR p. 11), tirées **une sur deux** par
  couloir (`AssembleurCarte::CHANCE_VOIE_UNIQUE`). Un couloir à voie unique ne
  reçoit **jamais** de Chute de blocs : tombée, elle fermerait le passage à
  jamais.
- **`mare_acide`** (The Crypt of Perpetual Darkness, G1798 p. 5, chantier EXT-CRYPTE 2026-10-23) : la Mare d'acide
  déclenchée reste au sol, armée, connue et sautable — elle se déclenche à CHAQUE pas (`MoteurPieges::ETAT_MARE`), et
  n'est PAS une fosse : son libellé et sa légende ne disent pas « y marcher fait tomber », elle ne reçoit ni
  l'immobilisation (`immobilise: false`) ni les protections contre les fosses. Publiée sous son propre état.
- **EtatGroupe.carte** gagne `pieges: [{x, y, etat: "detecte|fosse_ouverte|mare_acide|desarme|declenche|bloc",
  nom, zone?: [{x, y}]}]` — les pièges **cachés n'y figurent jamais** (la table ne les montre
  pas). EtatGroupe.entites héros gagne `niveau`. `zone` (Lame balançoire
  seulement, voir plus bas) n'est publié que sous les **mêmes règles de
  brouillard et de fouille** que `x`/`y` — une zone non détectée reste absente.
- **`fosse_ouverte`** (René, 2026-09-27 : « avoir un état différent sur la
  carte pour identifier un piège détecté sans être déclenché ») : une Fosse
  DÉCLENCHÉE ne repasse plus à `detecte`. Le trou reste (livret p. 14) mais il
  ne se lit plus comme un piège intact : `detecte` veut désormais dire
  **détecté et jamais déclenché**, sans exception. Une fosse ouverte reste
  **armée** (y marcher la déclenche à nouveau) et **se saute**, mais **ne se
  désamorce pas** (« non applicable une fois déclenchée — le trou reste »,
  doc 16 §7.3) : le menu n'offre plus `desamorcer_{x}_{y}` sur elle. Les
  quêtes déjà en cours gardent leurs fosses ouvertes sous `detecte` : rien ne
  permet de les distinguer après coup.

### Against the Ogre Horde — la Lame balançoire et la Fosse des ténèbres (livret F9528 p. 4-5, lot B, 2026-10-02)

Deux pièges `boite: "horde_ogre"` — posés sur la carte **seulement** si le
thème de bestiaire du groupe inclut cette boîte, même lecture que
`Terrain::boite` (§Symboles de la carte et légende).

**Lame balançoire** (*Swinging Blade Trap*, p. 4-5) : le PREMIER piège du jeu
à occuper **plusieurs cases**. Une case de déclenchement (« gold overlay »)
et une **zone** de 3 cases (la case de déclenchement incluse), ligne
horizontale ou verticale selon ce que la salle offre au tirage — forme non
sourcée par le livret (porté sur un plan imprimé que les donjons générés ne
reprennent pas), décision de jeu documentée dans `AssembleurCarte::placerLameBalanciere()`.
Jamais posée en couloir, jamais sous le plancher de cases jouables
(`CASES_JOUABLES_MINIMUM`, §2.12 ter). Au plus une par carte.
- Détection : identique aux autres pièges de sol, « Fouiller la zone » sur la
  salle qui contient la case de déclenchement (la zone y est forcément
  entière, par construction).
- Déclenchement (marcher sur la case dorée, OU échec de désamorçage) : **2
  dés d'attaque** de Zargon contre **chaque héros présent sur une case de la
  zone**, qui se défend normalement (`desDefenseHerosDetail`) — à la
  différence du Piège à lances/Chute de blocs, qui ne lancent **jamais** de
  défense. Payload : `{type: "piege_declenche", zone: true, piege, personnage
  (le déclencheur), cibles: [{personnage, des_attaque, des_defense,
  faces_attaque, faces_defense, degats, pv_body_apres, tombe}, ...]}`. ⚠ Reste
  **armée** après (rien ne limite son usage dans le texte) : aucun changement
  d'état n'est écrit, contrairement à la Fosse ou au Piège à lances.
- Désamorçage — procédure **dédiée**, jamais le jet de Body ordinaire : le
  **Nain réussit automatiquement**, sans aucun dé (`{methode:
  "nain_automatique", succes: true}`) ; tout autre héros habilité (Nain,
  Explorateur, Trousse à outils, nœud *Désamorçage*) lance **UN SEUL dé de
  combat** — bouclier (blanc ou noir) = désarmée, crâne = **la zone entière se
  déclenche aussitôt** (`{methode: "trousse_de_combat", face, declenchement}`,
  sans exception du nœud « Désamorçage » qui adoucit l'échec sur les autres
  pièges — le texte ne connaît ici aucune exception).
- `EtatGroupe.carte.pieges[]` gagne `zone: [{x, y}]` sur cette seule entrée,
  publiée **seulement** une fois le piège lui-même connu (même garde que les
  autres pièges — un piège caché ne publie jamais sa zone non plus).

**Fosse des ténèbres** (*Pit of Darkness*, p. 5) : variante de la Fosse — même
cycle, même `franchissable` (jet de Body pour sauter une fois détectée), mais
:
- **jamais désamorçable** (`desarmable: "non"` — colonne existante, désormais
  lue par `MenuMoteur::generer()` : l'option `desamorcer_{x}_{y}` n'apparaît
  **jamais** sur elle) ;
- dégâts de chute **selon l'armure portée au moment de la chute**, et non
  plus 1 PV fixe : 1 PV (aucune armure, ou non métallique), 2 PV (armure
  métallique), 3 PV (Armure de plates). Le payload `piege_declenche` est
  inchangé dans sa forme (`degats`), seule la **valeur** varie.

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

### Wizards of Morcar — pièges magiques, coffres, potions et artefacts (lot 1b, 2026-10-06)

Trois pièges `boite: "wizards_of_morcar"`, tous `detectable: false` — **ne se
révèlent JAMAIS** (fouille, Œil du mineur, Potion de Vision, Sens du piège)
et n'apparaissent donc **jamais** dans `EtatGroupe.carte.pieges`, même sous
l'état `cache` : la carte ne publie que ce qui a été trouvé ou déclenché, et
ces trois-là ne sont trouvés que par accident (y marcher).

- **Piège de téléportation** (*Teleport Trap*) : paire A/B posée à
  l'assemblage. Marcher sur A téléporte sur B (si libre et dans une salle
  découverte, sinon rien ne se passe et le piège reste armé), **désoriente**
  (le tour se termine — héritée du mécanisme générique « un piège de sol
  vient de se déclencher »). Payload : `{type: "piege_teleporte", piege,
  personnage, destination: {x, y}}` (ou `{..., teleportation_echouee:
  true}` si la destination est refusée) ; `resultat.vers` du déplacement
  **est** cette destination, pas la case du piège.
- **Piège de l'ouragan** (*Hurricane Trap*) : posé en **couloir** seulement.
  Repousse **tous les héros** présents dans le couloir, à l'opposé du
  piège, jusqu'à 8 cases ou jusqu'au premier mur/meuble/piège rencontré —
  **scope assumé : les héros seulement**, aucun piège de sol de ce
  catalogue n'affecte un monstre ou un allié. Payload : `{type:
  "piege_bourrasque", piege, personnage, destination: {x, y} (le
  déclencheur), repousses: [{personnage_id, de: {x,y}, vers: {x,y}}, ...]}`
  (les AUTRES héros repoussés — chacun doit apparaître pour que leur propre
  manette explique le déplacement qu'ils n'ont pas demandé).
- **Piège d'embrasement** (*Fireburst Trap*) : marcher dessus **n'inflige
  rien tout de suite** — il s'AMORCE (`{type: "piege_amorce"}`, `degats: 0`)
  et explose au **DÉBUT DU TOUR SUIVANT du MJ** (`ResolveurTour::phaseMonstres()`,
  tout en tête) sur **toute la salle ou le couloir** où le jeton couvait :
  3 dés d'attaque de feu, **défense normale**, contre **tous les héros ET
  tous les monstres actifs** de la zone. Publié via le journal/`actions[]`
  de la phase des monstres : `{type: "piege_explosion", piege, cibles:
  [{type: "heros"|"monstre", ..., degats, pv_body_apres|vaincu}, ...]}`.
  `immunite_degat: "feu"` (Anneau de Feu, **ou désormais une potion** — voir
  plus bas) intercepte toujours avant le producteur. **Désamorçage par un
  sort** (Magic Reference Chart, relu à l'image 2026-10-08 : « discards a Tempest
  spell or any Water Spell, the trap is disarmed ») : une option de menu
  `desarmer_embrasement`, **créneau gratuit `interaction`** (« discards » rejoint
  `jeter`), une par (jeton, sort) LÉGAL : `parametres: {piege_index, piege: {x,
  y}, sort_id}`. Légal = jeton encore `amorce` dans la zone du héros ET sort
  connu, non épuisé cette quête, de nom *Tempête* ou d'élément `eau`. Le sort
  est épuisé (`disponible: false`), le jeton passe `desarme` : jamais
  d'explosion. Résultat : `{type: "piege_desarme_embrasement", piege, personnage,
  sort: {id, nom}}`. ⚠ *Tempête* est d'élément `air` dans `SortSeeder` : la règle
  le NOMME, ce n'est pas un « eau » par élément.
- La carte de trésor **« Magical Trap »** (×2 dans le deck Morcar) et
  **« Poison »** référencent chacune un piège par NOM (`piege_de_coffre`-style,
  `declencherEphemere()`) : « Magical Trap » rejoue la mésaventure du Piège
  d'embrasement **sur le fouilleur seul, sans défense** (divergence nommée —
  la salle entière reste le privilège de la version posée sur la grille) ;
  « Poison » lance 1 dé de combat, 1 crâne = 1 PV de Body. La carte
  « Nothing! » n'a pas de ligne dédiée : c'est déjà l'issue `rien`.

**Coffres renforcés** (« Reinforced Chests », livret p. 6) : AUCUN changement
de contrat — c'est déjà la règle de **tous** les coffres depuis le
2026-10-02 (« Chests and the supply crate are searched AT CONTACT ») : un
héros adjacent fouille le coffre lui-même (sa table), un héros non adjacent
fait « Fouiller — trésor » (le deck ordinaire). Les deux coexistaient déjà.

**Cinq potions** (`boite: "wizards_of_morcar"`) : trois en boutique (Potion
de résistance au feu/de prédisposition magique/de résistance à la magie,
300/400/300 po — en rayon dans toute campagne : le marché ne filtre pas par
`boite`, décision de René du 2026-10-09),
deux en carte de trésor SEULEMENT, jamais achetées (Potion d'alchimie,
Potion de charme — `rarete: "unique"`). `MoteurPotions::boire()` gagne trois
effets : `second_sort_par_tour` (potion) rejoint le nœud *Réserve arcanique*
et la Baguette de Rappel comme TROISIÈME source du même bonus
(`bonus_sort_utilise` — jamais trois sorts cumulés) ; `annule_prochain_sort_degats`
annule le prochain sort de Dread à dégâts de BODY (`MoteurDread::sortDreadDegats()`,
AVANT `immunite_degat`, pour les sorts sans `type_degat` comme *Death Bolt*) ;
`transmute_equipement_en_or` (Potion d'alchimie) défausse la première pièce
d'équipement du buveur contre de l'or — **choix non exposé au joueur**
(aucun paramètre d'API, auto-sélection : la première pièce, portée d'abord
— déséquipée au passage —, sac ensuite ; aucune → `transmute: {piece: null,
or: 0}`, annoncé). L'or va à la bourse commune (`groupe.or` + 100), annonce :
ligne `usage_objet`. La **Potion de charme**
(`rabais_recrutement_mercenaire: 25`, `recrutements_a_rabais: 3`) se boit **au
hub seulement** (`POST /groupes/{identifiant}/potions/boire-au-hub`). Elle écrit
un ÉTAT DURABLE sur le héros (`personnages.recrutements_a_rabais` += 3,
`rabais_recrutement_po` = 25 — migration `2026_10_08_100000`), que `POST
/groupes/{identifiant}/mercenaires` consomme UN par recrutement, dans la même
transaction que l'or : 25 po de moins, jamais davantage. Publié sur `/moi` :
`rabais_recrutement: {restants, po}` et `consommables[].boire_au_hub`. ⚠ Première
version fausse (remise permanente tant que la fiole était possédée), corrigée le
2026-10-08.

**Artefact Drakehide Cuirass** (*Cuirasse de Peau de Dragon*) : armure non
métallique, +1 dé de défense (`des_defense`, clé de PIÈCE — `bonus_des_defense` n'est
lu que sur les potions, les buffs et les améliorations de Forge, jamais sur une pièce :
corrigé le 2026-10-09), **déplacement
FIXE de 8 cases** (`deplacement_fixe`, nouveau — remplace tout le calcul
base+1d6+Raquettes+Bottes+menace, lu au seul point qui calcule ET persiste
le jet du tour), interdite au Magicien (`objets.classe_interdite`, nouvelle
colonne, migration additive — un VETO absolu, premier contrôle de
`Equipement::estAccessible()`). **Urdyn the Unmaker reste NON porté** : son
bonus contre Dreadshifter/Golem spécifiquement suppose ces deux monstres au
catalogue (capacité *Ambush*, hors de ce chantier) et un mot-clé de bonus
conditionné au TYPE de monstre adverse qui n'existe pas encore.

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
- Sept effets, vocabulaire fermé `App\Engine\MotsClesEpreuve` (registre vérifié
  dans les deux sens, lecteur déclaré confronté à son fichier) : `or`, `objet`,
  `parchemin`, `soin_groupe`, `retire_condition`, `desarme_pieges_salle`, et
  depuis le lot First Light C (2026-09-30) `oracle` — la SEULE qui paie sur
  l'ÉCHEC autant que sur la réussite (Bénédiction / Malédiction de l'Oracle,
  voir §entites[].benediction_oracle plus bas) : `resultat.oracle` vaut
  `"benediction"` ou `"malediction"` selon l'issue du jet.
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

**Voile d'ombre (2026-10-08).** **EtatGroupe.carte** gagne `ombre: [{x, y, l, h,
jetons, jetons_max, lanceur}]` — la couche `carte.grille['ombre']` posée en cours de
quête par le sort de héros *Cloak of Shadows* (« Voile d'ombre »), **jamais** le
catalogue `terrains` ni le mobilier. Un rectangle par voile (`x`,`y` = coin haut
gauche, `l`×`h` = 3×2 ou 2×3 — taille mesurée sur le livret G1504 p. 4, voir
`MoteurOmbre`), son **compteur** `jetons`/`jetons_max` (3) et le prénom du lanceur.
Brouillard : un voile est publié si AU MOINS UNE de ses cases n'est pas brouillée,
critère de la glace et des leviers. `lanceur_id` n'est pas publié.

Ce que le voile FAIT, et qui est lu par le moteur seul (rien à recalculer côté
client) : « heroes and monsters on the tile may not attack or be attacked » —
`MoteurSorts::attaqueInterdite()` (le héros dessus : plus aucune option d'attaque
au menu, 422 au résolveur), `estInattaquable()` (le héros dessus n'est la cible d'aucun
monstre), `MoteurOmbre::contientMonstre()` (le monstre dessus n'est une cible d'aucune
arme ni d'aucun rayon, et ne frappe pas) ; « the darkness blocks line of sight into
and through it » — `FabriqueGrille::pour()` seul, via `Grille::assombrir()`. On
**marche** dessus. « At the start of the spellcaster's turn, remove a shadow token »
— à l'ouverture du tour du lanceur (une fois par round) ; un lanceur tombé perd son
jeton à l'ouverture du round ; au dernier le voile disparaît de la couche. Chaque
jeton retiré est un événement `ombre_decompte` (`{personnage, jetons, dissipee,
texte}`) au journal et au fil du combat ; un monstre qui ne frappe pas à cause du
voile est un `monstre_dans_l_ombre`. Rendu : zone sombre translucide + chapelet de
pastilles (jetons) sur la table et la manette, entrée « Voile d'ombre » dans la
légende. Hors périmètre, nommé : les attaques d'un allié ou d'un mercenaire contre
une cible sous le voile, ou depuis lui.

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

**Sorts de Dread illustrés** (2026-10-16). Chaque carte de la magie du MJ (`sorts_dread`)
a son gabarit `sort_dread` (`config/images.php`), et son sujet est le **TYPE** de son
effet (`config('images.sorts_dread_effets')`, confronté à `MotsClesSortDread::TYPES`
**dans les deux sens**) — jamais `effet`, qui porte les dés et les crânes qu'un prompt
ferait peindre en chiffres sur la carte. `BibliothequeImages::urlSortDread()` rend
`catalogue/sorts_dread/{id}-{slug}` ou, à défaut, l'emblème `sort_dread` (un œil) : `null`
seulement quand la carte n'existe pas. Deux lecteurs, et c'est voulu : la **scène de
table** `sort` (`objets[].image_url`, pour les sorts qui jettent un dé — un sort SANS dé
n'a pas de scène, voir §`.table.scene`) et **`GET /api/guide`**, `cartes[].image_url` du
paquet `dread`, où figurent les 63 cartes du paquet. `images:generer --type=sorts_dread` (inclus
dans `tous`) les produit.

**Mobilier — emprise en MOTS** (2026-10-16). Le gabarit `mobilier` ne reçoit plus
« 3 × 2 » mais `{forme}` (`BibliothequeImages::formeMobilier()` : « une fois et demie
plus large que haut : son côté le plus long est HORIZONTAL dans l'image »), parce qu'un chiffre dans
un prompt se fait peindre sur le meuble. Il ne prend plus non plus le style à torches
(`images.style`) : c'est lui qui peignait un brasero dans la bibliothèque. Les meubles
déjà générés gardent leur ancienne image tant qu'on ne les relance pas.

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

**Symboles de livret — le pictogramme officiel de chaque case** (chantier symboles, René, 2026-10-24 : « les vrais symboles des livrets à la place des icônes »). Chaque famille de la carte peut afficher le PICTOGRAMME imprimé sur le livret de quête, à la place de l'icône Material Symbols. Le serveur publie `symbole_url` (`string|null`, **toujours présent**, `null` par défaut) sur :
- `carte.mobilier[]` : chaque meuble, d'après son nom de catalogue, avec **`symbole_rotation`** (`0` ou `90`, **toujours présent**) : l'image du livret dessine le meuble dans le sens de son CATALOGUE (`largeur`×`hauteur`), et la pose le couche ou le dresse au hasard. `90` quand l'emprise POSÉE (`l`×`h`) est la transposée de celle du catalogue ET qu'elle n'est pas carrée (un râtelier 1×3 posé 3×1), `0` sinon. Le client tourne le pictogramme de 90° dans son emprise ; il ne décide jamais de la rotation ;
- `carte.terrain[]` : **case par case**, selon la famille de la plaque (décidé par `EtatGroupe::terrain()`). **Une case isolée** (sans `groupe_id`) porte le sien. **Une plaque répétée** (`motif` du catalogue : Jungle, Toile, Sable) porte le sien sur **chaque case** (René, 2026-10-24 : « l'icône doit se répéter sur chaque case ») ; son `etendue` est `null`. **Un bloc fixe** (Mare, Brasier : `motif` nul) ne porte son dessin que sur son **ancre**, et ce dessin couvre l'emprise entière (`etendue`), comme un meuble sur sa silhouette ; les autres cases portent `null` ;
- `carte.pieges[]` : les états `detecte`, `desarme` et `retient` seulement. Un trou ouvert (`fosse_ouverte`), une mare d'acide et un bloc tombé se dessinent sans symbole : la silhouette EST la case ;
- `carte.portes[]` : par ÉTAT — `fermee`, `ouverte`, `verrouillee`, `secrete`, et `verrou_pierre` pour une porte de pierre encore fermée (une porte de pierre forcée n'est plus qu'une `ouverte`) ; chaque porte porte aussi **`symbole_rotation`** (`0` ou `90`, **toujours présent**) : le dessin du livret est une porte de mur HORIZONTAL (battant couché, nord-sud). `90` quand la porte relie l'EST et l'OUEST (percée dans un mur vertical), `0` au nord-sud. La décision se lit sur les deux cases de la jonction (`Grille::casesPorte()`, `MoteurPortes::rotationSymbole()`) ; le client tourne le battant comme un meuble (`SymboleCarte`, `tourne`), il ne la recalcule jamais ;
- `carte.leviers[]` ;
- `carte.escalier` : l'objet entier, `null` s'il n'y en a pas ;
- `carte.dangers[]` : les états `roule` et `actif` seulement. Un rocher arrêté (`bloc`) est un bloc plein, sans symbole, comme la Chute de blocs.

Un fichier par symbole : `public/images/livrets/{type}/{slug}.webp`, `{type}` ∈ `mobiliers`, `terrains`, `pieges`, `portes`, `leviers`, `escalier`, `dangers` (`BibliothequeImages::TYPES_SYMBOLE`, fermé), `{slug}` = `Str::slug()` de la clé : le nom de catalogue (`Forge des nains` → `forge-des-nains`) ou le libellé fixe (`portes/verrou-pierre.webp`, `leviers/levier.webp`, `escalier/escalier.webp`). Seul le `.webp` est servi. **Les symboles sont DANS le dépôt** (René, 2026-10-24) ; le repli reste obligatoire : sans fichier, `symbole_url` vaut `null` et le client garde l'icône d'aujourd'hui — jamais un emblème SVG, qui prétendrait « symbole manquant » là où aucun n'était attendu.

Le client **ne compose aucune URL** : il dessine la `symbole_url` reçue sur la case (tournée de `symbole_rotation` pour le battant d'une porte), ou sur l'étendue (`etendue`) qu'un bloc fixe reçoit de son ancre. ⚠ Un symbole est un **pictogramme** : il se pose SUR la carte, à l'intérieur de la silhouette de sa famille. Une **illustration** (`image_url`) est une image peinte : elle reste dans la LÉGENDE et l'aperçu de salle, à côté du symbole, jamais à sa place. Hors phase 1, sans symbole : épreuves, mur de glace, voile d'ombre, monstres.

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
  Une tentative par héros et par monstre. ⚠ **Le résultat publie POURQUOI** (2026-10-17) :
  `motif` = `jet_rate` (le jet n'atteint pas le requis — `jet.issue` = `reussite_mixte` pour
  un « raté de peu », jamais une réussite : P4 tranchée) ou `case_bloquee` (jet réussi, mais la
  case de recul est occupée ou hors plateau : le résolveur ne déplace RIEN) ; `null` quand la
  figure recule. Le fil écrit « 3 succès sur 4, raté de peu — Gobelin ne recule pas » à partir de
  `jet.succes`, `jet.difficulte`, `jet.issue` et `motif`. Le prédicat de recul libre est UN seul,
  `App\Partie\Poussee::reculLibre()`, lu par le menu (`ciblesRepoussables()`) et par le résolveur.
- **Fracasser un meuble** — option `detruire_mobilier_{index}`, type `jet` Body,
  difficulté `mobiliers.difficulte_destruction` (⚠ `null` = **indestructible**).
  La pièce cesse de bloquer mouvement ET vue ; si elle était **fouillable**, elle
  rend **une dernière fouille** à son destructeur, même déjà vidée par le groupe.
- **Attaquer un meuble** (2026-10-04, PAS un jet de Body — ce n'est PAS le même
  bullet que ci-dessus) — option `attaquer_mobilier_{index}`, type
  `attaquer_mobilier`, offerte quand `mobiliers.pv_body` n'est pas `null`
  (Crystal Cluster, Haut Autel, Coffre du Dread). Troisième voie de
  destruction : le meuble s'épuise **au combat**, comme un monstre — pas de
  tentative limitée par héros, retentable sans limite tant qu'il tient encore.
  Dés d'attaque = `personnage.des_attaque` (la classe/l'arme/les talents
  passifs, PAS les bonus conditionnels — Furie, flanquement… —, qu'aucune
  source de ce meuble n'évoque) ; dés de défense = `mobiliers.defense_dice`
  (`0` est une vraie valeur : le Crystal Cluster « cannot defend ») ; le meuble
  défend avec les boucliers **BLANCS** (seule couleur sourcée pour ce
  mécanisme, G1504 p. 10 — « counting the white shields scored »). Réponse
  `{type: "attaque_mobilier", mobilier, des_attaque, des_defense, touches,
  boucliers, degats, pv_body_avant, pv_body_apres, detruit, faces_attaque,
  faces_defense, face_touchante, face_defensive}` — même forme qu'une attaque
  de monstre, journalisée (`JournalCombat::attaqueMobilier()`) et mise en
  scène à la table (`SceneDeTable::attaqueMobilier()`) comme un combat
  ordinaire, le meuble en défenseur. ⚠ **S'arrête à la carte** : la pièce
  cesse de bloquer mouvement et vue (`FabriqueGrille::pour()`, la boucle
  UNIQUE) et disparaît de `EtatGroupe.carte.mobilier[]` comme une pièce
  fracassée ; un déclenchement de GABARIT DE QUÊTE (le sorcier qui jaillit du
  Coffre du Dread, la quête gagnée à la chute du Haut Autel) est hors du
  périmètre de ce lecteur générique. **Exception (2026-10-09)** : si le meuble
  est l'**élément désigné de l'objectif** (`quete.objectif = "detruire_element"`),
  sa destruction **termine la quête sur-le-champ** (`ResolveurTour::terminerQuete()`,
  point de passage unique des fins de quête) : la réponse porte alors
  `objectif_detruit: {nom, texte}` (texte de fin du livret, traduit, aussi
  diffusé en narration et écrit au journal `systeme`/`objectif_detruit`) et
  `quete: {etat: "terminee", or_butin, niveaux, …}` — la même forme qu'une fin
  par vote. « Remove all remaining monsters from play » : la quête terminée,
  les monstres restants quittent le jeu avec elle (aucune instance n'est
  modifiée, comme à toute victoire). Le groupe est au hub, la clôture de
  campagne s'ouvre (jalon `boss_final`).
- **Forcer un levier** — l'option `actionner_levier` demande désormais un **jet de
  Body** (difficulté du levier, 1-3) et **coûte le créneau d'ACTION** : ce n'est
  plus une interaction gratuite. ⚠ **Retentable sans limite**, contrairement aux
  épreuves — c'est ce qui autorise une salle à ne tenir qu'à ce levier sans jamais
  se sceller. ⚠ **Retentable tant qu'il reste une porte à ouvrir** (2026-10-09) : un
  levier **déjà forcé** (toutes ses portes ouvertes) reste visible sur la carte mais
  n'est plus offert par le menu, et un `POST choix` sur lui est refusé (422, « Ce
  levier a déjà été actionné : la porte qu'il commande est ouverte. »). Décidé par
  `MoteurPortes::levierAOuvrir()`, le même prédicat pour le menu et le résolveur.
  ⚠ **`portes_ouvertes`** (payload `actionner_levier`, `force: true`) NOMME toutes les
  portes que le geste ouvre — celles du levier ET leurs jumelles de seuil, que
  `MoteurPortes::ouvrir()` entraîne avec elles — jamais une liste vide sur une
  réussite.

- **EtatGroupe.carte** gagne `mobilier: [{x, y, l, h, nom, bloque_mouvement,
  bloque_vue, pv_body, defense_dice, pv_restants, symbole_url, symbole_rotation}]` (`symbole_rotation` : voir §Symboles de livret) — l'ancre `(x, y)` est le
  coin haut-gauche de l'emprise (l×h), même convention que `cellulesEmprise()`.
  Contrairement aux pièges, un meuble n'a pas d'état « caché » : il est
  simplement soumis au même **brouillard de guerre** que le reste de la salle
  (une salle non découverte n'expose aucun de ses meubles).
  ⚠ `pv_body`/`defense_dice`/`pv_restants` (2026-10-04) sont `null` pour un
  meuble ORDINAIRE (pas une barre de vie qu'il n'a pas) ; pour un meuble
  attaquable, `pv_restants` est la DÉCISION publiée par le serveur (déjà
  décomptée des coups portés), jamais à recalculer côté client.
  ⚠ **Sans salle, publié via la case** (2026-10-06) : un mur magique posé EN
  QUÊTE (`MoteurMobilier::poserMurMagique()`, Wall of Stone) peut tomber en
  COULOIR, qui n'a pas d'index de salle — comme un levier de couloir, il
  s'affiche dès que sa CASE quitte le brouillard, pas dès que « sa » salle
  (inexistante) est découverte.
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
  jet réussi révèle les **pièges cachés** ET les **portes secrètes** (qui deviennent
  des portes fermées, ouvrables) de la **salle ou du couloir** du fouilleur, en
  entier — **sans rayon ni ligne de vue** (René, 2026-09-27 ; c'était un rayon de 3
  cases filtré par la vue). Une porte en fait partie si l'une de ses deux cases y
  tombe. `App\Partie\ZoneFouille` est le point de passage unique. Echo :
  `pieges_reveles`, `portes_revelees` (forme inchangée).
  ⚠ **`issue` sans ambiguïté** (2026-10-09, verdict Morcar § 2) — pour les fouilles de
  zone (`fouiller`, `fouiller_pierre`), `issue` dit ce que la RECHERCHE a donné :
  `reussite` = quelque chose a été trouvé (`a_trouve: true`) ; `rien` = le jet a réussi
  et ne trouve rien (`succes: true`, `a_trouve: false`) — avant, ce cas portait
  `issue: reussite` et se lisait comme une découverte ; `echec` = le jet a raté (rien
  trouvé, `succes: false`). `succes` reste le résultat brut du dé. Le temps fort du
  narrateur suit : `rien` → `fouille_rien`.
- **Verrous** (doc 14 §3.3) :
  - `cle` : option `ouvrir_porte` (id `ouvrir_porte_{x}_{y}_{cote}`) au contact d'une porte
    verrouillée, offerte si le héros possède l'objet-clé → la porte s'ouvre (persistant) ;
  - ⚠ **Une option PAR porte fermée adjacente** (2026-10-17, décision de René en partie
    réelle) : un héros dans un coin entre DEUX portes closes en voit deux, et chacune ouvre
    la SIENNE (`parametres.porte` la désigne). La liste est lue en UN point,
    `MoteurPortes::portesFermeesAdjacentes()` : le menu l'offre, le résolveur n'accepte
    que ce qu'elle contient (422 « Aucune porte fermée adjacente » sinon). Une porte
    secrète non trouvée n'y figure JAMAIS (c'est un mur pour le héros) ;
  - ⚠ **Libellé : la DIRECTION, seulement quand elles sont plusieurs** (2026-10-17,
    décision de René). Une seule porte offerte ce tour (tous gestes confondus) : libellé
    INCHANGÉ — « Ouvrir la porte », « Ouvrir la porte (clé) », « Bénédiction de l'Oracle —
    révéler la salle », « Pousser la porte de pierre — N dés… ». Dès que PLUSIEURS portes
    sont offertes, chaque libellé de porte porte la direction de SA porte vue de la case
    du héros : « au nord », « au sud », « à l'est », « à l'ouest » (`Rayon::DIRECTIONS`,
    le seul vocabulaire cardinal), p. ex. « Ouvrir la porte à l'ouest (clé) ». Le serveur
    décide ce mot ; l'IA n'habille JAMAIS un libellé mécanique (prix, dés, direction),
    et la publication des menus ne réécrit pas `libelle` (`GenererMenu::publier()`).
  - `monstres_vaincus` : ouverture **automatique** quand les instances désignées sont
    vaincues (hook post-combat) — aucune action joueur ;
  - `levier` : option `actionner_levier` (id `actionner_levier_{x}_{y}`) au contact d'un
    levier (`cartes.grille.leviers`) → **jet de Body** (difficulté du levier, plafonnée) ;
    réussi, il ouvre la/les porte(s) liée(s) par `verrou.levier_id` et révèle ce qu'il y
    a derrière. ⚠ Coûte le créneau d'**action** depuis le 2026-08-24 (c'était une
    interaction gratuite), et se **retente** sans limite ;
  - `pierre` (Against the Ogre Horde p. 4, lot B, 2026-10-02) : option
    `forcer_porte_pierre` (id `forcer_porte_pierre_{x}_{y}_{cote}`, une par porte de
    pierre adjacente), offerte
    au contact d'une porte de pierre **uniquement si le héros lance au moins
    2 dés d'attaque DE BASE** (`personnage.des_attaque`, valeur à mains nues
    de la classe — JAMAIS l'arme en main ; un magicien, 1 dé, ne voit jamais
    cette option). Coûte le créneau d'**action**. Résolution : le héros lance
    ses dés d'attaque de base comme des dés de combat ; **2 crânes ou plus**
    ouvrent la porte **pour de bon** (persistant, comme toute autre porte) ;
    sinon rien ne change, retentable à un tour suivant. Réponse
    `{type: "forcer_porte_pierre", porte, des_lances, faces, cranes, reussi}`.
    ⚠ **Posée UNIQUEMENT sur une arête de BOUCLE** de la carte (jamais
    l'arbre couvrant) — par construction, les deux salles qu'elle relie sont
    déjà connectées autrement, donc une porte de pierre ne peut **jamais**
    être le seul chemin vers l'objectif, quel que soit le groupe. Au plus une
    par carte, et seulement si le thème de bestiaire du groupe inclut
    `horde_ogre`. `EtatGroupe.carte.portes[]` publie `verrou: "pierre"` comme
    tout autre type de verrou (§Symboles de la carte et légende) — aucune
    illustration dédiée, elle reste une porte `fermee` ordinaire tant qu'elle
    n'est pas ouverte.
- **Souris familière, option `lacher_souris`** (The Crypt of Perpetual Darkness, G1798 quête 2 note B, chantier
  EXT-CRYPTE 2026-10-23 ; icône `pets`) : offerte sur CHAQUE porte fermée adjacente (id `lacher_souris_{x}_{y}_{cote}`,
  `parametres.porte`) tant qu'un héros porte une souris utilisable (`Equipement::ligneSouris()`, un objet `charges: 1`).
  Interaction LIBRE, comme la Bénédiction de l'Oracle : la porte reste close, et la salle derrière se DÉCOUVRE sans
  que `revelerSalle()` ne réveille ses dormants (la différence avec l'Oracle, écrite). Le message nomme les monstres et
  les meubles de la salle derrière. Une charge est dépensée ; la souris se brise à zéro (journalisé). Réponse
  `{type: "lacher_souris", porte, salles_revelees, monstres, meubles, souris_epuisee}`.
- **Bénédiction de l'Oracle, option (a)** (First Light, FL-Q p. 6, lot C
  2026-09-30) : option `oracle_salle` (id `oracle_salle_{x}_{y}_{cote}`),
  offerte **à la place** de — et en plus de — `ouvrir_porte` sur CHAQUE porte
  fermée adjacente (une option par porte, `MoteurPortes::portesFermeesAdjacentes()`,
  close OU verrouillée, sans la clé ; jamais une porte secrète non trouvée) si le
  héros porte `benediction_oracle`. `App\Partie\MoteurPortes::ouvrir()` n'est
  **jamais appelé** : la porte reste close, seule la salle derrière se révèle
  (même `revelerDerriere()`/`sallesAdjacentesPorte()` que toute ouverture).
  Interaction LIBRE (notre arbitrage), dépense la Bénédiction. Réponse
  `{type: "oracle_salle", porte, salles_revelees, benediction_oracle: false}`.
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
  - `errant` — monstre du bestiaire instancié (`monstre`), **sans plafond**, sa carte revenant
    sous le paquet comme les autres. Carte 2024 (*Wandering Monster*) : il est posé sur une case
    **adjacente OU DIAGONALE** du fouilleur (les huit voisines, `caseLibreProche()`) et **frappe
    tout de suite** (`frappe_errant`, payload d'`attaque_monstre`) ; le fouilleur **continue son
    tour** (rien ne l'arrête). Si toutes les voisines sont occupées, il est posé « as close as
    possible » (repli, LR p. 15) et **ne frappe pas** : il joue au tour des monstres. **Décision de René
    (2026-10-16)** : le monstre errant est un monstre comme les autres pour la suite du round — s'il est
    ENCORE au contact du fouilleur à la phase des monstres du même round (le tour de Zargon), il frappe **à
    nouveau**, comme tout monstre. (Lecture du code : aucune exclusion de l'errant à cette phase ; pas encore
    vérifié en partie.) ⚠ **Défi du
    chevalier** : quand un Chevalier de la salle accepte la proposition, la frappe immédiate
    **attend sa réponse** — `defi_en_attente: true` sur le payload, et le monstre ne joue pas au
    tour des monstres tant que le défi est ouvert. Accepté : il frappe le Chevalier. Refusé,
    expiré ou impossible à placer : il frappe le fouilleur **à ce moment-là**, si celui-ci est
    toujours à son contact (`frappe` de la réponse `POST /reaction`, ou du `GET /etat` qui
    purge l'expiration), avec `apres_defi` sur l'`attaque_monstre` (`refuse` | `expire` |
    `impossible`). Le même chemin vaut pour une fouille de mobilier : aucune table de meuble ne
    rend aujourd'hui d'errant (`MobilierSeeder`), mais si l'une en rendait un, elle passerait
    par `appliquerButin()` et serait traitée pareil ;
  - `piege` — appliqué **tout de suite** au fouilleur (`declenchement`), jamais posé
    sur la grille. Deux cas, à ne pas confondre :
    - **carte « Hazard! » du paquet** (`variante: fleches | trou`, cartes 2021 et 2024) :
      **1 PV de Body exactement**, le tour s'arrête (`a_joue`), **ni jet ni poison**.
      Le payload `piege_declenche` porte `hazard: true`, `variante`, `piege.nom`
      (« Flèche cachée » / « Trou peu profond »), `degats` (ce qui a été réellement
      perdu) et `fin_de_tour: true` ;
    - piège **nommé** (un meuble : aiguille du tombeau, fiole de l'établi) ou
      « Piège de coffre » : effet du catalogue, d6 (1 PV ou Empoisonné).

  - `rien`.

  Clés additionnelles du payload : `deck_restant` (cartes encore en pioche),
  `deck_vide` (deck épuisé → rétrogradé en `rien`), `coffre`, `sac_deborde`
  (l'objet a été remis **au-delà** de la capacité du sac — cf. ci-dessous),
  `objet_indisponible` (carte pointant un objet absent du catalogue).

  **Sly Storage** (FL-Q p. 7, First Light, 2026-09-30) : la salle a une
  **armoire** (`mobiliers` « Armoire », encore debout — ni détruite) et c'est
  le **premier** héros du groupe à y fouiller un trésor (`quetes.tresors_fouilles`
  encore vide pour cette salle, lu AVANT de l'y inscrire) → il tire **DEUX**
  cartes, résolues dans l'ordre. Le payload porte `armoire: true` sur la carte
  principale et `carte_armoire` (la seconde carte, de la **même forme** qu'un
  payload `fouille_tresor`, y compris son propre `declenchement` si elle est un
  piège). ⚠ La seconde carte **ne marque pas** une seconde entrée de
  `tresors_fouilles` — ce n'est pas une seconde fouille du héros, c'est le
  meuble qui en rend une de plus pour le même geste ; elle ne peut non plus
  **jamais** retomber sur le coffre désigné de la quête (déjà épuisé par la
  première lecture de `coffrePlein()`), toujours sur le deck ordinaire. ⚠ «
  Résolues dans l'ordre » n'admet aucune exception : la seconde se tire même si
  la première est un piège (qui ferme déjà le tour du héros) ou un monstre
  errant — rien dans le texte ne la conditionne à l'issue de la première.
  `JournalCombat`/`SceneDeTable` l'annoncent (ligne dédiée + scène « Armoire —
  seconde carte ») : un effet automatique que rien n'annonce est injouable.

  **Coffres et caisse : AU CONTACT, plus à la fouille de salle** (René,
  2026-10-02). « Fouiller — trésor » ne paie plus le coffre désigné ni la
  caisse : on les fouille par l'action **« Fouiller : Coffre »** /
  **« Fouiller : Caisse de ravitaillement »** (`type: fouille_mobilier`),
  offerte seulement quand le héros est adjacent au meuble.
  - **Tombeau** (*Return of the Witch Lord*, chantier 2026-10-23) : l'issue `monstre` fait sortir un
    mort-vivant de base, posé au contact ORTHOGONAL du fouilleur (repli sur la case libre la plus proche) ;
    payload `{issue: "monstre", monstre: {instance_id, nom, x, y}}`, annoncé « X sort du tombeau… ».
    Si rien ne peut sortir (créature hors bestiaire, aucune case libre) : `issue: "rien"` et
    `monstre_indisponible: true` — la fouille le dit au lieu de se taire.
  - **Coffre** dans une salle à coffre désigné (salle du fond, passages
    secrets) encore plein → la récompense du coffre de la quête (artefact,
    or ou potion de `carteCoffre()`) REMPLACE la table ordinaire du coffre ;
    payload `{type: "fouille_mobilier", mobilier: "Coffre", coffre_quete:
    true, issue, …}`. Une seule fois pour le groupe : `quetes.coffres_ouverts`
    (nouvel état durable, restauré par les snapshots).
  - **Caisse de ravitaillement** (Against the Ogre Horde p. 5, boîte
    `horde_ogre`) → au PREMIER qui l'ouvre : `{issue: "caisse_ravitaillement",
    caisse_ravitaillement: true, objets: [{objet, sac_deborde?}, ×4]}`
    (4× Potion de guérison) ; aux suivants : `{issue: "rien", caisse_vide:
    true}`.
  - ⚠ Repli : une salle à coffre désigné SANS aucun coffre physique (pose
    refusée par le plancher de cases jouables) paie encore son coffre à la
    fouille de salle — sinon « atteindre et récupérer » serait impossible.

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
Σ cout des nœuds non innés acquis` (dérivé, toujours juste ; le coût, 1 ou 2,
est exposé plus bas, §« Le pouvoir final et le `cout` »).

| Méthode | Route | Corps | Effet |
|---|---|---|---|
| POST | /groupes/{identifiant}/competences | {personnage_id, competence_id} | acquiert une case de la grille (422 : pas son héros, classe différente, déjà acquise, prérequis manquant, **points insuffisants pour son `cout`** — la phrase même de `achats[].raison`) |
| GET | /api/moi | — | personnages enrichis : `niveau, points_competence, competences: [{id, nom, description, cout, rang, statut, libelle, raison, cadence}], achats: [{id, cout, achetable, raison}]` (voir ci-dessous ; `nom`/`description` ajoutés le 2026-10-10 : une entrée « Disponible » se lit seule ; `cout`, `rang` et `achats` le 2026-10-22) |
| GET | /api/competences | — | catalogue des grilles : `[{id, classe, nom, description, type, innee, categorie, categorie_icone, colonne, rang, cout, effet, avantage, avantage_icone, prerequis_id}]` |

**La GRILLE de talents** (René, 2026-08-23) — chaque classe a **3 colonnes ×
3 lignes**. La colonne est une **catégorie propre à la classe** (`categorie`,
libellé libre : « Furie » chez le barbare, « Traque » chez l'explorateur), et
acquérir la ligne *n* exige la ligne *n−1* **de la même colonne**. `prerequis_id`
porte toujours cette règle et ne change pas de sens : le seeder le **calcule**
depuis `(classe, colonne, rang − 1)` au lieu de le nommer, ce qui rend
impossible une chaîne traversant deux colonnes. Neuf cases pour 4 à 7 points de
campagne : on descend une colonne, on renonce à une autre. Depuis le 2026-10-22,
une colonne peut porter un **4e rang**, son pouvoir final (section suivante).

**Le pouvoir final et le `cout`** (René, 2026-10-22 — `docs/regles/talents-et-capacites.md`,
« Pouvoirs finaux ») : une colonne peut porter un **4e rang** qui coûte **2 points** et
exige le rang 3 de la même colonne. Les rangs 1 à 3 coûtent **1**. Une colonne sans 4e
rang reste valide. Le coût est une donnée, `competences.cout`, publiée partout où un
nœud l'est — `GET /api/competences`, `GET /api/guide`, `GET /api/moi` (`competences[]`) ;
une capacité de carte (`innee`) publie `cout: 0`, elle ne coûte rien. Les points deviennent
`points_competence = (niveau − 1) − Σ cout des nœuds non innés acquis`
(`Personnage::pointsCompetence()`, la seule formule ; `pointsDepenses()` en donne la somme).

⚠ **La décision d'achat est publiée, jamais recalculée.** `GET /api/moi` porte, pour chaque
héros, `achats: [{id, cout, achetable, raison}]` : une entrée par case de la grille de SA
classe (les capacités de carte n'y figurent pas). `achetable` vaut `true` quand le prérequis
est acquis ET que `cout ≤ points_competence`. Sinon `raison` nomme la règle qui ferme la case :
« Prérequis : {nom du nœud du dessus} » ou « Coûte {cout} point(s) de compétence : il t'en
reste {points} ». `raison` vaut `null` pour une case achetable et pour une case déjà acquise
(celle-ci se lit dans `competences[]`). `POST /groupes/{identifiant}/competences` refuse selon
**la même décision** (`App\Partie\Talents::achats()`, un seul point de passage) : le 422 porte
la phrase que la case affiche grisée. La manette ne recalcule ni le prérequis ni le solde.

⚠ **Seul ce qu'on ACHÈTE compte.** Une capacité de carte ne coûte rien et n'entre ni dans
`points_competence` ni dans `achats`. Le score de puissance du groupe (`ScorePuissance`) ajoute
+1 par point dépensé : un pouvoir final vaut donc +2 au budget de rencontre du héros qui l'a
acheté.

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
| PUT | /groupes/{identifiant}/sorts-repertoire | {personnage_id, element_actuel, nouveau_repertoire} | **remplace** un élément CONNU par un répertoire **optionnel** — `protection`/`detection`/`tenebres` (Wizards of Morcar, livret p. 11) — **hub uniquement**, ouvert aux **cinq classes de lanceurs** (pas seulement l'Elfe). 422 : hors hub, héros d'un autre joueur, non-lanceur, `element_actuel` non connu de ce héros, `element_actuel` = `parchemin` (ce n'est pas un répertoire), ou `nouveau_repertoire` hors des trois répertoires optionnels **ou déjà connu de ce héros** (le refuser évite de perdre un répertoire en silence). Les répertoires offerts et remplaçables sont publiés par `/moi` (`repertoires`). Rejouable entre deux quêtes (« Spellcasters may change their spells between quests ») — y compris d'un répertoire optionnel à un autre |

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
| `charges` (2026-09-25) | `{restantes, max}` pour un objet à charges, `null` sinon ; et la ligne de charges d'`avantages` dit « 2 utilisations restantes sur 4 » | le restant de **CET exemplaire** (`MoteurCharges::restantes()`, `null` en base = neuf). Le sac affichait le chiffre du CATALOGUE — l'arc de Sylvan disait « 4 » avec 2 flèches. Le résultat d'une action porte `charges_depensees: [{objet, personnage, restantes, max, detruit}]` (une entrée par exemplaire, `App\Partie\TamponCharges` rempli par `MoteurCharges`), et le fil en fait « Orbe Céleste de Lyra : 2 utilisations restantes sur 4 » ou « … est épuisé et se brise » — la destruction n'existait jusque-là que dans l'historique, jamais à la table. ⚠ **Charges ≠ fenêtre** (René) : seul un total `effet.charges` se dépense et se brise ; un objet `frequence` (« une fois par quête ») se réarme à chaque quête, ne se brise jamais et n'apparaît ni ici ni dans le badge |

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

### Forge gratuite — Chef-d'œuvre du Nain (pouvoir final, lot B, 2026-10-23)

Le nœud **Chef-d'œuvre** (nain·Forge, 4e rang, `cout: 2`, mécanique `forge_gratuite`)
rend **une** amélioration gratuite **à chaque passage au hub**. Un passage = la phase de
hub qui suit une quête **achevée** (terminée ou échouée) ; le hub d'avant la première
quête n'en est pas un. La marque « déjà utilisée à ce passage » vit en base, sur le
**héros** (`personnages.chef_oeuvre_quete_id`, l'id de la quête qui a fermé le passage) :
la décision est `App\Partie\Forge::gratuiteOuverte()`, la seule lecture.

- `/moi` publie, **par amélioration** de `forge_catalogue[]` : `prix` vaut **0** et
  `gratuite: true` tant que l'amélioration est ouverte pour le forgeron du groupe.
  Une pièce non forgeable n'a pas de prix à publier, donc pas de gratuité. Le
  `forgeable` ne change pas : le prix n'y entre toujours pas.
- `POST /groupes/{id}/forge` : si le forgeron a l'amélioration ouverte, **aucun débit**
  et aucun contrôle de bourse ; la marque est posée dans la même transaction.
  La réponse porte `gratuite: true|false` (201) ; le journal `systeme`
  `forge_appliquee` porte le même `gratuite`. Pas de 422 pour cela : le serveur décide,
  et une seconde amélioration au même passage est payée au prix du catalogue.
- Le fil ne publie pas de ligne de hub pour la gratuité : le prix affiché à `0`
  (« gratuit » côté manette, `opt.gratuite`) est l'annonce.

### Pouvoirs finaux, lot B — options et payloads (2026-10-23)

Cinq nœuds de la grille (`CompetenceSeeder`), chacun avec son lecteur déclaré dans
`MotsClesTalent` :

| Nœud (colonne) | Mécanique | Option (`id` / `type`) | `creneau` | Payload |
|---|---|---|---|---|
| Tunnelier (nain·Mine) | `tunnelier` | `tunnelier` / `tunnelier` | `action` | `type: "tunnelier"` |
| Mémoire parfaite (magicien·Érudition) | `recuperer_sort_epuise` + `cout: geste_libre` | `memoire_parfaite` / `memoire_parfaite` (`parametres.sorts[]`) | `interaction` | `type: "concentration"`, `geste_libre: true`, **sans** `tour_sacrifie` |
| Chef-d'œuvre (nain·Forge) | `forge_gratuite` | (aucune option : `/moi` et `POST /forge`) | — | `gratuite` |
| Flair du pisteur (explorateur·Traque) | `refaire_fouille_negative` | (passif) | — | annonce `talents_declenches` |
| Trésor caché (explorateur·Butin) | `fouille_meilleure_carte` | `fouiller_tresor_cache` / `fouille_tresor_cache` | `action` | `type: "fouille_tresor"`, `tresor_cache: true` |

- **Tunnelier** : le héros reçoit, pour CE tour, l'effet **exact** du sort Traverser la
  Pierre — le même buff `franchit_mur`, de durée `ce_tour`, de source
  `sort:Traverser la Pierre` (`MoteurSorts::appliquerTunnelier()`). Il paie le coût du
  sort : **l'action** (`a_agi`). Une fois par quête (`capacites_utilisees`). La traversée,
  le brouillard (`casesInconnues()`) et la chute dans la roche (`verifierRocheMortelle()`)
  sont ceux du sort. Aucune limite d'épaisseur de mur. Le menu ne l'offre pas si le sort
  est absent du catalogue.
- **Mémoire parfaite** : récupère un sort épuisé comme « Se concentrer », sans marquer
  `a_joue` ni `a_agi`. Sa fenêtre est la sienne (`cout: geste_libre`) : elle ne consomme
  pas celle de Concentration (`cout: tour_complet`), et réciproquement. Le fil dit « d'un
  geste libre — sans perdre son tour ni son action ».
- **Flair du pisteur** : une fouille de salle OU de meuble dont la carte (ou la ligne de
  table) est **négative** — `rien`, `piege`, `errant`, ou un objet absent du catalogue —
  est refaite une fois : la première carte est écartée (son effet ne se joue pas), la
  seconde est gardée quelle qu'elle soit. Sans limite de nombre. Annoncé dans
  `talents_declenches` : « la fouille est refaite (piège écarté) ». Le Sixième sens
  s'applique d'abord, au tirage, puis le Flair juge la carte qui reste.
- **Trésor caché** : même créneau et même entrée `Quete::aFouille()` que « Fouiller —
  trésor ». Deux cartes tirées, la meilleure gardée selon l'ordre fixe
  `DeckFouille::ORDRE_TRESOR_CACHE` (artefact > potion > trésor par valeur en or > objet >
  rien > piège > errant), à égalité la première tirée. L'autre carte retourne sous le
  paquet quand le paquet l'a retirée (une carte REMISE y est déjà). Si la carte gardée est
  négative et que le Flair est là, elle est refaite une fois. Le payload porte
  `tresor_cache: true` ; le fil dit « tire deux cartes et garde la meilleure ». Dans une
  salle qui livre son **coffre désigné**, le coffre remplace le tirage : le pouvoir n'est
  ni dépensé ni appliqué, et le payload porte `tresor_cache_sans_effet: true`.

Le type `memoire_parfaite` n'est jamais journalisé : c'est une option de menu, et son
résultat est le payload `concentration`. `JournalCombat::TYPES` le déclare muet pour
cette raison.

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
  **cible unique (2026-10-09)** : un sort de dégâts ne liste que des monstres
  (`cible: monstre`, comme sa carte) ; le tir ami ne subsiste que
  pour les sorts de **zone** (Flamme hypnotique, sans liste) et de **rayon** (Éclair,
  choisi par direction).
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
type, disponible, description, image_url}]` (et l'onglet Sorts de la manette s'en nourrit ; rafraîchi
aussi via `.groupe.etat` → re-GET). **`description`** (2026-10-09) : ce que le sort
FAIT, tiré du catalogue (`sorts.description`, seedé par `SortSeeder`) — la feuille
d'information de la manette l'affiche telle quelle (`descriptionSort()` ne lit plus
aucune table côté client, et une description absente s'affiche comme absente).

**Répertoires à changer — décision publiée (Wizards of Morcar, 2026-10-06).**
Chaque personnage expose aussi `repertoires: {remplacables: [element], offerts:
[element]}`, calculé par `MoteurSorts::repertoiresChangeables()` : `remplacables`
= les éléments connus, **sauf `parchemin`** (un parchemin n'est pas un répertoire) ;
`offerts` = les trois répertoires optionnels (`protection`/`detection`/`tenebres`)
que ce héros ne connaît pas encore. Un non-lanceur reçoit `{remplacables: [],
offerts: []}`. La manette (onglet Sorts, hub seulement) affiche ces listes telles
quelles et ne re-dérive rien.

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

**Après la victoire finale** (`Groupe::victoireFinaleAcquise()` : boss final `terminee`, arc épuisé — 2026-10-16) :
la clôture est la **seule issue**. Aucune quête ne démarre (422 `pret` sur `POST /pret`, 422 `groupe` sur
`POST /quetes`, membre comme table), aucun tour de Hopekins Rest n'est publié (`/moi`, `/etat`) ni accepté
(`PUT …/faveurs/lieu`, 422 `phase`). `DELETE /cloture` annule la fenêtre sans rouvrir le départ : le groupe reste au
hub, et `POST /cloture` la rouvre (issue `victoire`).

**Faveurs de Hopekins Rest : elles ne survivent pas à la campagne** (décision de René, 2026-10-16 : « aucune
récompense, car on ne suit pas l'histoire réelle »). Toutes les issues (victoire, échec, abandon, arrêt d'urgence)
passent par `ClotureCampagne::purger()` : chaque héros de la campagne, actif OU parti en cours de route, voit ses
faveurs relâchées (`FaveursHopekins::relacherCampagne()`), effets défaits, PV max compris (+2 de *Dewdrop Inn*),
avant la remise à plein. Aucune ligne `personnage_faveurs` ne subsiste ; une autre campagne en cours n'est pas touchée.

| Méthode | Route | Corps | Effet |
|---|---|---|---|
| POST | /groupes/{identifiant}/cloture | {abandon?: bool} | ouvre la fenêtre |
| GET | /groupes/{identifiant}/cloture | — | EtatCloture, ou `{cloture: null}` (200) si aucune fenêtre ouverte (même sonde de rattrapage que le marché, pas de 404) |
| PUT | /groupes/{identifiant}/cloture/repartition | {inventaire_id, personnage_id} | réassigne un équipement (annule les confirmations) |
| POST | /groupes/{identifiant}/cloture/confirmation | — | confirme ; tous confirmés → finalisation |
| DELETE | /groupes/{identifiant}/cloture | — | annule la fenêtre (rien appliqué). Après la victoire finale, le groupe reste au hub SANS quête possible (`POST /pret` et `POST /quetes` refusés) : la clôture reste la seule issue, `POST /cloture` la rouvre. Annule aussi une fenêtre d'abandon ouverte par un membre. |
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
theme, resume, issue, niveau_atteint, termine_le) ; 5. **faveurs de Hopekins Rest
relâchées** (`FaveursHopekins::relacherCampagne()`, tout héros du pivot, actif ou
parti en cours de route : effets défaits — mercenaire offert retourné, répertoire
d'*Arcane Library* retiré, +2 PV de *Dewdrop Inn* réconcilié par le point de passage
des plafonds — puis lignes supprimées ; *Witch's Hut* n'est pas une faveur tenue, sa
potion reste au sac) ; 6. détachement (`groupe_actif_id` null), **personnages remis à
plein** (pv_body/pv_mind au max, sorts tous `disponible`, conditions/buffs effacés —
victoire, échec ou abandon referment l'ardoise) puis **purge complète** : quetes, cartes,
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

⚠ **Relèvement par le Corps — mode Story de *Jungles of Delthrak* (décision de
René, 2026-10-09).** `{type: "regain_corps", personnage_id, nom, pv_body,
releve: true}` : à l'ouverture du round, un héros à terre gagne 1 point de Corps
et SE RELÈVE, si aucun monstre n'est actif (la définition de la fin de combat)
et si au moins un autre héros est debout (`ResolveurTour::relevementsStory()`).
Même remontée que les deux payloads ci-dessus (`tour_monstres.actions`, ou
`captifs_repris` round sans monstre), journalisé ; la scène « se relève » sur la
table vient de l'observateur `tombe` (`AnnonceurChute`), pas de ce payload. Le
soin d'urgence ne change pas : la réaction `soin_urgence` offre déjà les sorts de
soin disponibles au héros qui tombe, sans condition sur `a_joue`.

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

⚠ **First Light — carte Dragon** (2026-09-30, docs/plan-first-light.md ; **tour
fractionné construit le 2026-10-16**) : `vol_draconique` (*Draconic Flight*). Texte
de la carte, FL-Q p. 7 : « The dragon may take partial movement, perform an action,
and then finish its movement. The dragon's powerful wings allow it to move through
squares occupied by heroes and monsters, though it cannot end its movement on an
occupied square. » Le CHEMIN traverse les FIGURES, jamais le mobilier ni les murs.
**Un seul budget** (`monstre.deplacement`, en POINTS) coupé en **deux jambes autour
d'UNE action** — lecteur `MoteurDread::tourDraconique()`, en tête de l'action Dread
(il remplace le sort seul et le vol d'un bloc).

*Règle de coupure* (écrite, simple) : le moteur suit le trajet d'approche case par
case, départ inclus, et coupe à la **première case libre** d'où le tour a une action
— un sort lançable D'ICI (le choix `choisirSort()`, évalué à cette position, compteur
et « à volonté » compris), ou, après au moins un pas, un héros au contact (le coup).
Le sort prime. Après l'action, la jambe 2 dépense ce qui reste du budget vers le
héros le plus proche ; **après un coup, pas de jambe 2** (au contact, on ne s'éloigne
pas de sa proie). Aucune action sur le trajet : une seule jambe, sans action. Au
contact dès le départ, le vol ne marche pas (rien à traverser) : seul un sort peut
partir, et le coup reste à la résolution normale. Jamais d'arrêt sur une case
occupée ni interdite à l'arrêt (mare, brasier) : `Grille::empriseArretPossible()`.

*Payloads, dans cet ordre* (à plat dans `tour_monstres.actions`) :
1. **jambe 1** (seulement si elle parcourt au moins une case) —
   `{type: "deplacement_monstre", id, monstre, depart, chemin, vers, vol: true, jambe: 1}` ;
2. **l'action** — soit `{type: "sort_dread", ...}` (signé du lanceur, forme inchangée),
   soit `{type: "vol_draconique", instance_id, monstre, cible, touches, boucliers,
   degats, pv_body_apres, cible_tombee, ...}` : le COUP, **sans** `depart` ni `vers`
   (le mouvement est dans la jambe 1) ;
3. **jambe 2** — `{type: "deplacement_monstre", ..., vol: true, jambe: 2}`, après un
   sort seulement.
Sans action : une seule `deplacement_monstre` avec `vol: true` et `jambe: null`.
Le fil dit « prend son vol — N cases », « achève son vol — N cases », « fond en vol
et avance de N cases ». La table fait marcher chaque jambe comme une marche
(`EtatGroupe.mouvements`, deux trajets de la même figurine, dans l'ordre) : la scène
d'un sort attend la marche qui le PRÉCÈDE dans le tour, jamais celle qui le suit
(`SceneDeTable::depuisResultat()`). Le coup, lui, attend sa jambe 1 comme avant.

`sort_a_volonte` (capacité PARAMÉTRÉE, `{sort: "Boule de Flammes"}`) : ce sort
échappe au compteur `usages_dread` pour l'instance qui la porte — chaque lancer
coûte toujours l'action du tour, et le payload reste `{type: "sort_dread",
...}` sans changement de forme. Divergence assumée : le Spectre, dont la carte
dit aussi « at will », reste bridé à `USAGES_BASE` — ce lot ne le touche pas.

**Sorciers du Dread de Wizards of Morcar — vague 2A (Storm Master, High Mage,
Necromancer, 2026-10-08).** Capacité `sorts_uniques` : « Each spell may only be
used once per quest […] a full set of six spells » — `usages_dread` = taille du
répertoire, `instances_monstres.sorts_dread_lances` (JSON, dans le snapshot) liste
les sorts dépensés ; le verrou 1×/rencontre des invocations est levé pour eux.
Tous les payloads restent `{type: "sort_dread", sort, ...}` ; formes ajoutées :
- Murs magiques (*Muraille de glace/de flammes*) : `{mur_magique: true, cases: [2×{x,y}],
  mobilier: {index, nom, pv_body, defense_dice}}` — le MÊME mobilier attaquable que
  le *Mur de Pierre* des héros (`MoteurMobilier::poserMurMagique()`), publié par
  `carte.mobilier` comme lui.
- *Foudroiement* / *Tremblement de terre* : `resultats[]` + `cases_affectees` comme
  un rayon ; `mur_annule: {nom, x, y}` quand la ligne a rencontré un mur magique
  (« both spells are cancelled »).
- *Ouragan* : `{repousse: {personnage_id, nom, de, vers, cases, declenchements[]}}`
  (`declenchements` = les payloads de piège déclenchés en route, même forme que
  `piege_declenche`).
- *Désapprentissage* : `{oubli: {cible, sort_oublie}}` (même table `sorts_oublies_de_quete`).
- *Vent voleur* / *Corrosion* : `resultats[0].objet_detruit` ; `arrache: true` pour le vent.
- *Relève des morts* (réactif, **sans action**) : `{reaction: "mort_de_monstre",
  sans_action: true, releve: {monstre, x, y, squelette_id}}` ; publié aussi sous
  `reaction_dread` dans le payload de l'attaque qui a tué (`attaque_monstre`).
- *Invocation de momie* / *Appel des squelettes* : `invoques` sans `de` (renfort fixe).
Payloads de tour ajoutés : `{type: "possession_deplacement", personnage, de, vers,
vers_monstre, pieges_declenches}` (le héros *Possédé* est déplacé par le moteur, résultat
de son action ; `pieges_declenches` porte les pièges croisés, vide sinon) et `{type: "conditions_levees", levees: [{personnage_id, nom, condition}]}`
(grésil retombé en tête de la phase des monstres, dans `tour_monstres.actions`).
Nouveau type d'option de menu : `attaquer_liens` (*Liens magiques*, créneau action),
`parametres.cibles: [{id, nom, soi}]` = liste blanche ; payload `attaque_mobilier`
(`mobilier: "Liens magiques de …"`, `detruit`). Nouvelles conditions (`conditions[]`
d'EtatGroupe) : *Grésil aveuglant*, *Ligoté*, *Possédé*.

**Orc Warcaster et Artificer, créatures de la boîte — vague 2B (2026-10-08).**
Mêmes règles que la vague 2A (`sorts_uniques`, un sort une seule fois par quête).
Formes de `sort_dread` ajoutées :
- *Appel des orques / des gobelins* : `invoques[]` (chaque entrée porte désormais
  `instance_id`) + `activation_immediate: [id…]` — ces créatures jouent **dans la
  foulée du lanceur**, leurs actions suivent le sort dans `tour_monstres.actions`
  (« may move and attack immediately »). *Invocation de golem* / *Appel du
  Dreadshifter* : `invoques[]` sans `activation_immediate`.
- *Esprit de vengeance* : `resultats[]` comme tout sort à dés ; la cible n'est pas
  forcément en ligne de vue (seul sort dans ce cas).
- *Bouclier de protection* / *Lames aiguisées* : `{renforts: {creatures[], volee:
  defense|attaque, des, duree}}`. *Orque berserker* : `{double_tour: {monstre,
  instance_id}, activation_immediate: [id, id]}` — l'orque joue son tour DEUX fois
  à la suite du lanceur (deux actions dans `tour_monstres.actions`), jamais trois.
- *Parchemins de Morcar* : `{amelioration: {jetons_ombre: 3}}` ; *Marteau de la
  Ruine* : `{amelioration: {bonus_attaque: 2, se_brise_sans_degat: true}}`.
- *Drain de vie* : `resultats[]` (par héros : `{cible, de, touche, degats,
  pv_body_apres}`), `monstres_touches[]` (créatures du lanceur prises dans la zone,
  `{monstre, de, touche, degats}`) et `drain: {pv_rendus, pv_body_apres}`.
- *Implorer les puissances du Dread* (réactif, sans action — publié sous
  `reaction_dread` du coup qui tue, comme *Relève des morts*) : `{reaction:
  "zero_pv_du_lanceur", sans_action: true, de, issue: ignoree|invoque|froid,
  invoques[]?, resultats[]?}`.
Événements automatiques annoncés par `{type: "effet_dread", mecanique, texte, ton}`
dans `tour_monstres.actions` (et au journal) : jeton d'ombre absorbé, *Marteau*
brisé, bouclier dissipé, lames émoussées, **coup de corne** — le serveur décide le
texte. Un coup absorbé par un jeton rend `reaction_monstre: "jeton_ombre"` dans le
payload de l'attaque. `attaque_monstre` gagne `marteau_brise: true` quand le coup
du lanceur n'a rien retiré. **EtatGroupe** : `entites[].conditions[]` d'un monstre
publie, sous leur libellé, `Parchemins de Morcar (N jetons)`, `Marteau de la Ruine
(+2 dés d'attaque)`, `Bouclier de protection (+1 dé de défense)`, `Lames aiguisées
(+1 dé d'attaque)` (`MoteurDread::etiquettesDread()`).

**Embuscade du Dreadshifter** (`capacites: [embuscade]`). Un Dreadshifter posé à la
génération est **un coffre** : un meuble `Coffre` sur sa case (`carte.mobilier`,
indiscernable d'un coffre ordinaire — il n'est ni fouillable ni attaquable), la
créature absente de `entites` (`revele = false`). Quand un héros entre dans l'une
des 8 cases autour, la réponse du déplacement porte `embuscades: [{type:
"embuscade", monstre, instance_id, declencheur: {personnage_id, nom}, etait:
"coffre", position: {x, y}, action: <payload de son tour, immédiat>}]` ; le coffre
disparaît de `carte.mobilier`, la créature rejoint `entites`. Seul le déclencheur
« un héros entre dans les 8 cases » de la carte est porté, et seul le coffre (pas
la porte).

**Coup de corne du Minotaure** (`capacites: [coup_de_corne]`). Quand un héros
**finit son tour** dans l'anneau de 10 cases autour du Minotaure (figure de 2
cases), la réponse de l'action qui clôt le tour porte `coups_de_corne: [<payload
`attaque_monstre`> + {mecanique: "coup_de_corne"}]` (2 dés d'attaque, jet de
défense ordinaire), précédé d'un `effet_dread` au journal. Pas de coup s'il est
endormi, paralysé ou enchaîné.

**Tir au choix** (`capacites: [tir_au_choix]`, 2026-10-09 — Gruulob dans ses DEUX
formes : « they may choose to fire at range at any hero in their line of sight »).
Un monstre de mêlée qui porte ce trait TIRE sur place, à distance, sur le héros le
plus avantageux qu'il voit (même choix que `tirerSiCibleEnVue()`), avec ses dés
d'attaque ordinaires — `attaque_monstre` porte alors `portee: "distance"`. Dès qu'une
figure (héros ou allié) est à son CONTACT, il frappe au corps-à-corps comme tout
monstre. Il ne RECULE jamais pour tirer : le recul est le trait de l'archer
(`portee: distance`), pas celui-ci. Le choix est toujours « tirer » : le serveur
tranche, jamais l'IA.

**Le patron TOURELLE et les quatre adversaires nommés de *Jungles of Delthrak*** (René,
2026-10-15 — `docs/regles/bestiaire-et-rencontres.md`, `docs/regles/carte-donjon.md`).
Vocabulaire fermé `App\Engine\MotsClesAdversaires` (onze clés, chacune avec son lecteur).

- **Figure immobile = `deplacement: 0`** (`Monstre::estImmobile()`). Aucune clé de plus sur
  `entites[]` : c'est le catalogue qui le dit (`des_attaque`, `emprise`… inchangés). Elle n'avance
  jamais — pas de `deplacement_monstre` pour elle ; elle frappe ce qui est à sa portée ou ne fait
  rien. **Placement** : en SALLE seulement (jamais en couloir), hors parvis d'une porte, d'un
  levier ou d'une épreuve, la salle restant connexe et au-dessus du plancher de cases jouables.
  `carte.grille.spawn_figures_fixes` (liste `{x, y}`, sous-ensemble de `spawn_monstres`) est
  **interne** : jamais publiée par `EtatGroupe::carte()`, lue seulement par `DemarreurQuete`.
- **Tourelle** (`capacites: [tourelle]` — *Guardian Effigy*, Against the Ogre Horde p. 27).
  Chaque tour du MJ, elle fait feu sur un héros en ligne de vue (figures bloquantes), **au contact
  comme à distance, aux mêmes dés** : `attaque_monstre` porte `portee: "distance"` ET
  **`tourelle: true`** — le fil dit « fait feu sur », la table « Tourelle — fait feu depuis son
  poste ». Sans héros en vue : rien.
- **Météore** (`capacites: {meteore: {des_attaque: 2}}` — Qwindrak the Warlock, livret de quêtes First
  Light p. 31, note C : « Each time a hero casts a spell or makes an attack against him, Qwindrak hurls
  a meteor at them. Roll 2 Attack dice for each meteor. »). Porté par la **fiche d'origine** : chaque
  forme de la pioche le garde (`InstanceMonstre::ligneDeReference()`), y compris le Dragon. Chaque
  ATTAQUE d'un héros dont la cible est la figure, réussie ou non, et chaque SORT d'un héros dont les
  cibles visées la comprennent, lui valent un météore : 2 dés d'attaque contre ce héros, sa défense
  normale. Seuls les HÉROS déclenchent (ni allié, ni mercenaire, ni compagnon). Le météore part
  **après la résolution de l'action**, et seulement si la figure est encore `actif` : la chute du Dragon
  achève Qwindrak, et la victoire n'a pas de météore. Son coup est une `attaque_monstre` avec
  **`meteore: true`** et `portee: "distance"` (il est lancé de loin), journalisée comme toute attaque
  de monstre. Le résultat le porte dans **`meteores`** (liste plate, `attaque_monstre` chacune) ; une
  riposte (*Représailles*) le porte dans `frappe.meteores`. Le fil le dit « Un météore de … » ; la
  scène de table « Météore de … sur … » (genre `attaque`).
- **Immunité aux sorts** (`immunite_sorts`, totale : Effigy ; `immunite_conditions: [endormi,
  saute_tour]`, partielle : Scion). Le **menu** n'offre pas la créature comme cible (une entrée
  de sort sans cible légale disparaît) ; un sort de **zone** ou de **rayon** passe sans l'atteindre
  et le DIT : `touches[]` porte `{type: "monstre", instance_id, nom, immunise: true}` (zone) et
  `{…, degats: 0, vaincu: false, immunise: true}` (rayon de sort — pas la technique du Moine,
  qui n'est pas un sort). Les sorts du Dread (collatéral, soin, drain) l'épargnent aussi.
- **Apparition à la vue** (`apparait_a_la_vue` — *Dread Skull*, G1798 ; **aucune ligne de catalogue
  ne la porte encore**, `MotsClesAdversaires::SANS_DONNEE`). La figure attend `revele = false` ;
  la réponse d'un déplacement ou de toute action porte `apparitions: [{type:
  "apparition_a_la_vue", monstre, instance_id, declencheur: {personnage_id, nom}, position: {x, y},
  action: <son tir, immédiat>}]` dès qu'un héros la VOIT — **chaque case du chemin** compte. Elle
  n'est jamais révélée à l'ouverture de la porte.
- **Silencing Snare** (`filet_silencieux: {sort}` — Deathspinner). Quand un héros **finit son
  tour** en sa ligne de vue, la réponse de l'action qui clôt le tour porte `filets_silencieux:
  [{type: "filet_silencieux", monstre, instance_id, cible: {personnage_id, nom}, sort}, <payload
  sort_dread>]` : une annonce, puis le sort lancé SANS action ni usage du budget. Pas de filet si
  elle ne pourrait pas agir (endormie, paralysée, tour perdu — la *Tempête* de la carte —, enfumée,
  sous la Baguette d'Os).
- **Souffle du Construit** (`souffle_du_construit: {degats, des_rouges}` — Golem Cœur-de-Forge),
  UNE fois par quête, à la place de son action : `{type: "souffle_du_construit", monstre,
  instance_id, resultats: [{cible: {personnage_id, nom}, des_rouges: [d6, d6], degats_bruts,
  degats_annules, degats, pv_body_apres, cible_tombee}]}` — tous les héros de la même salle ou du
  même couloir. Il souffle dès que deux héros au moins sont pris (le seul debout, pour un groupe
  réduit à un). Scène de table `genre: "attaque"`, un objet par héros pris.
- **Vrilles** (Scion Fleur-Pourrie). À la fin de son tour : `{type: "vrille_posee", monstre,
  instance_id, vrille: {instance_id, nom, x, y} | null}` (`null` : aucune case libre à portée) ; la
  Vrille rejoint `entites` (`revele: true`) et ne joue qu'au round suivant. Au coup fatal, il
  sacrifie une Vrille et tombe à **1 PV** : `reaction_monstre: "sacrifice_de_vrille"` sur l'action
  qui l'a frappé, plus un événement `{type: "reaction_monstre", mecanique: "sacrifice_de_vrille",
  sacrifice: {instance_id, nom, x, y}}` au journal. Son balayage est une `frappe_de_zone` qui
  atteint aussi les cases en diagonale.

**EtatGroupe** : `entites` (héros ET monstres) gagnent
`conditions: [{nom, duree}]` — la table et la manette affichent les états.
⚠ **Depuis le 2026-10-10**, une condition de HÉROS porte aussi **`effet_source`** (`string|null`) :
l'effet PRÉCIS de sa `source` (`sort:Courage`, `potion:…`), relu sur le sort ou l'objet et traduit par
le serveur (`MoteurSorts::effetLisibleDeSource()` → `MotsClesEquipement::avantages()`, ex. « +2 dé(s)
d'attaque »). « Renforcé » est la condition par défaut de TOUS les buffs (attaque, défense, relance,
déplacement doublé) : sa `description` est générique et c'est ce champ qui distingue deux lignes
« Renforcé ». La fiche l'affiche à côté de `source`.
⚠ **Depuis le 2026-10-09**, une condition de HÉROS porte aussi `description` : ce
que la condition FAIT, tiré du catalogue (`conditions.description`, un texte par
condition, seedé — « Vaporeux » et « Intangible » n'en avaient aucune, verdict
Morcar § 5). La fiche la montre sous les pastilles, sans table côté client ;
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

⚠ **`entites[].franchit_figures` N'EXISTE PLUS** (publié du 2026-09-11 au 2026-10-15). Signalé en
partie réelle : « la mobilité de combat du Rogue ne permet pas de se déplacer à travers les
ennemis » — `DeplacementSheet.vue` traitait tout monstre comme un mur, faute de savoir que CE héros
porte le talent `franchit_figures` ou le buff *Voile de Brume*. Le drapeau y avait répondu ; la
vraie réponse est venue ensuite : le serveur publie la LISTE des cases atteignables
(`se_deplacer.parametres.destinations`, voir §`se_deplacer.parametres.destinations` — la case derrière un monstre y
figure pour un Rogue, pas pour un barbare), calculée par le code même de la résolution
(`DeplacementHeros::grille()`, qui lit `MoteurSorts::mobiliteCombatDisponible()`, seul point de
passage de l'expression). Plus aucun écran ne lisait le drapeau ; une clé sans lecteur est une règle
promise et jamais tenue, elle est retirée. Le moteur, lui, lit toujours le talent et le buff à la
source. La republier exige d'abord un lecteur ET un test en jeu qui l'épingle.

⚠ **`entites[].benediction_oracle` / `malediction_oracle`** (héros seulement,
First Light FL-Q p. 6, lot C 2026-09-30) — mêmes champs sur `GET /api/moi`
(`personnages[].benediction_oracle/malediction_oracle`). Deux états DURABLES
de l'Oracle : la Bénédiction (au choix, une fois : révéler une salle derrière
une porte fermée adjacente sans l'ouvrir — `type: "oracle_salle"`, interaction
libre — OU relancer tout un jet de Défense, réaction hors tour, voir
`RELANCE_BENEDICTION_ORACLE` plus bas) et la Malédiction (jeton Mark of
Zargon ; le moteur, jamais l'IA, force une relance à la première occasion
éligible de la quête et garde le résultat le pire pour le héros — annoncé en
suffixe sur `attaque`/`attaque_monstre`, `malediction_oracle:
{degats_original, degats_relance, garde}`). Levée par `POST
/groupes/{id}/marche/lever-malediction`, voir §Phase marché.

⚠ **`entites[].en_choc`** (héros seulement, *Against the Ogre Horde* p. 9,
Hasbro, confirmée applicable à toute créature ; René, 2026-10-01, qui REVIENT
sur son arbitrage du 2026-09-06 — 0 Mind ne fait plus TOMBER, il met en CHOC).
DÉRIVÉ de `pv_mind` (`Personnage::estEnChoc()` : `pv_mind === 0` avec
`pv_mind_max > 0`), **jamais** une colonne ni une entrée de `conditions[]` —
publié pour qu'un client rechargé affiche le badge sans avoir à re-dériver la
règle, la même raison que `benediction_oracle` ci-dessus. ⚠ DISTINCT de
`tombe` : un héros en choc reste **debout** — il joue, mais `des_attaque` et
`des_defense` ci-dessus (§`entites[]` heros) publient déjà les valeurs
PLAFONNÉES (1 / 2, « armor, weapons, and artifacts do not increase the dice
while a hero is at 0 Mind Points ») plutôt que les colonnes brutes, et le
déplacement du tour (`deplacement.de_annule` / `de_annule_par`, voir
§Déplacement) peut valoir `"État de choc"` — composé avec l'Armure de plates
si les deux s'appliquent (`"Armure de plates + État de choc"`), parce que le
choc touche **toute** créature, y compris le Chevalier que le seul malus
d'armure exempte. Un buff de SORT (`bonus_des_attaque`/`bonus_des_defense`)
continue de s'ajouter par-dessus le plafond — « can be temporarily increased
by some spells » — jamais un objet ou une potion.

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
| GET | /api/moi | — | {joueur, personnages: [...]} — chaque perso : `disponible` (pas de groupe), et si engagé `groupe: {identifiant, nom, phase, narrateur_actif}` ; `visite_hopekins: {quete_id, lieux: [{cle, libelle, effet, disponible, motif}]}\|null` (le choix de lieu de Hopekins Rest à ce tour — 2026-10-16, voir §Faveurs, « Choix au hub ») ; `faveurs: [{cle, libelle, effet}]` (faveurs de Hopekins Rest, 2026-10-08 — `FaveursHopekins::publier()` ; *Weapon Expert* y ajoute `specialite` et, au hub tant que le type n'est pas choisi, `choix` ; *Arcane Library* `repertoire` et `choix` ; *Mercenary Guild* `recrutement_offert` — 2026-10-15) ; `attribut_body/attribut_mind/des_attaque/des_defense` (fiche perso, invariants hors quête) ; `equipement: {armes: [{inventaire_id, nom, emplacement, bouclier}…], casque, armure, talisman: {inventaire_id, nom}\|null, sac: [{inventaire_id, nom, categorie, rarete, quantite, equipable}], capacite, occupation, maitrises: [tag…]}` (chaque pièce équipée porte son `inventaire_id` pour déséquiper ; `equipable` = objet du sac montable dans un slot — voir §Équipement) |
| POST | /api/personnages | {nom, classe, elements?} | crée un perso du roster (libre) |
| POST | /api/groupes | {nom, theme, longueur, ton?, personnage_id, bestiaire_boites?} | crée un groupe DEPUIS un perso LIBRE du joueur (le perso le rejoint comme fondateur) ; 422 si perso déjà engagé ; `bestiaire_boites` absent/`null` = automatique, liste (vide comprise) = manuel — voir §Bestiaire automatique ou manuel |
| POST | /api/groupes/{identifiant}/joueurs | {personnage_id} | rejoint par code avec un perso libre (existant, + accepte {nom,classe}) |

Le `personnages[].groupe.narrateur_actif` (bool) pilote le bouton « Reprendre »
côté joueur : on ne peut reprendre que si une table est active sur le groupe.

### Statut « prêt » et démarrage de quête (au hub)
Une nouvelle quête démarre quand **TOUS les joueurs membres sont prêts** ET
qu'un **narrateur est actif** (remplace le démarrage manuel par la table).

| Méthode | Route | Corps | Effet |
|---|---|---|---|
| POST | /api/groupes/{identifiant}/pret | {personnage_id, pret} | (dé)marque un perso prêt (cache `partie:pret:{groupe_id}`) ; si tous les membres actifs sont prêts ET narrateur actif → **démarre la quête** (DemarreurQuete) et réinitialise les statuts. ⚠ **422 si CE joueur a un panier de marché non vide et non confirmé** — l'application du marché est atomique, donc rien n'est débité, mais ses achats se perdraient au départ (constaté en partie réelle, 2026-08-17). On refuse à ce joueur seul, jamais au groupe : bloquer le départ pour tous referait le piège du vote de sortie, où un joueur distrait enferme les autres. **Même patron, 2026-10-15** : 422 `pret` tant que le **compagnon animal offert** est ouvert (groupe de moins de 4 héros, avant la 1re quête — un seul geste d'un seul joueur, prendre ou refuser, lève le refus pour tous) et tant que CE héros porte *Weapon Expert* sans type d'arme choisi. Le démarrage de quête referme la phase de marché **explicitement** (`marche_ferme_par_quete` au journal + `.marche.finalise`), au lieu de la laisser expirer en silence. **Campagne GAGNÉE (2026-10-16)** : 422 `pret` « La campagne est gagnée (boss final vaincu) : aucune nouvelle quête ne peut démarrer. Clôturez la campagne. » pour tout `pret: true`, refusé AVANT l'écriture du cache (aucun `.prets.maj`, rien ne reste en attente) ; `pret: false` reste permis. Même motif que `DemarreurQuete::refusDeDepart()`. |
| POST | /api/groupes/{identifiant}/quetes | — | démarrage MANUEL (bouton « Lancer la quête » de la table) — autorisation **membre OU table** (le narrateur sans compte peut lancer). Sans ça la table prenait un 401 → faux « session expirée » → redirection vers le login joueur. **Campagne GAGNÉE (2026-10-16)** : 422 `groupe` (même motif que `/pret`) — `DemarreurQuete::demarrer()` le lève, donc aucune quête 4 (`boss_final` par défaut de `JalonsCampagne`) ne part, ni pour le membre ni pour la table. |

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

## Système (état des services — René, 2026-10-02)

Page **Système** (`/systeme`), liée depuis l'écran Narrateur et depuis le
panneau Réglages : « voir l'état des services externes, s'il y a toujours du
crédit disponible et que le service fonctionne ». **PUBLIC**, exactement
comme `/api/parametres` et `/api/guide` — aucune autorisation, aucune clé API
jamais renvoyée.

⚠ **Aucun fournisseur (Anthropic, Gemini, Voyage) n'expose le crédit prépayé
restant** — vérifié dans leur documentation officielle (2026-10-02) : pas
d'endpoint de solde ; l'API Admin d'Anthropic (coûts historiques) exige une
clé admin distincte, hors de portée d'un compte individuel, et ne donnerait de
toute façon qu'un historique de coûts, jamais un solde. Le `credit` de chaque
service est donc toujours une **INFÉRENCE**, jamais une lecture — construite
depuis (1) la classification de l'issue du **dernier appel réel** au service
(`App\Agent\SanteServices`), (2) un **test payant explicite** que le joueur
déclenche lui-même (`POST /api/systeme/tester`), (3) notre propre télémétrie
de tokens (`consommation_ia`). Chaque verdict porte son `explication` en
toutes lettres — jamais maquillé en certitude (même hard rule que « le serveur
publie la décision » : c'est aussi vrai d'un aveu d'incertitude).

| Méthode | Route | Corps | Réponse |
|---|---|---|---|
| GET | /api/systeme | — | **PUBLIC** — **EtatSysteme** (voir ci-dessous). Sondes automatiques GRATUITES, mises en cache **~60 s** côté serveur (page ouverte + rafraîchie toutes les 30 s par le front sans marteler les fournisseurs). |
| POST | /api/systeme/tester | {service: "anthropic"\|"gemini_texte"\|"gemini_tts"\|"gemini_image"\|"voyage"} | **PUBLIC** — test de connectivité **RÉEL et PAYANT** (quelques jetons), un service à la fois, déclenché explicitement par le joueur (jamais automatique). Réponse 200 : `{ok: true, service, duree_ms, extrait}` ou `{ok: false, service, duree_ms, erreur}`. **422** pour `gemini_tts`/`gemini_image` : aucun appel bon marché n'existe pour une simple vérification côté TTS (quota ~100 requêtes/jour partagé par toute la table — utiliser « Écouter » dans Réglages, déjà mis en cache) ni côté image ; **422** aussi si le fournisseur demandé n'a pas de clé serveur. Le résultat retague l'entrée `SanteServices` du service en `source: "test"` plutôt que `"dernier_appel"`. |

**EtatSysteme** :
```json
{
  "genere_a": "2026-10-02T10:00:00+00:00",
  "services": [
    {
      "id": "anthropic", "libelle": "Anthropic (Claude)", "famille": "externe",
      "etat": "ok", "detail": "Clé valide, API joignable (sonde gratuite GET /v1/models — ne mesure pas le crédit).",
      "latence_ms": 180,
      "derniere_reussite": "2026-10-02T09:58:00+00:00",
      "dernier_echec": null,
      "credit": {"etat": "ok", "source": "dernier_appel", "explication": "Dernier appel réel réussi — aucun signal d'épuisement. Aucun fournisseur n'expose de solde réel : vérifiez la console pour le crédit exact."},
      "console_url": "https://console.anthropic.com/settings/billing"
    }
  ],
  "consommation": { "...": "ConsommationIa::agregat() — voir §Paramètres globaux" },
  "avertissements": ["Worker « queue » : exécute un code plus ancien que le code actuel — redémarrer : docker compose restart queue queue-jeu."]
}
```

Chaque entrée de `services` :

- `id` : `anthropic` · `gemini_texte` · `gemini_tts` · `gemini_image` · `voyage`
  · `qdrant` · `mariadb` · `reverb` · `queue_queue` · `queue_queue-jeu` ·
  `backups`. `famille` : `externe` (les 5 premiers) ou `interne` (les 6 derniers).
- `etat` : `ok` · `degrade` · `panne` · `non_configure` · `inconnu`. **Décidé
  côté serveur** (hard rule) : le front affiche le badge, il ne recalcule
  jamais le verdict depuis `credit`/`dernier_echec`. `non_configure` ≠ `panne`
  — jouer sans clé API est un mode **supporté** (narration scriptée, pas de
  RAG, icônes à la place des illustrations), phrasé neutre, jamais en rouge
  alarmant.
- `detail` : une phrase humaine en français — jamais un code d'erreur brut.
- `credit.etat` : `ok` · `epuise` · `quota_atteint` · `inconnu`. `credit.source` :
  `dernier_appel` (un appel de JEU a tranché), `test` (le joueur a cliqué
  « Tester »), ou `aucune` (rien d'observé depuis le dernier redémarrage du
  cache). Un crédit `epuise` fait passer l'`etat` du service à `panne` même
  si la sonde gratuite de connectivité répond — la sonde ne consomme pas de
  crédit, elle ne peut donc jamais le mesurer elle-même.
- `dernier_echec.message` est **tronqué (300 car.) et ne contient jamais la
  clé API** — les clients l'envoient en en-tête, jamais dans le corps, donc
  aucun corps d'erreur fournisseur ne peut la reproduire ; de toute façon
  jamais recopiée telle quelle sans être passée par la classification.
- Services internes (`qdrant`/`mariadb`/`reverb`/`queue_*`/`backups`) :
  `credit` vaut systématiquement `{etat: "inconnu", source: "aucune",
  explication: "Service interne, sans notion de crédit."}` — présent pour que
  le front n'ait pas de cas particulier à coder, jamais affiché comme un
  manque.

**Classification de l'issue d'un appel** (`App\Agent\SanteServices::classer()`)
— catégories : `credit_epuise` · `quota_atteint` (quota JOURNALIER — Gemini
`RESOURCE_EXHAUSTED` avec une métrique de quota « par jour », ou un 429 sans
métrique identifiable) · `limite_debit` (429 transitoire) · `cle_invalide`
(401/403, `API_KEY_INVALID`) · `indisponible` (5xx/529/réseau/timeout) ·
`autre`. **Seule la forme Anthropic est un contrat fournisseur sourcé**
(`error.type` explicite, doc officielle) : la distinction quota-journalier vs
débit-transitoire chez Gemini (tous deux des 429) est une **heuristique**, pas
une garantie Google — documentée comme telle dans le code, avec repli sur
`limite_debit` (le cas le plus fréquent) quand la métrique n'est pas
identifiable.

**Détection du worker qui exécute un code périmé** (`queue_queue` /
`queue_queue-jeu`) — le défaut qui a figé un playtest entier le 2026-08-05
(CLAUDE.md §Commands) : `queue`/`queue-jeu` chargent les classes PHP une
seule fois au démarrage, donc une modification de code sans redémarrage les
laisse tourner contre un schéma/comportement qu'ils ne connaissent pas, sans
qu'aucune erreur ne le signale. Chaque worker publie, au plus une fois par
minute (`Illuminate\Queue\Events\Looping`, `App\Agent\SanteFileAttente`), un
battement `{queue, pid, demarre_a, version_code, vu_a}` où `version_code` est
la mtime la plus récente sous `app/` + `config/`, figée UNE FOIS à son
démarrage. Le contrôleur recalcule la même empreinte à CHAQUE requête (le
conteneur `app`, lui, relit le code en direct) et compare : une différence
affiche « Redémarrer queue / queue-jeu : ils exécutent l'ancien code », une
absence de battement depuis plus de 5 min affiche « aucun worker vu depuis
N min ».

**Sauvegardes** : plus récent dossier sous `backups/` (vu depuis le conteneur
`app`, bind-monté — le même chemin que `./image-tools/sauvegarder.sh` écrit
depuis l'hôte) ; `degrade` au-delà de 7 jours, `panne` si le dossier existe
mais est vide, `inconnu` avec une phrase explicite si `backups/` n'est pas
visible depuis ce conteneur — jamais de donnée inventée (No demo mode).

`consommation` réutilise tel quel `ConsommationIa::agregat()` (voir
§Paramètres globaux ci-dessus) — **tokens uniquement, jamais de prix en
dollars** : le projet ne détient aucune grille tarifaire sourcée pour les
trois fournisseurs, et inventer un prix violerait la hard rule « ne jamais
seeder une valeur que les sources ne donnent pas ».

`avertissements` est une liste de phrases prêtes à afficher en bandeau,
calculée depuis `services` (un service en `panne`, ou un crédit `epuise` sur
un service par ailleurs joignable) — encore une décision prise côté serveur,
pas recalculée par le front depuis la liste des services.

### Monstre à phases — trois champs ajoutés à TOUTE action qui blesse un monstre (chantier transverse 2026-10-04)

**Contrat ajouté avant le payload**, comme la hard rule l'exige. Un monstre
« à phases » (*Against the Ogre Horde* p. 6 : « adopt new statistics […]
still considered the same monster ») n'est pas tué à 0 Body : il adopte la
forme suivante. `MoteurDegats::infligerAMonstre()` — le point de passage
UNIQUE par lequel un monstre peut mourir, quel que soit le chemin de dégâts
(attaque de héros, sort, allié, reflet de sort d'un monstre sur un autre, Eau
bénite) — publie la DÉCISION déjà prise, jamais les ingrédients : trois
champs s'ajoutent au payload de **toute** action qui blesse un monstre
(`attaque`, `attaque_allie`, `sort`, `sort_dread`, `braise`, `degat_differe`,
`frapper_entre_monstres`…), toujours présents (même `null`/`false`), comme
`cible_vaincue` à côté d'eux :

- **`changement_phase`** : `null`, ou `{avant, apres, stats, sorts_termines, flamme?}` — `avant` : le nom que
  l'ÉCRAN montrait (`nomAffiche()`, habillage compris) ; `apres` : le `nom_base` de la
  forme adoptée si l'instance est habillée (son nom d'habillage ne change pas), sinon son
  nom affiché (le nom de catalogue de la nouvelle forme, ★ compris). Depuis
  le 2026-10-10, **`stats`** `{avant: {attaque, defense}, apres: {attaque, defense,
  pv_body, pv_body_max}}`. ⚠ Jamais publié en avance : le client ne connaît la forme
  suivante qu'au moment où elle vient de jouer. La règle de table « Zargon ne révèle pas
  le second jeu de statistiques » (Ogre Horde p. 6) ne tient plus ici : les dés se
  montrent à chaque jet, et le verdict Jungle a trouvé muet que l'attaque de Gruulob passe
  de 3 à 4 dés (la scène de table, elle, ne dit toujours que la nouvelle forme). Rendu : une ligne de journal (`ton: "info"`)
  et une scène de table dédiée (`genre: "transformation"`), au-dessus de
  n'importe quel type d'action — jamais une spécificité par type, le même
  patron que les pièges imbriqués (`declenchement`/`pieges_declenches`).
  **`sorts_termines`** (liste de libellés, `[]` hors pioche) : les sorts actifs sur la créature
  qui cessent AVEC sa forme (« Any spell active on Qwindrak ends ») ; la ligne de journal
  le dit (« Les sorts actifs sur lui cessent : … »). Seule la PIOCHE de Qwindrak
  (`synchroformes`, voir `docs/regles/bestiaire-et-rencontres.md`) en produit : la chaîne des
  phases (Gretzl, Gruzbella…) GARDE ses sorts.
  **`flamme`** — absent sauf à l'arrivée du Dragon de Qwindrak : `{monstre, instance_id,
  resultats: [{cible: {personnage_id, nom}, des_rouges: [d6], degats, pv_body_apres,
  cible_tombee}]}`. La flamme du Dread : chaque héros DEBOUT DANS LA MÊME SALLE lance un dé rouge,
  1 à 3 lui fait perdre 1 Body (`App\Partie\MoteurSynchroformes::flammeALArrivee()`). Rendue par une
  seconde scène de table `genre: "attaque"` (« X — La flamme du Dread », un objet par héros, avec
  son dé : « dé 2 — −1 PV ») et par une ligne de journal par héros (« Albrecht : dé 2, −1 PV. »).
  ⚠ La PIOCHE elle-même (les formes restantes, `instances_monstres.formes_a_venir`) n'est JAMAIS
  publiée : seule la forme COURANTE l'est (`EtatGroupe::monstres()` : nom, `nom_base`, emprise,
  position). Le client ne sait pas quelle forme vient après celle qui joue.
- **`reaction_monstre`** : `null`, ou le nom de la mécanique réactive à usage
  unique qui vient de jouer (`"ignore_degats_attaque"` — Resilience/Demon
  Wings, `"increvable_une_fois"` — Sir Ragnar). Consomme le coup entier (le
  monstre ne subit RIEN) sans changer de phase ni mourir. `"sacrifice_de_vrille"`
  (Scion Fleur-Pourrie) n'est PAS à usage unique : chaque coup fatal qui trouve
  une Vrille la brûle, et le monstre reste à 1 PV.
- **`reddition_monstre`** : `null`, ou `{or}` — une dernière phase qui porte
  `recompense_reddition` (Gruzbella, vaincue elle s'incline et paie plutôt
  que de mourir) crédite le groupe et le dit, plutôt que de laisser
  `cible_vaincue: true` se lire comme une mort ordinaire.

Rendu identique manette/table, **sans aucun nouveau code front** : `info`
est un `ton` déjà stylé (`ActionTab.vue`, `ICONE_JOURNAL`), et une scène de
table sans `genre` connu retombe sur l'icône par défaut (`SceneEvenement.vue`,
`ICONE_GENRE[...] ?? 'bolt'`) — ajouter une forme de scène ne demande donc
pas de toucher l'icône tant qu'une entrée dédiée n'est pas jugée utile.

### Le MJ réagit au sort d'un héros — `sort_annule`, `sort_renvoye`, `reactions_mj` (René, 2026-10-15)

**Contrat ajouté avant le payload.** Quatre cartes du MJ se jouent *pendant le tour d'un
héros*, sur le sort qu'il vient de lancer : *Dispel* de Gretzl, *Dispel* et *Mirror Magic* de
Sinestra (le sort de Dread du même nom), *Break* de Gruzbella. `MoteurReactions` ne parlait
qu'aux héros (offre sur canal privé, réponse d'un téléphone) ; ici le MJ réagit **sans joueur
à consulter** — la résolution est synchrone, dans la requête du héros qui lance, et c'est le
**moteur** qui décide (`App\Partie\ReactionsMj`, appelée par `ResolveurTour::lancerSort()`,
le point commun du sort connu et du parchemin). Aucune route, aucune offre : ce n'est pas une
réaction de héros (`POST /reaction` n'est pas concerné).

Trois champs s'ajoutent au payload de l'action `sort` / `parchemin`, **absents** quand rien n'a
réagi (aucun client existant n'est touché) :

- **`sort_annule`** : `true` — le sort a été lancé (donc épuisé : un sort connu passe à
  `disponible: false`, un parchemin est consommé, l'action du héros est dépensée) puis annulé.
  Le payload ne porte alors ni dégâts ni condition : seulement la `cible` visée quand le sort
  n'en avait qu'une, et `reactions_mj`.
- **`sort_renvoye`** : `true` — *Mirror Magic* : le sort visait le lanceur du MJ et retombe sur
  le héros. Le payload porte l'effet **sur le héros** (`cible` = le héros, `tir_ami: true`,
  `degats`, `cible_tombee`, `condition`… les mêmes champs qu'un tir sur un compagnon).
- **`reactions_mj`** : liste (ordre de jeu) de décisions, chacune
  `{issue, mecanique, par: {instance_id, nom}, carte, jet?, conditions_levees?}` :
  - `issue` : `"annule"` · `"renvoie"` · `"resiste"` (*Dispel* tentée, le héros l'emporte ou
    égale : le sort part quand même, avec son effet normal) · `"rompu"` (*Break* : le sort a
    pris, puis ses effets sont levés — `effet_applique` repasse alors à `false` et
    `rompu_par_mj: true` s'ajoute, la vérité du payload étant « en vigueur une fois le sort
    lancé »).
  - `mecanique` : `"annule_sort_ciblant"` (capacité de Gretzl) · `"rompt_sort_actif"`
    (capacité de Gruzbella) · `"dissipe"` · `"renvoie"` (les deux sorts de Dread).
    Vocabulaire fermé : `MotsClesReactionMj::CAPACITES` et `MotsClesSortDread::MECANIQUES`.
  - `par` : le monstre qui réagit (`nomAffiche()`, habillage compris).
  - `carte` : le sort de Dread joué (`"Dissipation"`, `"Magie Miroir"`), `null` pour une
    capacité de monstre.
  - `jet` (*Dissipation* seulement) : `{lanceur, heros}`, chacun
    `{mind, des: [faces], de, total}` — Mind (la **jauge** `pv_mind`) + 1 dé rouge de chaque
    côté, le MJ lance **d'abord** ; le plus haut total annule, l'égalité profite au héros.
  - `conditions_levees` (*Break*) : `[{cle, libelle}]` — le mot de chaque état est décidé côté
    serveur (`MoteurSorts::libelleConditionMonstre()`).

**Politique du moteur** (aucune carte ne dit quand Zargon réagit) : seul un sort lancé
*contre* le camp du MJ appelle une réaction (il prend un monstre pour cible — figure choisie,
zone ou rayon) ; ordre : la capacité du monstre visé, puis *Mirror Magic* (cible UNIQUE
seulement), puis *Dispel* — une seule tentative par sort. Un lanceur non visé ne dissipe que ce
qu'il voit, et ni endormi, ni paralysé, ni passé aux ordres des héros. Une capacité se dépense
pour la rencontre (`capacites_reactives_utilisees`, colonne portée par le snapshot) ; un sort de
Dread coûte **un usage** du budget du lanceur, même quand le jet échoue.

Rendu, **sans nouveau code front** : le fil (`JournalCombat::lignesSortAvecReactionsMj()`,
tons `echec`/`succes`/`subit`/`pare` déjà stylés) et, pour la table, une scène `genre:
"reaction"` par décision à la suite de celle du sort (`SceneDeTable::reactionMj()`). Le rejeu
relit le même payload : la ligne est identique en direct et à la reconnexion.

## Jungles of Delthrak — le butin (lot A, 2026-10-09)

Source : livret F9907 p. 50 (« Treasure and Artifact Reference », page 26 du PDF) et p. 2
(« Alchemist's Shop »), relus à l'image. Tout n'apparaît que sous le thème `jungles_delthrak`
(`objets.boite`). **Migration additive** `2026_10_09_110000_delthrak_butin` : `objets.categorie`
gagne `tresor`, `inventaire.quetes_avant_reveil`, `groupe_mercenaires.invoque_par_objet_id`.

**Catalogue** (`GET /api/guide`, `/moi`, marché — rien ne change de forme) : 5 artefacts
`rarete: unique` (*Diadème de braise forgée* — slot `casque`, donc « pas avec le casque » ;
*Brassards du Sauvage* — slot `armure` ; *Brassard du Garde-Crocs* et *Ceinture de Puissance* —
slot `talisman` ; *Le Crâne de Saphir* — arme, `portee: distance`), 2 trésors `categorie: tresor`
(*Cœur d'émeraude de Delthrak* 75 po, *Relique naine ancienne* 50 po), 3 potions (*sang de serpent*
50 po, *sagesse ancienne* 400 po, *Élixir de pas d'araignée* 100 po). La *Potion of Healing*
(500 po) est la *Potion de guérison* existante. Les artefacts viennent du coffre de la quête, les
deux trésors du deck de fouille (`issue: objet`), les potions de l'étal.

**Le marché ne filtre PAS par `objets.boite`** (René, 2026-10-09 : « tout vendre partout ») :
toute pièce achetable de toutes les boîtes est en rayon, quel que soit le thème. Un `tresor`
n'est jamais à l'étal. **Revente** (`PhaseMarche::reventePour()`,
seul point de passage) : `effet.valeur_marchande` si présent (valeur ENTIÈRE, `inventaire[].revente`
et `paniers[].ventes[].prix_revente`), sinon 50 %.

**Nouveaux mots-clés d'`effet`** (`MotsClesEquipement`, tous lus) : `franchit_mobilier`,
`ignore_terrain_entravant` (même clé que le talent, même lecteur
`MoteurSorts::terrainEntravantIgnore()`), `franchit_fosses_revelees`,
`bonus_deplacement_inconditionnel`, `des_attaque_au_contact`, `appelle_allie`
(`{mercenaire, dormance_quetes}`), `valeur_marchande`, `recupere_sort_ou_competence`, `une_par_quete`.

**`entites[].franchit_mobilier` N'EXISTE PLUS** (retiré le 2026-10-15, sans lecteur ; il était le
jumeau d'`ignore_terrain_entravant`). CE héros traverse les meubles bloquants sans s'y arrêter
(Brassards du Sauvage portés, ou buff de l'Élixir) : décidé par `MoteurSorts::mobilierFranchi()`,
lu par `DeplacementHeros::grille()` et `ResolveurTour`, et la manette reçoit la conséquence dans
`se_deplacer.parametres.destinations`, jamais l'ingrédient. Seuls les MEUBLES sont levés
(`Grille::franchirMobilier()`) : mur de glace, bloc de pierre et terrain bloquant tiennent. Le
déplacement refuse de finir sur un meuble (« On traverse un meuble… », aussi dans
`POST deplacement/apercu` → `atteignable: false`). Un déplacement qui a vraiment franchi du mobilier
ou du terrain gênant par une pièce/un buff porte `payload.franchit: ["mobilier", "terrain gênant"]`.
Fosse révélée ignorée (Élixir) : `pieges_declenches[].type === "piege_ignore"`.

**`entites[].traverse_roche` N'EXISTE PLUS** (publié du 2026-10-09 au 2026-10-15, retiré comme
`franchit_mobilier` & co, sans lecteur). CE héros traverse-t-il la ROCHE ce tour (Traverser la
Pierre, `franchit_mur`) ? Décidé par `MoteurSorts::traverseRoche()`, lu par `DeplacementHeros` ; la
manette reçoit la conséquence — les cases de roche CONNUES à portée figurent dans
`se_deplacer.parametres.destinations` — et le booléen `traverse_roche` de **`POST
deplacement/apercu`** (trajet par trajet, seul `traverse_roche` encore publié) pour prévenir avant
le second tap. Une case non connue (brouillard) reste intouchable : rien ne doit révéler de sol
inconnu.

**Brassard du Garde-Crocs** (« Utiliser un objet », option « Appeler l'allié », créneau d'action,
une fois par quête) : crée un allié *Raptor apprivoisé* (fiche existante) à la case libre la plus
proche, `groupe_mercenaires.recruteur_personnage_id` = le héros, `invoque_par_objet_id` = l'objet ;
payload `{type: usage_objet, allie: {id, nom, x, y}}`. L'allié est joué par son joueur comme tout
allié et **ne survit à aucune quête** (purgé à la victoire comme à l'échec). Raptor vaincu : le coup
fatal porte `objet_dormant: {objet, quetes, personnage}` et `inventaire.quetes_avant_reveil = 2` ;
tant que > 0 l'option disparaît du menu et `/moi` ajoute la ligne « DORMANT : se réveille après N
quête(s) terminée(s) » aux `avantages` de l'exemplaire. Chaque victoire de quête décrémente ; à zéro
le brassard est éveillé. Annonce au hub : `groupe.objets_reveilles` = `{quete_id, objets: [{objet,
personnage}]}` (dernière quête achevée), et `terminerQuete()` renvoie `objets_reveilles`.

**Potion de sagesse ancienne** (`cible: soi`) : rend le premier sort épuisé, à défaut la première
compétence « une fois par quête » dépensée (aucun choix exposé, comme la Potion de rappel) ; une par
héros et par quête (clé `potion:{nom}` du compteur `capacites_utilisees`). `utilisable` (consommables
de `/moi`) et le menu ne l'offrent que s'il y a quelque chose à rendre ; résultat annoncé
(`potion.effets.recupere: {type: sort|competence, nom}`). **Ceinture de Puissance** : `des_attaque` du
menu et de `/moi` incluent déjà le dé quand l'arme n'est pas à distance ; `classe_interdite: magicien`.

## Jungles of Delthrak — la carte (lots B, D, E, 2026-10-09)

Source : livret F9907 p. 4-5, relu à l'image. Tout n'apparaît que sous le thème
`jungles_delthrak` (`terrains.boite`, `mobiliers.boite`, `pieges.boite`) : « le gabarit
dit COMBIEN, le thème dit LESQUELS ». Aucune migration — des lignes de catalogue
(`TerrainSeeder`, `MobilierSeeder`, `PiegeSeeder`), un vocabulaire fermé de mobilier.

**Terrain** (`EtatGroupe.carte.terrain[]`, brouillard inchangé) gagne deux DÉCISIONS
booléennes publiées par le serveur : `entravant` (terrain gênant : sable, toile,
jungle — 2 points de déplacement, `cout_deplacement` vaut déjà 2) et `interdit_arret`
(Mare, Brasier : on les traverse, on n'y finit pas son mouvement). Le client ne les
déduit ni du nom ni du coût (la Rivière gelée coûte 2 sans être « gênante »).
**`carte.terrain[].avantages`** (`string[]`, 2026-10-10) : ce que FAIT la case, en phrases décidées par le
serveur (`MotsClesTerrain::avantages()`, même traducteur que `/guide`) — « coûte 2 points pour y entrer »,
« terrain gênant : coûte plus cher à traverser ». La légende l'affiche telle quelle ; le tableau de textes
local n'est plus qu'un repli. Jamais vide (une case sans effet dit « sans effet à ce jour »).

**`entites[].ignore_terrain_entravant` N'EXISTE PLUS** (retiré le 2026-10-15, sans lecteur). CE
héros traverse-t-il le terrain gênant sans payer ? Décidé par `MoteurSorts::terrainEntravantIgnore()`
(talent `ignore_terrain_entravant`, Bracers of the Wild, Spiderstep Elixir), lu par
`DeplacementHeros::grille()` et `ResolveurTour` ; la manette reçoit le RÉSULTAT — le `cout` de chaque
case dans `se_deplacer.parametres.destinations` (1 + 1 + 1 + 1 au lieu de 1 + 2 + 2 + 1 pour un
druide aux Ronces complices), jamais l'ingrédient. Les monstres **Agile** l'ignorent côté moteur
(`Grille::autoriserFranchissement()`).

**PLAQUES DE TERRAIN (2026-10-24, René : « des terrains à plusieurs cases »).** Un BLOC FIXE (Mare et Brasier,
2×2) ou un MOTIF tiré (Jungle, Toile, Sable : une forme de la liste fermée `App\Engine\FormesTerrain`) reste publié
**case par case** dans `carte.terrain[]`, avec deux champs de plus, tous deux décidés par le serveur :
- `groupe_id` (`string|null`) : les cases d'une même plaque partagent le même identifiant (`bloc-N` ou `motif-N`,
  unique dans la carte, sur le patron de `paire_id`). `null` pour une case isolée — et pour toute carte assemblée
  avant ce chantier, qui n'a pas de groupe : elle se lit exactement comme avant.
- `ancre` (`bool`) : **UNE case par groupe** porte `true` — celle du groupe, parmi les cases VISIBLES, la plus proche
  du centre de sa boîte. C'est là que se pose l'icône de REPLI d'une plaque sans pictogramme (`TERRAIN_ICONES`,
  `symboles.js`), et le dessin d'un bloc fixe. Le client ne recalcule ni le groupe, ni l'ancre, ni la forme : il DESSINE ce qu'il reçoit.
- `etendue` (`{x, y, l, h}|null`, 2026-10-24) : sur la case d'ANCRE seulement, le rectangle que couvrent les cases VISIBLES
  du groupe QUAND elles le remplissent entièrement (un bloc 2×2, une bande, deux cases en ligne). Deux usages, décidés ici :
  le **dessin d'un bloc fixe** s'étend sur ce rectangle, et l'**icône de repli** s'y centre. `null` sur un **motif qui porte
  son pictogramme sur chaque case** (chaque case dessine le sien) ; `null` pour une forme qui ne remplit pas son rectangle
  (un L) : l'icône reste sur la case d'ancre. `null` sur toute autre case.
Les trois champs sont publiés pour toute entrée (`null`/`false` hors plaque), sans clé optionnelle.

- **Mare** (*Pool of Water*) : BLOC 2×2 (une plaque, une icône) ; passage sans arrêt ; à la fouille de trésor d'une salle qui en
  contient une, option **`boire_mare`** (`type: boire_mare`, créneau d'action,
  `parametres: {soin, terrain}`), offerte au même endroit et sous les mêmes conditions que
  `fouiller_tresor`, **seulement si le héros a perdu au moins 1 Body**. Elle dépense la fouille
  du héros dans la salle, rend `soin` PV, ne tire aucune carte. Payload
  `{type: "boire_mare", salle, terrain, personnage, soin, pv_body_apres}`.
- **Cheminée** (*Healing Hearth*, First Light p. 7 — « a room containing a fireplace » : la règle
  vise la SALLE) : à la fouille de trésor d'une salle qui contient une `Cheminée`, pour un groupe
  qui joue la boîte `first_light`, option **`soin_cheminee`** (`type: soin_cheminee`, créneau
  d'action, `parametres: {soin: 1}`), offerte au même endroit et sous les mêmes conditions que
  `fouiller_tresor`, **seulement si le héros a perdu au moins 1 Body**. Même geste que la Mare : elle
  dépense la fouille du héros dans la salle, rend 1 PV, ne tire aucune carte. Décidée par
  `MoteurCheminee` (lue par le menu ET le résolveur, jamais recopiée). Payload
  `{type: "soin_cheminee", salle, personnage, soin, pv_body_apres}`, annoncé au journal, sans scène
  de table (comme la Mare).
- **Brasier** (*Bonfire*) : BLOC 2×2 (une plaque) ; passage sans arrêt ; **toute créature** qui le traverse
  lance **1 dé de combat PAR BRASIER traversé pendant un déplacement** (un par `groupe_id`, jamais un par case du
  bloc — « Any creature who moves through the bonfire must roll 1 combat die »), un crâne = −1 Body (nature `feu`).
  Héros : `payload.terrain_jets[]`
  (`{terrain, x, y, face, degats}`, un par jet, dégâts nuls compris) et, s'il y a eu dégât,
  `payload.terrain` (`{nom, chute, fin_tour, degats}`). Monstre : action `terrain_monstre`
  `{monstre, id, degats, jets[], pv_body, vaincu}`. Les alliés mercenaires ne brûlent pas
  (non porté, voir `docs/regles/carte-donjon.md`).
- **Cocon** (*Cocoon*) : mobilier (`carte.mobilier[]`, bloque mouvement ET vue). Option
  **`detruire_par_action_{index}`** (`type: detruire_par_action`, héros au contact, **aucun jet**,
  créneau d'action) ; payload `{type: "detruire_par_action", mobilier, x, y, detruit}`. Détruit,
  il disparaît de `carte.mobilier[]` comme toute pièce détruite.
- **Piège de lianes** (*Grasping Vine Trap*) : piège de sol ordinaire pour la fouille, le saut
  (Body, difficulté 2) et le désamorçage. Marcher dessus : 1 dé de combat. **Bouclier** : payload
  `pieges_esquives[]` (`{type: "piege_esquive", piege, personnage, faces}`), aucun dégât, le héros
  continue, le piège devient `detecte`. **Crâne** : −1 PV, condition `Immobilisé` (déplacement
  interdit), tour clos, état de piège **`retient`** (publié dans `carte.pieges[].etat`, entrée
  durable `retenu: personnage_id`) ; `pieges_declenches[]` porte `retenu`, `retient`,
  `condition_appliquee`. Sortie : l'option existante **`liberer_entraves`** (action du héros tenu
  ou d'un voisin au contact) ; son payload gagne `lianes_detruites[]` et le piège passe à `desarme`.

## The Mage of the Mirror — les sables mouvants (livret F7539 p. 29, 2026-10-15)

Source : quête 8 « The Gathering Storm », note C, relue au livret : « a hero must stand adjacent
to it and try to jump over the quicksand. The hero rolls one combat die… If a black shield is
rolled, the hero successfully lands on the square directly across… Any other result means the
hero lands in the quicksand and starts to sink. To avoid death, tell the hero to immediately
discard any two items (weapons, armor, potions, scrolls, etc.). This ends the hero's turn. On
the hero's next turn, the hero climbs out… onto the square across… » (René, 2026-10-15 : le
héros **choisit** les deux objets — `reference/18` et le plan disaient « au hasard », c'était
faux ; sans deux objets il tombe à 0 PV, à terre, la mort définitive étant refusée.)

**Un TERRAIN du catalogue**, pas une couche : `terrains` « Sables mouvants » (`boite =
mage_du_miroir`, `bloque_mouvement = true`, `bloque_vue = false`), trois clés du vocabulaire
fermé `MotsClesTerrain` — `saut_par_dessus: 2` (profondeur de la bande), `saut_reussi_sur:
bouclier_noir`, `enlise: 2` (objets à abandonner). `carte.terrain[]` le publie comme tout terrain
(brouillard inchangé) avec ses `avantages` en phrases décidées par le serveur ; sa case n'est ni
un passage ni un arrêt (`FabriqueGrille::pour()`, `Grille::arretInterdit()`). **Placement** :
`structure.terrains.sables: {min, max}` (nombre de BANDES, 0..1 aux quatre gabarits) ; le thème dit
si ; une bande de 2 rangées collée à un mur, jamais en salle de départ, jamais devant une
porte/un levier/une épreuve, **sans jamais couper la salle** (contournement d'au moins une case,
plancher de cases libres, une vraie ligne de saut) — la pose est abandonnée plutôt que forcée.
Écart nommé au livret : l'imprimé coupe la salle en deux ; ici le saut est un raccourci risqué.

**Le saut** — option `sauter_sables_{x}_{y}` (`x,y` = première case de sable), `type:
saut_sables`, `creneau: mouvement`, offerte **seulement** depuis une case voisine, avec une
file de sable de la profondeur exacte et une case de réception libre en face, et de quoi payer
les cases traversées (3 points : 2 de sable + la réception, par analogie avec la Fosse).
`parametres: {sable: {x,y}, vers: {x,y}, sens: n|s|e|o, cout: 3, enlise: 2}` — le serveur publie
la DÉCISION, le client ne calcule ni la ligne ni l'arrivée. Résolution : un dé de combat.
Payload `{type: "saut_sables", terrain, terrain_id, sens, depart, en_face, cout, des_lances: 1,
faces: [face], face_requise, succes, franchi, vers, deplacement_restant}` ; sur échec s'y
ajoutent `a_defausser` (0 ou 2), `tombe`, et — si le héros n'a pas deux objets —
`objets_disponibles` et `fin_de_tour: true`. La réponse de `POST /choix` porte `des` (dé
unilatéral, `defensive: "bouclier_noir"`) ; la table reçoit une scène `jet`.

**L'enlisement** — `etat_personnage_quete.enlise` (JSON, **colonne**, jamais le cache) :
`{phase: defausse|sables, a_defausser, sable: {x,y}, vers: {x,y}, terrain}`. Publié par
`entites[].enlise` = `{a_defausser, vers, terrain}` (`null` hors des sables) ; la manette et la
table en tirent un badge. Il survit au round et au snapshot (`Sauvegarde`).

- Phase **`defausse`** — menu FORCÉ, une seule option : `defausser_sables` (`type:
  defausse_sables`, `creneau: interaction`), `parametres: {restantes, objets: [{cle:
  "objet:{id}", inventaire_id, nom, quantite, porte, detail}]}`. `objets` est la **liste
  blanche** : `POST /choix {option_id: "defausser_sables", parametres: {cle}}` abandonne **un
  exemplaire** (une pile compte pour autant d'objets qu'elle en a) et le serveur republie la
  liste, jusqu'à deux. **Un « objet »** = toute unité d'inventaire du héros, **portée ou non** (le
  livret nomme les armes et armures) ; une pièce portée qui part fait recalculer ses dés
  (`Equipement::recalculerCombat()`). Le dernier abandon clôt le tour (`fin_de_tour: true`). Plus
  rien à abandonner : la liste est vide, l'option devient « Sombrer » et le héros tombe (`tombe:
  true`). Payload `{type: "defausse_sables", objet, porte, restantes, a_defausser, tombe,
  fin_de_tour}`.
- Phase **`sables`**, au tour suivant — menu FORCÉ : `sortir_des_sables` (`type: sortie_sables`,
  `creneau: tour`, `parametres: {vers}`) ; si la case d'en face est occupée, seulement
  `attendre` et une `situation` qui le dit (le livret n'en dit rien : il reste enlisé).
  Payload `{type: "sortie_sables", terrain, depart, vers}`. Le tour s'achève.
- Le résolveur refuse tout autre geste à un héros enlisé (422), même forgé.

Les trois types sont dans `JournalCombat::TYPES` (le fil dit le saut, l'abandon, la sortie).

## Garanties

- **Le moteur fait autorité** : `choix` valide l'option contre le dernier menu
  proposé + l'état ; option illégale → 422.
- **Un monstre ne meurt qu'à UN endroit** : `MoteurDegats::infligerAMonstre()`
  (chantier 2026-10-04) décide seul si 0 Body veut dire « vaincu » ou « adopte
  sa phase suivante », quel que soit le chemin de dégâts. Voir §Monstre à
  phases ci-dessus.
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
  portée restante. Une **action** jouée APRÈS un déplacement entamé forfait le reliquat
  (`perd_deplacement`, voir §`creneau`) ; avant tout pas, elle ne coûte rien au déplacement.
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

## Against the Ogre Horde — les Sorciers du Dread (livret F9528 p. 10-11, 31, 35, 37, 2026-10-16)

Trois sorts de Dread (*Mind Burst* → « Explosion mentale », *Mind Lock* → « Verrou mental », *Dominate* → « Domination ») et trois
Sorciers qui les lancent avec une **réserve de cartes par sort** : Nexrael (tier `base`, 4 cartes de Mind Burst), Xenloth (sous-boss,
5 + 5), Festral (boss, 3 + 3 + 3). Règles en vigueur : `docs/regles/sorts-dread.md` §« Les Sorciers du Dread d'Against the Ogre Horde ».

**Jet en opposition.** Chaque payload d'opposition publie la forme que le fil et la table dessinent déjà (`JetDes.vue`) :
`faces_attaque` (les dés du Sorcier, autant que son Mind), `faces_defense` (ceux du héros), `face_touchante: "crane"`,
`face_defensive`, `touches` (crânes du Sorcier), `boucliers` (boucliers blancs du héros — ou, pour *Mind Burst* qui compare des crânes
BRUTS, les crânes du héros, avec `face_defensive: "crane"`).

Formes de `sort_dread` ajoutées (`resultats[]`, une entrée par victime) :
- *Verrou mental* : `{cible, pv_mind_lanceur, pv_mind_cible, <jet>, cranes_nets, effet_applique, tours_geles?, contresort?}` — `tours_geles` =
  cartes prêtées (un crâne net = un tour, borné par la main du Sorcier). `cranes_nets: 0` → `effet_applique: false`, rien n'est posé.
- *Domination* : `{cible, pv_mind_lanceur, pv_mind_cible, <jet>, cranes_nets, cranes_requis, effet_applique, domine?, fin_domination?,
  actions_domine?: [<action>…], cartes_restantes?}`. Si `domine`, `actions_domine[]` porte, **dans l'ordre**, ce que le MJ a joué avec le héros
  dominé : `domination_deplacement`, puis `domination_attaque` OU `domination_sort`. `JournalCombat::actionsDuTour()` les étale comme des
  actions à part entière — le fil et la table les lisent chacune par son propre arm, avec le héros dominé pour acteur.
- *Explosion mentale* : `{cible, <jet brut>, pv_mind_lanceur_avant, pv_mind_cible_avant, ecart_cranes (signé : > 0 le Sorcier gagne, < 0 c'est
  le héros), pv_mind_apres, degats_mind, mind_absorbe, entre_en_choc, degats_mind_lanceur, pv_mind_lanceur_apres, lanceur_en_choc,
  cartes_restantes}`. Le perdant paie l'écart en Mind — le Sorcier aussi.

Types d'action ajoutés (tous déclarés dans `JournalCombat::TYPES`, une phrase chacun) :
- `{type: "verrou_mental_tour", personnage_id, nom, sort, lanceur: {instance_id, nom}, cartes_avant, cartes_apres, tentative: {des, faces[],
  cranes, seuil, libere}|null, libere, raison: "cartes_rendues"|"cranes"|null}` — le tour d'un héros GELÉ, à l'**ouverture du round**
  (`tour_monstres.actions`) : tour perdu, une carte rendue, puis (s'il en reste) la tentative de libération. `tentative` est `null` quand
  la dernière carte vient d'être rendue (aucun dé pour rien).
- `{type: "verrou_mental_leve", personnage_id, nom, sort, lanceur, raison: "lanceur_vaincu"}` — le Sorcier est tombé : « the effects of that
  spell immediately end ». Publié par le tampon générique (`annonces_automatiques`) du coup qui l'a tué.
- `{type: "domination_deplacement", personnage_id, personnage, de, vers, vers_heros, pieges_declenches[]}`,
  `{type: "domination_attaque", personnage_id, personnage, cible: {personnage_id, nom}, touches, boucliers, degats, pv_body_apres,
  cible_tombee, faces_attaque, faces_defense, face_touchante, face_defensive, portee?: "distance"}`,
  `{type: "domination_sort", personnage_id, personnage, sort, cible, resultat: <payload `sort` du sort lancé>}`.
  **Le héros dominé joue son arme ÉQUIPÉE** (*Against the Ogre Horde* p. 10 : « move, fight, cast spells, and perform any other action »).
  `portee: "distance"` ⇔ il TIRE : arme `portee: distance` non `jetable` (arbalète, arc, fronde), cible en ligne de vue hors contact.
  Il s'arrête sur la première case d'où il voit sa cible et tire sans marcher : `domination_deplacement` n'est alors PAS publié (rien à
  raconter). Déjà au contact de sa cible, il ne marche pas du tout (il frappe de sa case). Sans `portee`, c'est un coup au corps à corps — à MAINS NUES au contact d'une arme qui refuse le contact (`inutilisable_adjacent`,
  prédicat `Objet::interditAuContact()`). Une arme `jetable` n'est JAMAIS lancée par Zargon : il frappe au contact. Un sort, un objet et une
  capacité de classe restent hors du profil (écrit, pas oublié).
- **Le Sorcier qui vient de lancer peut encore marcher, jamais frapper** (*Against the Ogre Horde* p. 10, capacité `mouvement_apres_sort` :
  Nexrael, Xenloth, Festral). Le `sort_dread` du tour est suivi, dans la MÊME action composée (`actions_composites`), d'un
  `deplacement_monstre` ordinaire — `{type, id, monstre, depart, chemin[], vers}`, sans `vol` ni `jambe` — animé comme toute marche de monstre
  (`mouvements`). Il avance vers le héros ou l'allié le plus proche, et n'attaque pas. Aucun déplacement si son sort l'a déjà déplacé (*Fuite*),
  s'il vole déjà (`vol_draconique`), s'il est enchaîné ou immobile.

**Plusieurs rounds dans une même réponse.** Quand un gel de *Mind Lock* prend leur tour à tous les héros debout à l'ouverture du round, plus
personne ne peut déclencher la phase suivante : le moteur la rejoue d'elle-même (`tour_monstres.actions` porte alors les actions de plusieurs
rounds, dans l'ordre) jusqu'à ce qu'un héros retrouve la main — au plus la réserve du Sorcier (borne : 8 rounds).

**Fiche du héros.** La condition `Verrou mental` (`entites[].conditions[]`) publie `effet_source` : « Gelé pour N tours encore (il tient N
cartes de Verrou mental) : … » — décidé par `VerrouMental::texte()`, jamais re-déduit par le client. La condition n'a aucun compteur
(`duree: 0`) ; ce que le gel COMPTE vit dans `etat_personnage_quete.verrou_mental` `{lanceur_id, sort, cartes}` (migration
`2026_10_16_100000_verrou_mental_sur_etat_personnage_quete`), porté par le snapshot (`Sauvegarde`).

**État de choc d'un MONSTRE.** `instances_monstres.pv_mind` à 0 sur une fiche qui a du Mind : 1 dé d'attaque et 2 de défense au plus
(`InstanceMonstre::estEnChoc()`, dérivé de la jauge). Pas de nouveau champ publié.

**Réserve de cartes et garde personnelle.** `monstres.capacites.reserve_cartes` (`{sort: nombre}`) est armée à chaque placement dans
`instances_monstres.habillage.dread_etat.cartes` ; `capacites.garde_personnelle` (`{monstre, attaque, defense}`, Festral) pose
`habillage.dread_etat.garde_personnelle` sur les figures de ce nom partageant la salle du chef. Aucun des deux n'est publié tel quel.

## The Mage of the Mirror — la malédiction du loup-garou (livret F7539 p. 10-11, 2026-10-16)

Le sort de Dread *Werewolf's Curse* (« Malédiction du loup-garou ») est porté. Règles en vigueur : `docs/regles/sorts-dread.md` §« La malédiction du loup-garou ».

**Le sort.** `sort_dread` dont `resultats[]` porte, en plus des clés de contrôle, `de_malediction` (le d6 brut du lancer) : `6` → `effet_applique: false`, rien n'est posé ; tout autre résultat → `effet_applique: true` et la condition `Malédiction du loup-garou` est posée sur la cible. La résistance est `un_de_rouge_six` (`MotsClesSortDread::RESISTANCE_UN_DE_ROUGE_SIX`).

**Les deux états (conditions du catalogue, `GET /api/moi`, fiche `entites[].conditions[]`).** Aucun nouveau champ publié : chacune porte sa description, que la fiche affiche.
- `Malédiction du loup-garou` — DURABLE, `duree: 0`, effet `malediction_loup`. Elle ne tombe qu'à un remède : la **Potion d'aconit** (objet nouveau, `retire_condition`) ou la **Potion de restauration supérieure** (clause ajoutée). Elle survit à la fin de quête (source `sort_dread:`, non purgée par `reinitialiserQuete()`).
- `Forme de loup` — `duree: 0`, effet `forme_loup`, `inattaquable`, `action_interdite`. Posée au jet de transformation, retirée à la **fin de la phase des monstres** (le tour de Zargon). Un héros sous cette forme ne reçoit ni potion ni échange (`MenuMoteur` n'en propose pas, `MoteurPotions::boire()` et `SeanceEchange::resoudre()` le refusent).

**Types d'action** (tous déclarés dans `JournalCombat::TYPES`, une phrase et une scène chacun) :
- `{type: "malediction_jet", personnage_id, nom, faces: [d6, d6], total, transforme}` — le jet de DEUX dés rouges, à l'**ouverture du round** (`tour_monstres.actions` ou `captifs_repris`), pour chaque héros maudit, transformé ou non. `transforme: true` (total ≥ 10) : la forme est posée et le tour du héros est perdu (aucun menu).
- `{type: "loup_deplacement", personnage_id, personnage, de: {x, y}, vers: {x, y}, vers_heros: string|null, pieges_declenches: []}` — le pas du loup pendant la phase des monstres, **avant** les créatures de Zargon. Vitesse : celle du Loup géant (9). Aucun piège.
- `{type: "loup_attaque", personnage_id, personnage, cible: {personnage_id, nom}, touches, boucliers, degats, pv_body_apres, cible_tombee, faces_attaque, faces_defense, face_touchante, face_defensive}` — la morsure : 6 dés d'attaque (ceux du Loup géant), défense du héros mordu, source `attaque_monstre`. Une scène d'attaque (`genre: attaque`).
- `{type: "loup_reprend_forme", personnage_id, nom}` — fin de la phase des monstres : la forme se retire, le héros revient sous le contrôle de son joueur.

**Le tour du loup** est joué par la couture `HerosJoueParLeMj` avec le profil `ControleMj::loupGarou()` (interne : non publié).

**Round sans héros jouable.** Un groupe dont tous les héros sont sous forme de loup à l'ouverture du round ne reste pas figé : la phase des monstres se rejoue d'elle-même, comme pour un gel de *Mind Lock* (borne : 8 rounds). Un donjon vidé attend aussi le retour de forme avant de se clore.

**Ce que la règle ne dit pas, et que nous tranchons** (écrit, pas deviné) : la figurine du loup est inerte pendant le tour des héros (ni attaquable, ni agissante) ; les PV sont ceux du héros, sans changement à la transformation ni au retour ; les biens restent au héros, inutilisables sous forme de loup ; la morsure d'un *loup-garou* (monstre errant de la quête 7) n'est pas portée — aucun loup-garou n'est au bestiaire.

## Dangers mobiles autonomes — le rocher roulant et la brume de la mort (2026-10-23)

Décision de René (2026-10-15, Q3/Q6 de *Kellar's Keep* et de *Witch Lord*) : **une seule mécanique** « danger mobile
autonome », deux catalogues (`dangers`, `DangerSeeder`) — rocher roulant (*Kellar's Keep* q. 3, F4543 p. 12-13) et
brume de la mort (*Return of the Witch Lord* q. 3, F4193 p. 13). État durable : `cartes.grille['dangers']`. Règles :
`docs/regles/carte-donjon.md` § « Dangers mobiles autonomes ».

### Publication — `EtatGroupe.carte.dangers[]`

- Forme : `[{nom, x, y, etat}]`, `etat` ∈ `roule` (rocher en mouvement), `actif` (brume), `bloc` (rocher arrêté : passage
  bloqué, dessiné en bloc plein).
- Filtrée par le brouillard (une case non connue n'est pas publiée). **Jamais publié** : le rocher en attente (`attente`,
  il tombera au passage de sa case déclencheur) et la brume détruite (`detruit`).
- Le serveur publie la décision : ni trajectoire ni destruction ne sont recalculées côté client.

### Frapper une brume — option `frapper_danger_{index}`

- `type: 'frapper_danger'`, `parametres: {danger: index, nom}` ; offerte dans le menu d'un héros au **contact orthogonal**
  d'une brume `actif` (`MenuMoteur`, `MoteurDanger::brumesAuContact()`). Icône manette : `swords` (`ActionTab.vue`).
- `POST /choix` `{option_id: "frapper_danger_{index}"}` : le résolveur revérifie l'index (`entreeBrume()`, 422 si périmé)
  et le contact (422 hors de portée). Une **Lame des Esprits** en main détruit la brume ; une autre arme ne lui fait rien
  (`danger_sans_effet`). Le coup coûte l'action du héros.

### Tempête contre une brume — `lancer_sort` avec `cible_type: 'danger'`

- Les brumes actives rejoignent la liste `cibles[]` **du seul sort qui les détruit** (la Tempête, `cible: monstre` au
  catalogue) : `{type: 'danger', id, nom}`. `id` est **à partir de 1** (`index + 1`), comme toute cible — la validation de
  `parametres.cible_id` exige `min:1`. La ligne de vue s'applique comme aux monstres.
- `POST /choix` `{option_id: "lancer_sort", parametres: {cle: "sort:{id}", cible_id, cible_type: "danger"}}`.
  `cible_type` accepte désormais `monstre | heros | danger` (`ChoixController`).
- La Tempête n'a **aucun autre effet** sur la brume (ni tour sauté, ni jet de Mind) : elle la détruit.

### Journal — types `danger_*` (`JournalCombat::TYPES`, déclarés et rendus)

| `type` | Payload | Quand |
|---|---|---|
| `danger_chute` | `{danger, x, y, heros}` | le dernier héros debout a franchi la case déclencheur du rocher |
| `danger_roule` | `{danger, distance, x, y, bloc, touches: [{heros, degats, faces}]}` | tour du MJ : le rocher roule de la somme de deux dés rouges ; `bloc` s'il s'écrase au mur ; `faces` = les dés de combat (sans défense) |
| `danger_avance` | `{danger, x, y, pas, touches: [{heros, degats, faces: []}]}` | tour du MJ : la brume glisse ; `faces` vide (coup sec, 1 PV par héros traversé) |
| `danger_detruit` | `{danger, par, heros, x, y}` | Lame des Esprits au contact, ou Tempête |
| `danger_sans_effet` | `{danger, heros, arme, x, y}` | frappe au contact sans arme qualifiée |

Les mouvements (`danger_roule`, `danger_avance`) sont journalisés par le moteur au tour du MJ, en tête de
`ResolveurTour::phaseMonstres()` — et, quand le donjon est NETTOYÉ (plus aucun monstre actif, `phaseMonstres()` sautée),
dans la branche de `ouvrirNouveauTour()` : la quête continue, Zargon joue encore.

### Clé de réponse — `tour_dangers`

- Présente seulement dans la branche « donjon nettoyé » (où `tour_monstres` n'existe pas) : la liste des payloads
  `danger_*` joués ce tour. Dans les autres cas les mouvements passent par `tour_monstres` (la phase des monstres).

## Magrian (Rise of the Dread Moon) et le Deflect de Gruzbella — réactions nouvelles (chantier EXT-MAGRIAN, 2026-10-23)

**Contrat ajouté avant le payload**, comme la règle l'exige. Toutes ces réactions sont à usage unique, sans action,
pour la rencontre (`capacites_reactives_utilisees`, colonne du snapshot) ; le vocabulaire est fermé
(`MotsClesReactionMj::CAPACITES`, testé dans les deux sens).

- **`reactions_mj[]`** (*Le MJ réagit au sort d'un héros*) — nouvelle `mecanique` **`"consomme_magie"`** (*Consume Magic*),
  `issue: "annule"` : le sort est annulé (`sort_annule: true`, sans effet) et le porteur regagne des PV de Body.
  Champ ajouté : **`soin`** (entier) = PV de Body réellement regagnés, plafonnés au maximum de la figure (`0` si elle y
  est déjà). Politique : la capacité ne vise que le sort qui prend le camp du MJ (un sort sans cible monstre n'est pas
  une réaction), quel que soit le monstre visé ; elle n'exige ni vue, ni éveil.

- **`reaction_monstre`** (valeur d'une action qui blesse une figure, et entrée de journal `type: "reaction_monstre"`) —
  deux valeurs nouvelles :
  - **`"redirige_attaque"`** (*Deflect* de Gruzbella) : l'action est une `attaque` de HÉROS dont la **cible est un HÉROS** :
    `cible: {personnage_id, nom}`, `personnage` (l'attaquant), `touches`, `boucliers`, `degats`, `pv_body_apres` (du héros
    frappé), `cible_tombee`, et **`detournee_de: {instance_id, nom}`** (la figure qui a détourné le coup). Le héros frappé
    répond avec SA défense (`HerosJoueParLeMj::frapperUnHeros()`, la même frappe que la Domination).
  - **`"reflexion"`** (*Reflection* de Magrian) : le coup qui l'aurait achevée retombe sur un héros en vue. L'action
    d'attaque porte `degats: 0` et `pv_body_apres` de la figure **inchangé**. L'entrée de journal `reaction_monstre`
    (`mecanique: "reflexion"`) porte **`cible: {personnage_id, nom}`** : c'est elle qui nomme le héros touché, et les
    dégâts qu'il subit sont ceux du coup, sans jet de défense.

- **Événements `reaction_monstre` de début de tour** :
  - **`mecanique: "terreur"`** (*Terror*) — posée au début du tour du PREMIER héros (ordre de la liste des états) qui a
    une figure en vue capable de Terror, `cible: {personnage_id, nom}`. Pour CE tour seulement, la figure est
    intouchable par les sorts et les coups de ce héros : le menu ne propose pas la cible (`ciblesPourArme`,
    `ciblesBalayees`, `genererBrut`, liste des sorts), et le résolveur refuse (`frapper()`, rayon, zone). Levée à la fin
    de SON tour (« Terminer le tour ») : un événement `condition_terminee` (`condition: "terrorisé…"`,
    `pourquoi: "la durée est écoulée"`) l'annonce. Un filet au début du tour du MJ lève toute Terreur restante.
    État durable : `habillage.conditions.terrorise` (libellé `terrorisé (intouchable pour le héros dont c'est le tour)`)
    et `habillage.terreur_de` (identifiant du héros désigné).
  - **`mecanique: "realite_decalee"`** (*Shift Reality*) — au début du tour du MJ, avant les créatures : `sort` = le nom
    du sort de Dread lancé (le premier de son répertoire encore disponible, dans l'ordre du répertoire). Le sort qui suit
    est un `sort_dread` ordinaire, qui paie son budget (sauf « à volonté »). Sans sort utilisable, rien n'est dépensé.

- **`attaque`** — champ **`detournee_de`** `{instance_id, nom}` uniquement sur une frappe détournée (voir
  `redirige_attaque` ci-dessus). Le fil de combat le lit via la phrase de `reaction_monstre`.

Rendu : **sans nouveau code front** — les phrases sont dans `JournalCombat` (`reaction_monstre`, `reactions_mj`),
`LignesEffetsAutomatiques` (entrées de journal), `SceneDeTable` (défense réactive) ; le compendium
(`resources/js/compendium.js`) porte les libellés des cinq clés.
## Kellar's Keep & Spirit Queen — salle dangereuse, portes à condition, métamorphe (2026-10-23, chantier EXT-RESTES)

Source : livret F4543 (Kellar's Keep, q. 4 note D p. 15 ; q. 6 note A p. 19 ; q. 9 notes A et D p. 25) et livret G0053 (Spirit
Queen's Torment, q. 13 note A p. 31). Règles en vigueur : `docs/regles/carte-donjon.md`, `docs/regles/combat-et-tour.md`,
`docs/regles/bestiaire-et-rencontres.md`.

### Menu — `POST /choix`

- **Désamorcer une fournaise** : une option `type: "desamorcage"` (la MÊME que le piège), avec
  `parametres: {mobilier: {x, y}}` au lieu de `parametres.piege`. `jet: {attribut: "body", difficulte}` n'est publié que pour
  un héros qui lance un jet de Body ; un Nain ou un Explorateur (« sans outils ») reçoit le libellé « 1 dé de combat (seul le
  bouclier noir rate) » sans `jet`. Présente si le héros peut désamorcer (`MoteurPieges::peutDesamorcer()`) et est au contact
  orthogonal du meuble ; le résolveur revalide la même question sur l'état vivant. **Jamais** pour la forge des nains.
- **Forcer une porte à condition** : `type: "forcer_porte_condition"`, `id: "forcer_porte_condition_{x}_{y}_{cote}"`,
  `parametres: {porte: {x, y, cote}}`. Le libellé porte le seuil chiffré (« 2 dés rouges, total inférieur à ton Corps de départ
  (8) »). Interaction libre : ouvrir ne ferme pas le tour, un jet raté le ferme. Icône manette : `lock_open`.

### Résultat d'action

- `desamorcage_mobilier` : `{type, option_id, libelle, mobilier: {nom, x, y}, succes, desarme, methode: "trousse"|"sans_outils",
  …détail du jet}`. Journalisé `jet`. Un échec ne frappe pas et ne ferme pas le tour.
- `forcer_porte_condition` : `{type, option_id, libelle, porte: {x, y, cote}, verrou, attribut: "corps"|"esprit",
  comparaison: "strict"|"inclusif", valeur, faces: [d6…], total, reussi}`. `faces` a UN élément pour le Nain devant la porte de
  l'Est, DEUX sinon. `reussi: true` révèle la salle derrière la porte.

### Annonces — `annonces_automatiques` (tampon générique, `TamponAnnonces::annoncer()`)

| `type` | Payload | Quand |
|---|---|---|
| `salle_dangereuse` | `{moment: "fin_deplacement"\|"fin_tour", mobilier: {nom, x, y}, personnage: {id, nom}, faces, touches, degats, pv_body_apres, tombe}` | un héros finit son déplacement (forge) ou son tour (fournaise) DANS la salle : un dé de combat, un crâne = 1 PV. Une salle = un dé, quel que soit le nombre de meubles. Annoncé même sans crâne. |
| `metamorphe_tirage` | `{monstre, carte, tire, definitif}` | à chaque mort du métamorphe : la carte tirée ; `definitif: true` quand elle égale la carte qui vient de tomber (mort pour de bon) |

`JournalCombat::TYPES` déclare `salle_dangereuse`, `desamorcage_mobilier`, `forcer_porte_condition`, `metamorphe_tirage` ; le test
`AnnoncesEffetsAutomatiquesTest` les confronte aux sources dans les deux sens. La table ne rend pas de scène pour ces types : le
fil les dit, comme pour le coup de corne (`coups_de_corne`).

### Carte — `EtatGroupe.carte.portes[].verrou`

- Nouvelle valeur : `"condition"` (porte à condition, Kellar's Keep). Son état reste `fermee` : c'est un jet, pas une clé. La
  manette affiche le libellé de l'option, jamais le type du verrou seul (`ApercuSalle` le traduit en « jet à réussir »).
- Une porte à condition ne se pose que sur une arête de BOUCLE, au plus une par carte (`AssembleurCarte::marquerPortesDeCondition()`).

### Meubles — `mobiliers.effet.salle_dangereuse` (vocabulaire `MotsClesMobilier`)

`{moment: "fin_deplacement"|"fin_tour", exempte: "nain"|null, desamorcable: bool}`. Une cellule active (posée, non détruite,
non désarmée) ; l'état `desarme: true` est écrit dans `cartes.grille.mobilier[i]` (durable, jamais en cache).

### Fiche — `monstres.capacites.metamorphe`

`{forme_de_depart: "Abomination", paquet: ["Gobelin", "Squelette", "Orque", "Zombie", "Momie", "Guerrier du Chaos", "Gargouille",
"Abomination"]}` : le paquet déclaré (les huit cartes 2021 de la boîte de base, ordre déclaré). La forme courante reste dans
`instances_monstres.monstre_id`, l'origine dans `origine_monstre` (sans migration). Un changement de forme se dit par
`changement_phase` comme toute forme, sans `synchro` : les sorts actifs ne cessent pas.
