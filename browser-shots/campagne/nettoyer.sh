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

# BATTEMENT DE CŒUR (verdict Morcar, 2026-10-09, §6) : on arrête LE battement que cette
# campagne a lancé (son PID est dans battement.pid), on VÉRIFIE qu'il est mort, et on signale
# tout reste du même script. Un battement vivant écrit dans jar-table.txt : la table passe
# « narrateur inactif ». Pas de « on tue et on espère » — le résultat est relu.
arreter_battements() {
  local pid_fichier="$D/battement.pid" pid restants="" i
  if [ -f "$pid_fichier" ]; then
    pid=$(cat "$pid_fichier" 2>/dev/null || true)
    if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null \
       && tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null | grep -q "battement.sh"; then
      kill "$pid" 2>/dev/null || true
      echo "  battement de table PID $pid : arrêt demandé" >&2
    else
      echo "  battement de table PID ${pid:-?} : déjà absent (rien à arrêter)" >&2
    fi
  fi
  rm -f "$pid_fichier"
  # Restes du même script (lancés par un autre passage, p. ex. preparer-livret.sh).
  for pid in $(pgrep -f "$D/battement.sh" || true); do
    kill "$pid" 2>/dev/null || true
    echo "  reste du battement PID $pid : arrêt demandé" >&2
  done
  # Relecture : aucun battement de ce dossier ne doit survivre, cinq secondes au plus.
  for i in 1 2 3 4 5; do
    restants=$(pgrep -f "$D/battement.sh" || true)
    [ -z "$restants" ] && break
    sleep 1
  done
  if [ -n "$restants" ]; then
    echo "  ⚠ battement(s) TOUJOURS VIVANT(S) : $restants — kill -9 à la main" >&2
  else
    echo "  ✓ aucun battement de table ne tourne" >&2
  fi
}
arreter_battements

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
      // ⚠ On compte ce qui a DISPARU, jamais ce qu'on a demandé (2026-09-24).
      // L'ancien message affichait \$joueurs->count() — l'INTENTION — et un
      // échec de suppression était avalé plus bas par le « || true » : un
      // compte fondateur a ainsi survécu sous le message « 2 compte(s)
      // supprimé(s) », et l'agent qui l'a vu l'a supprimé à la main, dans la
      // base réelle, contre la consigne. Un script de ménage qui ment pousse
      // exactement au geste qu'il existe pour éviter.
      foreach (\App\Models\Joueur::whereIn('id', \$joueurs)->get() as \$j) {
        try { \$j->delete(); } catch (\Throwable \$e) { echo '  ⚠ compte '.\$j->identifiant.' NON supprimé : '.\$e->getMessage().\"\n\"; }
      }
      \$restants = \App\Models\Joueur::whereIn('id', \$joueurs)->pluck('identifiant');
      echo '  '.(\$joueurs->count() - \$restants->count()).'/'.\$joueurs->count().\" compte(s) supprimé(s)\n\";
      if (\$restants->isNotEmpty()) {
        echo '  ⚠ RESTENT : '.\$restants->implode(', ').\" — à signaler, NE PAS supprimer à la main\n\";
      }
    }
  " 2>&1 | grep -vE '^\s*$|INFO' || true
fi

rm -f "$D"/jar-*.txt "$D"/perso-*.txt "$D"/ident-*.txt "$D/groupe.txt"
echo "  fichiers de session locaux effacés"
