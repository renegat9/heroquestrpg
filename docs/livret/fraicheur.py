#!/usr/bin/env python3
"""Fraîcheur des captures du livret — une capture périmée ne se signale pas toute seule.

Le livret a un garde-fou pour les vignettes d'objets (le README le rappelle après
chaque `images:generer`) et **aucun** pour les captures d'écran. Elles vieillissent
en silence pendant que le texte, lui, est corrigé — d'où deux figures trouvées le
2026-09-17 que leur propre légende contredisait : `30-manette-action` énumérait un
menu auquel deux options avaient été ajoutées, et `31-manette-deplacement` montrait
un trajet d'avant le correctif, à côté d'une prose qui disait déjà « on ne traverse
pas un meuble ».

Le principe : chaque figure DÉCLARE les fichiers qu'elle montre. Si l'un d'eux a été
commité APRÈS la prise de la capture, l'image est suspecte. Ce n'est pas une preuve
qu'elle ment — un commit peut ne rien changer à l'écran —, c'est une question posée
à quelqu'un qui saura regarder.

⚠ Les captures ne sont PAS versionnées (`.gitignore` : `/browser-shots/livret/`),
donc leur date de prise est leur `mtime` — jamais une date git. Un dépôt fraîchement
cloné n'en a aucune, et c'est un état distinct de « périmée » : le livret s'y génère
sans figures, ce que ce contrôle doit dire plutôt que laisser deviner.

⚠ Le REGISTRE SE VÉRIFIE DANS LES DEUX SENS, comme tous les registres du projet :
aucune figure du livret ne peut manquer à la table ci-dessous (sinon elle vieillirait
justement en silence, le défaut que ce fichier existe pour clore), et aucune entrée
de la table ne peut désigner une figure que le livret n'utilise plus.

    python3 docs/livret/fraicheur.py          # rapport
    python3 docs/livret/fraicheur.py --strict # sort en échec si une capture est périmée
"""

import io
import os
import re
import subprocess
import sys

RACINE = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
CAPTURES = os.path.join(RACINE, 'browser-shots', 'livret', 'web')
GENERER = os.path.join(RACINE, 'docs', 'livret', 'generer.py')

SCENE = 'resources/js/components/table/SceneEvenement.vue'

# ⚠ LE SOCLE PARTAGÉ. `DungeonGrid` dessine la grille — cases, portes, pièges,
# terrain, trajet — pour la manette ET pour la table ; les deux vues le disent
# dans leur propre en-tête (« le MÊME que la manette »). Sans lui ici, une
# évolution du rendu de carte n'aurait périmé AUCUNE des trois figures qui le
# montrent : un faux négatif, c'est-à-dire un silence, bien pire qu'une fausse
# alerte qu'on voit passer. C'est ce socle qui porte l'aperçu du trajet.
SOCLE = ['resources/js/components/carte/DungeonGrid.vue',
         'resources/js/components/carte/symboles.js',
         'resources/js/components/carte/LegendeCarte.vue']
CARTE = ['resources/js/components/table/DungeonMap.vue'] + SOCLE

