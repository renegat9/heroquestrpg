# Conception — Schéma de données & base de connaissance

> Document technique. Détaille le doc 11 (§5-6) : le schéma **MariaDB** (relationnel) et la structure **Qdrant** (bible RAG). Types MariaDB indicatifs, implémentables en migrations Laravel.

---

## 1. Organisation

- **MariaDB** = état de jeu **exact**, journal, sauvegardes et **catalogues** de référence.
- **Qdrant** = **lore sémantique** (bible) récupéré par RAG.
- Principe (doc 07) : l'état vivant n'est **jamais** en Qdrant ; Qdrant ne porte que la bible.

Quatre groupes de tables MariaDB : **Comptes & roster**, **Campagne & session**, **Journal & sauvegarde**, **Catalogues**.

---

## 2. MariaDB — Comptes & roster

### `joueurs`
| Colonne | Type | Note |
|---|---|---|
| id | BIGINT PK | |
| pseudo | VARCHAR | |
| identifiant | VARCHAR UNIQUE | login simple (cadre interne) |
| mot_de_passe | VARCHAR | hash |
| timestamps | | |

### `personnages`
| Colonne | Type | Note |
|---|---|---|
| id | BIGINT PK | |
| joueur_id | FK → joueurs | propriétaire (roster) |
| groupe_actif_id | FK → groupes NULL | un seul groupe actif à la fois |
| nom | VARCHAR | |
| classe | ENUM(barbare,nain,elfe,magicien) | |
| niveau | INT | progression **par jalons**, liée aux quêtes (P6) |
| attribut_body / attribut_mind | INT | dés de jet |
| pv_body_max / pv_body | INT | jauge physique |
| pv_mind_max / pv_mind | INT | jauge mentale |
| des_attaque / des_defense | INT | combat |
| deplacement_base | INT | total/tour = base + 1d6 |
| or | INT | bourse **personnelle persistante** (roster) |
| timestamps | | |

> En campagne, l'or est **commun** (`groupes.or`, M3) ; à la **clôture**, il est réparti vers `personnages.or`. `capacite_sac` **dérivée** (PV Body max ÷ 2 + Nain +1 + nœuds), calculée à la volée.

### `personnage_competences` (pivot)
| id PK · personnage_id FK · competence_id FK | nœuds d'arbre acquis |

### `inventaire`
| Colonne | Type | Note |
|---|---|---|
| id | BIGINT PK | |
| personnage_id | FK | |
| objet_id | FK → objets | |
| emplacement | ENUM(arme_principale,arme_secondaire,armure,sac,consommable) | |
| quantite | INT | consommables |
| ameliorations | JSON NULL | bonus de **Forge** (Nain) attaché à cet exemplaire (réfère `forge_ameliorations`) ; permanent, suit l'objet aux échanges et à la répartition |

> Capacité du **sac** vérifiée par le moteur (et à la confirmation de phase marché).

### `personnage_sorts` (pivot)
| id · personnage_id FK · sort_id FK · disponible BOOL | épuisé/dispo (récup. par quête) |

### `personnage_conditions`
| id · personnage_id FK · condition_id FK → conditions · duree INT · source VARCHAR | (les instances de monstres portent des conditions de la même façon) |

### `personnage_historique`
| id PK · personnage_id FK · groupe_nom VARCHAR · theme VARCHAR · resume TEXT · issue VARCHAR · niveau_atteint INT · termine_le TIMESTAMP | un résumé par campagne terminée ; **survit au nettoyage** du groupe |

---

## 3. MariaDB — Campagne & session

