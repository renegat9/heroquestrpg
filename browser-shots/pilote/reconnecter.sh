#!/usr/bin/env bash
# Reconnecte une session dont l'overlay « session expirée » bloque la manette.
# Le bouton « Se reconnecter » du jeu NE réauthentifie pas (cf. verdict §2.15) :
# il faut repasser par le formulaire de connexion.
set -u
D="$(cd "$(dirname "$0")" && pwd)"
S="${1:?session}"
case "$S" in
    p1) ID=bram0649 ;;
    p2) ID=thora0649 ;;
    p3) ID=sylvaine0649 ;;
    p4) ID=aldric0649 ;;
    *) echo "session inconnue"; exit 1 ;;
esac

"$D/hq" "$S" goto '{"url":"/joueur","pause":2500}' >/dev/null
"$D/hq" "$S" fill "{\"selector\":\"input.joueur-input\",\"n\":0,\"value\":\"$ID\"}" >/dev/null
"$D/hq" "$S" click '{"texte":"Entrer","pause":3000}' >/dev/null
"$D/hq" "$S" click '{"texte":"Reprendre la partie","pause":4000}' >/dev/null
"$D/hq" "$S" save >/dev/null
ov=$("$D/hq" "$S" els '{"selector":".session-overlay"}' | grep -o '"n":[0-9]*' | cut -d: -f2)
echo "$S reconnecté — overlay restant : ${ov:-?}"
