#!/bin/bash
# Efface ce que `preparer.sh` a créé : la campagne, ses héros, ses comptes, et
# les fichiers locaux de session.
#
# POURQUOI ce script existe (René, 2026-08-23) : `preparer.sh` n'avait pas de
# contrepartie, et une base de développement a fini avec 23 campagnes, 65 héros
# et 65 comptes de test — plus 185 points de bible Qdrant appartenant à des
# groupes disparus depuis longtemps. Le manque n'était pas un moyen de
# DISTINGUER les tests (un drapeau `est_test` en base serait une clé décorative
# que le harnais oublierait de poser, puisqu'il joue exprès sur les vraies
# routes) : c'était une ÉTAPE de ménage.
#
# ⚠ CIBLÉ, pas global : il ne touche qu'au groupe de `groupe.txt` et aux comptes
# de ses héros. Pour remettre toute la base à zéro, c'est
# `php artisan partie:purger --supprimer --tout`.
#
#   ./nettoyer.sh          # purge la campagne courante
#   ./nettoyer.sh --garder-comptes
set -eu
D="$(cd "$(dirname "$0")" && pwd)"
GARDER=0
[ "${1:-}" = "--garder-comptes" ] && GARDER=1

pkill -f "$D/battement.sh" 2>/dev/null || true
rm -f "$D/battement.pid"

CODE="$(cat "$D/groupe.txt" 2>/dev/null || true)"

if [ -z "$CODE" ]; then
  echo "Aucun groupe.txt : rien à purger côté serveur."
else
  # ⚠ Passer par ClotureCampagne::purger() et non par des DELETE : le service
  # emporte aussi les caches de phase, la bible Qdrant du groupe et ses
  # illustrations. Les héros, eux, sont DÉTACHÉS par la purge (ils retournent au
  # roster) — ici on les veut supprimés, avec leurs comptes.
  docker compose -f "$D/../../docker-compose.yml" exec -T app php artisan tinker --execute="
    \$g = \App\Models\Groupe::where('identifiant', '$CODE')->first();
    if (! \$g) { echo \"  groupe $CODE : déjà absent\n\"; exit; }
    \$persos = \$g->personnages()->get();
    \$joueurs = \$persos->pluck('joueur_id')->filter()->unique();
    app(\App\Partie\ClotureCampagne::class)->purger(\$g);
    foreach (\App\Models\Personnage::whereIn('id', \$persos->pluck('id'))->get() as \$p) { \$p->delete(); }
    echo '  campagne $CODE purgée : '.\$persos->count().\" héros\n\";
    if ($GARDER == 0) {
      foreach (\App\Models\Joueur::whereIn('id', \$joueurs)->get() as \$j) { \$j->delete(); }
      echo '  '.\$joueurs->count().\" compte(s) supprimé(s)\n\";
    }
  " 2>&1 | grep -vE '^\s*$|INFO' || true
fi

rm -f "$D"/jar-*.txt "$D"/perso-*.txt "$D/groupe.txt"
echo "  fichiers de session locaux effacés"
