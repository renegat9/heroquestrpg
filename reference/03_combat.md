# Conception — Système de Combat

> Fiche de référence. Le combat **reste celui de HeroQuest de base** ; ce document fixe les règles et leurs points d'accroche avec nos ajouts (arbres, attributs, sorts). Valeurs = base du jeu, **à ajuster en playtest**.

---

## 1. Vue d'ensemble

Combat tactique sur grille, **identique au jeu original**. Le moteur (déterministe) calcule l'adjacence, la ligne de vue et les jets ; l'IA n'a **aucune autorité** sur la résolution — elle ne fait que narrer le résultat.

---

## 2. Les dés de combat

Dé à 6 faces : **3 crânes**, **2 boucliers blancs**, **1 bouclier noir**. Un crâne sort donc 1 fois sur 2.

- **Crâne** = touche potentielle (à l'attaque).
- **Bouclier blanc** = défense réussie pour un **héros**.
- **Bouclier noir** = défense réussie pour un **monstre**.

---

## 3. Le tour d'un personnage

1. **Déplacement** : **valeur de base du héros + 1d6** (ex. Elfe 5 + 1d6 = 6 à 11 cases), en cases **orthogonales** (pas de diagonale). On ne traverse pas une figurine. La base par héros est dans le doc Personnages ; le nœud *Pas léger* de l'Elfe ajoute +1.
2. **Une action** : attaquer, lancer un sort, fouiller, désamorcer, **gérer son équipement** (équiper/jeter, ou échanger avec un allié adjacent — voir doc Personnages §7), etc.

Déplacement et action peuvent s'enchaîner dans l'ordre choisi (avant/après), mais **une seule attaque** par tour sauf capacité spéciale.

---

## 4. Attaque

- Cible **adjacente** (orthogonale) au corps-à-corps.
- Lancer un nombre de dés de combat égal à la **valeur d'Attaque** (déterminée par l'arme — voir doc Market).
- Compter les **crânes**.

## 5. Défense

- Le défenseur lance ses **dés de défense** (valeur de Défense + armure).
- **Héros** comptent les **boucliers blancs** ; **monstres** comptent les **boucliers noirs**.
- Chaque bouclier **annule un crâne**.

## 6. Dégâts et mise hors de combat

- **Dégâts = crânes − boucliers** (minimum 0).
- Chaque point de dégât retire **1 Point de Body**.
- À **0 Point de Body**, la figurine est **« tombée »** : elle occupe toujours sa case et reste **relevable** (soin/allié) ; mort définitive si non relevée avant la fin du combat (P1/C4).

### Total Party Kill (TPK)
Si **tous les héros sont tombés** sans relève possible, le groupe tranche par **vote** :
- **Recharger** la dernière sauvegarde (snapshot/checkpoint, doc Mémoire) → on rejoue depuis là.
- **Abandonner** la campagne → **clôture par abandon** (doc Session §6) : groupe fermé et nettoyé, l'**or d'avant la mission** (`or_initial`) réparti entre les joueurs, personnages renvoyés au roster (équipement + part d'or + résumé d'échec).

> Égalité → **recharger** (option la moins destructive).

---

## 7. Attaques à distance et ligne de vue

- Armes à distance (arbalète) et sorts offensifs exigent une **ligne de vue** dégagée (calculée par le moteur).
- Certaines armes ont des restrictions (ex. arbalète inutilisable si un ennemi est adjacent — voir Market).

---

## 8. Armes et armure

La **valeur d'Attaque** vient de l'arme équipée, la **valeur de Défense** de l'armure. Stats et effets spéciaux (diagonale, deux mains, jet, portée) → centralisés dans le **doc Market**, §catalogue.

---

## 9. Modificateurs venant des arbres

Les nœuds d'arbre (doc Personnages) s'appliquent ici :

| Nœud | Effet en combat |
|---|---|
| **Coup puissant** (Barbare) | Relance une fois les dés d'attaque ratés. |
| **Frénésie** (Barbare) | +1 dé d'attaque sous la moitié des PV de Body. |
| **Garde tenace** (Nain) | +1 dé de défense contre la 1ʳᵉ attaque du combat. |
| **Tir précis** (Elfe) | Avantage en attaque à distance. |
| **Maîtrise lourde** (Barbare) | Accès armes à deux mains / armure lourde. |

---

## 10. Sorts en combat

Résolution détaillée dans le **doc Sorts**. Rappels d'intégration :
- Sorts de dégâts → touchent les **PV de Body**, défense applicable.
- Sorts mentaux → la cible tente un **jet de Mind** ; échec = subit l'effet (binaire, sans dégâts de PV de Mind).
- Sorts utilitaires (Peau de Pierre, Courage, Vent Véloce) → modifient les dés ou le déplacement.
- **Tir ami possible** : un sort offensif mal placé peut toucher un allié (voir doc Sorts).

---

## 11. Ordre du tour en multijoueur

Adaptation du tour HeroQuest au modèle tablette-hôte + téléphones :

1. **Phase des héros** : chaque **personnage** joue à tour de rôle, dans un ordre fixé en début de quête. Un joueur contrôlant plusieurs personnages joue chacun à sa position d'initiative (sur son téléphone).
2. **Phase des monstres** : le **MJ IA** active tous les monstres ; le moteur résout leurs jets.

> Décision (C1) : l'ordre des héros est **figé pour toute la quête**.

---

## 12. Adaptation numérique

- Le moteur calcule **adjacence, ligne de vue, portées** — fini les litiges de table.
- **Déplacement orthogonal** conservé (fidélité). Pas de diagonale au déplacement.
- Les **monstres suivent un comportement scripté simple** (cible le plus proche/faible), résolu par le moteur — pas par le LLM (C2). Le MJ IA narre leurs actions sans les décider mécaniquement.
- Animation des dés côté interface ; le résultat reste celui du moteur.

---

## 13. Décisions actées

1. **Initiative (C1)** : ordre des héros **figé pour toute la quête**, défini au départ.
2. **Monstres (C2)** : **comportement scripté simple** (cible le plus proche / le plus faible), résolu par le moteur. Pas de décisions confiées au LLM.
3. **Attaque d'opportunité (C3)** : **aucune** — le désengagement est libre (fidèle au jeu de base).
4. **Armure de plates (AP)** : déplacement = **base seule, sans le 1d6**.
5. **Figure tombée (C4)** : **occupe sa case** et reste **relevable** (voir P1 pour la mort).
6. **TPK** : choix de groupe par vote — **recharger** la dernière sauvegarde, ou **abandonner** (clôture, or d'avant la mission réparti) ; égalité → recharger.
