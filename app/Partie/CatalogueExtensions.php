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
     * Une FICHE par boîte qui tourne : son nom officiel, son ambiance, et ses créatures par palier. Lue par le livret
     * de jeu (`docs/livret/catalogue.sh`) ET, mise en phrase, par le MJ IA (`descriptions()`) : la même source, pour
     * que ce que lit le joueur et ce que lit le MJ ne puissent pas diverger.
     *
     * @return list<array{id: string, libelle: string, ambiance: string, boss: list<string>, sous_boss: list<string>, base: list<string>}>
     */
    public function fiches(): array
    {
        $formesSuivantes = Monstre::nomsDeFormeSuivante();
        $monstres = Monstre::query()->orderBy('id')->get()
            ->reject(fn (Monstre $m) => in_array($m->nom_base, $formesSuivantes, true));

        $fiches = [];
        foreach (DemarreurQuete::BOITES_THEMATIQUES as $boite) {
            $deLaBoite = $monstres->filter(fn (Monstre $m) => in_array($boite, $m->boites(), true));
            $parPalier = fn (string $tier) => $deLaBoite->where('tier', $tier)->pluck('nom_base')->unique()->values()->all();

            $fiches[] = [
                'id' => $boite,
                'libelle' => $this->demarreur->libelleBoiteBestiaire($boite),
                'ambiance' => rtrim((string) config("extensions.descriptions.{$boite}", ''), '. ').'.',
                'boss' => $parPalier('boss'),
                'sous_boss' => $parPalier('sous_boss'),
                'base' => $parPalier('base'),
            ];
        }

        return $fiches;
    }

    /**
     * @return array<string, string> boîte → « Nom officiel — ambiance. Créatures : … »
     */
    public function descriptions(): array
    {
        $descriptions = [];
        foreach ($this->fiches() as $fiche) {
            // L'ordre du catalogue (par id), tous paliers confondus — celui que le MJ lisait déjà.
            $creatures = Monstre::query()->whereIn('nom_base', [...$fiche['boss'], ...$fiche['sous_boss'], ...$fiche['base']])
                ->orderBy('id')->pluck('nom_base')->unique()->implode(', ');

            $descriptions[$fiche['id']] = "{$fiche['libelle']} — {$fiche['ambiance']}"
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
