#!/bin/bash
# Le CATALOGUE du livret, tiré de la base — première étape de la régénération.
#
#   ./docs/livret/catalogue.sh /chemin/catalogue.json
#
# ⚠ Il embarque `avantages_objets` : ce que fait chaque objet, TRADUIT PAR LE
# SERVEUR (`MotsClesEquipement::avantages()`), le même texte que le sac du
# téléphone. Jusqu'au 2026-09-16 le livret traduisait les effets avec sa propre
# table Python, recopiée un jour et jamais tenue à jour : 37 objets sur 105 y
# sortaient avec « — », dont les Bottes elfiques et la Baguette de Rappel, dans
# la table même des artefacts « les plus marquants ».
#
# Un script plutôt qu'une commande dans le README : l'étape des vignettes était
# une commande tapée une fois à la main, et c'est comme ça qu'elle s'est perdue.
set -eu
cd "$(dirname "$0")/../.."
SORTIE="${1:?usage : $0 <fichier de sortie>}"

docker compose exec -T app php artisan tinker --execute="
  \$d = [];
  foreach (['classes_heros','monstres','objets','sorts','pieges','mobiliers','terrains','epreuves','competences'] as \$t) {
    \$d[\$t] = \Illuminate\Support\Facades\Schema::hasTable(\$t) ? \DB::table(\$t)->get() : [];
  }
  // Attaque et défense AVEC l'équipement de départ, décidées par le serveur
  // (même source que GET /api/guide) : le livret ne refait pas le calcul.
  \$d['depart_classes'] = \App\Models\ClasseHeros::all()->mapWithKeys(
    fn (\$c) => [\$c->nom => \App\Partie\EquipementDepart::valeurs(\$c)]
  );
  \$d['avantages_objets'] = \App\Models\Objet::all()->mapWithKeys(
    fn (\$o) => [(string) \$o->id => \App\Engine\MotsClesEquipement::avantages((array) \$o->effet)]
  );
  file_put_contents('/tmp/catalogue.json', json_encode(\$d, JSON_UNESCAPED_UNICODE));" >/dev/null
docker compose exec -T app cat /tmp/catalogue.json > "$SORTIE"
echo "catalogue : $SORTIE ($(wc -c < "$SORTIE") octets)"
