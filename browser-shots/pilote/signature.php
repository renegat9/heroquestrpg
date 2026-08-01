<?php
// Une ligne compacte décrivant l'état de la partie (pour Monitor : on n'émet que sur changement).
$g = \App\Models\Groupe::latest('id')->first();
$pv = [];
foreach ($g->personnages as $p) {
    $pv[] = mb_substr($p->nom, 0, 3).':'.$p->pv_body.'/'.$p->pv_body_max;
}
$q = $g->queteCourante;
$m = $q ? \App\Models\InstanceMonstre::where('quete_id', $q->id)->get() : collect();
echo sprintf("phase=%s or=%d | Q%s=%s[%s] | monstres %d/%d vivants, %d révélés | %s\n",
    $g->phase, $g->or,
    $q ? $q->position_arc : '-', $q ? mb_substr($q->titre, 0, 34) : '-', $q ? $q->etat : '-',
    $m->where('etat', '!=', 'mort')->count(), $m->count(), $m->where('revele', true)->count(),
    implode(' ', $pv));
