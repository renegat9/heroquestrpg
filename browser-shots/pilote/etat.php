<?php
// Vue rapide de l'état serveur du groupe le plus récent (contrôle croisé des rapports d'agents).
$g = \App\Models\Groupe::latest('id')->first();
echo "GROUPE #{$g->id} {$g->identifiant} | or={$g->or} | phase={$g->phase} | etat={$g->etat} | quete_courante={$g->quete_courante_id}\n";
foreach ($g->personnages as $p) {
    $e = $g->quete_courante_id
        ? \App\Models\EtatPersonnageQuete::where('personnage_id', $p->id)->where('quete_id', $g->quete_courante_id)->first()
        : null;
    echo sprintf("  %-24s %-10s niv%d or=%-4d PV %d/%d MIND %d/%d%s\n",
        $p->nom, $p->classe, $p->niveau, $p->or,
        $p->pv_body, $p->pv_body_max, $p->pv_mind, $p->pv_mind_max,
        $e ? sprintf('  [pos=(%d,%d) a_joue=%d a_agi=%d dep=%d tombe=%d]', $e->position_x, $e->position_y, $e->a_joue, $e->a_agi, $e->deplacement_restant, $e->tombe) : '');
}
foreach (\App\Models\Quete::where('groupe_id', $g->id)->orderBy('id')->get() as $q) {
    echo "  Q{$q->position_arc} #{$q->id} « {$q->titre} » [{$q->type_jalon}] {$q->etat} or_initial={$q->or_initial}\n";
}
if ($g->quete_courante_id) {
    $ms = \App\Models\InstanceMonstre::where('quete_id', $g->quete_courante_id)->get();
    $vivants = $ms->whereNotIn('etat', ['mort', 'vaincu']);
    echo '  MONSTRES: '.$vivants->count().' vivants / '.$ms->count().' total | révélés: '.$ms->where('revele', true)->count()."\n";
    foreach ($vivants as $m) {
        echo sprintf("     #%d %-26s pv=%d/%d pos=(%d,%d) elite=%d revele=%d etat=%s\n",
            $m->id, $m->habillage['nom'] ?? ($m->monstre->nom ?? '?'), $m->pv_body, $m->pv_body_max,
            $m->position_x, $m->position_y, (int) $m->elite, (int) $m->revele, $m->etat);
    }
}
$ev = \App\Models\Evenement::where('groupe_id', $g->id)->latest('id')->take(12)->get()->reverse();
echo "  DERNIERS EVENEMENTS:\n";
foreach ($ev as $e) {
    $d = $e->donnees ?? $e->contenu ?? $e->payload ?? null;
    echo "     #{$e->id} [{$e->type}] ".substr(is_string($d) ? $d : json_encode($d, JSON_UNESCAPED_UNICODE), 0, 170)."\n";
}
