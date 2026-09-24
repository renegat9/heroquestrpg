#!/usr/bin/env python3
"""Construit docs/livret/livret.html — le livret de jeu, tables issues du
catalogue RÉEL (dump de la base) et captures prises sur la vraie stack.

  python3 docs/livret/generer.py <catalogue.json>

Rien n'est saisi à la main de ce que la base porte déjà : stats, prix, effets
et difficultés viennent du dump, de sorte qu'un livret périmé se régénère au
lieu de mentir. Les textes de règle, eux, sont écrits ici et adossés aux docs
de conception (reference/01 à 05, 10) — ils citent leur source en commentaire.
"""
import json, os, re, sys, html, shutil, datetime

RACINE = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
CAT = json.load(open(sys.argv[1], encoding='utf-8'))
# ⚠ Ce que fait un objet est TRADUIT PAR LE SERVEUR (`MotsClesEquipement::avantages()`),
# pas ici. Le livret tenait sa propre table Python, recopiée un jour et jamais suivie :
# 37 objets sur 105 sortaient avec « — » (2026-09-16). Un catalogue produit à l'ancienne
# doit donc ARRÊTER la génération, pas l'autoriser à retomber dans ce silence.
if 'avantages_objets' not in CAT:
    sys.exit("Catalogue sans « avantages_objets » : régénère-le avec ./docs/livret/catalogue.sh")
SORTIE = os.path.join(RACINE, 'docs', 'livret', 'livret.html')
PDF = 'HeroQuest-RPG-Livret-de-jeu.pdf'

# ---------------------------------------------------------------- images ----
def _index(dossier):
    """{id: chemin} des vignettes de docs/livret/img — des copies réduites à la
    TAILLE D'IMPRESSION. Les sources 1024x1024 de public/images produisaient un
    PDF de 104 Mo : Chromium embarque le bitmap décodé, pas le fichier."""
    base = os.path.join(RACINE, 'docs', 'livret', 'img', 'catalogue', dossier)
    out = {}
    if not os.path.isdir(base):
        return out
    for f in sorted(os.listdir(base)):
        m = re.match(r'^(\d+)-(.+)\.webp$', f)
        if m:
            out[int(m.group(1))] = f'img/catalogue/{dossier}/{f}'
    return out

IMG = {d: _index(d) for d in
       ('monstres', 'objets', 'sorts', 'pieges', 'mobiliers', 'terrains', 'epreuves', 'portes', 'leviers')}

def img_classe(nom):
    p = f'docs/livret/img/classes/{nom}.webp'
    return f'img/classes/{nom}.webp' if os.path.exists(os.path.join(RACINE, p)) else None

def capture(nom):
    # les .webp redimensionnés (browser-shots/livret/web) pèsent 1 Mo au total
    # là où les PNG d'origine en font 14 : le PDF les embarque tels quels.
    for p in (f'browser-shots/livret/web/{nom}.webp', f'browser-shots/livret/{nom}.png'):
        if os.path.exists(os.path.join(RACINE, p)):
            return '../../' + p
    return None

# ------------------------------------------------------------- fragments ----
def e(x):
    return html.escape(str(x if x is not None else ''))

def j(v):
    """Les colonnes JSON reviennent en texte selon le pilote — normalise."""
    if isinstance(v, (dict, list)):
        return v
    if not v:
        return {}
    try:
        return json.loads(v)
    except Exception:
        return {}

TELEPHONE = re.compile(r'^(1[0-4]|3[0-3])-')

def _dimensions_png(nom):
    """(largeur, hauteur) lues dans l'IHDR du PNG d'origine — 8 octets d'en-tête,
    4 de longueur, 4 de type, puis les deux entiers 32 bits.

    ⚠ Sans dimensions, une image `loading="lazy"` n'occupe AUCUNE place tant
    qu'elle n'est pas chargée : on saute sur une ancre, les captures au-dessus
    arrivent ensuite, et le titre visé se retrouve 600 px plus bas. Mesuré. Le
    ratio des .webp servis est celui des PNG (redimensionnement à largeur fixe),
    donc ces valeurs-ci suffisent au navigateur pour réserver la bonne boîte."""
    chemin = os.path.join(RACINE, 'browser-shots', 'livret', f'{nom}.png')
    try:
        with open(chemin, 'rb') as f:
            entete = f.read(24)
        if entete[:8] != b'\x89PNG\r\n\x1a\n':
            return None
        return int.from_bytes(entete[16:20], 'big'), int.from_bytes(entete[20:24], 'big')
    except OSError:
        return None

def fig(nom, legende, classe=None):
    """`classe` est déduite du nom : une capture de manette (412x915) est bien
    plus haute que large et doit être plafonnée en hauteur, sinon elle occupe
    une page entière à elle seule."""
    src = capture(nom)
    if not src:
        return f'<!-- capture manquante : {nom} -->'
    if classe is None:
        classe = 'fig tel' if TELEPHONE.match(nom) else 'fig'
    dim = _dimensions_png(nom)
    taille = f' width="{dim[0]}" height="{dim[1]}"' if dim else ''
    return (f'<figure class="{classe}"><img src="{src}"{taille} alt="{e(legende)}">'
            f'<figcaption>{legende}</figcaption></figure>')

MORCEAUX = []
def ecrire(s):
    MORCEAUX.append(s)

CSS = r"""
@import url('https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700&family=Spectral:ital,wght@0,400;0,600;1,400&family=Public+Sans:wght@400;600;700&display=swap');

@page { size: A4; margin: 17mm 15mm 16mm; }
@page :first { margin: 0; }

:root{
  --encre:#221a12; --encre-2:#4a3a29; --encre-3:#6d5a44;
  --parchemin:#f6efe3; --parchemin-2:#efe5d4; --trait:#d8c9ae;
  --braise:#b4551d; --or:#8a6a20; --sang:#8c2f1f; --nuit:#14100c;
  --display:'Cinzel','Trajan Pro',Georgia,serif;
  --narr:'Spectral',Georgia,'Times New Roman',serif;
  --ui:'Public Sans',system-ui,sans-serif;
}
*{box-sizing:border-box}
body{margin:0;background:var(--parchemin);color:var(--encre);
     font-family:var(--narr);font-size:10.2pt;line-height:1.52;
     -webkit-print-color-adjust:exact;print-color-adjust:exact}

/* ---------------------------------------------------------- couverture -- */
.couv{position:relative;height:297mm;width:210mm;overflow:hidden;
      background:var(--nuit);color:#f3e7d0;page-break-after:always;display:flex;
      flex-direction:column;justify-content:flex-end}
.couv img.fond{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;
               opacity:.62;filter:saturate(.85) contrast(1.05)}
.couv .voile{position:absolute;inset:0;
  background:linear-gradient(180deg,rgba(12,9,6,.76) 0%,rgba(12,9,6,.20) 38%,rgba(12,9,6,.88) 78%,#0c0906 100%)}
.couv .bloc{position:relative;padding:0 22mm 26mm}
.couv .surtitre{font-family:var(--ui);letter-spacing:.34em;text-transform:uppercase;
  font-size:9pt;color:#d9a05b;margin-bottom:6mm}
.couv h1{font-family:var(--display);font-weight:700;font-size:46pt;line-height:1.02;
  margin:0 0 5mm;color:#f7ecd6;text-shadow:0 2px 24px rgba(0,0,0,.7)}
.couv .sous{font-family:var(--narr);font-style:italic;font-size:14pt;color:#e6d3b1;
  margin:0 0 9mm;max-width:130mm}
.couv .filet{height:2px;width:56mm;background:linear-gradient(90deg,#c8862f,transparent);margin-bottom:7mm}
.couv .pied{font-family:var(--ui);font-size:8.6pt;color:#b39b78;letter-spacing:.06em}

/* ------------------------------------------------------------- titrage -- */
h2{font-family:var(--display);font-size:21pt;font-weight:700;color:var(--encre);
   margin:0 0 1mm;letter-spacing:.01em;page-break-after:avoid}
h2 .num{color:var(--braise);font-size:15pt;margin-right:3mm;letter-spacing:.08em}
h3{font-family:var(--display);font-size:12.6pt;font-weight:600;color:var(--braise);
   margin:7mm 0 2mm;page-break-after:avoid}
h4{font-family:var(--ui);font-size:9.4pt;font-weight:700;letter-spacing:.13em;
   text-transform:uppercase;color:var(--or);margin:5mm 0 1.5mm;page-break-after:avoid}
.chapitre{page-break-before:always}
.chapitre>h2{border-bottom:2px solid var(--trait);padding-bottom:2.5mm;margin-bottom:4mm}
.chapo{font-style:italic;color:var(--encre-2);font-size:11pt;margin:0 0 5mm;
  border-left:2.5px solid var(--braise);padding-left:4mm}
p{margin:0 0 3mm;text-align:justify;hyphens:auto}
ul,ol{margin:0 0 3mm;padding-left:5.5mm}
li{margin-bottom:1.2mm}
strong{color:var(--encre);font-weight:600}
em.vo{color:var(--encre-3)}
code{font-family:var(--ui);font-size:8.8pt;background:var(--parchemin-2);
     border:1px solid var(--trait);border-radius:2px;padding:0 1mm}

/* ------------------------------------------------------------ tableaux -- */
table{width:100%;border-collapse:collapse;font-family:var(--ui);font-size:8.6pt;
      margin:0 0 4mm;page-break-inside:auto}
thead{display:table-header-group}
tr{page-break-inside:avoid}
th{background:#e7dbc4;color:#4a3a29;text-align:left;font-weight:700;font-size:7.8pt;
   letter-spacing:.08em;text-transform:uppercase;padding:1.8mm 2mm;
   border-bottom:1.5px solid var(--trait)}
td{padding:1.6mm 2mm;border-bottom:.5px solid #e2d6bf;vertical-align:middle}
tbody tr:nth-child(even) td{background:rgba(226,214,191,.32)}
td.n,th.n{text-align:center;font-variant-numeric:tabular-nums}
td.nom{font-weight:600;color:var(--encre);font-family:var(--narr);font-size:9.4pt}
td.vig{width:11mm;padding:1mm}
td.vig img{width:9mm;height:9mm;object-fit:cover;border-radius:2px;
  border:1px solid var(--trait);display:block}

/* ------------------------------------------------------------- figures -- */
figure.fig{margin:0 0 4mm;page-break-inside:avoid}
figure.fig img{width:100%;height:auto;display:block;border:1px solid #2a2118;border-radius:3px;
  background:var(--nuit);box-shadow:0 2px 10px rgba(60,42,20,.22)}
figure.fig figcaption{font-family:var(--ui);font-size:7.9pt;color:var(--encre-3);
  margin-top:1.4mm;line-height:1.35}
figure.tel img{width:auto;height:auto;max-width:100%;max-height:118mm;margin:0 auto}
figure.tel figcaption{text-align:center}
/* Scène de table RECADRÉE sur sa carte (600 px de large à l'écran, pas 1600) :
   en pleine colonne elle deviendrait une affiche, d'où un plafond de hauteur ET
   de largeur — la scène de salle, large et basse, passait sous le premier seul.
   ⚠ Ces règles vivent dans LES DEUX feuilles, comme `height:auto` — corriger
   l'écran seul casse le PDF en silence. */
figure.scene img{width:auto;height:auto;max-width:min(100%,120mm);max-height:92mm;margin:0 auto}
figure.scene figcaption{text-align:center}
.duo{display:grid;grid-template-columns:1fr 1fr;gap:5mm;align-items:start}
.trio{display:grid;grid-template-columns:1fr 1fr 1fr;gap:4mm;align-items:start}

/* -------------------------------------------------------------- blocs --- */
.encadre{background:var(--parchemin-2);border:1px solid var(--trait);
  border-left:3px solid var(--braise);border-radius:3px;padding:3.5mm 4mm;
  margin:0 0 4mm;page-break-inside:avoid;font-size:9.6pt}
.encadre h4{margin-top:0}
.avert{border-left-color:var(--sang);background:#f3e6dd}
.cartouche{background:var(--nuit);color:#efe0c4;border-radius:4px;padding:4mm 5mm;
  margin:0 0 4mm;page-break-inside:avoid}
.cartouche h4{color:#d9a05b;margin-top:0}
.cartouche p{color:#e4d5b8}

.grille-classes{display:grid;grid-template-columns:repeat(3,1fr);gap:3.5mm;margin-bottom:4mm}
.cl{border:1px solid var(--trait);border-radius:3px;overflow:hidden;
    background:var(--parchemin-2);page-break-inside:avoid}
.cl img{width:100%;height:30mm;object-fit:cover;object-position:center 22%;display:block}
.cl .nom{font-family:var(--display);font-size:10.4pt;font-weight:700;padding:1.6mm 2mm .4mm}
.cl .race{font-family:var(--ui);font-size:7.2pt;letter-spacing:.1em;text-transform:uppercase;
  color:var(--encre-3);padding:0 2mm 1.4mm}
.cl .st{font-family:var(--ui);font-size:7.6pt;color:var(--encre-2);padding:0 2mm 2.2mm;
  display:flex;flex-wrap:wrap;gap:0 2.6mm}
.cl .st b{color:var(--braise)}

.des{display:grid;grid-template-columns:repeat(3,1fr);gap:3mm;margin-bottom:4mm}
.de{border:1px solid var(--trait);border-radius:3px;background:#fff;padding:3mm;text-align:center}
.de .face{font-size:19pt;line-height:1}
.de .lib{font-family:var(--ui);font-size:8pt;font-weight:700;margin-top:1.5mm}
.de .txt{font-family:var(--ui);font-size:7.4pt;color:var(--encre-3);line-height:1.3}

.vignettes{display:grid;grid-template-columns:repeat(6,1fr);gap:2.5mm;margin-bottom:4mm}
.vg{text-align:center;page-break-inside:avoid}
.vg img{width:100%;aspect-ratio:1;object-fit:cover;border-radius:3px;border:1px solid var(--trait)}
.vg span{display:block;font-family:var(--ui);font-size:6.9pt;color:var(--encre-2);
  margin-top:.8mm;line-height:1.2}

.somm{font-family:var(--ui);font-size:10pt;columns:2;column-gap:10mm}
.somm div{break-inside:avoid;margin-bottom:2.2mm}
.somm a{display:flex;gap:3mm;align-items:baseline;color:inherit;text-decoration:none}
.somm b{color:var(--braise);font-variant-numeric:tabular-nums;min-width:6mm}

.memo{columns:2;column-gap:8mm;font-family:var(--ui);font-size:8.8pt}
.memo section{break-inside:avoid;margin-bottom:4mm;border:1px solid var(--trait);
  border-radius:3px;padding:2.5mm 3mm;background:var(--parchemin-2)}
.memo h4{margin:0 0 1.5mm}
.memo ul{padding-left:4mm;margin:0}
.memo li{margin-bottom:.8mm}
"""

