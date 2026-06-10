# Conception — Quêtes & MJ IA (3/3) : Garde-fous de l'agent

> Document d'analyse. Couvre l'encadrement du MJ IA : intégrité des règles, cohérence narrative, ton/contenu et robustesse. Voir aussi *Génération* (1/3) et *Mémoire* (2/3).

---

## 1. Principe

Le MJ IA est **borné** : il narre et propose, mais le **moteur fait autorité**. Toute la conception vise à rendre l'IA *incapable* de casser les règles ou la cohérence, plutôt qu'à espérer qu'elle se comporte bien.

---

## 2. Intégrité des règles

- L'IA **ne résout jamais** une mécanique (dés, PV, combat, jets) — **moteur seul** (rappel C2 et principe global).
- L'IA **propose des options** que le moteur sait exécuter ; les options **invalides sont filtrées** avant affichage (menus seulement → surface de risque réduite).
- **Catalogue défini (Q6)** : l'IA n'invente **aucune stat** ; elle habille du contenu existant.
- **Mécanisme d'application** : chaque sortie d'IA suit un **schéma** (structured outputs → forme garantie), puis le **moteur valide les références** (catalogue/état). Le schéma garantit la **forme**, pas la **véracité** — d'où la validation moteur indispensable.

---

## 3. Cohérence narrative

- L'IA s'appuie sur la **bible d'univers** (doc Mémoire) et ne doit pas contredire un fait établi.
- **Fait manquant → récupérer via RAG** plutôt qu'inventer ; à défaut, rester vague plutôt que se contredire.
- Les **branches prises** contraignent ce qui est narrable ensuite (un PNJ mort ne réapparaît pas).

---

## 4. Ton & contenu — préférences de table

> Projet **interne** : le ton et le contenu relèvent des **préférences du groupe**, comme à toute table de jeu entre joueurs connus — pas d'un cadre de protection grand public.

- Chaque **groupe règle son ton / son intensité** (héroïque, sombre, comique…) à la création de la partie.
- Le **thème** saisi reste **dans le registre fantasy** : c'est une contrainte de **cohérence d'univers** (le jeu est un dungeon crawler fantasy), pas une mesure de sécurité ; un thème hors-genre est reformulé.
- Le réglage influence le **style de narration et l'âpreté** des situations, **pas** les règles ni l'intégrité du moteur.

---

## 5. Robustesse / repli

- Si l'IA **échoue ou hallucine** : repli sur un **jeu d'options génériques**, re-génération, ou rejet par la **validation du moteur**.
- **Menus seulement** = peu de surface d'erreur : un choix proposé est toujours exécutable, sinon il n'est pas montré.
- Les monstres étant **scriptés** (C2), un plantage de l'IA n'interrompt pas le combat.

---

## 6. Décisions actées

1. **Ton/contenu (Q9)** : **configurable par groupe** (préférences de table — projet interne) ; le thème reste fantasy par cohérence d'univers.
2. (Rappels structurants) L'IA ne résout aucune mécanique ; catalogue défini ; cohérence adossée à la bible.
3. **Structure de l'agent** : **un seul agent MJ** + **skills par tâche** + **schémas de sortie** (structured outputs) + **validation moteur / retry** (doc technique §3-4).

---

## 7. Questions ouvertes à trancher

1. **Réglages de ton précis** : combien de crans, et que recouvre chacun ?
2. **Transparence** : montre-t-on aux joueurs quand une option a été filtrée/rejetée, ou est-ce silencieux ?
