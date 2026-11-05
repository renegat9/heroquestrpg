<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Groupe;
use App\Models\Monstre;

/**
 * Les extensions telles que le MJ IA les lit pour choisir le bestiaire d'une campagne (René, 2026-11-05). Une ligne par
 * boîte qui tourne : son nom officiel, son ambiance (`config/extensions.php`) et ses créatures, lues dans le catalogue.
 *
 * ⚠ Les créatures ne sont jamais recopiées dans la config : une ligne retirée du bestiaire disparaît d'ici d'elle-même.
 * Les formes suivantes d'un monstre à phases (Demonspider…) sont tues : on ne les rencontre qu'en jeu, jamais d'entrée.
 */
final class CatalogueExtensions
{
    public function __construct(private readonly DemarreurQuete $demarreur) {}

    /**
     * @return array<string, string> boîte → « Nom officiel — ambiance. Créatures : … »
     */
    public function descriptions(): array
    {
        $formesSuivantes = Monstre::nomsDeFormeSuivante();
        $monstres = Monstre::query()->orderBy('id')->get()
            ->reject(fn (Monstre $m) => in_array($m->nom_base, $formesSuivantes, true));

        $descriptions = [];
        foreach (DemarreurQuete::BOITES_THEMATIQUES as $boite) {
            $creatures = $monstres->filter(fn (Monstre $m) => in_array($boite, $m->boites(), true))
                ->pluck('nom_base')->unique()->implode(', ');
            $ambiance = rtrim((string) config("extensions.descriptions.{$boite}", ''), '. ');

            $descriptions[$boite] = $this->demarreur->libelleBoiteBestiaire($boite).' — '.$ambiance.'.'
                .($creatures === '' ? '' : " Créatures : {$creatures}.");
        }

        return $descriptions;
    }

    /**
     * Ce que le squelette de campagne reçoit : le choix est-il ouvert, les boîtes retenues à ce jour (le tirage, ou la
     * sélection des joueurs), et le catalogue décrit.
     *
     * @return array{choix_ouvert: bool, retenues: list<string>, catalogue: array<string, string>}
     */
    public function pourSquelette(Groupe $groupe): array
    {
        return [
            'choix_ouvert' => BestiaireGroupe::choixOuvertAuMj($groupe),
            'retenues' => BestiaireGroupe::duGroupe($groupe)->boites,
            'catalogue' => $this->descriptions(),
        ];
    }
}
