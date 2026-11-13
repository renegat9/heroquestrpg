#!/bin/bash
# Battement de la table d'un combat d'arène : POST /api/table/ping toutes les 12 s (la présence expire sinon).
#   battement.sh <code>   (lancé en arrière-plan ; son PID va dans battement-<code>.pid)
D="$(cd "$(dirname "$0")" && pwd)"
CODE="$1"
echo $$ > "$D/battement-$CODE.pid"
while true; do
  python3 - "$D" "$CODE" <<'EOF' >/dev/null 2>&1
import json, sys, urllib.parse, requests
d, code = sys.argv[1], sys.argv[2]
f = f'{d}/session-table-{code}.json'
data = json.load(open(f))
s = requests.Session()
for c in data['cookies']:
    s.cookies.set(c['name'], c['value'], domain='localhost.local', path='/')
xs = [c.value for c in s.cookies if c.name == 'XSRF-TOKEN']
s.post('http://localhost/api/table/ping', headers={'Accept': 'application/json', 'X-XSRF-TOKEN': urllib.parse.unquote(xs[-1]) if xs else ''})
dd = {c.name: c.value for c in s.cookies}
data['cookies'] = [{'name': k, 'value': v} for k, v in dd.items()]
json.dump(data, open(f, 'w'))
EOF
  sleep 12
done
