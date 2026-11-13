#!/usr/bin/env python3
"""Efface ce que `preparer.py` a créé, et RIEN d'autre : les combats, héros et comptes listés dans `crees.json`.

  nettoyer.py               ← inventaire seul
  nettoyer.py --supprimer   ← arrête les battements, purge (GroupesArene::purger, double verrou), supprime

⚠ Les groupes, personnages et comptes de la base MariaDB sont de la PRODUCTION. Ce script ne vise que les ids de son
registre, et chaque suppression re-vérifie en base que la ligne est bien de test (mode `arene`, pseudo `Arene…`).
Les lignes de palmarès (`arene_combats`) qui ne citent QUE ces héros partent avec eux.
"""
import json
import os
import signal
import subprocess
import sys

D = os.path.dirname(os.path.abspath(__file__))
RACINE = os.path.dirname(os.path.dirname(D))
registre = os.path.join(D, 'crees.json')
if not os.path.exists(registre):
    print('Aucun registre : rien à nettoyer.'); sys.exit(0)
c = json.load(open(registre))
print('combats :', c['combats']); print('héros :', c['heros']); print('joueurs :', c['joueurs'])
if '--supprimer' not in sys.argv:
    print('Inventaire seul. Relancer avec --supprimer.'); sys.exit(0)

for f in os.listdir(D):
    if f.startswith('battement-') and f.endswith('.pid'):
        try:
            os.kill(int(open(os.path.join(D, f)).read().strip()), signal.SIGTERM)
        except Exception:
            pass
        os.remove(os.path.join(D, f))

php = f'''
$codes = {json.dumps(c['combats'])}; $heros = {json.dumps(c['heros'])}; $joueurs = {json.dumps(c['joueurs'])};
$arenes = app(\\App\\Partie\\Arene\\GroupesArene::class);
foreach (\\App\\Models\\Groupe::whereIn("identifiant", $codes)->where("mode", "arene")->get() as $g) {{ $arenes->purger($g); echo "combat {{$g->identifiant}} purgé\\n"; }}
$palmares = 0;
foreach (\\Illuminate\\Support\\Facades\\DB::table("arene_combats")->get() as $l) {{
  $ids = collect(json_decode($l->combattants, true))->pluck("heros_id")->all();
  if ($ids !== [] && array_diff($ids, $heros) === []) {{ \\Illuminate\\Support\\Facades\\DB::table("arene_combats")->where("id", $l->id)->delete(); $palmares++; }}
}}
echo "palmarès : $palmares ligne(s)\\n";
foreach (\\App\\Models\\Personnage::whereIn("id", $heros)->where("mode", "arene")->whereNull("doublure_de")->whereIn("joueur_id", $joueurs)->get() as $p) {{ $p->delete(); echo "héros {{$p->id}} supprimé\\n"; }}
foreach (\\App\\Models\\Joueur::whereIn("id", $joueurs)->where("pseudo", "like", "Arene%")->get() as $j) {{ $j->delete(); echo "joueur {{$j->id}} supprimé\\n"; }}
'''
subprocess.run(['docker', 'compose', 'exec', '-T', 'app', 'php', 'artisan', 'tinker', '--execute', php], cwd=RACINE, check=False)
for f in os.listdir(D):
    if f.startswith('session-') or f == 'crees.json':
        os.remove(os.path.join(D, f))
print('sessions et registre locaux effacés')