# Figure → les fichiers qu'elle MONTRE.
#
# ⚠ ÉTROIT, ET C'EST TOUT L'ENJEU. La première version déclarait `ManetteView`
# et `TableView` — les coques — en dépendance de presque chaque figure. Elles
# bougent pour des raisons de routage ou de pile de feuilles qui ne changent
# RIEN à l'écran photographié : le contrôle a signalé **18 figures sur 27** dès
# sa première exécution, toutes sur le même commit. Un avertissement qui se
# déclenche partout n'est plus lu, et un garde-fou qu'on apprend à ignorer est
# pire que pas de garde-fou — il donne l'impression d'être couvert.
#
# On ne déclare donc que ce qui PEINT l'image : l'onglet ou la feuille, plus le
# service serveur quand c'est lui qui en compose le contenu.
DEPENDANCES = {
    '01-accueil': ['resources/js/views/AccueilView.vue'],
    '02-narrateur': ['resources/js/views/NarreurView.vue'],
    '03-joueur-connexion': ['resources/js/views/JoueurView.vue'],
    '04-guide-heros': ['resources/js/views/GuideView.vue'],
    '05-guide-bestiaire': ['resources/js/views/GuideView.vue'],
    '06-guide-equipement': ['resources/js/views/GuideView.vue'],
    '07-guide-sorts': ['resources/js/views/GuideView.vue'],
    '08-guide-pieges': ['resources/js/views/GuideView.vue'],
    '10-roster': ['resources/js/views/JoueurView.vue'],
    '11-manette-marche': ['resources/js/components/manette/MarketTab.vue'],
    '12-manette-fiche': ['resources/js/components/manette/FicheTab.vue',
                         'resources/js/components/manette/PipsGauge.vue'],
    '13-manette-sac': ['resources/js/components/manette/SacTab.vue'],
    '14-manette-sorts': ['resources/js/components/manette/SpellsTab.vue',
                         'resources/js/components/manette/SpellInfoSheet.vue'],
    '15-manette-echange': ['resources/js/components/manette/EchangeSheet.vue'],
    # C'est ChoixListeSheet qui porte le palier de QUANTITÉ (« Combien en
    # jeter ? ») : la figure ne montre rien de ce que ActionTab compose.
    '16-manette-jeter-quantite': ['resources/js/components/manette/ChoixListeSheet.vue'],
    '19-table-prologue': ['resources/js/components/table/PrologueOverlay.vue'],
    '20-table-hub': ['resources/js/components/table/GroupPanel.vue',
                     'resources/js/components/table/MarketPanel.vue'],
    '21-table-quete': CARTE + ['resources/js/components/table/InitiativeBar.vue',
                               'resources/js/components/table/OuvertureQuete.vue'],
    '22-table-donjon': CARTE,
    # ⚠ Le menu est COMPOSÉ par le moteur : une option ajoutée là périme
    # l'image sans qu'aucun fichier Vue ne bouge. C'est exactement ce qui est
    # arrivé à cette figure, dont la légende ÉNUMÉRAIT un menu devenu
    # incomplet — la légende a menti avant l'image.
    '30-manette-action': ['resources/js/components/manette/ActionTab.vue',
                          'resources/js/components/manette/ChoiceCard.vue',
                          'app/Partie/MenuMoteur.php'],
    '31-manette-deplacement': ['resources/js/components/manette/DeplacementSheet.vue'] + SOCLE,
    # ⚠ CORRIGÉ 2026-09-18 : cette figure montre le FIL DU COMBAT (« Fil du
    # combat », JournalCombat), pas une feuille de ciblage — c'est ActionTab.vue
    # qui le peint, pas CibleSheet.vue (qui n'apparaît nulle part hors du tour
    # actif). L'ancienne entrée n'aurait jamais signalé une évolution du fil.
    '32-manette-combat': ['resources/js/components/manette/ActionTab.vue',
                          'app/Partie/JournalCombat.php'],
    # Attaquer à une arme (forme À PLAT, `parametres.arme`+`cibles`) saute
    # directement à CibleSheet ; c'est MenuMoteur qui décide de cette forme
    # (une seule entrée dans `armesAAttaquer`) et ManetteView qui route dessus.
    '33-manette-attaque-simple': ['resources/js/components/manette/CibleSheet.vue',
                                 'resources/js/views/ManetteView.vue',
                                 'app/Partie/MenuMoteur.php'],
    # Deux armes aux cibles valides ouvrent le sous-choix « Avec quelle arme ? »
    # (ChoixListeSheet, titre posé par ManetteView) avant CibleSheet — MenuMoteur
    # décide de la forme `armes[]` et calcule les cibles PAR ARME.
    '34-manette-attaque-deux-armes': ['resources/js/components/manette/ChoixListeSheet.vue',
                                      'resources/js/views/ManetteView.vue',
                                      'app/Partie/MenuMoteur.php'],
    '70-scene-attaque': [SCENE, 'app/Partie/SceneDeTable.php'],
    '71-scene-jet': [SCENE, 'app/Partie/SceneDeTable.php'],
    '72-scene-attaque-monstre': [SCENE, 'app/Partie/SceneDeTable.php'],
    '73-scene-chute': [SCENE, 'app/Partie/SceneDeTable.php'],
    '74-scene-salle': [SCENE, 'app/Partie/SceneDeTable.php'],
    '75-scene-fouille': [SCENE, 'app/Partie/SceneDeTable.php'],
    '76-scene-piege': [SCENE, 'app/Partie/SceneDeTable.php'],
}


def figures_du_livret():
    """Les figures réellement appelées par `generer.py` — la source de vérité."""
    source = io.open(GENERER, encoding='utf-8').read()
    return set(re.findall(r"fig\('([0-9a-z-]+)'", source))


def modifies_non_commites():
    """Les chemins modifiés dans l'ARBRE DE TRAVAIL, pas encore commités.

    ⚠ Angle mort mesuré le 2026-09-18, le lendemain de l'écriture de ce
    contrôle : il ne comparait qu'aux COMMITS, si bien que deux chantiers
    entiers posés dans l'arbre — qui changeaient le menu d'action de fond en
    comble — le laissaient annoncer « à jour ». Une capture prise avant une
    modification non commitée est pourtant tout aussi périmée qu'après un
    commit : ce qui la périme, c'est que l'écran ait changé, pas que git l'ait
    enregistré.
    """
    sortie = subprocess.run(
        ['git', 'status', '--porcelain', '--untracked-files=all'],
        cwd=RACINE, capture_output=True, text=True,
    ).stdout

    return {ligne[3:].strip() for ligne in sortie.splitlines() if len(ligne) > 3}


