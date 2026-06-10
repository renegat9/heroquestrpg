# Conception — Bestiaire

> Document d'analyse. Reprend les monstres de **HeroQuest**, adaptés à notre système. Valeurs = **base HeroQuest, à ajuster en playtest**. Les monstres sont **pilotés par un comportement scripté** (C2) et **habillés par le MJ IA** (Q6). Projet **interne** : le contenu HeroQuest est utilisé directement.

---

## 1. Vue d'ensemble

- Un monstre est un **bloc de stats du catalogue** ; le moteur le résout, le MJ IA le **renomme/redécrit** selon le thème (un *gobelin* → *écumeur des cryptes*) **sans changer les stats**.
- Pas d'attribut de jet (Body/Mind) comme les héros : un monstre n'a besoin que d'**Attaque, Défense, PV de Body, PV de Mind, Déplacement**.
- **Déplacement fixe** (pas de 1d6) — fidèle à HeroQuest et plus simple pour l'IA scriptée. Le héros, lui, utilise base + 1d6.

---

## 2. Bloc de stats d'un monstre

| Champ | Rôle |
|---|---|
| **Déplacement** | Cases par tour (valeur fixe) |
| **Attaque** | Dés de combat à l'attaque |
| **Défense** | Dés de combat en défense (compte les **boucliers noirs**) |
| **PV de Body** | Jauge de vie ; à 0 → vaincu |
| **PV de Mind** | Résistance aux **sorts mentaux** (jet de Mind du monstre) |
| **Coût** | Poids dans le **budget de rencontres** (adaptation de difficulté, doc Quêtes §2) |

> **Règle clé — Mind 0 (morts-vivants)** : un monstre à **PV de Mind = 0 est immunisé aux sorts mentaux** (il n'a pas d'esprit à affecter). Sans cette règle, le système de résistance (0 dé = 0 succès) en ferait à tort les cibles les plus faciles à contrôler.

---

## 3. Bestiaire de base

| Monstre | Dépl. | Attaque | Défense | PV Body | PV Mind | Note |
|---|---|---|---|---|---|---|
| **Gobelin** | 10 | 2 | 1 | 1 | 1 | Rapide, fragile, en nombre |
| **Orque** | 8 | 3 | 2 | 1 | 2 | Fantassin polyvalent |
| **Fimir** | 6 | 3 | 3 | 2 | 3 | Brute résistante |
| **Squelette** | 6 | 2 | 2 | 1 | 0 | Mort-vivant léger ; immunisé au mental |
| **Zombie** | 6 | 2 | 3 | 1 | 0 | Lent mais coriace ; immunisé au mental |
| **Momie** | 4 | 3 | 4 | 2 | 0 | Très défensive ; immunisée au mental |
| **Guerrier du Chaos** | 7 | 4 | 5 | 3 | 3 | Élite, redoutable des deux côtés |
| **Gargouille** | 6 | 4 | 5 | 3 | 4 | Le plus coriace du bestiaire de base |

---

## 4. Sous-boss & boss final

L'arc de campagne (doc Quêtes §4) jalonne la progression de **sous-boss** puis d'un **boss final**, construits comme des **blocs élites** :

- **Sous-boss** : version renforcée d'un monstre (PV de Body augmentés, +1 Attaque *ou* Défense, parfois **1 capacité**), ou un monstre haut de gamme (Guerrier du Chaos, Gargouille).
- **Boss final** : bloc **unique**, nettement supérieur (PV élevés, Attaque/Défense fortes, **1 à 2 capacités** signature, parfois des **sbires**).

Exemples (proposés, à équilibrer) :

| Type | Dépl. | Attaque | Défense | PV Body | PV Mind | Capacités |
|---|---|---|---|---|---|---|
| **Sous-boss « Champion »** | 7 | 4 | 4 | 5 | 3 | 1 |
| **Boss final « Seigneur »** | 7 | 5 | 5 | 10 | 5 | 1–2 (+ sbires) |