AUJ = datetime.date.today().strftime('%d/%m/%Y')

CHAPITRES = []          # (numéro, titre) pour le sommaire

def chapitre(num, titre, chapo=None):
    CHAPITRES.append((num, titre))
    ecrire(f'<section class="chapitre" id="ch{num}">'
           f'<h2><span class="num">{num}</span>{titre}</h2>')
    if chapo:
        ecrire(f'<p class="chapo">{chapo}</p>')

def fin():
    ecrire('</section>')

# =========================================================== COUVERTURE ====
ecrire(f'''
<div class="couv">
  <img class="fond" src="img/couverture.webp" alt="">
  <div class="voile"></div>
  <div class="bloc">
    <div class="surtitre">Jeu de rôle de table · Maître du jeu IA</div>
    <h1>HeroQuest<br>RPG</h1>
    <div class="filet"></div>
    <p class="sous">Livret de jeu — les règles, l'écran de table, la manette,
       et tout ce que le donjon peut vous jeter à la figure.</p>
    <div class="pied">Projet auto-hébergé · à jouer en réseau local · {AUJ}</div>
  </div>
</div>''')

# =============================================================== SOMMAIRE ==
ecrire('<section class="chapitre" id="somm" style="page-break-before:auto">'
       '<h2>Sommaire</h2><!--GABARIT-SOMMAIRE-->')
ecrire('''
<div class="encadre" style="margin-top:6mm">
  <h4>Comment lire ce livret</h4>
  <p>Les <strong>trois premiers chapitres</strong> suffisent pour s'asseoir et jouer :
  ce qu'est le jeu, comment ouvrir une table, et comment se déroule un tour. Le reste
  est de la référence — on y revient quand une question se pose à la table.</p>
  <p>Les valeurs chiffrées de ce livret sont <strong>extraites de la base du jeu</strong>
  au moment de sa génération : elles disent ce que le moteur applique réellement, et non
  ce qu'un document de conception avait prévu.</p>
</div>''')
ecrire('</section>')

# ====================================================== 1. LE JEU ==========
chapitre(1, 'Le jeu en une page',
         "Un donjon, quatre héros, des dés à crânes — et un maître du jeu qui n'a "
         "jamais besoin d'aller chercher la règle.")
ecrire('''
<p><strong>HeroQuest RPG</strong> est un jeu de rôle de table fondé sur HeroQuest. Le combat
tactique reste celui du plateau : une grille, des dés de combat, des monstres qui frappent
fort. Ce qui change, c'est que la partie est <strong>arbitrée par un programme</strong> et
<strong>racontée par une intelligence artificielle</strong> — et que ces deux rôles ne se
mélangent jamais.</p>

<div class="cartouche">
  <h4>La règle qui gouverne tout le reste</h4>
  <p><strong>Le moteur fait autorité sur toute mécanique.</strong> Dés, points de vie,
  déplacement, portée, ligne de vue, butin : tout est résolu par du code déterministe.
  <strong>L'IA narre, décrit et propose</strong> — elle habille un gobelin en « Fouilleur de
  cendres », elle raconte la salle, elle donne un nom au boss. Elle ne décide jamais d'un
  résultat, et elle ne peut pas inventer une action que le moteur refuserait ensuite.</p>
</div>

<h3>Deux écrans, deux rôles</h3>
<p>Les rôles sont des <em>vues</em>, pas des appareils : n'importe quel navigateur peut tenir
l'un ou l'autre.</p>
<ul>
  <li><strong>L'écran de table</strong> — grand écran partagé, posé au milieu. Il porte la
      carte du donjon, les figurines, la narration du maître du jeu, la barre d'initiative et
      l'état du groupe. C'est le plateau.</li>
  <li><strong>La manette</strong> — le téléphone de chaque joueur. Elle porte la fiche du
      héros, son sac, ses sorts, et surtout <strong>le menu de ses actions</strong> quand
      vient son tour. C'est la main du joueur.</li>
</ul>

<h3>La boucle de jeu</h3>
<p>Elle tient en trois temps, répétés jusqu'à la fin de la quête :</p>
<ol>
  <li>l'IA <strong>raconte</strong> ce qui vient de se produire et ce que l'on voit ;</li>
  <li>le moteur <strong>compose un menu</strong> des actions légales pour le héros dont c'est
      le tour — et l'IA ne fait qu'en soigner les libellés ;</li>
  <li>le joueur <strong>choisit</strong>, le moteur <strong>résout</strong>, et l'on
      recommence.</li>
</ol>
<p>On ne tape donc jamais de texte libre. Ce n'est pas une limitation d'écriture : c'est ce
qui garantit qu'aucune action proposée ne sera refusée après coup, et qu'une partie reste
jouable même quand plus personne n'a la règle exacte en tête.</p>
''')
ecrire(fig('01-accueil', "L'écran d'accueil : on entre par le rôle que l'on tient ce soir — "
                         "la table, ou une manette. Le guide de jeu intégré est accessible sans compte."))
ecrire('''
<div class="encadre">
  <h4>Ce que le jeu fait sans clé d'API</h4>
  <p>Le jeu reste <strong>jouable sans aucun service d'IA</strong> : menus du moteur, narration
  scriptée, noms de catalogue, emblèmes dessinés. Ce n'est pas un mode dégradé, c'est une
  façon prévue de jouer — l'IA ajoute la couleur, pas la partie.</p>
</div>''')
fin()

# =============================================== 2. OUVRIR UNE TABLE ======
chapitre(2, 'Ouvrir une table',
         "Cinq minutes, un code de groupe, et autant de téléphones que de joueurs.")
ecrire('''
<h3>Le narrateur n'a pas de compte</h3>
<p>Celui qui tient la table ouvre simplement <code>/narrateur</code> et saisit le
<strong>code du groupe</strong>. Rien d'autre : pas d'inscription, pas de mot de passe. Tant
que cet écran reste ouvert, il envoie un battement de cœur toutes les quinze secondes — c'est
ce signal qui dit au serveur qu'une table est active. <strong>Sans narrateur actif, aucune
quête ne démarre</strong>.</p>

<h3>Les joueurs ont un compte et un roster</h3>
<p>Chaque joueur se crée un compte (un pseudo, un identifiant — c'est tout) et se constitue un
<strong>roster de personnages</strong> qui lui survit d'une campagne à l'autre. Un personnage
n'est engagé que dans <strong>un seul groupe à la fois</strong> ; pour jouer ailleurs en
parallèle, on engage un autre héros de son roster.</p>
''')
ecrire('<div class="duo">' +
       fig('02-narrateur', 'Côté table : un code, et l’écran partagé s’ouvre.') +
       fig('03-joueur-connexion', 'Côté joueur : un identifiant suffit à retrouver son roster.') +
       '</div>')
ecrire('''
<h3>Monter le groupe</h3>
<ol>
  <li>Un joueur <strong>crée le groupe</strong> depuis un personnage libre : il en devient le
      fondateur, choisit un <strong>thème</strong> (registre fantasy) et une
      <strong>longueur de campagne</strong>. Le code du groupe apparaît.</li>
  <li>Les autres <strong>rejoignent par ce code</strong>, chacun avec un personnage libre de
      son roster. Un personnage ne figure qu'une fois dans un groupe.</li>
  <li>Le narrateur <strong>ouvre la table</strong> avec le même code.</li>
  <li>Au hub, on <strong>achète</strong>, on <strong>équipe</strong>, on se
      <strong>donne</strong> du matériel (chapitre 9).</li>
  <li>Quand chacun se déclare <strong>prêt</strong> et qu'un narrateur est actif, la quête
      démarre.</li>
</ol>
''')
ecrire('<div class="duo">' +
       fig('10-roster',
           "Le roster d’un joueur : Grom, engagé et verrouillé sur la campagne en cours, "
           "n’a pas de bouton de suppression ; Essai, libre et jamais entré en jeu, porte "
           "<strong>Supprimer (créé par erreur)</strong>.") +
       fig('20-table-hub', 'Le hub sur l’écran de table : les deux thèmes de la campagne sous '
                           'le titre — le récit libre et la boîte de bestiaire, figée pour '
                           'toute la campagne — l’ordre du tour réglable, le récit du maître de '
                           'jeu à relire en bas.') +
       '</div>')
ecrire('''
<h3>Supprimer un personnage créé par erreur</h3>
<p>Un roster ne savait qu'ajouter — jusqu'à un mauvais clic sur la classe à la création. Trois
conditions, <strong>toutes vérifiées côté serveur</strong>, ouvrent le bouton :</p>
<ol>
  <li>le personnage appartient au <strong>joueur connecté</strong> ;</li>
  <li>il est <strong>libre</strong> — aucun groupe actif ;</li>
  <li>il <strong>n'a jamais joué</strong> : jamais entré en quête, jamais achevé de campagne.</li>
</ol>
<p>⚠ <strong>Le vétéran est délibérément exclu, et c'est un choix, pas une limite technique.</strong>
Un héros entre deux campagnes porte des niveaux, de l'or, un équipement, un historique : c'est de
la <strong>donnée de campagne</strong>, que la règle dure du projet interdit de détruire. Ce
bouton ne corrige que l'erreur de saisie ; ranger un vétéran qu'on ne joue plus reste un autre
geste, à venir.</p>
''')

LONGUEURS = [('Très courte', '1 quête', 'une soirée'),
             ('Courte', '3 à 5 quêtes', 'quelques soirées'),
             ('Normale', '7 à 10 quêtes', 'une campagne complète'),
             ('Longue', '12 à 15 quêtes', 'une longue campagne'),
             ('Très longue', '17 à 20 quêtes', 'au très long cours')]
ecrire('<h3>La longueur décide de l\'arc</h3>'
       '<p>Elle n\'est pas qu\'un compteur : c\'est elle qui fixe le nombre de sous-boss et '
       'la place du boss final. On la choisit à la création du groupe, et on ne la change plus.</p>'
       '<table><thead><tr><th>Longueur</th><th>Quêtes</th><th>Ce que cela représente</th></tr></thead><tbody>')
for nom, q, duree in LONGUEURS:
    ecrire(f'<tr><td class="nom">{nom}</td><td>{q}</td><td>{duree}</td></tr>')
ecrire('</tbody></table>')
ecrire('''
<div class="encadre avert">
  <h4>Arriver, partir, se reconnecter</h4>
  <p>Un <strong>nouveau joueur</strong> ne rejoint qu'<strong>entre deux quêtes</strong>, au hub.
  En revanche, un membre de la quête en cours peut <strong>se reconnecter à tout moment</strong> :
  téléphone rechargé, application fermée, coupure de réseau — il reprend son héros là où il en
  était. Quitter en pleine quête demande un <strong>vote du groupe</strong> ; entre deux quêtes,
  on part librement avec sa part du pot commun.</p>
</div>''')
fin()

# ================================================= 3. LES HÉROS ===========
LIB_CLASSE = {
    'barbare': ("Brute de combat", "Frappe le plus fort du jeu, ne réfléchit pas beaucoup. "
                "Armes à deux mains et armure lourde dès le premier niveau."),
    'nain': ("Robuste et technique", "Le spécialiste des pièges : il les désamorce "
             "<strong>sans outils</strong>, et seul un bouclier noir le fait échouer."),
    'elfe': ("Polyvalent, magie légère", "Le meilleur marcheur. Choisit à la création un "
             "élément de magie <em>ou</em> trois sorts du répertoire elfique."),
    'magicien': ("Lanceur complet, fragile", "Neuf sorts dès le premier niveau, quatre points "
                 "de vie. Ni armure ni arme large."),
    'barde': ("Soutien et verbe", "+1 dé de défense tant qu'il ne porte ni métal ni bouclier. "
              "Ses cartes de classe sont des chants."),
    'druide': ("Nature et soin", "Refuse toute armure métallique. Ses cartes de classe sont "
               "des sorts de nature."),
    'warlock': ("Pacte et malédiction", "Halfling, donc lent. Manie exactement ce qu'un "
                "magicien peut manier, plus sa baguette."),
    'rogue': ("Ombre, lame et butin", "Ni métal ni bouclier ; démarre avec la Bandoulière. "
              "Sa frappe opportuniste exige un allié au contact de la cible."),
    'moine': ("Mains nues", "Un dé d'attaque supplémentaire à mains nues, trois dés de défense "
              "sans la moindre armure, et cinq armes autorisées, pas une de plus."),
    'chevalier': ("Rempart du groupe", "Les armures ne le ralentissent pas. Peut annuler les "
                  "dégâts subis par un héros voisin — une fois par quête."),
    'berserker': ("Rage et sang", "Peut perdre des points de vie pour gagner des dés d'attaque. "
                  "N'utilise aucune arme à distance."),
    'explorateur': ("Traqueur et fouineur", "Un nain, mais le plus rapide des siens. Le plus gros "
                    "sac du jeu, et il désamorce comme un nain."),
}
ORDRE_CLASSES = ['barbare', 'nain', 'elfe', 'magicien', 'chevalier', 'berserker',
                 'moine', 'rogue', 'barde', 'druide', 'warlock', 'explorateur']
CLASSES = {c['nom']: c for c in CAT['classes_heros']}

chapitre(3, 'Les héros',
         "Deux statistiques seulement — mais trois familles de chiffres qu'il ne faut pas confondre.")
ecrire('''
<h3>Attribut, points de vie, valeurs de combat</h3>
<p>C'est la seule subtilité du système, et elle se retient en une phrase :
<strong>l'attribut est le nombre de dés que l'on lance, les points sont la jauge de vie, et
les valeurs de combat viennent de l'équipement.</strong></p>
<table>
<thead><tr><th>Ce chiffre</th><th>Sert à</th><th>Évolue</th></tr></thead>
<tbody>
<tr><td class="nom">Attribut Body</td><td>le nombre de dés d'un jet physique (forcer, sauter, escalader, résister)</td><td>par des talents dédiés</td></tr>
<tr><td class="nom">Attribut Mind</td><td>le nombre de dés d'un jet mental (savoir, perception, volonté, persuasion)</td><td>par des talents dédiés</td></tr>
<tr><td class="nom">Points de Body</td><td>la vie physique — à 0, le héros tombe</td><td>dégâts et soins ; plein entre deux quêtes</td></tr>
<tr><td class="nom">Points de Mind</td><td>la résistance à la magie, à la peur, à la corruption</td><td>idem</td></tr>
<tr><td class="nom">Dés d'attaque</td><td>l'attaque — <strong>ils viennent de l'arme</strong>, pas du héros</td><td>en changeant d'arme</td></tr>
<tr><td class="nom">Dés de défense</td><td>la parade — base 2, <strong>l'armure s'y ajoute</strong></td><td>en s'équipant</td></tr>
<tr><td class="nom">Déplacement</td><td>une base propre au héros, <strong>+ 1d6 chaque tour</strong></td><td>talents, sorts, armure lourde</td></tr>
</tbody></table>

<div class="encadre avert">
  <h4>À mains nues, tout le monde lance un dé</h4>
  <p>Les fiches ci-dessous donnent la <strong>base</strong> du héros. L'attaque vient de l'arme
  équipée : un barbare tout nu frappe à 1 dé comme un magicien. C'est son épée large qui en
  fait 3. De même, la défense vaut 2 pour tous, et casque, armure et bouclier
  <strong>se cumulent</strong> par-dessus — jusqu'à 6 dés pour un héros complètement harnaché.</p>
</div>
''')

