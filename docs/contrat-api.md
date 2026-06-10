# Contrat API & temps réel — prototype vertical

> Contrat partagé entre le serveur (Laravel) et le front (Vue). Toute évolution
> se fait ici d'abord. Réfs : doc 11 §4 (flux d'un tour), §7 (canaux).

## Authentification

Session Laravel (cookie) — login simple interne. `POST /api/connexion`
`{identifiant, mot_de_passe}` → `{joueur: {id, pseudo}}`. Routes protégées par
middleware `auth` sauf connexion.

## Endpoints

| Méthode | Route | Corps | Réponse |
|---|---|---|---|
| POST | /api/connexion | {identifiant, mot_de_passe} | {joueur} |
| POST | /api/deconnexion | — | 204 |
| GET | /api/moi | — | {joueur, personnages: [...]} |
| POST | /api/groupes | {nom, theme, longueur, ton} | {groupe} + dispatch squelette |
| POST | /api/groupes/{identifiant}/joueurs | {personnage_id} ou {nom, classe} | {personnage} (rejoint le groupe) |
| GET | /api/groupes/{identifiant}/etat | — | **EtatGroupe** (voir ci-dessous) |
| POST | /api/groupes/{identifiant}/quetes | — | {quete} — démarre la quête suivante (assemble carte, spawn monstres, initiative) |
| POST | /api/groupes/{identifiant}/choix | {option_id, parametres?} | 202 — le moteur résout, l'état et la narration arrivent par Reverb |

## EtatGroupe (GET etat + broadcast `.groupe.etat`)

```json
{
  "groupe": {"identifiant": "...", "nom": "...", "phase": "hub|quete", "or": 0, "etat": "en_cours"},
  "quete": {"id": 1, "titre": "...", "type_jalon": "normale", "etat": "en_cours"} ,
  "carte": {"largeur": 12, "hauteur": 10, "cases": [["m","s","p"]]},
  "entites": [
    {"type": "heros", "id": 1, "nom": "...", "classe": "nain", "x": 2, "y": 3,
     "pv_body": 6, "pv_body_max": 8, "pv_mind": 4, "pv_mind_max": 4, "tombe": false},
    {"type": "monstre", "id": 9, "nom": "<habillage IA ou nom_base>", "x": 5, "y": 4,
     "pv_body": 2, "pv_body_max": 2, "etat": "actif"}
  ],
  "initiative": [{"entite": "heros|monstre", "id": 1, "nom": "...", "a_joue": false}],
  "narration": "dernier texte du MJ",
  "mj_reflechit": false
}
```

`quete`/`carte`/`entites`/`initiative` sont `null`/`[]` en phase hub.

## Canaux Reverb (préfixe d'événement = broadcastAs)

| Canal | Événement | Payload | Écouté par |
|---|---|---|---|
| `groupe.{identifiant}` (private) | `.narration.diffusee` | {texte} | table |
| `groupe.{identifiant}` | `.groupe.etat` | EtatGroupe | table + manettes |
| `groupe.{identifiant}` | `.mj.reflechit` | {actif} | table + manettes |
| `joueur.{id}` (private) | `.menu.propose` | {menu: {contexte, options: [{id, libelle, type: "action|dialogue|jet|attaque|deplacement", parametres}]}} | manette du joueur |

Autorisations (routes/channels.php) : `groupe.{identifiant}` → le joueur a un
personnage actif dans ce groupe ; `joueur.{id}` → id === joueur connecté.

## Garanties

- **Le moteur fait autorité** : `choix` valide l'option contre le dernier menu
  proposé + l'état ; option illégale → 422.
- **L'API ne dépend jamais du LLM** : si le job IA échoue (pas de clé, erreur),
  repli (menu générique / narration neutre) — le jeu reste jouable.
- Toute mutation d'état passe par un événement journalisé (`evenements`) puis
  un broadcast `.groupe.etat`.
