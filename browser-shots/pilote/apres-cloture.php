<?php
// Vérification de la clôture de campagne : purge, or réparti, historique, roster libéré.
$g = \App\Models\Groupe::find(77);
if ($g === null) {
    echo "GROUPE #77 : supprimé de la table groupes\n";
} else {
    echo "GROUPE #77 {$g->identifiant} | etat={$g->etat} | phase={$g->phase} | or={$g->or} | quete_courante={$g->quete_courante_id}\n";
}

echo "\n-- purge --\n";
foreach ([
    'quetes' => \App\Models\Quete::where('groupe_id', 77)->count(),
    'cartes' => \App\Models\Carte::whereIn('quete_id', \App\Models\Quete::where('groupe_id', 77)->pluck('id'))->count(),
    'instances_monstres' => \App\Models\InstanceMonstre::whereIn('quete_id', \App\Models\Quete::where('groupe_id', 77)->pluck('id'))->count(),
    'evenements' => \App\Models\Evenement::where('groupe_id', 77)->count(),
    'snapshots' => \App\Models\Snapshot::where('groupe_id', 77)->count(),
] as $table => $n) {
    echo sprintf("  %-20s %d\n", $table, $n);
}

echo "\n-- personnages (roster) --\n";
foreach (\App\Models\Personnage::whereIn('nom', ['Bram Brise-Crâne', 'Thora Poing-de-Fer', 'Sylvaine Feuille-Vive', 'Aldric le Sage'])->get() as $p) {
    echo sprintf("  %-24s niv%d or=%-5d groupe_actif=%s\n", $p->nom, $p->niveau, $p->or, $p->groupe_actif_id ?? 'libre');
}

echo "\n-- personnages_historiques --\n";
foreach (\App\Models\PersonnageHistorique::latest('id')->take(6)->get() as $h) {
    echo '  '.substr(json_encode($h->toArray(), JSON_UNESCAPED_UNICODE), 0, 220)."\n";
}