ecrire('<h3>Les douze classes</h3><div class="grille-classes">')
for nom in ORDRE_CLASSES:
    c = CLASSES.get(nom)
    if not c:
        continue
    src = img_classe(nom)
    titre, _ = LIB_CLASSE.get(nom, ('', ''))
    ecrire(f'''<div class="cl">
      {'<img src="' + src + '" alt="">' if src else ''}
      <div class="nom">{nom.capitalize()}</div>
      <div class="race">{c['race']} · {titre}</div>
      <div class="st"><span>Body <b>{c['pv_body']}</b></span><span>Mind <b>{c['pv_mind']}</b></span>
        <span>att. B <b>{c['attr_body']}</b></span><span>att. M <b>{c['attr_mind']}</b></span>
        <span>déf. <b>{c['des_defense']}</b></span><span>dépl. <b>{c['deplacement_base']}</b></span></div>
    </div>''')
ecrire('</div>')

ecrire('<h4>Ce que chacune apporte à la table</h4><table>'
       '<thead><tr><th>Classe</th><th>Race</th><th class="n">Sac</th><th>Particularité</th></tr></thead><tbody>')
for nom in ORDRE_CLASSES:
    c = CLASSES.get(nom)
    if not c:
        continue
    cap = c['pv_body'] // 2 + (c['bonus_sac'] or 0)
    _, desc = LIB_CLASSE.get(nom, ('', ''))
    ecrire(f'<tr><td class="nom">{nom.capitalize()}</td><td>{c["race"]}</td>'
           f'<td class="n">{cap}</td><td>{desc}</td></tr>')
ecrire('</tbody></table>')

ecrire('''
<div class="encadre">
  <h4>D'où vient le déplacement</h4>
  <p>La base est <strong>raciale</strong>, plus un bonus si la classe est vendue comme agile :
  nain et halfling 3, humain 4, elfe 5 — et +1 pour les classes agiles (rogue, moine,
  berserker, explorateur). Deux règles tiennent l'ensemble : <strong>aucun nain ne dépasse
  l'elfe</strong>, <strong>aucun halfling ne dépasse un humain</strong>. À chaque tour, on
  ajoute <strong>1d6</strong> — un elfe parcourt donc 6 à 11 cases.</p>
  <p>Le <strong>sac à dos</strong> vaut les points de Body maximum divisés par deux, plus le
  bonus de la classe. Il ne contient que les armes et armures <em>non équipées</em> : potions,
  parchemins et objets de quête n'y prennent jamais de place.</p>
</div>''')
ecrire(fig('04-guide-heros', "Le guide de jeu intégré (accessible sans compte) donne pour chaque "
                             "classe ses maîtrises d'équipement et sa grille de talents complète."))
fin()

# ============================================ 4. LES DÉS ET LES JETS ======
chapitre(4, 'Les dés et les jets',
         "Un seul dé pour tout le jeu : six faces, trois crânes, deux boucliers blancs, "
         "un bouclier noir.")
ecrire('''
<div class="des">
  <div class="de"><div class="face">☠</div><div class="lib">3 faces — Crâne</div>
    <div class="txt">Une touche à l'attaque.<br>Un succès sur un jet de compétence.</div></div>
  <div class="de"><div class="face">🛡</div><div class="lib">2 faces — Bouclier blanc</div>
    <div class="txt">Une parade réussie,<br><strong>pour un héros</strong>.</div></div>
  <div class="de"><div class="face">⬛</div><div class="lib">1 face — Bouclier noir</div>
    <div class="txt">Une parade réussie,<br><strong>pour un monstre</strong>.</div></div>
</div>
<p>Un crâne sort donc <strong>une fois sur deux</strong>. Les monstres parent moins souvent que
les héros : c'est voulu, et c'est de là que vient tout l'équilibre du combat.</p>

<h3>Le jet de compétence</h3>
<p>On lance <strong>autant de dés que l'attribut concerné</strong> — Body pour la force,
l'agilité et l'endurance, Mind pour le savoir, la perception, la volonté et la persuasion.
Chaque crâne compte pour un succès, et la difficulté annoncée dit combien il en faut.</p>
<table>
<thead><tr><th>Difficulté</th><th class="n">Succès requis</th><th>Exemple</th><th>Chances avec 3 dés</th></tr></thead>
<tbody>
<tr><td class="nom">Facile</td><td class="n">1</td><td>forcer une porte branlante</td><td>≈ 88 %</td></tr>
<tr><td class="nom">Moyenne</td><td class="n">2</td><td>crocheter une serrure, convaincre un garde hésitant</td><td>50 %</td></tr>
<tr><td class="nom">Difficile</td><td class="n">3</td><td>désamorcer un mécanisme complexe, lire une rune ancienne</td><td>≈ 13 %</td></tr>
<tr><td class="nom">Très difficile</td><td class="n">4 et plus</td><td>exploit héroïque</td><td>impossible à 3 dés</td></tr>
</tbody></table>
<p>La réussite est <strong>mixte</strong> : selon le contexte, un quasi-échec peut donner un
« succès à coût » plutôt qu'un échec sec. Et les attributs <strong>n'ont aucun plafond</strong>
— c'est la difficulté qui monte avec le groupe.</p>
''')
ecrire('''
<h3>Chaque lancer s'affiche sur la table</h3>
<p>Attaque, défense, jet d'attribut, piège, fouille : dès qu'une action est résolue, l'écran du
narrateur la montre en <strong>scène illustrée</strong> — les portraits, les dés réellement
tombés, et surtout <strong>ce que le résultat rapporte</strong>. La carte reste visible autour.
La scène se ferme d'un <strong>clic sur l'écran</strong>, ou d'elle-même après un délai réglable
dans les paramètres du narrateur (<strong>5 secondes</strong> par défaut). Elle raconte ce que le
moteur a décidé : l'écran ne calcule rien.</p>
''')
ecrire(fig('70-scene-attaque',
           "Grom frappe : deux crânes contre un bouclier noir, un dégât — et Crevassier des "
           "Glaces, qui n'a qu'un point de Body comme tout monstre de base, est terrassé. Les "
           "dés qui comptent sont cerclés de vert."))
ecrire(fig('71-scene-jet',
           "Un jet d'attribut dit toujours ce qu'il rapporte : deux succès sur les deux requis, la "
           "table vole en morceaux et laisse 45 pièces d'or au groupe.", 'fig scene'))

EPR = CAT['epreuves']
ecrire('<h3>Les épreuves du donjon</h3>'
       '<p>Ce sont des jets <em>posés sur la carte</em> : un élément de décor que l\'on peut '
       'tenter, une fois, avec le héros de son choix. Le menu n\'en propose jamais un que le '
       'moteur refuserait — si l\'option est là, la tentative est légale.</p>'
       '<table><thead><tr><th class="vig"></th><th>Épreuve</th><th class="n">Jet</th>'
       '<th class="n">Diff.</th><th>Ce qu\'elle rapporte</th></tr></thead><tbody>')
GAIN_EPR = {
    'or': lambda v: f"{v} pièces d'or pour le groupe",
    'parchemin': lambda v: "un parchemin",
    'desarme_pieges_salle': lambda v: "tous les pièges de la salle sont désarmés",
    'retire_condition': lambda v: "retire une condition au héros",
    'soin_groupe': lambda v: f"rend {v} PV de Body à tout le groupe",
    'objet': lambda v: "une pièce d'équipement",
}
for x in sorted(EPR, key=lambda z: (z['attribut'], z['difficulte'])):
    ef = j(x['effet'])
    gain = GAIN_EPR.get(ef.get('mecanique'), lambda v: '—')(ef.get('valeur'))
    src = IMG['epreuves'].get(x['id'])
    ecrire(f'<tr><td class="vig">{"<img src=\"" + src + "\" alt=\"\">" if src else ""}</td>'
           f'<td class="nom">{e(x["nom"])}</td><td class="n">{x["attribut"].capitalize()}</td>'
           f'<td class="n">{x["difficulte"]}</td><td>{gain}</td></tr>')
ecrire('</tbody></table>')
fin()

# ================================================== 5. LE TOUR ============
chapitre(5, 'Le tour de jeu',
         "Un déplacement, une action — dans l'ordre que l'on veut.")
ecrire('''
<h3>L'ordre est figé pour toute la quête</h3>
<p>L'initiative est fixée <strong>par personnage</strong> au démarrage de la quête et ne bouge
plus. Un joueur qui contrôle deux héros les joue chacun à sa place. Quand tous les héros ont
joué, vient la <strong>phase des monstres</strong> : le moteur les active, l'IA raconte ce
qu'ils font.</p>

<h3>Se déplacer</h3>
<p>Le total du tour vaut <strong>la base du héros + 1d6</strong>, en cases
<strong>orthogonales</strong> — jamais en diagonale. On ne traverse pas une figurine, ni un
meuble. Le dé est lancé une fois par tour et le reste du déplacement peut être fractionné
autour de l'action.</p>

<h3>L'Armure de plates fait perdre le dé</h3>
<p>La carte officielle 2021 est nette : « +2 dés de défense, mais <strong>1 seul dé rouge de
mouvement</strong> ». Chez nous — une base de classe et un seul d6, jamais deux dés — retirer
LE dé retire tout le hasard du tour : le héros en Armure de plates n'avance que de sa
<strong>base</strong>, point. Coûteux — 3,5 cases en moyenne — et voulu : c'est ce que valent
850 pièces d'or et +2 dés de défense.</p>
<p>Le dé est <strong>quand même lancé</strong>, puis barré d'un ✕ : la manette et la table le
montrent tomber avant de le rayer, avec le nom de la pièce qui l'annule. Cacher le lancer
aurait caché la perte elle-même — le joueur doit voir ce qu'il aurait eu.</p>
<p>Deux exemptions, et seulement deux : le <strong>Chevalier</strong> (« les armures ne le
ralentissent pas ») et une plate forgée <strong>Allégée</strong> à la Forge du Nain, sur son
propre exemplaire — voir <a href="#ch7">chapitre 7</a>. Le reste du groupe subit la perte du
dé comme n'importe quel porteur de plates.</p>
''')
ecrire('<div class="duo">' +
       fig('35-manette-deplacement-plates',
           "Grom en Armure de plates : le dé tombe (2), mais un ✕ le barre — « le dé ne "
           "compte pas ». Il n'avance que de sa base, 4 cases.", 'fig tel') +
       fig('36-manette-deplacement-allegee',
           "Borin porte la même armure, forgée Allégée : le dé compte normalement — 3 + dé 2 "
           "= 5 cases. Même pièce, même case d'armure ; seule la Forge change la règle.", 'fig tel') +
       '</div>')
ecrire(fig('77-scene-deplacement',
           "La table le montre aussi, au moment où le tour commence : le dé rouge, rayé, et la "
           "pièce responsable nommée en toutes lettres — jamais un chiffre qui change de sens "
           "sans un mot pour le dire.", 'fig scene'))
ecrire('<div class="duo">' +
       fig('31-manette-deplacement',
           "Un tap ne part plus tout droit : le serveur renvoie le <strong>trajet exact</strong> "
           "— ici 11 cases, budget épuisé au bout — et le peint en orange ; seul un second tap, "
           "ou le bouton <em>Y aller</em>, l'engage. La manette ne recalcule rien, elle affiche "
           "une décision déjà prise.", 'fig tel') +
       fig('30-manette-action',
           "Le menu du tour, composé par le moteur — jamais par l'IA — et qui ne s'allonge pas : "
           "depuis le 2026-09-18, <strong>Ranger</strong> reste <strong>une seule option</strong> "
           "quel que soit le nombre de pièces concernées (même règle pour <strong>Équiper</strong> "
           "dès que le sac en porte une), la liste vivant dans le sous-choix plutôt que dans le "
           "menu.", 'fig tel') +
       '</div>')
ecrire('''
<h3>Une action, et une seule</h3>
<p>Attaquer, lancer un sort, lire un parchemin, boire une potion, fouiller, désamorcer,
actionner un levier, tenter une épreuve, équiper ou ranger une pièce : tout cela consomme
<strong>l'action du tour</strong>. Une seule attaque par tour, sauf capacité explicite.</p>
<p>Quand une action recouvre plusieurs objets — neuf sorts, trois parchemins, deux potions,
ou même une seule pièce d'équipement — le menu ne s'allonge pas : <strong>une seule entrée
porte la liste</strong>, et l'on choisit ensuite. C'est la même règle partout, et c'est ce qui
garde un menu lisible sur un téléphone : le doc 13 §3.1 borne à « 2 à 5 options claires », et un
sac ordinaire — une arme à une main compte double, une pour la main droite, une pour la
gauche — produisait <strong>neuf boutons</strong> rien que pour s'équiper. Relevé en jeu le
2026-09-18 : dix options au menu pour une seule pièce au sac.</p>

<h3>Les gestes qui ne coûtent rien</h3>
<p>Une poignée de gestes n'entament <strong>aucun créneau</strong> du tour, ni le mouvement ni
l'action : ouvrir une porte, jeter un objet, activer un style élémentaire, proposer la retraite
au groupe, proposer de quitter le donjon, utiliser un objet dont la carte le dit elle-même
(chausse-trappes, bombe fumigène). Répétables, ils restent au menu même après avoir
agi — les économiser n'aurait aucun sens. Le téléphone <strong>lit</strong> ce que le serveur
publie (le champ <code>creneau</code>, jamais recalculé côté client) et marque ces options d'un
<strong>∞</strong> à la suite du libellé : un geste gratuit qui ne se voit pas comme tel se
garde comme s'il coûtait.</p>
''')
ecrire('<div class="duo">' +
       fig('14-manette-sorts',
           "Le sous-choix d'un sort. « Sommeil » est grisé : déjà lancé, il ne redeviendra "
           "disponible qu'à la prochaine quête.", 'fig tel') +
       fig('32-manette-combat',
           "Hors de son tour, Grom suit le fil du combat : le Tireur d'Au-delà touche Borin, "
           "qui rend le coup à l'Ossement Scellé. Le détail des dés reste complet, coup par "
           "coup, jamais résumé.", 'fig tel') +
       '</div>')
