<?php

declare(strict_types=1);

use App\Models\Evenement;
use App\Support\Journal;
use Illuminate\Support\Facades\DB;

/*
 * Le numéro de séquence du journal tient quand deux écrivains visent le même (2026-10-31).
 *
 * Un job IA (le squelette de campagne et son prologue) et une action de jeu lisaient le même maximum : l'index unique
 * (groupe_id, sequence) refusait le second, et le job mourait (journal de la pile, 2026-10-31 03:51, groupe 175).
 * Le second écrivain est simulé ici : juste avant l'insertion, une autre ligne prend le numéro visé.
 */

it('retente au numéro suivant quand un autre écrivain a pris le numéro visé', function () {
    $groupe = creerGroupe('table-journal-'.uniqid());
    Journal::ajouter($groupe, 'systeme', ['n' => 1]);

    $intrus = true;
    Evenement::creating(function (Evenement $e) use (&$intrus) {
        if ($intrus) {
            $intrus = false;
            DB::table('evenements')->insert([
                'groupe_id' => $e->groupe_id, 'quete_id' => $e->quete_id, 'sequence' => $e->sequence,
                'type' => 'systeme', 'acteur' => null, 'payload' => json_encode(['intrus' => true]),
                'created_at' => now(),
            ]);
        }
    });

    $evenement = Journal::ajouter($groupe, 'narration', ['texte' => 'prologue']);

    expect($evenement->sequence)->toBe(3)
        ->and(Evenement::where('groupe_id', $groupe->id)->orderBy('sequence')->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and(Evenement::where('groupe_id', $groupe->id)->where('sequence', 3)->value('type'))->toBe('narration');
});
