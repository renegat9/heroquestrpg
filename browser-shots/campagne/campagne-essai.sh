#!/bin/bash
# Charge ou retire une CAMPAGNE CONSTRUITE D'ESSAI pour le harnais du mode classique (2026-11-19).
#
#   ./campagne-essai.sh charger essai-classique.json   → valide puis enregistre (ou remplace) la campagne
#   ./campagne-essai.sh retirer <slug>                 → la supprime, si aucun groupe ne la joue
#
# ⚠ Seul un slug `essai-*` passe, dans les deux sens. Les campagnes saisies par René (heroquest-livret-de-quetes,
# la-forteresse-de-kellar, le-retour-du-seigneur-sorcier…) sont de la PRODUCTION, comme les groupes : on ne les
# charge, ne les écrase ni ne les retire jamais d'ici.
# Pourquoi un script : `PUT /api/constructeur/campagnes/{slug}` exige le jeton CSRF d'une session navigateur ; ici on
# passe par le même service que la route (`ValidationCampagne` puis `CampagnesConstruites::enregistrer()`).
set -eu
D="$(cd "$(dirname "$0")" && pwd)"
RACINE="$D/../.."
VERBE="${1:-}"; ARG="${2:-}"

case "$VERBE" in
  charger)
    [ -f "$ARG" ] || [ -f "$D/$ARG" ] || { echo "✗ fichier introuvable : $ARG" >&2; exit 1; }
    [ -f "$ARG" ] || ARG="$D/$ARG"
    SLUG=$(python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['slug'])" "$ARG")
    case "$SLUG" in essai-*) ;; *) echo "✗ « $SLUG » n'est pas une campagne d'essai (essai-*) : refusé." >&2; exit 1 ;; esac
    TMP="essai-campagne-$$.json"
    cp "$ARG" "$RACINE/storage/app/$TMP"
    trap 'rm -f "$RACINE/storage/app/$TMP"' EXIT
    docker compose -f "$RACINE/docker-compose.yml" exec -T app php artisan tinker --execute="
      \$d = json_decode(file_get_contents(storage_path('app/$TMP')), true);
      \$refus = app(\App\Partie\Classique\ValidationCampagne::class)->valider(\$d);
      if (\$refus) { echo '✗ refusée : '.json_encode(\$refus, JSON_UNESCAPED_UNICODE).\"\n\"; exit; }
      app(\App\Partie\Classique\CampagnesConstruites::class)->enregistrer(\$d);
      echo \"  ✓ campagne $SLUG chargée\n\";
    " 2>&1 | grep -vE '^\s*$|INFO'
    ;;
  retirer)
    case "$ARG" in essai-*) ;; *) echo "✗ « $ARG » n'est pas une campagne d'essai (essai-*) : refusé." >&2; exit 1 ;; esac
    docker compose -f "$RACINE/docker-compose.yml" exec -T app php artisan tinker --execute="
      \$c = \App\Models\CampagneConstruite::where('slug', '$ARG')->first();
      if (! \$c) { echo \"  campagne $ARG : déjà absente\n\"; exit; }
      \$n = \App\Models\Groupe::where('campagne_construite_id', \$c->id)->count();
      if (\$n > 0) { echo \"  ⚠ campagne $ARG jouée par \$n groupe(s) — ./nettoyer.sh d'abord, NON retirée\n\"; exit; }
      \$c->delete();
      echo \"  ✓ campagne $ARG retirée\n\";
    " 2>&1 | grep -vE '^\s*$|INFO'
    ;;
  *) echo "usage : $0 charger <fichier.json> | retirer <slug essai-*>" >&2; exit 1 ;;
esac