ecrire('''
<div class="encadre">
  <h4>Ce que le serveur décide, le téléphone ne le recalcule pas</h4>
  <p>Portée, ligne de vue, cases franchissables, attaque supplémentaire disponible, embrasure
  de porte : tout arrive <strong>déjà décidé</strong> dans la réponse du serveur. La manette
  affiche, elle n'arbitre pas — c'est la seule façon qu'une règle changée un jour ne laisse pas
  derrière elle un téléphone qui ment.</p>
</div>''')
fin()

# ================================================= 6. LE COMBAT ===========
chapitre(6, 'Le combat',
         "Des crânes contre des boucliers, et une figurine qui tombe sans mourir tout de suite.")
ecrire('''
<h3>Résoudre une attaque</h3>
<ol>
  <li>La cible doit être <strong>adjacente</strong> au corps à corps (certaines armes frappent
      aussi en diagonale), ou en <strong>ligne de vue dégagée</strong> à distance.</li>
  <li>L'attaquant lance ses <strong>dés d'attaque</strong> et compte les <strong>crânes</strong>.</li>
  <li>Le défenseur lance ses <strong>dés de défense</strong> : le héros compte les boucliers
      <strong>blancs</strong>, le monstre les boucliers <strong>noirs</strong>.</li>
  <li><strong>Dégâts = crânes − boucliers</strong>, jamais moins de zéro. Chaque point retire
      un point de Body.</li>
</ol>

<h3>Tomber n'est pas mourir</h3>
<p>À <strong>0 point de Body</strong>, la figurine est <strong>tombée</strong> : elle occupe
toujours sa case et reste <strong>relevable</strong> par un soin ou un allié. Elle ne meurt
définitivement que si personne ne la relève avant la fin du combat.</p>
''')
ecrire('<div class="duo">' +
       fig('72-scene-attaque-monstre',
           "Briseur de Sceaux frappe Grom : trois crânes, un seul bouclier blanc. Les deux "
           "dégâts l'amènent à zéro.", 'fig scene') +
       fig('73-scene-chute',
           "La scène suivante le dit : à terre, mais relevable jusqu'à la fin du combat. La chute "
           "s'affiche toujours <em>après</em> le coup qui l'a causée.", 'fig scene') +
       '</div>')
ecrire('''

<div class="encadre avert">
  <h4>Quand tout le groupe tombe</h4>
  <p>Si tous les héros sont à terre sans relève possible, le groupe tranche par
  <strong>vote</strong> : <strong>recharger</strong> la dernière sauvegarde et rejouer, ou
  <strong>abandonner</strong> la campagne — l'or d'avant la mission est alors réparti et les
  personnages rentrent au roster avec un résumé d'échec. <strong>En cas d'égalité, on
  recharge</strong>, l'option la moins destructrice.</p>
  <p>Et l'on peut toujours <strong>battre en retraite</strong> : cette option n'a
  <em>aucune</em> condition, précisément pour rester disponible au pire moment. Elle ouvre un
  vote à trois issues — continuer, recommencer la quête, ou arrêter là.</p>
</div>

<h3>Quelques règles qui surprennent</h3>
<ul>
  <li><strong>Aucune attaque d'opportunité.</strong> On se désengage librement, comme au plateau.</li>
  <li><strong>Les monstres sont scriptés</strong> — ils visent le plus proche ou le plus faible.
      Le moteur les joue, l'IA les raconte. Elle ne choisit jamais leur cible.</li>
  <li><strong>L'Armure de plates fait perdre le dé</strong> de mouvement, pas deux cases — le d6
      est toujours lancé, et rayé (voir <a href="#ch5">chapitre 5</a>).</li>
  <li><strong>Le tir ami existe.</strong> Un sort de zone ou une flèche mal placée peut toucher
      un allié : le placement avant de lancer est un vrai choix.</li>
  <li><strong>Une arme de jet est perdue.</strong> Dague ou hachette lancée reste où elle
      tombe — et un héros qui jette sa seule arme se retrouve à mains nues sur-le-champ.</li>
</ul>
''')
ecrire(fig('22-table-donjon',
           "Une galerie déjà bien entamée, cinq monstres révélés. À gauche, le fil des "
           "événements donne chaque jet ; en haut, la barre d'initiative mêle héros et monstres, "
           "sous les deux thèmes de la campagne ; à droite, l'état du groupe — Aldric est tombé "
           "à 0 point de Body (« il glisse au sol au milieu du fracas », dit le maître de jeu), "
           "encore relevable jusqu'à la fin du combat."))
fin()

# ============================================= 7. L'ÉQUIPEMENT ============
def avantages(objet):
    """Ce que fait l'objet, tel que le SERVEUR le dit — le même texte que le sac de la
    manette. Aucune traduction ici : voir `avantages_objets` dans docs/livret/catalogue.sh."""
    return ' · '.join(CAT['avantages_objets'].get(str(objet['id'])) or []) or '—'

OBJ = CAT['objets']
def par(cat, raretes=None, tri=None):
    r = [o for o in OBJ if o['categorie'] == cat and (raretes is None or o['rarete'] in raretes)]
    return sorted(r, key=tri or (lambda o: (o['prix_base'] or 0)))

def table_objets(liste, titre=None, colonne_prix=True):
    if titre:
        ecrire(f'<h4>{titre}</h4>')
    ecrire('<table><thead><tr><th class="vig"></th><th>Objet</th>'
           + ('<th class="n">Prix</th>' if colonne_prix else '')
           + '<th>Ce qu\'il fait</th></tr></thead><tbody>')
    for o in liste:
        src = IMG['objets'].get(o['id'])
        vig = f'<img src="{src}" alt="">' if src else ''
        prix = f'<td class="n">{o["prix_base"]}</td>' if colonne_prix else ''
        ecrire(f'<tr><td class="vig">{vig}</td><td class="nom">{e(o["nom"])}</td>{prix}'
               f'<td>{e(avantages(o))}</td></tr>')
    ecrire('</tbody></table>')

chapitre(7, "L'équipement",
         "Cinq emplacements, deux mains, un sac — et des cartes qui disent qui a le droit "
         "de porter quoi.")
ecrire('''
<h3>Cinq emplacements équipés</h3>
<table><thead><tr><th>Emplacement</th><th>Ce qu'il reçoit</th></tr></thead><tbody>
<tr><td class="nom">Arme principale</td><td>une arme — la main droite</td></tr>
<tr><td class="nom">Arme secondaire</td><td>le bouclier <strong>ou</strong> une seconde arme à une main</td></tr>
<tr><td class="nom">Casque</td><td>le casque, qui a son propre emplacement</td></tr>
<tr><td class="nom">Armure</td><td>cotte, plates, brassards, cape</td></tr>
<tr><td class="nom">Talisman</td><td>les bijoux d'artefact — ils relèvent les PV maximum</td></tr>
</tbody></table>

<h3>Les deux mains : quatre tenues, pas une de plus</h3>
<ol>
  <li>deux armes à <strong>une</strong> main ;</li>
  <li>une arme à <strong>deux</strong> mains, seule ;</li>
  <li>une arme à une main <strong>+ un bouclier</strong> ;</li>
  <li>une arme à une main, seule (ou rien du tout).</li>
</ol>
<p><strong>La seconde arme n'ajoute aucun dé : elle ajoute un choix.</strong> Le menu d'action
offre une attaque <em>par arme</em>, chacune avec ses propres cibles légales — puisque portée,
diagonales et jet sont des propriétés de l'arme. C'est ainsi qu'on porte une arme de mêlée et
une arme de jet sans rien reprendre au sac en plein combat.</p>
''')
ecrire('<div class="duo">' +
       fig('33-manette-attaque-simple',
           "Une seule arme en main : toucher <strong>Attaquer</strong> mène directement à la "
           "feuille de cibles. Aucun choix d'arme à faire, alors aucun n'est proposé — la "
           "profondeur suit la donnée.", 'fig tel') +
       fig('34-manette-attaque-deux-armes',
           "Deux armes en main, de portées différentes : <strong>Attaquer</strong> ouvre "
           "d'abord « Avec quelle arme ? ». La Rapière et l'Épée courte portent toutes deux "
           "2 dés — ce n'est donc pas un dé de plus qui pose la question, c'est que la Rapière "
           "frappe aussi en diagonale là où l'Épée courte se limite aux quatre cases "
           "orthogonales : les deux entrées n'ont pas les mêmes cibles légales.", 'fig tel') +
       '</div>')
ecrire('<div class="duo">' +
       fig('13-manette-sac',
           "L'onglet Sac : Grom porte son Épée large et son Armure de plates, sac à dos "
           "vide — 0/4. Chaque ligne équipée ouvre par le ⓘ sa feuille de détail complète, "
           "amélioration de Forge comprise (chapitre 7).", 'fig tel') +
       fig('12-manette-fiche',
           "La fiche du héros : attributs de jet, points de vie, conditions actives et talents "
           "acquis.", 'fig tel') +
       '</div>')
ecrire('''
<div class="encadre">
  <h4>Deux règles à ne pas confondre</h4>
  <p>Les <strong>dés d'attaque de l'arme remplacent</strong> la valeur du porteur — l'arme fait
  l'attaque. Les <strong>dés de défense de l'armure s'ajoutent</strong> : casque, armure et
  bouclier se cumulent, jusqu'à six dés pour un héros complet.</p>
  <p>Chaque pièce porte une <strong>maîtrise</strong>, et chaque classe en autorise un ensemble.
  Le magicien est le seul vraiment bridé, comme au plateau. Au marché, une pièce non maîtrisée
  reste <strong>achetable</strong> — la bourse est commune et le don entre héros existe : on
  achète pour un coéquipier.</p>
</div>
''')
table_objets(par('arme', ('commun', 'peu_commun', 'rare')), 'Les armes du commerce')
table_objets(par('armure', ('commun', 'peu_commun', 'rare')), 'Armures et protections')
table_objets(par('outil'), 'Outils et matériel')
ecrire('<h4>Potions et consommables</h4>'
       '<p>Ils ne consomment jamais la capacité du sac, se boivent depuis « Utiliser un objet » '
       'dans les actions, et peuvent viser un <strong>héros adjacent</strong> aussi bien que '
       'soi-même.</p>')
table_objets(par('consommable', ('commun', 'peu_commun', 'rare')))

ecrire('''
<h3>La Forge du Nain</h3>
<p>Un Nain qui a acquis le nœud <strong>Forge</strong> améliore <strong>définitivement</strong>
une pièce d'équipement — arme ou armure, jamais un artefact — contre de l'or de la bourse
commune, <strong>au hub uniquement</strong>. L'amélioration se choisit dans la feuille de
détail de l'objet, ouverte depuis le Sac : le catalogue qui s'y affiche est la liste exacte
que le serveur acceptera, jamais une promesse que le clic suivant refuserait.</p>
<p>Les <strong>six</strong> améliorations du catalogue fonctionnent : <strong>Affûtée</strong>
(+1 dé d'attaque), <strong>Renforcée</strong> (+1 dé de défense), <strong>Perforante</strong>
(annule un bouclier de la cible), <strong>Cruelle</strong> (relance un dé d'attaque raté),
<strong>Allégée</strong> (annule la perte du dé de mouvement d'une armure lourde — voir
<a href="#ch5">chapitre 5</a>) et <strong>Gardée</strong> (ignore le premier Étourdi ou Apeuré
du combat). <strong>« Une fois par combat »</strong> (Cruelle, Gardée) veut dire : tant qu'un
monstre reste en vue, comme les styles élémentaires du Moine — le compteur se réarme dès que
plus aucun monstre n'est visible.</p>
<p>Une pièce forgée porte sa marque <strong>pour tout le monde</strong> : la feuille de détail
affiche l'amélioration posée même à un joueur sans Nain dans son groupe — un fait sur l'objet,
pas un secret du forgeron. Pour forger l'équipement d'un <strong>compagnon</strong>, on le lui
échange d'abord (le Nain ne voit que son propre sac) ; une fois forgée, la pièce peut repartir
vers son propriétaire sans perdre son amélioration.</p>
''')
ecrire('<div class="duo">' +
       fig('17-manette-forge',
           "La feuille de détail d'une Épée large non forgée : trois améliorations s'appliquent "
           "à une arme, chacune avec son prix. Un bouton par option, jamais un choix que le "
           "serveur refuserait ensuite.", 'fig tel') +
       fig('18-manette-forge-visible',
           "La même épée, forgée Affûtée puis rendue à son propriétaire (un barbare, pas un "
           "Nain) : l'amélioration reste affichée, sans aucun bouton pour la refaire — visible "
           "de tous, modifiable par un seul.", 'fig tel') +
       '</div>')
ecrire(fig('06-guide-equipement',
           "Le guide intégré liste l'armurerie complète, avec pour chaque pièce la classe qui "
           "peut la porter."))
fin()

# ================================================== 8. LA MAGIE ===========
# Texte des douze sorts élémentaires : reference/02_sorts.md §7, aligné sur les
# cartes officielles (doc 16 §3bis). Écrit à la main parce que la formulation
# EST la règle — le champ `effet` en base n'en porte que les nombres.
TEXTE_SORT = {
 'Boule de Feu': "2 dégâts <strong>fixes</strong>, sans dés d'attaque : la cible lance 2 d6 bruts et chaque 5 ou 6 en annule un. La parade ordinaire ne s'applique pas.",
 'Courage': "+2 dés d'attaque à un héros (le lanceur compris) pour sa prochaine attaque. Le sort <strong>se rompt dès qu'aucun monstre n'est plus en vue</strong> — impossible de le mettre en réserve.",
 'Trait de Feu': "1 dégât <strong>fixe</strong>, annulé net si la cible sort un 5 ou un 6 sur un seul d6.",
 'Sommeil': "Le sort prend toujours, sans jet au lancer. Le monstre tente de se rompre sur-le-champ puis à chacun de ses tours, en lançant 1 d6 par point de Mind : un seul 6 le réveille. Tant qu'il dort, <strong>il ne se défend plus</strong>.",
 'Voile de Brume': "Un héros <strong>traverse les cases occupées par les monstres</strong> pendant son prochain déplacement. Ce n'est pas de l'invisibilité : il passe au travers, il ne s'y arrête pas.",
 'Eau de Guérison': "Rend jusqu'à 4 points de Body à un héros.",
 'Soin du Corps': "Rend jusqu'à 4 points de Body — lançable sur soi.",
 'Traverser la Pierre': "La cible <strong>traverse la roche et les portes closes pendant tout son déplacement</strong> : plusieurs murs, et des salles qu'on révèle sans ouvrir la porte. ⚠ <strong>Terminer son mouvement dans la roche fait tomber le héros.</strong>",
 'Peau de Pierre': "+1 dé de défense <strong>jusqu'au premier dégât subi</strong> — parer sans rien encaisser ne le consomme pas.",
 'Génie': "Deux modes au choix : une <strong>attaque à 5 dés</strong> à distance, <strong>ou</strong> ouvrir une porte de son choix — sans adjacence ni clé, ce qui dégage un passage tenu par des figurines.",
 'Vent Véloce': "<strong>Double le déplacement</strong> d'un héros pour ce tour (base + 1d6, ×2).",
 'Tempête': "Un monstre choisi <strong>passe son prochain tour</strong> — ni déplacement ni attaque, et <strong>sans aucun jet de résistance</strong>. C'est le sort qui ralentit un boss.",
}
ELEMENTS = [('feu', 'Feu', 'offensif'), ('eau', 'Eau', 'contrôle et soin'),
            ('terre', 'Terre', 'défense et soin'), ('air', 'Air', 'mobilité et puissance')]