def dernier_commit(chemins):
    """Horodatage du dernier commit touchant l'un de ces chemins, et son sujet."""
    sortie = subprocess.run(
        ['git', 'log', '-1', '--format=%ct\t%cs\t%s', '--'] + chemins,
        cwd=RACINE, capture_output=True, text=True,
    ).stdout.strip()

    if not sortie:
        return None, None, None

    horodatage, date, sujet = sortie.split('\t', 2)
    return int(horodatage), date, sujet


def main():
    strict = '--strict' in sys.argv
    attendues = figures_du_livret()
    declarees = set(DEPENDANCES)

    # ── LE REGISTRE, DANS LES DEUX SENS ──────────────────────────────────────
    manquantes = sorted(attendues - declarees)
    orphelines = sorted(declarees - attendues)
    faute_de_registre = bool(manquantes or orphelines)

    if manquantes:
        print('✗ FIGURES NON SURVEILLÉES — ajoutez-les à DEPENDANCES, sinon elles')
        print('  vieilliront en silence, ce que ce contrôle existe pour empêcher :')
        for nom in manquantes:
            print('    · {}'.format(nom))
        print()

    if orphelines:
        print('✗ ENTRÉES MORTES — le livret n\'utilise plus ces figures :')
        for nom in orphelines:
            print('    · {}'.format(nom))
        print()

    # ── FRAÎCHEUR, FIGURE PAR FIGURE ─────────────────────────────────────────
    absentes, perimees, fraiches, en_cours = [], [], [], []
    travail = modifies_non_commites()

    for nom in sorted(attendues & declarees):
        fichier = os.path.join(CAPTURES, nom + '.webp')

        if not os.path.exists(fichier):
            absentes.append(nom)
            continue

        prise = os.path.getmtime(fichier)
        commit, date, sujet = dernier_commit(DEPENDANCES[nom])

        # ⚠ L'arbre de travail d'abord : un chantier en cours périme une
        # capture avant tout commit, et c'est même le cas le PLUS fréquent —
        # on refait les captures en fin de chantier, pas après le push.
        # ⚠ Et l'ordre compte, sinon on crie au loup : une capture prise
        # APRÈS l'édition non commitée est à jour, précisément parce qu'on
        # vient de la refaire pour ce chantier-là. Mesuré le 2026-09-18 — la
        # passe du livret a fini à « 7 en chantier » alors que ses sept
        # captures étaient neuves. On ne retient donc que les dépendances
        # modifiées APRÈS la prise, exactement comme pour un commit.
        # ⚠ C'est le FICHIER qui date, jamais le commit. Troisième correction
        # de ce contrôle, et la plus instructive : comparer à la date du COMMIT
        # rendait périmée toute capture prise AVANT le commit qui enregistre le
        # code qu'elle montre — c'est-à-dire toutes, puisqu'on capture en fin de
        # chantier et qu'on commite ensuite. Mesuré le 2026-09-18 : sept figures
        # neuves déclarées périmées par le `git push` qui venait de les livrer.
        # Le `mtime` du fichier source répond à la seule question qui compte —
        # « l'écran a-t-il changé depuis la photo ? » — qu'il soit commité ou
        # non. Le commit ne sert plus qu'à NOMMER le changement dans le rapport.
        recents = sorted(
            c for c in DEPENDANCES[nom]
            if os.path.exists(os.path.join(RACINE, c))
            and os.path.getmtime(os.path.join(RACINE, c)) > prise
        )

        if not recents:
            fraiches.append(nom)
        elif set(recents) & travail:
            en_cours.append((nom, sorted(set(recents) & travail)))
        else:
            perimees.append((nom, date, sujet))

    if absentes:
        print('· ABSENTES ({}) — dépôt neuf, ou captures jamais prises.'.format(len(absentes)))
        print('  Le livret se génère sans elles : voir docs/livret/README.md §Refaire les captures.')
        for nom in absentes:
            print('    · {}'.format(nom))
        print()

    if en_cours:
        print('⚠ CHANTIER EN COURS ({}) — ce qu\'elles montrent est modifié dans l\'arbre'.format(len(en_cours)))
        print('  de travail, pas encore commité. À refaire AVANT le commit, pas après :')
        print('  c\'est le moment où l\'on sait encore ce qui a changé à l\'écran.')
        for nom, touches in en_cours:
            print('    · {:<26} {}'.format(nom, ', '.join(os.path.basename(c) for c in touches)))
        print()

    if perimees:
        print('⚠ PÉRIMÉES ({}) — prises AVANT le dernier changement de ce qu\'elles montrent.'.format(len(perimees)))
        print('  Ce n\'est pas une preuve qu\'elles mentent : allez les REGARDER, et')
        print('  relisez la légende en même temps — c\'est elle qui ment en premier.')
        for nom, date, sujet in perimees:
            print('    · {:<26} {} — {}'.format(nom, date, sujet[:64]))
        print()

    print('{} à jour · {} en chantier · {} périmées · {} absentes · {} figures au livret'.format(
        len(fraiches), len(en_cours), len(perimees), len(absentes), len(attendues)))

    if faute_de_registre:
        return 2
    if strict and (perimees or en_cours):
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
