#!/bin/bash
# Joue jusqu'à la fin de la quête, ou 120 itérations.
cd "$(dirname "$0")"
for i in $(seq 1 120); do
  phase=$(./hq.sh 1 etat 2>/dev/null | python3 -c "import json,sys;print((json.load(sys.stdin).get('groupe') or {}).get('phase','?'))" 2>/dev/null)
  if [ "$phase" != "quete" ]; then echo "ITERATION $i : phase=$phase — la quête n'est plus en cours"; break; fi
  echo "--- itération $i ---"
  python3 pilote.py
done
echo "BOUCLE TERMINEE"