SORTS = CAT['sorts']
def sorts_de(el):
    return [s for s in SORTS if s['element'] == el]

chapitre(8, 'La magie',
         "Quatre éléments, douze sorts, une fois chacun par quête — et des parchemins pour "
         "ceux qui ne lancent rien.")
ecrire('''
<h3>Qui lance quoi</h3>
<table><thead><tr><th>Héros</th><th>Accès à la magie</th></tr></thead><tbody>
<tr><td class="nom">Magicien</td><td>Lanceur complet. Démarre avec <strong>3 éléments au choix</strong>, soit neuf sorts. Le quatrième s'ouvre par un talent.</td></tr>
<tr><td class="nom">Elfe</td><td>Au choix à la création : <strong>1 élément</strong> (ses trois sorts) <em>ou</em> <strong>3 sorts du répertoire elfique</strong>. Le choix est exclusif.</td></tr>
<tr><td class="nom">Barde · Druide · Warlock</td><td>Un répertoire propre de trois sorts, tirés de leurs cartes de classe.</td></tr>
<tr><td class="nom">Barbare · Nain · les autres</td><td>Aucun sort connu — mais tous peuvent lire un <strong>parchemin</strong>.</td></tr>
</tbody></table>

<div class="encadre">
  <h4>Une fois par quête, et pas de repos</h4>
  <p>Chaque sort connu est lançable <strong>une fois par quête</strong>, puis épuisé. Tout
  redevient disponible <strong>entre deux quêtes</strong>. Il n'existe aucun repos en cours de
  quête : la seule exception est le talent <em>Concentration</em> du magicien, qui sacrifie son
  tour pour récupérer un sort — une fois par quête.</p>
  <p>Les sorts de dégâts se résolvent <strong>sans jet de toucher</strong>. Les sorts mentaux
  sont <strong>binaires</strong> : la cible résiste, ou subit. Et les créatures à
  <strong>Mind 0</strong> — squelette, zombie, momie — y sont totalement insensibles.</p>
</div>
''')
for cle, nom, couleur in ELEMENTS:
    liste = sorts_de(cle)
    ecrire(f'<h4>{nom} — {couleur}</h4>'
           '<table><thead><tr><th class="vig"></th><th>Sort</th><th class="n">Parchemin</th>'
           '<th>Effet</th></tr></thead><tbody>')
    for s in liste:
        src = IMG['sorts'].get(s['id'])
        vig = f'<img src="{src}" alt="">' if src else ''
        txt = TEXTE_SORT.get(s['nom'], '—')
        ecrire(f'<tr><td class="vig">{vig}</td><td class="nom">{e(s["nom"])}</td>'
               f'<td class="n">{s["difficulte_parchemin"]}</td><td>{txt}</td></tr>')
    ecrire('</tbody></table>')

ecrire('''
<h3>Les parchemins</h3>
<p>Un parchemin donne accès à un sort <strong>même hors de son répertoire</strong>, et il est
consommé à l'activation — que celle-ci réussisse ou non. Un <strong>lanceur</strong> (magicien,
elfe) le lit automatiquement ; un <strong>non-lanceur</strong> doit réussir un
<strong>jet de Mind</strong> dont la difficulté dépend du sort : c'est la colonne
« Parchemin » des tableaux ci-dessus — 1 pour les mineurs, 3 pour les plus puissants. Un échec
gaspille le parchemin.</p>
<p>C'est ce qui donne un goût de magie au barbare, et une vraie raison de monter son Mind.
Les parchemins ne se fabriquent jamais : on les trouve, ou on les achète.</p>
''')

ecrire('<h3>Les répertoires de classe</h3>'
       '<p>Ils ne se mélangent avec aucun élément. Le répertoire <strong>elfique</strong> est '
       'le seul dont le choix se <strong>rejoue au hub</strong>, entre deux quêtes : on emporte '
       'ce que la prochaine quête semble demander.</p>')
LIB_REP = {'elfique': 'Répertoire elfique — au choix, 3 sur 6',
           'barde': 'Barde', 'druide': 'Druide', 'warlock': 'Warlock'}
for cle in ('elfique', 'barde', 'druide', 'warlock'):
    liste = sorts_de(cle)
    if not liste:
        continue
    ecrire(f'<h4>{LIB_REP[cle]}</h4><div class="vignettes">')
    for s in liste:
        src = IMG['sorts'].get(s['id'])
        ecrire(f'<div class="vg">{"<img src=\"" + src + "\" alt=\"\">" if src else ""}'
               f'<span>{e(s["nom"])}</span></div>')
    ecrire('</div>')
ecrire(fig('07-guide-sorts', "Le guide intégré donne le détail chiffré de chaque sort : dés, "
                             "soin, durée, condition appliquée."))
fin()

# ============================================ 9. LE MARCHÉ ET L'OR ========
chapitre(9, "Le marché et l'or",
         "Une bourse commune, un étal qui ouvre entre deux quêtes, et un panier par joueur.")
ecrire('''
<h3>L'or appartient au groupe</h3>
<p>Il n'y a <strong>pas de solde par personnage</strong> pendant une campagne : une seule
bourse commune, alimentée par le butin, les quêtes et la revente. En arrivant dans un groupe,
un personnage <strong>verse son or personnel au pot</strong> ; en partant, il repart avec sa
part égale. À la clôture de la campagne, le pot est réparti vers les bourses personnelles du
roster.</p>

<h3>La phase de marché</h3>
<p>Elle s'ouvre <strong>au hub, entre deux quêtes</strong>, et elle est
<strong>atomique</strong> : chaque joueur compose son panier, et rien n'est débité tant que
<strong>tous</strong> n'ont pas confirmé. Le total projeté est affiché en permanence sur
l'écran de table, ce qui évite de découvrir à la validation qu'on est deux à convoiter la
même armure.</p>
<p>La revente se fait à <strong>50 % du prix marchand</strong>. Les objets
<strong>uniques</strong> — les artefacts — ne s'achètent, ne se revendent et ne se forgent
jamais.</p>
<p>Avant que quiconque ouvre l'étal, le même onglet propose déjà de <strong>recruter un
allié</strong> : un mercenaire scripté, payé sur la bourse commune et présent pour la durée
d'une quête — un renfort de chair pour un groupe réduit, jamais un remplaçant permanent.</p>
''')
ecrire('<div class="duo">' +
       fig('11-manette-marche',
           "Avant l'ouverture de l'étal : la case « Prêt pour la quête », et déjà deux "
           "mercenaires à recruter contre l'or commun — l'Éclaireur désamorce comme un nain, "
           "l'Arbalétrier frappe à distance puis à l'épée au contact.", 'fig tel') +
       fig('20-table-hub',
           "Le hub vu de la table, d'où l'on ouvre le marché pour tout le groupe : le bouton "
           "est à côté de « Lancer la quête » et « Clôturer », jamais cachée derrière un menu.") +
       '</div>')
ecrire('''
<div class="encadre avert">
  <h4>Un seul marchand, tout au prix normal</h4>
  <p>Le jeu décrit quatre profils de lieu — village isolé, bourg, cité marchande, marché noir —
  avec leurs multiplicateurs. <strong>Ils sont volontairement neutralisés</strong> : pour
  l'instant l'étal est unique et vend tout au prix de base, toutes raretés confondues. Les
  quatre profils restent déclarés parce qu'ils seront la matière du
  <strong>marchandage</strong>, qui n'existe pas encore.</p>
</div>

<div class="encadre">
  <h4>Donner plutôt que vendre</h4>
  <p>Au hub, un joueur peut <strong>donner</strong> un objet depuis ses héros vers n'importe
  quel héros actif du groupe, y compris celui d'un autre joueur — vous êtes autour de la même
  table, il n'y a rien à confirmer. La capacité du sac du <strong>receveur</strong> est
  vérifiée ; celle du donneur peut être en dépassement, se délester étant justement la façon de
  régulariser un sac saturé par le butin. <strong>Un artefact appartient au groupe</strong>,
  pas à celui qui l'a trouvé : il circule.</p>
</div>

<div class="encadre">
  <h4>En pleine quête : équiper, échanger, jeter</h4>
  <p>Les trois gestes d'inventaire existent aussi <strong>dans le donjon</strong>, et deux
  d'entre eux coûtent <strong>l'action du tour</strong> — vous ne réorganisez pas votre
  barda en frappant.</p>
  <ul>
    <li><strong>Équiper / ranger</strong> une pièce : une action. Une arme à une main se
    place à droite ou à gauche, et le menu dit ce qu'elle remplace.</li>
    <li><strong>Échanger avec un allié adjacent</strong> : une action pour la
    <strong>séance entière</strong>. Les deux sacs s'ouvrent côte à côte, les pièces
    circulent <strong>dans les deux sens</strong> et autant que vous voulez, puis une
    seule validation. Deux sacs pleins peuvent donc <strong>troquer</strong> : c'est
    l'état final qui compte, pas chaque pièce prise à part. Comme au hub, rien à
    confirmer — vous êtes autour de la même table.</li>
    <li><strong>Jeter</strong> un objet : <strong>gratuit</strong>, et autant de fois que
    vous le voulez dans le tour, y compris après avoir frappé.</li>
  </ul>
  <p>⚠ Un objet jeté est <strong>détruit</strong>, il ne reste pas au sol : le téléphone
  vous le fait confirmer, et vous demande combien d'exemplaires quand il y en a
  plusieurs.</p>
</div>
''')
ecrire('<div class="duo">' +
       fig('15-manette-echange',
           "La séance d'échange entre Borin et Aldric : les deux sacs côte à côte, une pièce "
           "<strong>encombrante</strong> partant dans chaque sens — le Bouclier de Borin vers "
           "Aldric, sa Dague en retour — sous une seule validation. Les capacités affichées en "
           "tête (2/4, 2/2) se recalculent à chaque curseur bougé, avant tout envoi au serveur.",
           'fig tel') +
       fig('16-manette-jeter-quantite',
           "« Jeter » sur une pile de plusieurs exemplaires ouvre d'abord un <strong>palier de "
           "quantité</strong> : le nombre qui part est celui qu'on choisit ici — deux fioles sur "
           "les trois portées — jamais toute la pile par défaut.", 'fig tel') +
       '</div>')
ecrire('''
<div class="encadre avert">
  <h4>Pourquoi jeter ne coûte rien</h4>
  <p>Le canon range les trois gestes sous « gérer son stuff coûte l'action du tour ».
  <strong>Nous en avons sorti « jeter »</strong>, et c'est un écart délibéré. Le même
  paragraphe crée la tension qui l'exige : sac plein devant un coffre, il faut se délester
  pour ramasser. Au prix d'une action par pièce, ouvrir un coffre encombré coûtait le tour
  entier — la tension devenait une punition. Les deux autres gestes, eux, restent au prix
  fort.</p>
</div>''')
fin()

# ================================================ 10. LE DONJON ===========
chapitre(10, 'Le donjon',
         "Des salles reliées par des couloirs, du brouillard qui recule, et des portes qui "
         "ne s'ouvrent pas toutes de la même façon.")
ecrire('''
<h3>Ce que l'on voit, et ce que l'on ne voit pas</h3>
<p>La carte est couverte d'un <strong>brouillard</strong> qui ne se lève que sur ce que le
groupe a réellement découvert. <strong>Ouvrir une porte révèle la salle entière et ses
monstres</strong>, comme au plateau — et c'est vrai de tous les chemins : la porte poussée à la
main, celle qu'un levier débloque, celle qu'un gardien vaincu libère.</p>
<p>Un passage secret non découvert est peint comme de la <strong>roche ordinaire</strong> :
rien ne le distingue d'un mur tant qu'on ne l'a pas cherché.</p>
''')
ecrire('<h4>Les portes</h4><div class="vignettes" style="grid-template-columns:repeat(4,1fr)">')
for f, lib in (('fermee', 'Fermée — à ouvrir'), ('ouverte', 'Ouverte'),
               ('verrouillee', 'Verrouillée — clé ou levier'), ('secrete', 'Secrète — à trouver')):
    p = f'docs/livret/img/catalogue/portes/{f}.webp'
    if os.path.exists(os.path.join(RACINE, p)):
        ecrire(f'<div class="vg"><img src="img/catalogue/portes/{f}.webp" alt=""><span>{lib}</span></div>')
ecrire('</div>')
ecrire('''
<p>Une porte <strong>occupe sa propre case</strong> : on s'y tient, on la franchit, elle n'est
pas une simple arête entre deux salles. Un <strong>levier</strong> posé quelque part dans le
donjon commande certaines portes verrouillées — mais rien n'indique lequel ouvre laquelle :
il faut l'actionner pour le savoir, et on peut réessayer sans limite.</p>

<h3>Le mobilier</h3>
<p>Il <strong>bloque le mouvement</strong> — toujours — et parfois la vue. La plupart des
meubles se <strong>fouillent</strong>, avec leur propre table de butin : c'est le seul endroit
du jeu qui rend une pièce d'équipement, là où les coffres ne rendent que de l'or, des potions
et des artefacts.</p>
''')
MOB = CAT['mobiliers']
ecrire('<table><thead><tr><th class="vig"></th><th>Meuble</th><th class="n">Taille</th>'
       '<th class="n">Bloque la vue</th><th>Ce qu\'on y trouve</th></tr></thead><tbody>')
