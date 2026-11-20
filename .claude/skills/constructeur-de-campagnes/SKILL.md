---
name: constructeur-de-campagnes
description: >-
  Utiliser pour tout travail sur le CONSTRUCTEUR DE CAMPAGNES du mode classique : son écran (/constructeur), son
  serveur MCP, le format du document de campagne, sa validation, et la saisie ou la relecture d'une campagne de
  livret officiel. Impose l'ordre complet quand un réglage de livret est ajouté (validation → moteur → catalogue →
  écran → MCP → guide → tests) et les interdits sur les campagnes réelles. Déclencheurs : « le constructeur »,
  « une campagne construite », « ajoute une règle de livret », « la quête N du livret ne colle pas », « relis la
  campagne avec son livret », « le MCP du constructeur », « ValidationCampagne », « CarteConstruite »,
  « quete_modifier », « campagne-essai », « le guide du constructeur ».
---

# Le constructeur de campagnes

Une **campagne construite** est un document JSON (format 1) : un plateau, puis des quêtes posées dessus. Le mode
classique la joue quête après quête. Le constructeur a **deux clients**, l'écran `/constructeur` et le serveur MCP, et
**une seule règle** : `ValidationCampagne`, appelée par les deux avant toute écriture.

Le mode d'emploi pour un humain est la page `/constructeur/guide` (`GuideConstructeurView.vue`). Cette skill dit quoi
modifier dans le code, et dans quel ordre. Elle renvoie aux fichiers au lieu de les recopier.

## ⚠ Les campagnes sont de la production

- Les campagnes enregistrées en base (`campagnes_construites`) sont **de la production**, comme les groupes. Celles de
  René (`heroquest-livret-de-quetes`, `la-forteresse-de-kellar`, `le-retour-du-seigneur-sorcier`…) ne s'écrasent
  **jamais** sans son accord explicite.