### `groupes`
| Colonne | Type | Note |
|---|---|---|
| id | BIGINT PK | |
| identifiant | VARCHAR UNIQUE | code saisi sur la tablette |
| nom | VARCHAR | |
| theme | TEXT | fantasy (cohérence d'univers) |
| longueur | ENUM(tres_courte,courte,normale,longue,tres_longue) | |
| nb_quetes_total | INT | dérivé de la longueur |
| plan_campagne | JSON | **squelette** : prémisse, menace/boss, jalons, fils narratifs (généré à la création, doc Quêtes §2) |
| ton | VARCHAR/JSON | préférence de table |
| **or** | INT | **bourse commune** (M3) |
| etat | ENUM(en_cours,en_pause,terminee) | |
| phase | ENUM(hub,quete) | conditionne arrivée/départ et repos |
| quete_courante_id | FK → quetes NULL | |
| timestamps | | |

### `groupe_personnages` (pivot — composition & initiative)
| Colonne | Type | Note |
|---|---|---|
| id | BIGINT PK | |
| groupe_id | FK | |
| personnage_id | FK | |
| ordre_initiative | INT | figé par quête (C1) |
| actif | BOOL | présent dans la partie courante |

### `quetes`
| Colonne | Type | Note |
|---|---|---|
| id | BIGINT PK | |
| groupe_id | FK | |
| gabarit_id | FK → gabarits_quete | |
| titre | VARCHAR | |
| position_arc | INT | n° dans l'arc (1..N) |
| type_jalon | ENUM(normale,sous_boss,boss_final) | arc (doc 06 §4) |
| branche_active | JSON | branche prise (ramification) |
| etat | ENUM(a_venir,en_cours,terminee,echouee) | |
| or_initial | INT | pot commun au **début de la quête** (base de la part d'un départ en cours de quête) |
| timestamps | | |

### `cartes`
| id PK · quete_id FK · largeur INT · hauteur INT · grille JSON | tuiles assemblées, murs, portes, pièges, spawns, état révélé |

`grille.portes` (Phase 2, doc 14 §3.1/3.3) = `[{x, y, etat: ouverte|verrouillee|secrete, verrou?, revele?}]` ;
`verrou` = `{type: cle, objet_id}` | `{type: monstres_vaincus, instances: [id…]}` | `{type: levier, levier_id}`.
Une porte non `ouverte` est infranchissable + opaque (overlay `App\Partie\Grille`). `grille.leviers` =
`[{x, y, levier_id}]` (action « Actionner le levier »).

### `instances_monstres`
| Colonne | Type | Note |
|---|---|---|
| id | BIGINT PK | |
| quete_id | FK | |
| monstre_id | FK → monstres | bloc de stats |
| pv_body / pv_mind | INT | courants |
| position_x / position_y | INT | sur la grille |
| etat | ENUM(actif,vaincu) | |
| habillage | JSON | nom/description donnés par l'IA |

### `etat_personnage_quete` (runtime)
| id PK · personnage_id FK · quete_id FK · position_x INT · position_y INT · a_joue BOOL · tombe BOOL | position et statut de tour |

---

## 4. MariaDB — Journal & sauvegarde

### `evenements` (journal rejouable)
| Colonne | Type | Note |
|---|---|---|
| id | BIGINT PK | |
| groupe_id | FK | |
| quete_id | FK NULL | |
| sequence | INT | ordre |
| type | ENUM(action,jet,choix,combat,narration,systeme) | |
| acteur | VARCHAR/JSON NULL | personnage ou monstre |
| payload | JSON | données de l'événement (jet, résultat, choix) |
| created_at | TIMESTAMP | |

### `snapshots`
| id PK · groupe_id FK · sequence_evenement INT · etat LONGTEXT/JSON · created_at | état vivant sérialisé à un instant (chargement rapide = snapshot + événements depuis) |

---

## 5. MariaDB — Catalogues (référence, *seedés*)

### `classes_heros`
| id · nom · pv_body · pv_mind · attr_body · attr_mind · des_attaque · des_defense · deplacement_base · bonus_sac | valeurs de départ des 4 héros (Nain bonus_sac = 1) |

### `competences` (arbres)
| id · classe · nom · type ENUM(passif,actif,deblocage) · effet JSON · prerequis_id FK self | structure d'arbre |

### `objets`
| id · nom · categorie ENUM(arme,armure,outil,consommable,parchemin) · rarete ENUM(commun,peu_commun,rare,unique) · prix_base INT · emplacement · effet JSON | catalogue Market |

### `sorts`
| id · element ENUM(feu,eau,terre,air) · nom · type ENUM(degats,mental,utilitaire) · difficulte_parchemin TINYINT(1-3) · effet JSON | 12 sorts héros |

### `sorts_dread`
| id · nom · palier ENUM(sous_boss,boss) · type ENUM(degats,controle,invocation,fuite) · effet JSON | magie ennemie |

### `monstres`
| id · nom_base · deplacement · attaque · defense · pv_body · pv_mind · tier ENUM(base,sous_boss,boss) · cout INT · capacites JSON · sorts_dread JSON | bestiaire ; `cout` = poids dans le budget de rencontres ; Mind 0 → immunité mentale (logique moteur) |

### `pieges`
| id · nom · detectable BOOL · desarmable ENUM(oui,non,partiel) · usage ENUM(unique,persistant) · effet JSON | catalogue pièges |

### `conditions`
| id · nom · type ENUM(physique,mental) · effet JSON · duree_defaut INT | catalogue d'états (les `mental` → immunité Mind 0) |

### `forge_ameliorations`
| id · nom · cible ENUM(arme,armure) · effet JSON · prix INT | catalogue des améliorations de Forge (prix fixe) ; appliqué à un exemplaire via `inventaire.ameliorations` |

### `tuiles`
| id · type ENUM(salle,couloir,porte) · theme · grille JSON | bibliothèque de tuiles |

### `gabarits_quete`
| id · nom · type_jalon · structure JSON | objectifs, jalons, points de décision, budget de rencontres |

---

## 6. Relations clés

- `joueurs` 1—N `personnages` ; `personnages` N—1 `groupes` (groupe_actif).
- `groupes` N—N `personnages` via `groupe_personnages` (+ initiative).
- `groupes` 1—N `quetes` 1—N `evenements` ; `groupes` 1—N `snapshots`.
- `quetes` 1—1 `cartes`, 1—N `instances_monstres`.
- Catalogues référencés par les tables runtime (objets→inventaire, sorts→personnage_sorts, monstres→instances_monstres…).

---

## 7. Qdrant — la base de connaissance (bible)

### Collection
- Une collection unique, p. ex. **`bible`**, **isolée par groupe via le payload `group_id`** (Q7) — plus simple qu'une collection par groupe.
- **Distance** : cosinus. **Dimension** : selon le modèle d'embedding (à fixer).
- **Index de payload** sur `group_id` et `type` (filtrage rapide).

### Schéma de point (payload)
| Champ | Type Qdrant | Rôle |
|---|---|---|
| group_id | keyword | **isolation** par campagne |
| type | keyword | pnj / lieu / evenement / branche / reputation / promesse |
| titre | text | |
| contenu | text | texte du lore (source de l'embedding) |
| quete_id | integer (NULL) | rattachement éventuel |
| statut | keyword | ex. PNJ : vivant/mort/allie/hostile |
| sequence | integer | ordre temporel |
| source_evenement_id | integer (NULL) | lien vers `evenements` (MariaDB) |

> Le **vecteur** encode `contenu` ; le **payload** porte les métadonnées filtrables.

### Ingestion
1. **À la création** : le thème amorce quelques entrées de bible.
2. **En jeu** : PNJ rencontrés, lieux, branches prises, réputation → embeddés et **upsertés** comme points.
3. **Compactage (Q8)** : au seuil de contexte, les anciens `evenements` sont **versés** en points de bible, puis le contexte est résumé.

### Récupération (RAG)
- À chaque scène : recherche sémantique de la requête de scène, **filtrée `group_id`** (+ éventuellement `type`/`quete_id`), top-k injecté dans le prompt.
- L'état vivant et les événements récents viennent de MariaDB / du contexte, **pas** de Qdrant.

---

## 8. Sauvegarde & cycle de vie

Une **campagne complète = MariaDB + Qdrant**. Sauvegarder les **deux volumes** ; `group_id` permet d'exporter/restaurer une campagne isolément.

**Clôture / groupe vide** (doc Session §6) : suppression en cascade de `groupes`, `groupe_personnages`, `quetes`, `cartes`, `instances_monstres`, `etat_personnage_quete`, `evenements`, `snapshots`, **et des points Qdrant du `group_id`**. **Survivent** : les `personnages` (détachés, `groupe_actif_id` = NULL) avec leur `or`, leur inventaire et leur `personnage_historique`.

---

## 9. Questions ouvertes à trancher

1. **Dimension d'embedding** : dépend du modèle retenu (API vs local).
2. **JSON vs normalisation** : garder les `effet`/`capacites` en JSON (souple) ou les normaliser (requêtable) ?
3. **Catalogues** : tables seedées (ci-dessus) ou fichiers de référence importés ?
4. **Positions & tour** : table runtime dédiée (proposée) ou reconstruites depuis le journal/snapshot ?
5. **Rétention du journal** : purge des vieux `evenements` une fois versés en bible, ou conservation intégrale ?