BUTIN_MOB = {
    'Table': "rien — c'est un obstacle",
    'Coffre': "or, potion, ou une pièce d'équipement",
    'Trône': "de l'or, ou rien",
    "Établi d'alchimiste": "des consommables — ou une fiole de poison",
    'Tombeau': "or, arme ou armure — et parfois une aiguille empoisonnée",
    'Bibliothèque': "des parchemins, un peu d'or",
    "Râtelier d'armes": "armes et armures",
    'Armoire': "consommables, outils, or",
}
for m in MOB:
    src = IMG['mobiliers'].get(m['id'])
    ecrire(f'<tr><td class="vig">{"<img src=\"" + src + "\" alt=\"\">" if src else ""}</td>'
           f'<td class="nom">{e(m["nom"])}</td><td class="n">{m["largeur"]}×{m["hauteur"]}</td>'
           f'<td class="n">{"oui" if m["bloque_vue"] else "non"}</td>'
           f'<td>{BUTIN_MOB.get(m["nom"], "—")}</td></tr>')
ecrire('</tbody></table>')
ecrire('''
<div class="encadre avert">
  <h4>Connecté ne veut pas dire jouable</h4>
  <p>Le placement du mobilier et des monstres garde toujours un <strong>plancher de cases
  libres</strong> dans chaque salle. Une salle qui reste techniquement « connexe » mais où
  quatre héros ne tiennent pas debout est une salle où le groupe se retrouve coincé sur le
  seuil, incapable d'agir — c'est arrivé, et c'est pour cela que la règle existe.</p>
</div>
''')
TER = CAT['terrains']
EFFET_TER = {
 'Glace glissante': "on glisse : un dé de combat, et sur bouclier blanc le tour s'arrête net",
 'Glissière de glace': "sens unique, le tour s'arrête au bout — et parfois 1 dégât",
 'Rivière gelée': "coûte <strong>2 points</strong> pour y entrer, et peut infliger 1 dégât de froid",
 'Tunnel de glace': "téléporte : la case d'arrivée n'est pas celle qu'on visait",
 'Chambre forte de glace': "1 dégât de froid par tour passé dans la zone",
 'Glace magique': "support des sorts de glace — mur et pont",
 'Rebord de crevasse': "décor, infranchissable",
}
ecrire('<h3>Le terrain</h3>'
       '<p>Certaines boîtes posent un terrain qui change le déplacement lui-même. '
       'Il se compte alors <strong>en points, pas en cases</strong> — une rivière gelée coûte '
       'deux points à traverser. Le serveur calcule, la manette affiche le budget réel.</p>'
       '<table><thead><tr><th class="vig"></th><th>Terrain</th><th class="n">Coût</th>'
       '<th>Effet</th></tr></thead><tbody>')
for t in TER:
    src = IMG['terrains'].get(t['id'])
    ecrire(f'<tr><td class="vig">{"<img src=\"" + src + "\" alt=\"\">" if src else ""}</td>'
           f'<td class="nom">{e(t["nom"])}</td><td class="n">{t["cout_deplacement"]}</td>'
           f'<td>{EFFET_TER.get(t["nom"], "—")}</td></tr>')
ecrire('</tbody></table>')
ecrire(fig('74-scene-salle',
           "Quand une porte s'ouvre sur des créatures, la table les présente avec leurs "
           "caractéristiques. Les noms sont ceux que le maître du jeu leur a donnés ; les chiffres "
           "sont ceux du catalogue, et l'IA n'y touche pas.", 'fig scene'))
ecrire(fig('21-table-quete',
           "L'en-tête d'une quête en cours : le titre du « Gardien des Premiers Sceaux », "
           "l'objectif toujours visible sous les deux thèmes de la campagne, et la barre "
           "d'initiative qui mêle les quatre héros à cinq monstres déjà révélés."))
fin()

# ======================================== 11. FOUILLER LE DONJON ==========
chapitre(11, 'Fouiller, les coffres et les artefacts',
         "Une fouille par héros et par salle — et un paquet de trésors qui tourne sans "
         "jamais s'épuiser.")
ecrire('''
<h3>Le paquet de fouille</h3>
<p>« Fouiller — trésor » tire une carte dans un paquet propre à la quête, composé comme celui
du plateau : <strong>24 cartes</strong> — des gemmes et des bijoux, des pièces d'or, trois
potions de soin, un peu d'héroïsme, de force et de défense, quelques fosses et pièges à
lances, et <strong>six monstres errants</strong>, de loin la carte la plus fréquente.</p>
<p>Chaque carte tirée <strong>repasse sous le paquet</strong> : il tourne au lieu de s'épuiser.
Une fouille par héros <strong>et par salle</strong> — le premier qui cherche ne referme pas la
pièce pour les autres, chacun tire la sienne. Le butin va au fouilleur ; l'or va au pot commun.
Une carte piège <strong>termine le tour</strong>.</p>
''')
ecrire(fig('75-scene-fouille',
           "Ce qu'une fouille trouve arrive avec ce que l'objet fait : une épée large, trois dés "
           "d'attaque, et la frappe en diagonale.", 'fig scene'))
ecrire('''

<h3>Les coffres désignés</h3>
<p>Ils ne consomment aucune carte du paquet. Deux règles les placent :</p>
<ul>
  <li>si la quête a un <strong>boss</strong>, un coffre est posé <strong>dans sa salle</strong> ;</li>
  <li>sinon, c'est la <strong>salle la plus profonde</strong> du donjon qui le porte ;</li>
  <li>et <strong>chaque passage secret mène à un coffre</strong> — celle des deux salles de la
      jonction qui est la plus profonde. Toujours. Un raccourci trouvé paie quelque chose.</li>
</ul>
<p>La salle désignée reçoit un <strong>vrai meuble Coffre</strong>, posé sur la carte comme
n'importe quel autre — jamais une salle que la narration dirait « au coffre » sans qu'aucune
case n'en montre un. Le placement du mobilier lui réserve toujours assez de cases libres pour
qu'il tienne, quelle que soit la forme de la salle tirée.</p>
<p>Le coffre désigné rend <strong>au plus un artefact</strong> par quête. S'il n'en reste aucun
que le groupe puisse porter, il verse une <strong>grosse somme d'or</strong> à la place —
jamais rien.</p>
<p>Un artefact ne sort d'un coffre que <strong>si aucun héros du groupe ne le détient</strong>.
Celui qu'on n'a pas su récupérer peut donc revenir dans une quête suivante, et un artefact à
<strong>usage limité</strong> — l'Arc de Vindication et ses quatre flèches, l'Anneau du Retour —
<strong>se brise</strong> à son dernier usage : le voilà, lui aussi, de nouveau trouvable.</p>

<div class="encadre avert">
  <h4>Le paquet de fouille ne contient aucun artefact</h4>
  <p>On peut fouiller toutes les salles d'un donjon sans jamais en voir un. Les artefacts ne
  viennent <strong>que</strong> des coffres désignés : ne pas trouver le bon coffre, c'est
  finir la quête sans artefact, quoi qu'on ait fouillé par ailleurs. Et dans une quête à boss
  sur deux environ, la salle du boss est elle-même derrière une porte secrète — une porte
  manquée peut donc coûter la quête, pas seulement un bonus.</p>
</div>
''')
ARTEFACTS = [o for o in OBJ if o['rarete'] == 'unique' and o['categorie'] in ('arme', 'armure')]
ecrire('<h3>Ce que les artefacts font</h3>'
       "<p>Un artefact ne monte pas la courbe de puissance : il fait ce que rien d'autre ne "
       "fait. Il appartient au groupe, circule librement entre héros, et ne se vend ni ne se "
       "forge. Quelques-uns des plus marquants :</p>")
CHOIX_ART = ['Fléau des Orques', 'Lame des Esprits', 'Dague de jet magique', 'Armure de Borin',
             'Amulette du Nord', 'Talisman du Savoir', 'Anneau de Sort', 'Baguette de Rappel',
             'Cendres du Phénix', 'Cape des Ombres', 'Bottes elfiques', 'Arc elfique de Vindication']
table_objets([o for n in CHOIX_ART for o in ARTEFACTS if o['nom'] == n], colonne_prix=False)
ecrire('''
<div class="encadre">
  <h4>Deux artefacts n'appartiennent qu'à une boîte</h4>
  <p>Les <strong>Raquettes de Vitesse</strong> et l'<strong>Anneau de Chaleur</strong> ne sortent
  que dans une campagne au thème <strong>« Horreur des Glaces »</strong> — c'est la seule où le
  froid existe comme dégât et où la glace glissante encombre le sol, donc la seule où ces deux
  pièces servent à quelque chose. Le coffre désigné d'une autre campagne ne les propose jamais :
  un artefact qui ne ferait rien nulle part n'est pas une récompense.</p>
</div>
''')
fin()

# ================================================= 12. LES PIÈGES =========
chapitre(12, 'Les pièges',
         "Cachés jusqu'à ce qu'on les cherche — ou qu'on marche dessus.")
ecrire('''
<p>Un piège est <strong>caché</strong> par défaut. L'action <strong>Fouiller</strong> révèle
ceux de la zone ; le nain qui a pris le talent <em>Œil du mineur</em> détecte
automatiquement ceux qui sont adjacents. Une fois détecté, on peut le
<strong>désamorcer</strong>, le <strong>franchir</strong>, ou l'ignorer à ses risques.</p>
<p>Désamorcer demande un <strong>jet de Body</strong>, ou une <strong>trousse à outils</strong>.
Mais le <strong>nain</strong> et l'<strong>explorateur</strong> désamorcent
<strong>sans outils</strong>, et par une résolution qui leur est propre : un seul dé, et seul un
<strong>bouclier noir</strong> les fait échouer. Ce n'est pas un bonus, c'est leur métier.</p>
''')
ecrire(fig('76-scene-piege',
           "Un piège déclenché : le héros, le piège, et ce qu'il coûte. La fosse retire un point de "
           "Body et immobilise celui qui y tombe.", 'fig scene'))
PIE = CAT['pieges']
EFFET_PIEGE = {
 'Fosse': "1 dégât et <strong>immobilise</strong> ; franchissable d'un jet de Body (difficulté 2) une fois détectée",
 'Piège à lances': "1 dégât au déclenchement",
 'Chute de blocs': "1 dégât et <strong>bloque le passage</strong>",
 'Piège de coffre': "à l'ouverture : 1 dégât <em>ou</em> empoisonnement",
 'Aiguille empoisonnée': "à l'ouverture : 1 dégât <em>ou</em> empoisonnement",
 'Fiole de poison': "à l'ouverture : <strong>empoisonné</strong>",
}
ecrire('<table><thead><tr><th class="vig"></th><th>Piège</th><th class="n">Désamorçable</th>'
       '<th class="n">Usage</th><th>Effet</th></tr></thead><tbody>')
for p_ in PIE:
    src = IMG['pieges'].get(p_['id'])
    ecrire(f'<tr><td class="vig">{"<img src=\"" + src + "\" alt=\"\">" if src else ""}</td>'
           f'<td class="nom">{e(p_["nom"])}</td><td class="n">{e(p_["desarmable"])}</td>'
           f'<td class="n">{e(p_["usage"])}</td><td>{EFFET_PIEGE.get(p_["nom"], "—")}</td></tr>')
ecrire('</tbody></table>')
ecrire('''
<div class="encadre">
  <h4>Les conditions que l'on peut ramasser</h4>
  <p><strong>Empoisonné</strong> (−1 PV par tour) · <strong>Étourdi</strong> (perd son prochain
  tour) · <strong>Apeuré</strong> (moins de dés, ne peut avancer vers la menace) ·
  <strong>Endormi</strong> · <strong>Commandé</strong> (agit pour l'ennemi un tour) ·
  <strong>Ralenti</strong> · <strong>Immobilisé</strong> · <strong>Caché</strong> ·
  <strong>Renforcé</strong> · <strong>Tombé</strong>. Chaque condition porte son effet, sa
  durée <em>et sa source</em> — la fiche du héros dit toujours d'où elle vient.</p>
  <p>Les morts-vivants (Mind 0) sont immunisés à tout ce qui est <strong>mental</strong> :
  apeuré, endormi, commandé.</p>
</div>''')
ecrire(fig('08-guide-pieges', "Le guide intégré : détectable, désamorçable, usage unique ou "
                              "persistant, et l'effet exact."))
fin()

# ================================================ 13. LE BESTIAIRE ========
LIB_CAP = {
 'charge': 'charge', 'invocation': 'invoque', 'frappe_de_zone': 'frappe de zone',
 'ethere': 'éthéré', 'resistance_magique': 'résistance magique', 'agile': 'agile',
 'regeneration': 'se régénère', 'vol_objet': "vole un objet", 'etreinte': 'étreinte',
 's_accroche': "s'accroche", 'racines_entravantes': 'racines entravantes',
 'tacticien': 'bouge avant ET après son attaque', 'venimeux': 'venimeux',
 'choix_attaque': 'choisit son attaque', 'spawn': 'fait surgir des rejetons',
}
def capacites_de(m):
    """`capacites` est tantôt une liste de mots, tantôt un objet dont les clés
    numériques portent un mot et les autres SONT le mot (avec leur paramétrage
    en valeur). Les deux formes existent en base — on lit les deux."""
    v = j(m['capacites'])
    if isinstance(v, list):
        noms = [x for x in v if isinstance(x, str)]
    elif isinstance(v, dict):
        noms = [(val if k.isdigit() and isinstance(val, str) else k) for k, val in v.items()]
    else:
        noms = []
    return ' · '.join(LIB_CAP.get(n, n.replace('_', ' ')) for n in noms if n) or '—'

LIB_BOITE = {'base': 'Boîte de base', 'horreur_des_glaces': 'Horreur des Glaces',
             'horde_ogre': 'Horde des Ogres', 'mage_du_miroir': 'Mage du Miroir',
             'dread_moon': 'Lune du Dread', 'jungles_delthrak': 'Jungles de Delthrak',
             None: 'Élites du donjon'}
MON = CAT['monstres']

chapitre(13, 'Le bestiaire',
         "Quarante et une créatures — et un maître du jeu qui les rebaptise sans jamais "
         "toucher à leurs chiffres.")
