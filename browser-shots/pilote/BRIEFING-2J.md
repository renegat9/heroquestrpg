# Briefing joueur — test de jeu à 2 (HeroQuest RPG)

Tu joues **un seul héros** dans une vraie partie en cours, via un navigateur réel
piloté à distance. Un autre agent joue le second héros en parallèle, et un
orchestrateur tient l'écran de table. Le but n'est **pas de gagner** : c'est de
jouer normalement et de **repérer tout ce qui cloche**.

## Outillage

```bash
P=/home/reneg/heroquestrpg/browser-shots/pilote

$P/montour <session>          # BLOQUE jusqu'à ton tour (~110 s max), puis affiche
                              #   narration + options numérotées. Sortie 3 = timeout,
                              #   c'est normal : relance-le.
$P/choisir <session> <index>  # clique l'option n° <index> et affiche le nouvel état

$P/hq <session> text                      # texte complet de l'écran
$P/hq <session> els '{"selector":"CSS"}'  # éléments (index, texte, actif)
$P/hq <session> click '{"selector":"CSS","n":0}'
$P/hq <session> shot '{"nom":"xx-01-truc"}'   # capture PNG dans browser-shots/
$P/hq <session> console                   # erreurs JS / réseau
```

Onglets en bas de la manette : `.botnav button` — index 0 = Marché (ou Action),
1 = Fiche, 2 = Sorts, 3 = Sac. Les options de menu sont `button.choice`.

## Règles absolues

1. **N'utilise QUE l'interface.** Aucun `curl`, aucun `php artisan`, aucune
   écriture en base, aucune lecture du code source pour « comprendre » un
   comportement. Si l'UI ne permet pas quelque chose, **c'est un constat de
   test**, pas un obstacle à contourner.
2. **Ne fais jamais `goto`, ne te déconnecte pas, ne recharge pas la page.** Ta
   session est déjà connectée et doit le rester.
3. **Ne joue que ton héros**, jamais l'autre session.
4. N'utilise pas le bouton d'urgence du narrateur ni « Clôturer ».
5. Bloqué plus de ~5 minutes sans que ce soit ton tour → signale-le, ne force pas.

## Déroulé

### Phase 1 — le hub (marché, équipement)
Le marché est **déjà ouvert** (profil cité). La bourse commune est de **900 or**,
partagée entre vous deux : concertez-vous implicitement en ne dépensant pas tout.

1. Onglet **Marché** : parcours l'étal. **Note tout ce qui te surprend** — un prix,
   un libellé, un badge, une pièce que tu t'attendais à voir ou pas.
2. Achète **1 à 3 pièces qui ont du sens pour ton héros**, puis confirme ton panier.
3. Onglet **Sac** : essaie d'**équiper** ce que tu as acheté. Si c'est refusé,
   **note le message exact** — c'est précisément ce qu'on teste.
4. Essaie le bouton **donner** (icône main) sur un objet, vers ton compagnon.

### Phase 2 — la quête
Quand l'orchestrateur te le dit, marque-toi **prêt** (bouton sur l'onglet Action
au hub). La quête démarre quand vous êtes prêts tous les deux.

Puis, en boucle jusqu'à la fin :
```
$P/montour <session>
lis la narration et les options
choisis ce qui a du sens pour ton héros
$P/choisir <session> <index>
# un second créneau s'ouvre parfois (déplacement PUIS action) : rejoue
```
**Fouille les salles** dès que l'option « Fouiller — trésor » apparaît : c'est une
mécanique récente qu'on veut éprouver (or, potion, piège, monstre errant, artefact).

## Points à observer EN PARTICULIER (2e passage)

La génération de carte vient d'être refaite. Sans forcer ces situations, note ce
que tu constates réellement à leur sujet — et dis-le franchement si tu ne les
rencontres pas :

1. **Les portes se repèrent-elles ?** Au tour précédent, un joueur a pris deux
   fois la case d'un allié pour un seuil. Les portes ont désormais des montants
   clairs. Sur la carte de déplacement, arrives-tu à voir du premier coup d'œil
   où sont les passages ?
2. **Les seuils font 2 cases.** Deux héros peuvent-ils tenir de front dans une
   porte ? Un personnage à distance peut-il viser en se plaçant À CÔTÉ du
   combattant de mêlée, au lieu d'être bloqué derrière lui ?
3. **Salles mitoyennes** : certaines portes donnent directement sur une autre
   salle, sans couloir. En vois-tu ?
4. **Portes secrètes** : « Fouiller la zone » peut en révéler une (elle apparaît
   en VIOLET). Elles ouvrent des raccourcis, jamais un passage obligatoire.
5. **Pièges dans les salles** : ils ne sont plus seulement dans les couloirs.
   Seuls les héros les déclenchent.
6. **Fouiller — trésor** : si l'option apparaît alors que la salle a déjà été
   fouillée, note précisément QUI avait fouillé, DANS QUELLE SALLE, et si tu
   t'étais déplacé entre-temps. Ce point est resté inexpliqué au test précédent.

## Ce qu'on attend de toi

Tiens une **liste de constats** au fil de l'eau. Pour chacun : ce que tu faisais,
ce que tu attendais, ce qui s'est passé, et une capture si c'est visuel. Signale
aussi bien les blocages que les frictions mineures (libellé ambigu, information
manquante, bouton qui ne réagit pas, incohérence de règle).

À la fin, rends un rapport court et factuel :
- **Bloquants** (impossible de continuer)
- **Incohérences de règle** (le jeu se contredit)
- **Frictions d'interface**
- **Ce qui marche bien** (utile aussi)

N'invente rien : si tu n'as pas vu quelque chose, ne le rapporte pas.