### Bibliothèque de capacités (définie, l'IA assigne)
- **Invocation** : fait apparaître des sbires de base.
- **Frappe de zone** : touche plusieurs cibles adjacentes.
- **Régénération** : récupère des PV de Body par tour.
- **Résistance magique** : +dés de défense contre les sorts.
- **Charge** : déplacement + attaque renforcée.

> Capacités **mécaniques et bornées** (le moteur les résout) ; l'IA choisit l'habillage, pas les effets.

### Sorts de Dread (magie ennemie)
Catalogue défini de **magie du Chaos**, pendant maléfique des sorts héros (doc Sorts). **Résolution identique au doc Sorts §5** : dégâts → PV de Body des héros (défense applicable) ; contrôle/mental → **jet de Mind du héros pour résister** (S2). Le **Mind des héros devient ainsi une vraie défense** : un Magicien (Mind 4) encaisse, un Barbare (Mind 1) est exposé.

| Sort de Dread | Effet | Palier |
|---|---|---|
| **Trait de Chaos** | Dégâts à distance sur un héros | Sous-boss |
| **Frayeur** | Un héros perd des dés d'attaque / recule (résiste au Mind) | Sous-boss |
| **Sommeil** | Endort un héros (résiste au Mind) | Sous-boss |
| **Tempête de feu** | Dégâts de zone sur les héros (défense applicable) | Sous-boss / boss |
| **Invocation de morts-vivants** | Fait apparaître squelettes / zombies | Boss |
| **Commandement** | Contrôle un héros un tour (résiste au Mind) | Boss |
| **Fuite** | Le lanceur se téléporte vers sa phase suivante | Boss |

**Répartition sur l'arc** : les **sous-boss** lancent déjà les sorts mineurs — la magie du Chaos n'est **pas réservée à la quête finale**. Le **boss final** ajoute les sorts vilains. L'intensité monte avec l'arc (doc Quêtes §4).

**Usages** : un lanceur dispose d'un nombre limité d'usages par rencontre, déclenchés par son comportement scripté (C2).

---

## 5. Comportement

- **Scripté simple** (C2) : cible le plus proche / le plus faible ; un boss déclenche sa capacité quand elle est disponible.
- Le **moteur décide et résout** ; le MJ IA **narre** les actions sans les choisir mécaniquement.

---

## 6. Habillage par l'IA (Q6)

- Un même bloc sert plusieurs fictions : le *gobelin* peut devenir homme-rat, cultiste, gobelin selon le **thème** de la campagne.
- L'IA fixe **nom, description, ambiance** ; les **stats restent celles du bloc**.

---

## 7. Intégration

- **Combat** : Attaque/Défense en dés, PV de Body comme vie (docs Combat/Personnages).
- **Sorts** : PV de Mind = résistance mentale ; **Mind 0 = immunité** (doc Sorts, S2).
- **Quêtes** : sous-boss et boss final = jalons de l'arc (doc Quêtes §4).
- **Market** : monstres et boss laissent du **butin** (or, objets, butin unique de boss) selon le gabarit.

---

## 8. Périmètre

- **MVP** : les 8 monstres de base, gabarits de sous-boss / boss final, bibliothèque de capacités, **sorts de Dread sur les sous-boss et le boss final**.
- **Phase 2** : bestiaire élargi, **lanceurs de Dread autonomes** (acolytes, sorciers de rang), capacités avancées, boss à plusieurs phases.

---

## 9. Questions ouvertes à trancher

1. **Échelonnage des boss** : stats exactes de sous-boss / boss final selon la longueur de campagne.
2. **Taille de la bibliothèque** de capacités et de sorts de Dread au MVP.
3. **Déplacement des monstres** : on confirme le **fixe** (vs base + 1d6 des héros) ?
4. **Butin** : tables de butin par monstre, ou butin piloté par le gabarit de quête ?
5. **Usages de Dread par rencontre** : combien, et comment équilibrer face au Mind des héros ?