ecrire('''
<p>Chaque monstre est un <strong>bloc défini</strong> : déplacement, dés d'attaque, dés de
défense, points de Body, points de Mind. L'IA le <strong>renomme et le redécrit</strong> selon
le thème de la campagne — un gobelin devient « Fouilleur de cendres », un squelette « Ossement
de la Forge » — mais ses statistiques ne bougent pas d'un pouce. C'est exactement la même
créature, mieux racontée.</p>

<div class="encadre">
  <h4>Mind 0 = immunité mentale</h4>
  <p>Squelette, zombie et momie n'ont aucun point de Mind. Aucun sort mental ne les atteint :
  ni sommeil, ni terreur, ni contrôle. Ce n'est pas une résistance, c'est une immunité — le
  sort ne les prend même pas pour cible.</p>
</div>
''')
for tier, titre, intro in (
    ('base', 'La piétaille', "Ce sont eux qui remplissent les salles. Leur coût sert au moteur "
                             "à composer une rencontre calibrée sur la puissance du groupe."),
    ('sous_boss', 'Les élites et les sous-boss', "Ils ferment un acte. Chacun apporte un trait "
                                                 "que la piétaille n'a pas."),
    ('boss', 'Les boss', "Un par campagne — plus les sous-boss selon la longueur. Ils portent "
                         "les sorts de Dread, la magie du maître du jeu."),
):
    liste = [m for m in MON if m['tier'] == tier]
    ecrire(f'<h3>{titre}</h3><p>{intro}</p>')
    ecrire('<table><thead><tr><th class="vig"></th><th>Créature</th><th class="n">Dépl.</th>'
           '<th class="n">Att.</th><th class="n">Déf.</th><th class="n">Body</th>'
           '<th class="n">Mind</th><th>Traits</th></tr></thead><tbody>')
    for m in sorted(liste, key=lambda z: (z['cout'] or 0, z['nom_base'])):
        src = IMG['monstres'].get(m['id'])
        dist = ' <em class="vo">(à distance)</em>' if m['portee'] != 'corps_a_corps' else ''
        ecrire(f'<tr><td class="vig">{"<img src=\"" + src + "\" alt=\"\">" if src else ""}</td>'
               f'<td class="nom">{e(m["nom_base"])}{dist}</td>'
               f'<td class="n">{m["deplacement"]}</td><td class="n">{m["attaque"]}</td>'
               f'<td class="n">{m["defense"]}</td><td class="n">{m["pv_body"]}</td>'
               f'<td class="n">{m["pv_mind"]}</td><td>{capacites_de(m)}</td></tr>')
    ecrire('</tbody></table>')

ecrire('''
<h3>Les sorts de Dread</h3>
<p>C'est la magie de l'adversaire, et elle suit exactement les mêmes règles de résolution que
celle des héros — <strong>c'est le Mind des héros qui sert de bouclier</strong>. Elle est
répartie sur tout l'arc : les sorts mineurs aux sous-boss, les plus vilains au boss final.
Un boss ne les lance pas à volonté : chaque rencontre a son compte d'usages.</p>
''')
ecrire(fig('05-guide-bestiaire', "Le bestiaire du guide intégré, avec les blocs de statistiques "
                                 "sourcés et les traits d'extension."))
fin()

# ============================================== 14. PROGRESSER ============
COMP = CAT.get('competences', [])
chapitre(14, 'Progresser',
         "On ne monte pas de niveau en tuant des monstres — on en monte en franchissant des jalons.")
ecrire('''
<h3>Les niveaux</h3>
<p>Aucun point d'expérience, aucune accumulation. Le groupe monte de niveau en franchissant les
<strong>jalons de la campagne</strong> : chaque <strong>sous-boss</strong> vaincu, le
<strong>boss final</strong>, et certains objectifs majeurs marqués par le gabarit de quête.
La cadence vise cinq à huit niveaux par campagne.</p>
<p>Chaque niveau donne <strong>un point de compétence</strong>, et à certains paliers un point
de Body ou de Mind selon la classe. Les <strong>attributs</strong> de jet, eux, ne montent
<strong>que</strong> par des nœuds dédiés de la grille : c'est un choix, jamais un automatisme.
Et ils n'ont <strong>aucun plafond</strong> — c'est la difficulté qui suit.</p>

<h3>La grille de talents : trois colonnes, trois rangs</h3>
<p>Chaque classe a ses <strong>trois colonnes</strong>, qui sont ses domaines, et
<strong>trois rangs</strong> par colonne. Prendre le rang <em>n</em> exige le rang
<em>n−1</em> <strong>de la même colonne</strong>. Neuf cases pour quatre à sept points :
descendre une colonne, c'est renoncer à une autre.</p>
<p>Les trois nœuds d'une colonne sont des <strong>effets différents du même domaine</strong>,
pas le même chiffre qui grossit. La chaîne est un ordre d'acquisition, pas une montée en
puissance.</p>
''')
if COMP:
    ecrire('<table><thead><tr><th>Classe</th><th>Colonne 1</th><th>Colonne 2</th>'
           '<th>Colonne 3</th></tr></thead><tbody>')
    for nom in ORDRE_CLASSES:
        cols = {}
        for c in COMP:
            if c['classe'] != nom or c['innee']:
                continue
            cols.setdefault(c['colonne'], {'cat': c['categorie'], 'noeuds': {}})
            cols[c['colonne']]['noeuds'][c['rang']] = c['nom']
        if not cols:
            continue
        cases = []
        for i in (1, 2, 3):
            col = cols.get(i)
            if not col:
                cases.append('<td>—</td>')
                continue
            chaine = ' · '.join(col['noeuds'][r] for r in sorted(col['noeuds']))
            cases.append(f'<td><strong>{e(col["cat"])}</strong><br>{e(chaine)}</td>')
        ecrire(f'<tr><td class="nom">{nom.capitalize()}</td>' + ''.join(cases) + '</tr>')
    ecrire('</tbody></table>')
ecrire('''
<div class="encadre">
  <h4>Les capacités de carte sont gratuites</h4>
  <p>Les classes d'extension portent en plus des <strong>capacités imprimées sur leur carte</strong>
  — la <em>Parade au bouclier</em> du chevalier, la <em>Furie</em> du berserker, le
  <em>Flair du danger</em> de l'explorateur. Elles arrivent avec la figurine, ne coûtent aucun
  point, et vivent <strong>hors de la grille</strong>.</p>
  <p>Quatre d'entre elles sont des <strong>réactions hors tour</strong> : le jeu vous
  interrompt pour vous les proposer au moment où elles servent — quand un voisin encaisse un
  coup, quand un monstre errant surgit.</p>
</div>''')
fin()

# ======================================== 15. FINIR UNE QUÊTE =============
chapitre(15, 'Finir une quête, finir une campagne',
         "Rien ne se referme tout seul : on décide de sortir, et on vote.")
ecrire('''
<h3>La quête ne se termine pas d'elle-même</h3>
<p>Tuer le dernier monstre <strong>ne clôt pas la quête</strong>. Le moteur constate seulement
que le donjon est nettoyé, puis propose <strong>« Quitter le donjon »</strong> — et ce choix
ouvre un <strong>vote du groupe</strong>. <strong>Majorité stricte, et une égalité signifie
qu'on reste</strong> : personne ne voit sa fouille écourtée par un compagnon pressé.</p>
<p>L'option n'apparaît que si l'<strong>objectif</strong> de la quête est accompli — vaincre le
sous-boss, vaincre le boss, ou atteindre la salle désignée et rapporter ce qu'elle garde — ou,
en dernier recours, si le donjon est entièrement vidé. Mieux vaut rentrer bredouille que rester
enfermé.</p>

<h3>Battre en retraite</h3>
<p>Cette option-là <strong>n'a aucune condition</strong>, et c'est tout l'intérêt : elle doit
rester disponible au pire moment, sinon ce n'est pas une retraite. Elle ouvre un vote à
<strong>trois issues</strong> :</p>
<ul>
  <li><strong>continuer</strong> — on serre les dents ;</li>
  <li><strong>recommencer la quête</strong> — on restaure la sauvegarde d'ouverture ;</li>
  <li><strong>arrêter la campagne</strong> — clôture immédiate.</li>
</ul>
<p><strong>Majorité relative, et toute égalité fait continuer</strong>, y compris entre les deux
issues destructrices : elles effacent la partie de tout le monde, une minorité ne doit jamais
pouvoir les imposer.</p>

<h3>La clôture de campagne</h3>
<ol>
  <li>Le groupe <strong>répartit l'équipement</strong> acquis pendant la campagne, butin de boss
      compris.</li>
  <li>La <strong>bourse commune est partagée</strong> vers les bourses personnelles du roster.</li>
  <li>L'IA écrit un <strong>résumé de campagne</strong>, ajouté à l'historique de chaque fiche
      de personnage.</li>
  <li>Les personnages <strong>retournent au roster</strong> de leur joueur, avec leur
      équipement, leur or et leur histoire.</li>
  <li>Les données du groupe sont <strong>supprimées</strong> — quêtes, cartes, instances,
      et la mémoire d'univers propre au groupe. Seul l'historique compact survit.</li>
</ol>
<p>Le résumé est écrit <strong>avant</strong> la purge : c'est ce qui permet de tout effacer
sans rien perdre d'essentiel.</p>
''')
ecrire(fig('19-table-prologue', "Le prologue d'une campagne, écrit par le maître du jeu à partir "
                                "du thème choisi à la création du groupe. C'est le même texte "
                                "qui nourrira sa mémoire tout au long de l'arc."))
fin()

# =============================================== 16. AIDE-MÉMOIRE =========
chapitre(16, 'Aide-mémoire',
         "La page à garder ouverte sur la table.")
ecrire('''
<div class="memo">
<section><h4>Le dé de combat</h4><ul>
  <li>3 crânes · 2 boucliers blancs · 1 bouclier noir</li>
  <li>Héros : bouclier <strong>blanc</strong></li>
  <li>Monstre : bouclier <strong>noir</strong></li>
  <li>Un crâne sort une fois sur deux</li>
</ul></section>

<section><h4>Un tour</h4><ul>
  <li>Déplacement = <strong>base + 1d6</strong>, orthogonal</li>
  <li><strong>Une</strong> action, avant ou après</li>
  <li>Une seule attaque, sauf capacité</li>
  <li>Pas d'attaque d'opportunité</li>
  <li>Armure de plates : perd le d6 entier (Chevalier/Allégée : le garde)</li>
</ul></section>

<section><h4>Une attaque</h4><ul>
  <li>Dés d'attaque = ceux de <strong>l'arme</strong></li>
  <li>Dés de défense = 2 + <strong>armure</strong></li>
  <li>Dégâts = crânes − boucliers (min. 0)</li>
  <li>0 PV de Body = <strong>tombé</strong>, relevable</li>
  <li>Non relevé en fin de combat = mort</li>
</ul></section>

<section><h4>Un jet de compétence</h4><ul>
  <li>Autant de dés que l'attribut</li>
  <li>Facile 1 · Moyen 2 · Difficile 3 · Très difficile 4+</li>
  <li>Body : force, agilité, endurance</li>
  <li>Mind : savoir, perception, volonté, persuasion</li>
</ul></section>

<section><h4>La magie</h4><ul>
  <li>Un sort : <strong>une fois par quête</strong></li>
  <li>Tout revient <strong>entre</strong> les quêtes — aucun repos</li>
  <li>Sorts mentaux : binaires, Mind 0 = immunisé</li>
  <li>Parchemin : auto pour un lanceur, <strong>jet de Mind</strong> sinon</li>
  <li>Le tir ami existe</li>
</ul></section>

<section><h4>Fouiller</h4><ul>
  <li>Une fouille <strong>par héros et par salle</strong></li>
  <li>Le paquet tourne, il ne s'épuise pas</li>
  <li>Carte piège = le tour s'arrête</li>
  <li>Artefact : <strong>uniquement</strong> dans un coffre désigné</li>
  <li>Un passage secret mène toujours à un coffre</li>
</ul></section>

<section><h4>Pièges</h4><ul>
  <li>Cachés jusqu'à la fouille</li>
  <li>Nain et explorateur : désamorcent <strong>sans outils</strong> (seul un bouclier noir échoue)</li>
  <li>Les autres : jet de Body, ou trousse à outils</li>
  <li>Fosse détectée : franchissable (Body, difficulté 2)</li>
</ul></section>

<section><h4>Le groupe</h4><ul>
  <li>Bourse <strong>commune</strong></li>
  <li>Marché : au hub, atomique, tous doivent confirmer</li>
  <li>Don : au hub, sac du receveur vérifié</li>
  <li>Échange en quête : allié <strong>adjacent</strong>, séance dans les deux sens, <strong>une</strong> action</li>
  <li>Jeter : <strong>gratuit</strong>, répétable — l'objet est détruit</li>
  <li>Revente : 50 % · artefacts : jamais</li>
  <li>Sac = PV Body max ÷ 2 (+ bonus de classe)</li>
</ul></section>

<section><h4>Les votes</h4><ul>
  <li>Sortir du donjon : majorité stricte, <strong>égalité = on reste</strong></li>
  <li>Retraite : 3 issues, <strong>égalité = on continue</strong></li>
  <li>TPK : recharger ou abandonner, <strong>égalité = recharger</strong></li>
  <li>Aucun vote n'a de délai : <strong>proposer n'est pas voter</strong></li>
</ul></section>

<section><h4>Si ça bloque</h4><ul>
  <li>Rien ne démarre → <strong>vérifier qu'une table est ouverte</strong> (le battement de cœur expire en 30 s)</li>
  <li>« Prêt » refusé → un panier de marché non confirmé traîne</li>
  <li>Téléphone perdu → se reconnecter : la partie reprend où elle en est</li>
</ul></section>
</div>
''')
fin()

