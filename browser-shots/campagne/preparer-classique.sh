#!/bin/bash
# Monte une partie en MODE CLASSIQUE sur une campagne CONSTRUITE (`docs/plan-mode-classique.md` §7) : deux comptes, un
# héros chacun, un groupe classique ouvert par `POST /api/classique`, la table et son battement, tout le monde prêt.
#
# Mêmes conventions que `preparer.sh` (jar-N.txt, perso-N.txt, ident-N.txt, groupe.txt, battement.pid) : c'est
# `nettoyer.sh`, inchangé, qui efface le groupe, ses héros et les comptes. ⚠ La campagne construite, elle, n'appartient
# pas au groupe : `nettoyer.sh` ne la touche pas.
#
#   ./preparer-classique.sh "nom du groupe" <slug de la campagne construite>
#   HQ_AU_HUB=1 ./preparer-classique.sh …    → reste au hub (personne n'est déclaré prêt)
#
# Rend le code du groupe sur la sortie standard.
set -eu
D="$(cd "$(dirname "$0")" && pwd)"

pids_battement=$(pgrep -f "$D/[b]attement.sh" | paste -sd' ' - || true)
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

NOM="$1"; CAMPAGNE="$2"
SUF=$(date +%H%M%S)

X() { grep -oP 'XSRF-TOKEN\s+\K[^\s]+' "$D/jar-$1.txt" | tail -1 | sed 's/%3D/=/g'; }
P() { curl -s -b "$D/jar-$1.txt" -c "$D/jar-$1.txt" -X "$2" "http://localhost/api$3" \
        -H 'Accept: application/json' -H 'Content-Type: application/json' \
        -H "X-XSRF-TOKEN: $(X "$1")" ${4:+-d "$4"}; }

slot=0
for spec in barbare:Albrecht nain:Brunehaut; do
  slot=$((slot+1)); CL="${spec%%:*}"; NH="${spec##*:}"
  rm -f "$D/jar-$slot.txt"
  curl -s -c "$D/jar-$slot.txt" http://localhost/ >/dev/null
  IDENT="cl$slot$SUF"
  echo "$IDENT" > "$D/ident-$slot.txt"
  P "$slot" POST /inscription "{\"pseudo\":\"$NH\",\"identifiant\":\"$IDENT\"}" >/dev/null
  P "$slot" POST /personnages "{\"nom\":\"$NH\",\"classe\":\"$CL\"}" \
    | python3 -c "import json,sys; print(json.load(sys.stdin)['personnage']['id'])" > "$D/perso-$slot.txt"
  echo "  joueur $slot ($IDENT) : $NH ($CL) id=$(cat "$D/perso-$slot.txt")" >&2
done

R=$(P 1 POST /classique "{\"nom\":\"$NOM\",\"campagne\":\"$CAMPAGNE\",\"personnage_id\":$(cat "$D/perso-1.txt")}")
CODE=$(echo "$R" | python3 -c "import json,sys; d=json.load(sys.stdin); print(d['groupe']['identifiant'])") \
  || { echo "✗ POST /classique a échoué : $R" >&2; exit 1; }
echo "$CODE" > "$D/groupe.txt"
echo "  groupe classique : $R" >&2

P 2 POST "/groupes/$CODE/joueurs" "{\"personnage_id\":$(cat "$D/perso-2.txt")}" >/dev/null

# Table + battement de cœur : sans lui (TTL 30 s), aucune quête ne démarre.
rm -f "$D/jar-table.txt"; curl -s -c "$D/jar-table.txt" http://localhost/ >/dev/null
XT=$(grep -oP 'XSRF-TOKEN\s+\K[^\s]+' "$D/jar-table.txt" | tail -1 | sed 's/%3D/=/g')
curl -s -b "$D/jar-table.txt" -c "$D/jar-table.txt" -X POST http://localhost/api/table \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H "X-XSRF-TOKEN: $XT" \
  -d "{\"code\":\"$CODE\"}" >/dev/null
nohup "$D/battement.sh" >/dev/null 2>&1 & echo $! > "$D/battement.pid"
sleep 3

# HQ_AU_HUB=1 : s'arrêter AU HUB, sans déclarer personne prêt — pour éprouver le marché, le sac, l'échange (tout prêt
# lance la quête d'office).
if [ "${HQ_AU_HUB:-0}" = "1" ]; then
  echo "$CODE"
  exit 0
fi

for s in 1 2; do
  R=$(P "$s" POST "/groupes/$CODE/pret" "{\"personnage_id\":$(cat "$D/perso-$s.txt"),\"pret\":true}")
  echo "  prêt $s : $(echo "$R" | cut -c1-200)" >&2
done
echo "$CODE"
