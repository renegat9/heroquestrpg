# Verdict — séance d'échange, 2 joueurs réels, deux navigateurs simultanés (18 septembre 2026)

> Seule partie du chantier « séance d'échange » (livré le 2026-09-17, couvert
> par Pest) jamais éprouvée à deux — sur les **vraies routes** de la manette,
> avec **deux sessions Playwright simultanées** (deux `BrowserContext`, deux
> cookies) pour pouvoir observer le receveur SANS jamais recharger sa page
> pendant que le donneur agit. Campagne de harnais dédiée
> (`test-echange-2j-cj0n`, Grom le barbare / Borin le nain, quête 1 « Quête 1 »
> lancée par `preparer.sh`), nettoyée en fin de test.
>
> Sacs des deux héros posés à la main (`tinker`, scène calquée sur
> `browser-shots/livret-echange.php`) : Grom = Épée large (encombrante) +
> Dague + Bâton + Casque (fillers) + 3 Fioles de soin (consommable) ; Borin =
> Bouclier (encombrant) + Épée courte + Hachette + Cotte de mailles (fillers).
> **Les deux sacs à exactement 4/4 (pleins)** dès le départ — délibérément,
> pour éprouver le cas qui justifie toute la séance (contrat-api.md :
> « deux sacs pleins qui échangent deux armures est légal, alors qu'aucun
> ordre d'application ne passerait un contrôle pièce par pièce »).

## La question centrale : le receveur voit-il l'objet arriver sans recharger ?

**OUI.** Observé sur l'écran de Borin, onglet Sac, sans navigation ni
rechargement entre les deux captures :

| t (depuis le clic « Valider ») | sac de Borin affiché | bandeau |
|---|---|---|
| t+85 ms | **Bouclier** ×1, Épée courte, Hachette, Cotte de mailles | Connecté |
| t+1272 ms | **Bouclier** ×1 (inchangé) | **MJ réfléchit…** |
| t+3557 ms | **Épée large** ×1 (à la place du Bouclier), Épée courte, Hachette, Cotte de mailles | Connecté |
| t+5723 ms | idem (stable) | Connecté |

Un rechargement forcé en toute fin de script (`page.reload()`) retrouve
exactement le même sac — la mise à jour « en direct » n'était donc pas un
affichage optimiste incohérent avec le serveur, c'est bien le même état.

**Mécanisme confirmé** (lu dans le code, puis vérifié à l'écran) : les deux
manettes sont abonnées au canal de **groupe** `groupe.{identifiant}`.
`ResolveurTour::resoudre()` diffuse `EtatGroupeDiffuse` (`.groupe.etat`) à la
fin de **toute** résolution, y compris `echanger` — pas seulement à l'acteur.
Côté manette, `ManetteView.vue` écoute `.groupe.etat` **inconditionnellement**
et déclenche un `GET /moi` débouncé de 300 ms
(`rafraichirMoi()`, ligne 68 et 114-121) : c'est ce re-GET, pas un patch local
de l'inventaire, qui met à jour le sac affiché. Le délai observé (~1,3 s à
~3,6 s) vient de cette latence réelle (job de menu + requête réseau), pas d'un
manque de diffusion — le badge « MJ réfléchit… » (mis à jour de façon
synchrone dès l'arrivée du broadcast `.mj.reflechit`) apparaît avant que le
sac n'ait fini de se rafraîchir, ce qui explique la capture intermédiaire à
t+1272 ms.

Captures : `01` à `14` (voir méthode plus bas pour les noms complets), les
clés étant `03-borin-sac-avant`, `08-borin-sac-t1s` (avant), `09-borin-sac-t3s`
(après, sans reload), `14-borin-sac-apres-RELOAD` (confirmation).

## Les sept points de contrôle

1. **Le donneur voit son sac à jour immédiatement.** Oui — même mécanisme que
   le receveur (Grom est lui aussi abonné à `groupe.{id}`), confirmé sur la
   capture `13-grom-sac-apres` : Bouclier reçu, Épée large partie, les trois
   fillers inchangés, 4/4. Je n'ai pas isolé son *propre* délai à la
   milliseconde (rien ne le distingue architecturalement de celui de Borin),
   mais rien n'indique un traitement local à part.

2. **Les deux sens fonctionnent en une seule validation.** Oui — un seul
   `POST /choix {option_id: "echanger", parametres: {cle, transferts: [...]}}`
   a fait partir l'Épée large de Grom vers Borin **et** le Bouclier de Borin
   vers Grom. Réponse `resultat.donne`/`resultat.recu` cohérente des deux
   côtés (vu aussi dans l'échange retour, sens inverse, deux tours plus tard).

3. **Le fil de combat annonce l'échange, sur les deux manettes.** **Le fil
   parle, mais il ment par omission** — voir défaut ci-dessous. Ce n'est donc
   ni un silence (la règle « effet muet = injouable » n'est pas enfreinte à la
   lettre) ni une vraie annonce.

4. **Les améliorations de Forge survivent à l'échange.** Oui, vérifié en
   conditions réelles (pas seulement en lisant le code) : une amélioration
   « Renforcée » (`bonus_des_defense: 1`) posée sur la ligne d'inventaire du
   Bouclier (id 492, `Inventaire.ameliorations`) a survécu à un second échange
   réel (Borin → Grom, retour) déclenché par `POST /choix`. Après coup, la
   même ligne (id 492) appartient à Borin et porte toujours
   `[{"nom":"Renforcée","effet":{"bonus_des_defense":1}}]` — confirmé en base
   ET dans la réponse `GET /moi`. C'est cohérent avec le code
   (`DonObjet::transferer()` fait un simple `UPDATE personnage_id` sur la
   MÊME ligne, jamais un delete+recreate). **Note annexe, hors périmètre de
   ce test** : la pièce étant rangée au sac (jamais équipée d'office par un
   échange), l'amélioration ne joue sur aucun dé tant qu'elle n'est pas
   équipée — et le champ `avantages` que `/moi` publie pour cette ligne vient
   du catalogue (`MotsClesEquipement::avantages($objet->effet)`), **pas** de
   `ligne.ameliorations` : rien à l'écran ne dit jamais « Renforcée » sur un
   objet en sac. Ce n'est pas une régression de l'échange (c'est vrai avec ou
   sans échange), mais ça vaut d'être noté : une amélioration payée est
   invisible tant que la pièce dort dans un sac.

5. **L'échange coûte l'action du donneur, pas celle du receveur.** Oui,
   vérifié sur `/etat` juste après le premier échange :
   `Grom a_agi=true, a_joue=false` (créneau ACTION consommé, créneau
   MOUVEMENT encore libre — l'échange ne termine pas le tour) ;
   `Borin a_agi=false, a_joue=false` (rien consommé chez le receveur).

6. **Un sac plein des deux côtés peut quand même troquer deux pièces.** Oui —
   **c'est le cas que ce test visait en priorité**. Grom et Borin étaient
   tous deux à 4/4 avant l'échange ; la fiche d'échange affichait
   « GROM 4/4 → 4/4 » et « BORIN 4/4 → 4/4 » (delta nul, 1 pièce encombrante
   contre 1 pièce encombrante) et le bouton **Valider l'échange n'était pas
   grisé**. La validation a réussi (`resultat.type: "echanger"` avec `donne`
   et `recu` non vides) — exactement le cas où un contrôle pièce-par-pièce
   séquentiel aurait bloqué le premier mouvement (le receveur passerait
   transitoirement à 5/4), quel que soit l'ordre choisi. Reproduit une
   seconde fois en sens inverse (Borin → Grom) avec le même résultat.

7. **L'option `echanger` disparaît du menu du donneur après usage.** Oui —
   `GET /moi`/le menu régénéré de Grom après l'échange ne contient plus
   « Échanger avec un allié adjacent » (vérifié par extraction de texte ET à
   l'écran, capture `12-grom-action-apres` : Se déplacer, Jeter un objet,
   Utiliser un objet, Battre en retraite, Terminer le tour — pas d'Échanger).
   Cohérent avec le code : l'option vit dans le même bloc `! $aAgi` que
   `equiper`/`relever` (`MenuMoteur.php` ~l.1197-1736).

## Défaut trouvé : le fil de combat annonce un échange générique, pas le vrai

**Symptôme observé** (capture `11-borin-action-fil`, et identique côté Grom,
`12-grom-action-apres`) : la ligne du fil de combat dit, mot pour mot,

> Grom donne un objet à un allié

sur les DEUX manettes, pour un échange qui a en réalité fait partir une
**Épée large** vers **Borin** et revenir un **Bouclier**. Aucun nom d'objet,
aucun nom d'allié, aucune mention du sens retour — la ligne est strictement
la même quels que soient les objets ou l'allié concerné.

**Cause.** `App\Partie\ResolveurTour::resoudreEchange()` construit son
payload avec les clés `avec` (nom de l'allié), `donne` (liste
`[{objet, quantite}]`) et `recu` (même forme) — cf.
`App\Partie\SeanceEchange::resoudre()`, qui renvoie exactement
`['donne' => [...], 'recu' => [...]]`. Mais
`App\Partie\JournalCombat::depuisResultat()` lit, pour le type `echanger` :

```php
'echanger' => [$this->info("{$acteurNom} donne ".($a['objet'] ?? 'un objet').' à '.($a['vers'] ?? 'un allié'))],
```

`$a['objet']` et `$a['vers']` **n'existent pas** dans ce payload (les clés
réelles sont `donne`/`recu`/`avec`) : le `??` retombe donc **systématiquement**
sur ses valeurs par défaut, quels que soient les objets échangés. La ligne
« Grom équipe/range un objet » a le même bug potentiel
(`equiper`/`desequiper` lisent aussi `$a['objet']` — mais ces deux résolveurs,
eux, publient bien une clé `objet`, donc ils s'en sortent ; seul `echanger` a
changé de forme de payload — `avec`/`donne`/`recu` — sans que
`JournalCombat` suive).

**Pourquoi ce n'est pas anodin.** Le hard rule du projet dit « un effet
automatique que rien n'annonce est injouable » — ici l'effet **est** annoncé,
mais faussement : à la table, les deux joueurs verraient une ligne qui ne dit
ni quoi, ni à qui, ni ce qui est revenu en échange, sur un geste qui bouge
pourtant deux inventaires à la fois. Pour un échange à sens unique ce serait
gênant ; pour un échange bidirectionnel (la moitié de l'intérêt de la
fonctionnalité), c'est doublement faux : la ligne ne dit rien du sens retour.

**Périmètre du correctif** (non fait, conformément à la consigne — décrit,
pas corrigé) : `JournalCombat::depuisResultat()` a besoin d'un cas `echanger`
qui lise `avec`/`donne`/`recu` et compose une phrase listant les objets des
deux côtés (potentiellement plusieurs par sens, `donne`/`recu` étant des
listes).

## Notes annexes

- **Un échange déclenche le verrou narratif B1** comme une action de tour
  ordinaire : `echanger` n'est pas dans la liste `['deplacement', 'attente']`
  qui vaut résolution « instantanée », donc `ChoixController::choisir()`
  diffuse `MjReflechit(true)` et pioche une narration (`cleTempsFort()` ne
  connaît pas de clé dédiée pour `echanger`, retombe sur `progression`
  faute de `resultat['issue']`). Observé à l'écran (bandeau « Le maître du
  jeu prépare la suite… » sur la manette de Borin, capture `11`) : rien de
  cassé, mais un échange d'objets entre deux héros adjacents produit la même
  pause que si un temps fort narratif venait de se produire, avec un texte
  générique de progression qui ne parle pas de l'échange.
- Le vote de sortie, le marché et les autres phases n'ont pas été retouchés
  par ce test — campagne restée en quête 1, jamais close.

## Méthode

- Harnais : `browser-shots/campagne/preparer.sh "Test Echange 2J" "cachots test" barbare:Grom nain:Borin`
  (code `test-echange-2j-cj0n`, `LONGUEUR` par défaut `tres_courte`),
  battement lancé automatiquement.
- Scène posée à la main (comme `browser-shots/livret-echange.php`) :
  positionne Borin orthogonalement adjacent à Grom et garnit les deux sacs à
  4/4 — script tinker one-shot, jamais pointé sur une autre base que ce
  groupe de harnais.
- Deux sessions **Playwright** dans le même process (`chromium.launch()` +
  deux `newContext()`), connectées avec les identifiants réels générés par
  `preparer.sh` (`bar1181336` pour Grom, `nai2181336` pour Borin), chacune
  ouverte sur `/manette/test-echange-2j-cj0n` — jamais `hq.sh` seul pour la
  partie « observation du receveur », puisque la question posée porte
  précisément sur ce qui s'affiche à l'écran d'un vrai navigateur.
- Conteneur officiel `mcr.microsoft.com/playwright:v1.48.0-jammy`,
  `--network host`, storage du script + captures dans un dossier hors dépôt
  (scratchpad de session).
- Second échange (retour, avec amélioration de Forge posée à la main sur le
  Bouclier) rejoué directement via `hq.sh`/l'API réelle (`POST /choix`), pour
  vérifier le point 4 sur le système réel sans reprendre un second aller-retour
  Playwright complet.

## Nettoyage

`./browser-shots/campagne/nettoyer.sh` exécuté en fin de test (groupe
`test-echange-2j-cj0n`, ses 2 héros et leurs 2 comptes).

**Personnages en base — avant / après :**

| | `App\Models\Personnage::count()` |
|---|---|
| Avant `preparer.sh` (production + tests antérieurs) | 6 |
| Après `preparer.sh` (+ Grom, + Borin) | 8 |
| Après `nettoyer.sh` | 6 |

Retour exact à 6 — aucun résidu. `nettoyer.sh` a rapporté la purge du groupe
`test-echange-2j-cj0n` (2 héros) et la suppression de 2 comptes.
