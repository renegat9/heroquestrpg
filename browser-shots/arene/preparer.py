#!/usr/bin/env python3
"""Monte un combat d'arène sur les VRAIES routes : comptes de test, héros d'arène, combat, table.

  preparer.py coop <slot>:<classe>:<NomHeros> [<slot>:<classe>:<NomHeros> ...]
      → le 1er slot ouvre le combat (POST /arene), les autres le rejoignent (POST /arene/{code}/combattants)
  preparer.py mvm <slotA> <slotB>
      → le 1er ouvre un Monster vs Monster (camp a), le 2e rejoint le camp b

Chaque slot reçoit `session-<slot>.json` (cookies + code) ; la table `session-table-<code>.json`. Imprime le code.
Le battement de table se lance à part : `battement.sh <code>` (une table sans battement n'est plus « active »).

⚠ Écrit dans la VRAIE base : les comptes s'appellent `Arene<slot>…` et `nettoyer.py` les retire, eux SEULS.
"""
import json
import os
import sys
import time
import urllib.parse

import requests

BASE = 'http://localhost'
D = os.path.dirname(os.path.abspath(__file__))
SUF = time.strftime('%H%M%S')


class Client:
    def __init__(self):
        self.s = requests.Session()
        self.s.get(BASE + '/')

    def r(self, m, p, b=None):
        xs = [c.value for c in self.s.cookies if c.name == 'XSRF-TOKEN']
        res = self.s.request(m, BASE + '/api' + p, json=b,
                             headers={'Accept': 'application/json', 'X-XSRF-TOKEN': urllib.parse.unquote(xs[-1]) if xs else ''})
        if res.status_code >= 400:
            raise SystemExit(f'{m} {p} {b} -> {res.status_code} {res.text[:500]}')
        return res.json() if res.text else {}

    def sauver(self, fichier, extra):
        dd = {c.name: c.value for c in self.s.cookies}
        json.dump({**extra, 'cookies': [{'name': k, 'value': v} for k, v in dd.items()]}, open(os.path.join(D, fichier), 'w'))


def joueur(slot):
    c = Client()
    c.r('POST', '/inscription', {'pseudo': f'Arene{slot}', 'identifiant': f'arene{slot}{SUF}'})
    return c


def main():
    mode = sys.argv[1]
    registre = os.path.join(D, 'crees.json')
    crees = json.load(open(registre)) if os.path.exists(registre) else {'joueurs': [], 'heros': [], 'combats': []}
    if mode == 'coop':
        code = None
        for i, spec in enumerate(sys.argv[2:]):
            slot, classe, nom = spec.split(':')
            c = joueur(slot)
            h = c.r('POST', '/personnages', {'nom': nom, 'classe': classe, 'mode': 'arene'})['personnage']
            jid = c.r('GET', '/moi')['joueur']['id']
            if i == 0:
                code = c.r('POST', '/arene', {'personnage_id': h['id']})['groupe']['identifiant']
            else:
                c.r('POST', f'/arene/{code}/combattants', {'personnage_id': h['id']})
            c.sauver(f'session-{slot}.json', {'code': code, 'slot': slot, 'heros_id': h['id'], 'joueur_id': jid})
            crees['joueurs'].append(jid); crees['heros'].append(h['id'])
            print(f'  slot {slot} : {nom} ({classe}) héros {h["id"]} joueur {jid}', file=sys.stderr)
    elif mode == 'mvm':
        a, b = sys.argv[2], sys.argv[3]
        ca = joueur(a)
        code = ca.r('POST', '/arene/monstres')['groupe']['identifiant']
        cb = joueur(b)
        cb.r('POST', f'/arene/{code}/camp', {'camp': 'b'})
        for slot, c in ((a, ca), (b, cb)):
            jid = c.r('GET', '/moi')['joueur']['id']
            c.sauver(f'session-{slot}.json', {'code': code, 'slot': slot, 'joueur_id': jid})
            crees['joueurs'].append(jid)
            print(f'  slot {slot} : joueur {jid}', file=sys.stderr)
    else:
        raise SystemExit(__doc__)
    t = Client()
    t.r('POST', '/table', {'code': code})
    t.sauver(f'session-table-{code}.json', {'code': code})
    crees['combats'].append(code)
    json.dump(crees, open(registre, 'w'))
    print(code)


if __name__ == '__main__':
    main()