- Avant d'en écrire une : `./image-tools/sauvegarder.sh` puis `--verifier`. Écrire ensuite par le service
  (`CampagnesConstruites::enregistrer($doc, $slug)`, qui valide d'abord), relire le document en base et le comparer au
  fichier source.
- Un groupe déjà en quête garde la carte qu'il joue : une correction s'applique à sa quête suivante. Vérifier quand
  même `Groupe::where('campagne_construite_id', …)` avant d'écrire, et le dire à René.
- Pour essayer : **uniquement** des slugs `essai-*`, chargés et retirés par
  `browser-shots/campagne/campagne-essai.sh charger <fichier> | retirer <slug>`. Le script refuse tout autre slug.
- **Aucun contenu de livret officiel dans le dépôt** : ni quête saisie, ni page, ni rendu de carte. Les fichiers de
  relecture vivent hors dépôt (`~/heroquest-livrets/…`). Exception écrite par René : les petits symboles de carte
  (`public/images/livrets/`). Les exemples versionnés sont inventés (`browser-shots/campagne/essai-*.json`).
- Dans **chaque brief d'agent**, mot pour mot, la phrase de CLAUDE.md sur la production **et** : « Les campagnes
  construites sont aussi de la **production** : seules les campagnes `essai-*` se chargent et se retirent, avec
  `browser-shots/campagne/campagne-essai.sh` ». Plus, comme toujours : « N'utilise AUCUN outil de surveillance ni de
  sous-agent ».
- Rien du constructeur ne doit toucher le mode RPG ni l'arène (mémoire « branche classique isolée ») : un meuble ou un
  mercenaire ajouté pour un livret entre dans la liste `RESERVES_AU_CLASSIQUE` de son moteur.

## Où vit quoi

| Rôle | Fichier |
|---|---|
| Le format, les clés et les refus (point de passage unique) | `app/Partie/Classique/ValidationCampagne.php` (constantes `CLES_*`, `CODES`) |
| Lire, écrire, exporter, importer, écrire une quête | `CampagnesConstruites.php`, `EmpreinteCampagne.php` |
| Les opérations de `quete_modifier` | `OperationsDeQuete.php` (`ajouter`, `retirer`, `remplacer`) |
| Les slugs et la palette publiée | `CatalogueConstructeur.php` (`DEFINITIONS`, `payload()`) |
| Le document → la carte jouée | `CarteConstruite.php` (même forme que `AssembleurCarte::assembler()`) |
| Le départ d'une quête | `DemarreurQueteConstruite.php`, `ChoixCampagne.php` (prérequis) |
| Les notes « quand → alors » | `NotesDeQuete.php`, vocabulaire fermé `App\Engine\MotsClesQuete` |
| La fin, la récompense | `FinDeQueteConstruite.php` |
| Les salles, salles en L comprises | `App\Partie\Salles` (`rectangles()`, `contientCase()`, `cases()`) |
| Les règles de livret | `Charge`, `Escorte`, `VerrouDeSortie`, `Confiscation`, `Glyphes`, `Deguisement`, `ObscuriteMagique`, `QueteDouble`, `MercenairesCampagne`, `Reputation`… dans `app/Partie/Classique/` |
| Routes HTTP | `routes/api.php`, préfixe `constructeur` (`ConstructeurController`) |
| Serveur MCP | `routes/ai.php`, `app/Mcp/ServeurConstructeur.php`, `app/Mcp/Outils/*` |
| L'écran | `resources/js/views/ConstructeurView.vue`, `components/constructeur/ProfilMonstre.vue`, `TableDeCases.vue` |
| Le guide | `resources/js/views/GuideConstructeurView.vue`, images `public/images/guide-constructeur/` |
| Le contrat | `docs/contrat-api.md` : « Constructeur de campagnes », « Serveur MCP du constructeur », puis une section par règle |
| Le plan et l'historique | `docs/plan-mode-classique.md` |
| Les tests | `tests/Feature/Classique/` (dont `Mcp/`, `ValidationCampagneTest`, `LivretsConformesTest`, `ConstructeurCouvreLaValidationTest`) |

## Ajouter un réglage demandé par un livret — l'ordre complet

Le 2026-11-20, quatre réglages ont atteint la validation et le moteur, mais pas le catalogue, l'écran, le MCP ni le
guide. L'éditeur de l'effet `eveiller` était même faux. Chaque étape ci-dessous est obligatoire, ou la raison de la
sauter est écrite.

1. **La règle, sourcée** : citer le livret et sa page. Si le moteur ne sait pas la jouer, passer d'abord par la skill
   `ajouter-mecanique-moteur` : vocabulaire fermé → lecteur → test **en jeu**. Pas de clé décorative.
2. **Le contrat** : `docs/contrat-api.md` d'abord. La clé du document, sa forme, ce que le moteur publie et ce qu'il
   journalise.
3. **La validation** : la clé entre dans la bonne liste `CLES_*` de `ValidationCampagne`, avec ses refus (un code de
   `CODES`, un `chemin` précis, un message qui dit quoi corriger). Un slug se vérifie contre `CatalogueConstructeur`.
4. **Le moteur** : le lecteur lit la clé (au départ par `DemarreurQueteConstruite`, ou en jeu). Un événement de jeu
   passe par `NotesDeQuete::signaler()`. Un nouvel effet de note entre dans `MotsClesQuete::EFFETS` avec ses
   `parametres`, testé dans les deux sens par `MotsClesQueteTest`. Une action annoncée entre dans `JournalCombat::TYPES`.
5. **Le catalogue publié** : si la clé désigne un slug d'une table que `CatalogueConstructeur::DEFINITIONS` ne
   connaît pas encore, l'ajouter **et** le publier dans `payload()`. Sans cela, ni l'écran ni une IA ne trouvent le
   slug (cas des sorts et des captifs). Un vocabulaire fermé se publie tel quel dans `vocabulaires`.
6. **L'écran** : une commande pour la clé, dans `ConstructeurView.vue` ou `ProfilMonstre.vue`.
   - L'écran modifie le document **en place** : une clé sans commande survit à l'enregistrement, mais personne ne peut
     la poser. Une case vide **retire** la clé, elle ne laisse jamais une valeur vide.
   - Un paramètre d'effet de note qui porte des **index** (monstres posés, portes) a son propre éditeur, pas celui
     d'un paramètre homonyme. `monstres` veut dire des apparitions pour `apparition` et des index pour `eveiller`.
   - Toute désignation par index est **recalée** dans `recaler()` quand l'élément visé est retiré.
   - Reconstruire le front (`npm run build`) et regarder l'écran dans un navigateur, sur une campagne `essai-*`.
7. **Le MCP** : les outils passent par les services, donc rien à recoder. Relire les `#[Instructions]` de
   `ServeurConstructeur` si la règle change ce qu'une IA doit savoir (« tout slug vient de `catalogues` »…).
8. **Le guide** : une ligne dans `GuideConstructeurView.vue`, et une capture si l'écran a changé de façon visible
   (procédure : skill `outillage-dev-et-tests`, « Les captures du guide du constructeur »).
9. **Les tests** : la validation (accepte, refuse), le jeu (la règle tient en partie), et
   `ConstructeurCouvreLaValidationTest`. Ce dernier échoue si une clé acceptée n'est nommée nulle part dans l'écran,
   ou si un catalogue consulté n'est pas publié. Il ne prouve que la **présence** du nom : un éditeur faux le passe
   quand même.
10. **Les données** : seulement ensuite, poser la clé dans les campagnes. Les campagnes de René : sauvegarde
    vérifiée, puis son accord.

## Le document : les conventions qui trompent

- **Les index** : `fin.cibles`, `quand.cible.monstre|porte`, `ouvrir_porte.porte`, `eveiller.monstres` désignent des
  monstres ou des portes **par leur rang** dans la quête. Retirer ou réordonner un élément décale les suivants : il faut
  tout recaler (l'écran le fait, un script qui édite le JSON doit le faire aussi). `ouvrir_porte` accepte aussi
  `"sortie"`, pour la porte de sortie verrouillée.
- **Les murs sont des arêtes** : une porte se pose sur le bord `(x, y, cote)` d'une case. Une porte d'accès (entrée ou
  sortie de type `porte`) doit donner sur la **roche, un bloc ou le bord du plateau**, sinon `porte_sans_bord`.
- **Les salles en L** : `plateau.salles[].parties: [{x, y, l, h}]`. Toute la géométrie passe par `App\Partie\Salles`,
  jamais par un `x/y/l/h` lu à la main. L'écran les dessine mais ne les trace pas.
- **Le mobilier** : `rotation` change l'emprise, que `payload()` publie par rotation. Le meuble entier doit tenir dans
  une salle ouverte **ou** entièrement dans un couloir (`mobilier_hors_salle` sinon). Deux poses ne partagent jamais
  une case (`case_partagee`).
- **Les slugs** sont `Str::slug()` du nom catalogue (`CatalogueConstructeur::slug()`). Aucune table n'a de colonne
  slug. Renommer une ligne du catalogue change son slug et casse les campagnes qui la nomment : c'est une migration
  **et** une correction des documents.
- **`salles_ouvertes`** : le donjon de la quête. Une salle fermée est de la roche.
- **Un réglage par livret = une clé**, pas un drapeau générique. Les clés existantes sont dans `CLES_*` et dans le
  contrat : relire les deux avant d'en créer une. Une règle de livret ressemble souvent à une règle déjà là (« c'est
  le même principe que la gargouille », René).

## Saisir ou relire une campagne de livret

Méthode de la relecture du 2026-11-19 / 20 (34 quêtes) :

1. **Les sources** : les PDF Hasbro sont en local, hors dépôt (`~/heroquest-livrets`, mémoire « livrets officiels en
   local »). Le **plateau officiel** est la carte vierge du livret de base. Les livrets sont **maîtres** en mode
   classique (René) : on se conforme au livret, on ne l'arrange pas.
2. **Mettre les deux côte à côte** : rendre la page de la carte du livret, puis capturer la grille de chaque quête
   dans l'écran (`node browser-shots/relecture-cartes.mjs "<titre>" <préfixe>`, en lecture seule). On compare case
   par case : salles ouvertes, portes et leur côté, portes secrètes, blocs et roche, meubles et leur rotation,
   monstres, pièges, escalier, notes.
3. **Les erreurs déjà commises** (vérifier soi-même ce que signale un agent ou un script) : un râtelier d'armes pris
   pour un chevalet de torture et des portes oubliées (agents) ; des icônes de piège rouges prises pour de la roche
   (premier passage automatique, à la couleur seule). Une icône
   inconnue se compare d'abord, à l'image, aux meubles qu'on a déjà, avant d'en créer un (cercueil = tombeau, sauf
   preuve contraire).
4. **Ce que l'écart demande** : une erreur de saisie se corrige dans le document. Une règle que le moteur ne sait pas
   jouer passe par « Ajouter un réglage », ci-dessus. Ce qu'on ne sait pas trancher se demande à René, avec la page
   du livret.
5. **Valider sans écrire** (outil MCP `valider`, ou `ValidationCampagne` par tinker), puis charger une copie `essai-*`
   et la regarder dans l'écran. Les fichiers corrigés restent hors dépôt.
6. **Appliquer** à la vraie campagne seulement sur feu vert, après une sauvegarde vérifiée (voir « Les campagnes sont
   de la production »).

## Le serveur MCP

- Lancement (stdio, sans port ni jeton) :
  `docker compose exec -T app php -d display_errors=stderr artisan mcp:start constructeur`. Sans
  `display_errors=stderr`, un avertissement PHP s'imprime sur la sortie standard et casse le protocole.
- Branché dans Claude Code par `claude mcp add constructeur -- docker compose -f <dossier>/docker-compose.yml exec -T
  app php -d display_errors=stderr artisan mcp:start constructeur`. Les outils n'apparaissent qu'au **démarrage**
  d'une session.
- Onze outils (`catalogues`, `campagnes`, `campagne_lire`, `quete_lire`, `apercu`, `valider`, `campagne_ecrire`,
  `quete_ecrire`, `quete_modifier`, `exporter`, `importer`), registre testé dans les deux sens
  (`tests/Feature/Classique/Mcp/RegistreTest.php`). Un outil n'a **jamais** sa propre règle : il appelle le service de
  la route HTTP.
- ⚠ `campagne_ecrire`, `quete_ecrire`, `quete_modifier` et `importer` **écrivent en production**. Il n'y a pas d'outil
  de suppression, mais une écriture remplace la campagne du même slug. Les règles de « production » ci-dessus valent
  pour le MCP comme pour un script.
- Une session MCP ouverte garde l'ancien code après un changement PHP, comme les workers de file : la relancer.
- L'éprouver sans client : envoyer `initialize`, `notifications/initialized`, `tools/list` puis des `tools/call` en
  JSON-RPC, une ligne chacun, sur l'entrée standard de la commande de lancement. La sortie d'erreur doit rester vide.

## Definition of done

- [ ] Règle sourcée (livret, page) ; moteur d'abord si l'effet est inédit
- [ ] Contrat mis à jour avant le code
- [ ] Validation : clé dans `CLES_*`, refus nommés, slug vérifié
- [ ] Catalogue publié si la clé nomme un slug d'une table nouvelle
- [ ] Écran : une commande, index recalés, front reconstruit, regardé dans un navigateur
- [ ] Instructions MCP relues ; guide (et capture) à jour
- [ ] Tests verts, `ConstructeurCouvreLaValidationTest` compris ; suite complète avant un commit poussé
- [ ] Workers redémarrés après un changement PHP (`docker compose restart queue queue-jeu`)
- [ ] Campagnes réelles : seulement sur feu vert, après une sauvegarde vérifiée ; essais en `essai-*`, retirés ensuite
