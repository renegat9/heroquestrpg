# Briefing joueur — test de jeu HeroQuest RPG

Tu joues **un seul héros** dans une vraie partie multijoueur en cours, via un
navigateur réel piloté à distance. Trois autres agents jouent les trois autres
héros en parallèle, et un orchestrateur tient l'écran de table. Le but n'est
pas de « gagner » : c'est de **jouer normalement et de repérer tout ce qui
cloche** dans le jeu.

## Ton outillage

Tout se trouve dans `/home/reneg/heroquestrpg/browser-shots/pilote/`. Utilise
les chemins absolus (le répertoire courant de ton shell n'est pas garanti).

```bash
P=/home/reneg/heroquestrpg/browser-shots/pilote

$P/montour <session>          # BLOQUE jusqu'à ton tour (max ~110 s), puis affiche
                              #   la narration du MJ + la liste numérotée des options.
                              #   Code de sortie 3 = timeout : relance-le, c'est normal.
$P/choisir <session> <index>  # clique l'option n° <index> et affiche le nouvel état.

$P/hq <session> text                      # texte complet de l'écran
$P/hq <session> els '{"selector":"CSS"}'  # liste les éléments (index, texte, actif)
$P/hq <session> click '{"selector":"CSS","n":0}'
$P/hq <session> click '{"texte":"Libellé"}'
$P/hq <session> shot '{"nom":"xx-01-quelquechose"}'   # capture PNG dans browser-shots/
$P/hq <session> console '{"n":30}'        # erreurs JS / réseau de la page
$P/hq <session> url
```

Onglets utiles de ta manette (boutons en bas) : `Action`, `Fiche`, `Sorts`, `Sac`.
Les options de menu sont toujours `button.choice`.

## Règles absolues

1. **N'utilise QUE l'interface.** Aucun `curl` vers l'API, aucun `php artisan`,
   aucune écriture en base. Si l'UI ne permet pas quelque chose, c'est un
   constat de test, pas un obstacle à contourner.
2. **Ne fais jamais `goto` vers `/joueur`, ne te déconnecte pas, ne recharge
   pas la page sauf si l'orchestrateur te le demande.** Ta session est déjà
   connectée et doit le rester. En cas de doute : `hq <session> url`.
3. **Ne joue que ton héros**, jamais une autre session.
4. **N'utilise pas le bouton d'urgence du narrateur ni « Clôturer »** — c'est
   le rôle de l'orchestrateur.
5. Si tu es bloqué plus de ~5 minutes sans que ce soit ton tour, **signale-le à
   l'orchestrateur** (message) au lieu de forcer.

## Boucle de jeu

```
tant que la quête n'est pas finie :
    $P/montour <session>            # attend ton tour
    lis la narration et les options
    choisis l'action qui a du sens pour ton héros (voir "ton rôle")
    $P/choisir <session> <index>
    parfois un second créneau s'ouvre (déplacement PUIS action) : rejoue
      choisir tant que des options pertinentes restent, sinon prends
      « terminer le tour » / « attendre » s'il existe
```

Un tour de héros = généralement **un déplacement + une action**. Après ton
action, le menu peut se réduire ; s'il ne reste rien d'utile, termine le tour.

## Ce qu'on te demande de surveiller (c'est le vrai livrable)

Note au fil de l'eau, avec ce que tu voyais à l'écran :

- **Blocages** : plus aucune option cliquable alors que ton tour continue ;
  bouton qui ne fait rien ; menu qui ne se rafraîchit pas.
- **Incohérences** : PV/or/inventaire qui ne collent pas à ce que la narration
  raconte ; une option proposée qui échoue avec un message d'erreur ; une
  action refusée sans explication.
- **Narration** : hors-sujet, répétitive, qui contredit l'état du jeu, ou
  générique alors qu'il se passe quelque chose de précis.
- **Ergonomie** : information manquante pour décider (on ne sait pas où on est,
  combien de PV a l'ennemi, ce que fait une option), libellé ambigu, absence de
  retour visuel après une action.
- **Erreurs techniques** : vérifie `hq <session> console '{"n":30}'` de temps en
  temps (toutes les ~5 actions) et signale toute erreur JS ou 4xx/5xx.

Prends une capture (`shot`) aux moments intéressants : première rencontre,
combat, montée de niveau, mort, fin de quête.

## Ton rôle

Joue **en personnage**, avec la logique tactique de ta classe, mais reste
naturel : un joueur humain qui découvre le donjon. N'hésite pas à essayer les
options secondaires (fouiller, examiner, équiper, lancer un sort) — c'est
justement ce qu'on veut tester. Évite quand même de saboter la partie.

## Rapport final

Quand la quête est terminée (ou quand l'orchestrateur te dit de t'arrêter),
renvoie :

1. Un résumé factuel de ce que ton héros a fait (5-10 lignes).
2. **La liste des problèmes constatés**, chacun avec : ce que tu as fait, ce que
   tu attendais, ce qui s'est passé, et la capture ou le texte à l'appui.
   Classe-les : bloquant / gênant / cosmétique.
3. Ce qui a bien marché (utile aussi).

Sois factuel : ne conclus pas à un bug avant d'avoir revérifié à l'écran
(`text`, `els`) — plusieurs « soft-locks » signalés lors de tests précédents
n'étaient qu'un manque de retour visuel.
