#!/bin/bash
# Monte une partie à DEUX JOUEURS, DEUX HÉROS CHACUN (lot M2, `docs/plan-mode-classique.md` §5) : la manette de
# chaque joueur pilote deux héros. `preparer.sh` crée un compte PAR héros et ne couvre donc pas ce cas.
#
# Mêmes conventions que `preparer.sh` (jar-N.txt, perso-N.txt, ident-N.txt, groupe.txt, battement.pid) : c'est
# `nettoyer.sh`, inchangé, qui efface tout — le groupe, ses quatre héros, les deux comptes.
#
#   ./preparer-deux-heros.sh "nom du groupe" "thème"
#
# ⚠ Le « prêt » n'est PAS envoyé ici : c'est la manette qui doit le faire pour chacun de ses héros, et c'est
# précisément ce qu'on vient regarder. Rend le code du groupe sur la sortie standard.
set -eu
D="$(cd "$(dirname "$0")" && pwd)"

pids_battement=$(pgrep -f "$D/battement.sh" | paste -sd' ' - || true)
if [ -n "$pids_battement" ]; then
  echo "✗ Un battement de table tourne déjà (PID $pids_battement) — ./nettoyer.sh d'abord." >&2
  exit 1
fi

# ⚠ SAUVEGARDE D'ABORD : ce script écrit dans la VRAIE base.
if [ -x "$D/../../image-tools/sauvegarder.sh" ]; then
  "$D/../../image-tools/sauvegarder.sh" >/dev/null 2>&1 \
    && echo "  ✓ sauvegarde prise avant la partie" >&2 \
    || echo "  ⚠ SAUVEGARDE ÉCHOUÉE — on continue, mais sans filet" >&2
fi

NOM="$1"; THEME="$2"
SUF=$(date +%H%M%S)

X() { grep -oP 'XSRF-TOKEN\s+\K[^\s]+' "$D/jar-$1.txt" | tail -1 | sed 's/%3D/=/g'; }
P() { curl -s -b "$D/jar-$1.txt" -c "$D/jar-$1.txt" -X "$2" "http://localhost/api$3" \
        -H 'Accept: application/json' -H 'Content-Type: application/json' \
        -H "X-XSRF-TOKEN: $(X "$1")" ${4:+-d "$4"}; }
ID() { python3 -c "import json,sys; print(json.load(sys.stdin)['personnage']['id'])"; }

# Deux comptes ; chacun crée deux héros. perso-1/2 = joueur 1, perso-3/4 = joueur 2.
n=0
for joueur in 1 2; do
  rm -f "$D/jar-$joueur.txt"
  curl -s -c "$D/jar-$joueur.txt" http://localhost/ >/dev/null
  IDENT="m2j$joueur$SUF"
  echo "$IDENT" > "$D/ident-$joueur.txt"
  P "$joueur" POST /inscription "{\"pseudo\":\"Joueur $joueur\",\"identifiant\":\"$IDENT\"}" >/dev/null
  for spec in $( [ "$joueur" = 1 ] && echo "barbare:Albrecht nain:Brunehaut" || echo "elfe:Cassandre magicien:Dagobert" ); do
    n=$((n+1)); CL="${spec%%:*}"; NH="${spec##*:}"
    P "$joueur" POST /personnages "{\"nom\":\"$NH\",\"classe\":\"$CL\"}" | ID > "$D/perso-$n.txt"
    echo "  joueur $joueur ($IDENT) : $NH ($CL) id=$(cat "$D/perso-$n.txt")" >&2
  done
done

R=$(P 1 POST /groupes "{\"nom\":\"$NOM\",\"personnage_id\":$(cat "$D/perso-1.txt"),\"theme\":\"$THEME\",\"longueur\":\"tres_courte\"}")
CODE=$(echo "$R" | python3 -c "import json,sys; print(json.load(sys.stdin)['groupe']['identifiant'])")
echo "$CODE" > "$D/groupe.txt"

# Le SECOND héros du joueur 1, puis les DEUX héros du joueur 2, par `personnage_ids`.
P 1 POST "/groupes/$CODE/joueurs" "{\"personnage_ids\":[$(cat "$D/perso-2.txt")]}" >/dev/null
P 2 POST "/groupes/$CODE/joueurs" "{\"personnage_ids\":[$(cat "$D/perso-3.txt"),$(cat "$D/perso-4.txt")]}" >/dev/null

# Table + battement de cœur : sans lui (TTL 30 s), aucune quête ne démarre.
rm -f "$D/jar-table.txt"; curl -s -c "$D/jar-table.txt" http://localhost/ >/dev/null
XT=$(grep -oP 'XSRF-TOKEN\s+\K[^\s]+' "$D/jar-table.txt" | tail -1 | sed 's/%3D/=/g')
curl -s -b "$D/jar-table.txt" -c "$D/jar-table.txt" -X POST http://localhost/api/table \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H "X-XSRF-TOKEN: $XT" \
  -d "{\"code\":\"$CODE\"}" >/dev/null
nohup "$D/battement.sh" >/dev/null 2>&1 & echo $! > "$D/battement.pid"
sleep 3

# Ce que le serveur publie au hub : le roster, avec le pilote de chaque héros.
P 1 GET "/groupes/$CODE/etat" | python3 -c "
import json,sys
g=json.load(sys.stdin)['groupe']
for r in g.get('prets', []): print('  roster :', r, file=sys.stderr)"
echo "$CODE"