CSS_WEB = r"""
@import url('https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700;800&family=Spectral:ital,wght@0,400;0,600;1,400&family=Public+Sans:wght@400;500;600;700&display=swap');

/* Version ÉCRAN du livret. Feuille autonome, pas une surcharge de la feuille
   d'impression : les deux médias n'ont presque aucune règle en commun (l'une
   compte en millimètres et découpe des pages, l'autre défile et se replie sur
   un téléphone), et empiler les deux revenait à écrire des surcharges pour
   chaque déclaration. Les NOMS DE CLASSES, eux, sont les mêmes — le corps du
   document est généré une seule fois. */
:root{
  --stone-950:oklch(0.16 0.012 255); --stone-900:oklch(0.20 0.013 255);
  --stone-850:oklch(0.235 0.014 255); --stone-800:oklch(0.27 0.015 255);
  --stone-700:oklch(0.34 0.016 255);
  --parch-100:oklch(0.95 0.022 82); --parch-ink:oklch(0.30 0.030 60);
  --torch:oklch(0.76 0.155 65); --gold:oklch(0.80 0.135 88);
  --ember:oklch(0.62 0.170 42); --danger:oklch(0.60 0.200 25);
  --ink-100:oklch(0.93 0.010 255); --ink-300:oklch(0.80 0.012 255);
  --ink-500:oklch(0.64 0.013 255);
  --display:'Cinzel','Trajan Pro',Georgia,serif;
  --narr:'Spectral',Georgia,'Times New Roman',serif;
  --ui:'Public Sans',system-ui,-apple-system,sans-serif;
  --line:1px solid oklch(0.34 0.016 255 / .55);
}
*{box-sizing:border-box}
body{margin:0;background:var(--stone-950);color:var(--ink-100);
     font-family:var(--narr);font-size:17px;line-height:1.65;
     -webkit-text-size-adjust:100%}
img{max-width:100%}

/* ---- bandeau collant ---- */
.lv-bar{position:sticky;top:0;z-index:20;display:flex;align-items:center;gap:14px;
  padding:10px 18px;background:oklch(0.16 0.012 255 / .92);backdrop-filter:blur(8px);
  border-bottom:var(--line)}
.lv-bar .t{font-family:var(--display);font-weight:700;font-size:15px;color:var(--parch-100);
  letter-spacing:.02em;white-space:nowrap}
.lv-bar .t .long{display:inline}
.lv-bar .sp{flex:1}
.lv-bar a{font-family:var(--ui);font-size:13px;font-weight:600;text-decoration:none;
  color:var(--ink-300);border:var(--line);border-radius:8px;padding:6px 12px;white-space:nowrap}
.lv-bar a:hover{color:var(--parch-100);border-color:var(--gold)}
.lv-bar a.pdf{color:var(--torch);border-color:oklch(0.62 0.170 42 / .5)}

.lv-page{max-width:900px;margin:0 auto;padding:0 20px 80px}

/* ---- couverture ---- */
.couv{position:relative;margin:0 -20px 34px;min-height:min(76vh,620px);
  display:flex;flex-direction:column;justify-content:flex-end;overflow:hidden;background:#0c0906}
.couv img.fond{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.6}
.couv .voile{position:absolute;inset:0;
  background:linear-gradient(180deg,rgba(12,9,6,.72) 0%,rgba(12,9,6,.15) 40%,rgba(12,9,6,.92) 82%,#0c0906 100%)}
.couv .bloc{position:relative;padding:0 24px 34px}
.couv .surtitre{font-family:var(--ui);letter-spacing:.3em;text-transform:uppercase;
  font-size:11px;color:#d9a05b;margin-bottom:14px}
.couv h1{font-family:var(--display);font-weight:700;font-size:clamp(38px,9vw,68px);
  line-height:1.02;margin:0 0 14px;color:#f7ecd6}
.couv .sous{font-style:italic;font-size:clamp(16px,2.6vw,20px);color:#e6d3b1;margin:0 0 18px;max-width:36ch}
.couv .filet{height:2px;width:140px;background:linear-gradient(90deg,#c8862f,transparent);margin-bottom:16px}
.couv .pied{font-family:var(--ui);font-size:12px;color:#b39b78}

/* ---- titrage ---- */
h2{font-family:var(--display);font-size:clamp(25px,5vw,34px);font-weight:700;
   margin:0 0 6px;color:var(--parch-100);line-height:1.15}
h2 .num{color:var(--ember);font-size:.62em;margin-right:12px}
h3{font-family:var(--display);font-size:20px;font-weight:600;color:var(--torch);margin:34px 0 8px}
h4{font-family:var(--ui);font-size:12px;font-weight:700;letter-spacing:.15em;
   text-transform:uppercase;color:var(--gold);margin:26px 0 8px}
.chapitre{padding-top:34px;margin-top:34px;border-top:var(--line);scroll-margin-top:64px}
.chapitre:first-of-type{border-top:0;margin-top:0}
.chapitre>h2{padding-bottom:12px;border-bottom:2px solid oklch(0.34 0.016 255 / .6);margin-bottom:18px}
.chapo{font-style:italic;color:var(--ink-300);font-size:19px;margin:0 0 22px;
  border-left:3px solid var(--ember);padding-left:16px}
p{margin:0 0 14px}
ul,ol{margin:0 0 14px;padding-left:22px}
li{margin-bottom:6px}
strong{color:var(--parch-100);font-weight:600}
em.vo{color:var(--ink-500);font-style:normal;font-size:.9em}
code{font-family:var(--ui);font-size:.88em;background:var(--stone-850);
  border:var(--line);border-radius:4px;padding:1px 5px}
a{color:var(--torch)}

/* ---- tableaux : ils débordent sur un téléphone, donc ils défilent ---- */
.tscroll{overflow-x:auto;margin:0 0 18px;border:var(--line);border-radius:10px;
  background:var(--stone-900);-webkit-overflow-scrolling:touch}
.tscroll table{margin:0;border:0}
table{width:100%;border-collapse:collapse;font-family:var(--ui);font-size:14px;min-width:min(100%,520px)}
th{background:var(--stone-850);color:var(--ink-300);text-align:left;font-weight:700;
   font-size:11px;letter-spacing:.1em;text-transform:uppercase;padding:10px 12px;
   border-bottom:var(--line);white-space:nowrap}
td{padding:10px 12px;border-bottom:1px solid oklch(0.27 0.015 255 / .7);vertical-align:middle}
tbody tr:last-child td{border-bottom:0}
td.n,th.n{text-align:center;font-variant-numeric:tabular-nums;white-space:nowrap}
td.nom{font-family:var(--narr);font-size:16px;font-weight:600;color:var(--parch-100)}
td.vig{width:52px;padding:6px 8px}
td.vig img{width:40px;height:40px;object-fit:cover;border-radius:6px;border:var(--line);display:block}

/* ---- figures ---- */
figure.fig{margin:0 0 20px}
figure.fig img{width:100%;height:auto;display:block;border:var(--line);border-radius:10px;background:#000}
figure.fig figcaption{font-family:var(--ui);font-size:13px;color:var(--ink-500);
  margin-top:8px;line-height:1.45}
figure.tel img{width:auto;height:auto;max-width:100%;max-height:70vh;margin:0 auto}
figure.tel figcaption{text-align:center}
/* ⚠ `width:100%` plafonné, JAMAIS `width:auto` ici : une image `loading=lazy` en
   largeur auto ne réserve AUCUNE place avant chargement (mesuré : 2 px), et les
   ancres de chapitre retombent — le piège n°7 du README, revenu par une règle neuve.
   L'impression garde `auto` : elle charge tout avant de rendre, et le plafond de
   hauteur exige de laisser la largeur suivre le ratio. */
figure.scene img{width:100%;max-width:560px;height:auto;margin:0 auto}
figure.scene figcaption{text-align:center}
.duo,.trio{display:grid;gap:18px;align-items:start}
.duo{grid-template-columns:1fr 1fr}
.trio{grid-template-columns:repeat(3,1fr)}

/* ---- blocs ---- */
.encadre{background:var(--stone-900);border:var(--line);border-left:3px solid var(--torch);
  border-radius:10px;padding:16px 18px;margin:0 0 18px}
.encadre h4{margin-top:0}
.avert{border-left-color:var(--danger)}
.cartouche{background:linear-gradient(180deg,var(--stone-850),var(--stone-900));
  border:var(--line);border-radius:12px;padding:18px 20px;margin:0 0 18px}
.cartouche h4{color:var(--gold);margin-top:0}

.grille-classes{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px}
.cl{border:var(--line);border-radius:10px;overflow:hidden;background:var(--stone-900)}
.cl img{width:100%;height:130px;object-fit:cover;object-position:center 22%;display:block}
.cl .nom{font-family:var(--display);font-size:17px;font-weight:700;padding:10px 12px 2px;color:var(--parch-100)}
.cl .race{font-family:var(--ui);font-size:10.5px;letter-spacing:.1em;text-transform:uppercase;
  color:var(--ink-500);padding:0 12px 8px}
.cl .st{font-family:var(--ui);font-size:12px;color:var(--ink-300);padding:0 12px 12px;
  display:flex;flex-wrap:wrap;gap:2px 12px}
.cl .st b{color:var(--torch)}

.des{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px}
.de{border:var(--line);border-radius:10px;background:var(--stone-900);padding:16px;text-align:center}
.de .face{font-size:30px;line-height:1}
.de .lib{font-family:var(--ui);font-size:13px;font-weight:700;margin-top:8px;color:var(--parch-100)}
.de .txt{font-family:var(--ui);font-size:12px;color:var(--ink-500);line-height:1.4;margin-top:4px}

.vignettes{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:20px}
.vg img{width:100%;aspect-ratio:1;object-fit:cover;border-radius:8px;border:var(--line)}
.vg span{display:block;font-family:var(--ui);font-size:11px;color:var(--ink-300);
  margin-top:5px;line-height:1.25;text-align:center}

.somm{display:grid;grid-template-columns:1fr 1fr;gap:6px 24px;font-family:var(--ui);font-size:15px}
.somm a{display:flex;gap:12px;align-items:baseline;color:var(--ink-300);text-decoration:none;
  padding:6px 8px;border-radius:8px}
.somm a:hover{color:var(--parch-100);background:var(--stone-850)}
.somm b{color:var(--ember);font-variant-numeric:tabular-nums;min-width:22px}

.memo{display:grid;grid-template-columns:1fr 1fr;gap:14px;font-family:var(--ui);font-size:14px}
.memo section{border:var(--line);border-radius:10px;padding:14px 16px;background:var(--stone-900)}
.memo h4{margin:0 0 8px}
.memo ul{padding-left:18px;margin:0}

@media (max-width:760px){
  body{font-size:16px}
  .duo,.trio,.grille-classes,.des,.memo,.somm{grid-template-columns:1fr}
  .vignettes{grid-template-columns:repeat(3,1fr)}
  .lv-page{padding:0 14px 60px}
  .lv-bar .t .long{display:none}   /* « HeroQuest RPG — » tronquait le titre */
  .couv{margin:0 -14px 26px}
  .cl img{height:150px}
}
@media (min-width:761px) and (max-width:980px){
  .grille-classes{grid-template-columns:repeat(2,1fr)}
  .vignettes{grid-template-columns:repeat(4,1fr)}
}
"""

# ================================================== ASSEMBLAGE ============
somm = ''.join(f'<div><a href="#ch{n}"><b>{n}</b><span>{t}</span></a></div>'
                for n, t in CHAPITRES)
corps = ''.join(MORCEAUX)
assert '<!--GABARIT-SOMMAIRE-->' in corps, 'le gabarit du sommaire a disparu'
corps = corps.replace('<!--GABARIT-SOMMAIRE-->', f'<div class="somm">{somm}</div>')

coupe = corps.index('<section class="chapitre"')
couverture, interieur = corps[:coupe], corps[coupe:]

dossier = os.path.dirname(SORTIE)
os.makedirs(dossier, exist_ok=True)

# ------------------------------------------------------- version IMPRESSION --
def page_impression(contenu, css_sup=''):
    return (f'<!doctype html><html lang="fr"><head><meta charset="utf-8">'
            f'<title>HeroQuest RPG — Livret de jeu</title>'
            f'<style>{CSS}{css_sup}</style></head><body>{contenu}</body></html>')

open(os.path.join(dossier, 'couverture.html'), 'w', encoding='utf-8').write(
    page_impression(couverture, '@page{size:A4;margin:0}.couv{page-break-after:auto}'))
open(SORTIE, 'w', encoding='utf-8').write(page_impression(interieur))

# ------------------------------------------------------------ version WEB ----
# Le CORPS est généré une seule fois : seuls les chemins d'images changent, et
# les tableaux gagnent un conteneur défilant (huit colonnes de bestiaire ne
# tiennent pas sur un téléphone — sans lui, c'est la PAGE qui défile de travers).
WEB = os.path.join(RACINE, 'public', 'livret')
os.makedirs(WEB, exist_ok=True)

def vers_web(html):
    h = html.replace('src="img/', 'src="/livret/img/')
    h = h.replace('src="../../browser-shots/livret/web/', 'src="/livret/captures/')
    h = h.replace('src="../../browser-shots/livret/', 'src="/livret/captures/')
    h = h.replace('<table>', '<div class="tscroll"><table>').replace('</table>', '</table></div>')
    h = h.replace('<img ', '<img loading="lazy" ')
    return h

barre = (
    '<div class="lv-bar"><span class="t"><span class="long">HeroQuest RPG — </span>'
    'Livret de jeu</span>'
    '<span class="sp"></span>'
    '<a href="#somm">Sommaire</a>'
    f'<a class="pdf" href="/livret/{os.path.basename(PDF)}" target="_blank" rel="noopener">PDF</a>'
    '</div>')

open(os.path.join(WEB, 'index.html'), 'w', encoding='utf-8').write(
    '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
    '<meta name="viewport" content="width=device-width,initial-scale=1">'
    '<title>Livret de jeu — HeroQuest RPG</title>'
    '<meta name="description" content="Les règles complètes de HeroQuest RPG : '
    'héros, dés, tour, combat, équipement, magie, donjon, bestiaire.">'
    f'<style>{CSS_WEB}</style></head><body>{barre}'
    f'<div class="lv-page">{vers_web(couverture)}{vers_web(interieur)}</div></body></html>')

# ⚠ Sonde d'existence pour l'accueil. Un HEAD sur index.html ne DISCRIMINE PAS :
# le try_files de nginx sert la SPA en text/html pour tout ce qui manque, donc
# une page absente répondait « présente ». Un JSON, lui, ne peut pas être
# confondu avec la SPA — c'est le même piège que celui payé sur le PDF.
json.dump({
    'genere_le': datetime.date.today().isoformat(),
    'chapitres': [{'numero': n, 'titre': t} for n, t in CHAPITRES],
    'pdf': f'/livret/{os.path.basename(PDF)}',
}, open(os.path.join(WEB, 'manifest.json'), 'w', encoding='utf-8'),
    ensure_ascii=False, indent=1)

# Les images du livret sont des copies RÉDUITES (docs/livret/img) : servir les
# sources 1024x1024 de public/images rendrait la page web six fois plus lourde
# pour un rendu identique à la taille d'affichage.
for src, dst in ((os.path.join(RACINE, 'docs', 'livret', 'img'), os.path.join(WEB, 'img')),
                 (os.path.join(RACINE, 'browser-shots', 'livret', 'web'),
                  os.path.join(WEB, 'captures'))):
    if os.path.isdir(src):
        shutil.rmtree(dst, ignore_errors=True)
        shutil.copytree(src, dst)

poids = sum(os.path.getsize(os.path.join(r, f))
            for r, _, fs in os.walk(WEB) for f in fs
            if not f.endswith('.pdf'))
print(f'{SORTIE} — {len(interieur) // 1024} Ko, {len(CHAPITRES)} chapitres (+ couverture.html)')
print(f'{WEB}/index.html — version web, {poids // 1024} Ko avec ses images')
