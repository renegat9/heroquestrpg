<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Quete;
use App\Partie\Salles;
use App\Partie\TamponAnnonces;

/**
 * LE RELÈVEMENT — lot E2, *Jungles of Delthrak* q. 4 E (F9907 p. 19) : « The crystal cluster pulses with dread energy. If a
 * Goblin is defeated in this room and the crystal hasn't been destroyed, the Goblin transforms into a Skullblight. Replace
 * the miniature appropriately. »
 *
 * `regles.relevements: [{salle, de, en, sauf_drapeau?}]` : une figure du type `de` (slug du catalogue) vaincue dans la salle
 * `salle` se relève aussitôt, sur sa case, en une figure du type `en` (active, révélée). `sauf_drapeau` : la règle cesse dès
 * que ce drapeau est posé — le cristal détruit, par une note `meuble_detruit` qui pose le drapeau. La figure vaincue le
 * reste (une fin `vaincre` la compte) ; la nouvelle est une figure de plus. Les errants comptent (« a Goblin »).
 *
 * Point de passage : la mort d'un monstre (`MoteurDegats`), AVANT le constat de la salle vidée — une salle où un gobelin se
 * relève n'est pas vide.
 */
final class Relevements
{
    public function __construct(private readonly CatalogueConstructeur $catalogue) {}

    /** @return array<string, mixed>|null l'annonce `relevement`, ou `null` si rien ne se relève */
    public function apresMort(Quete $quete, InstanceMonstre $mort): ?array
    {
        if (! $quete->estConstruite() || $mort->monstre === null) {
            return null;
        }
        $regles = (array) data_get($quete->documentConstruit(), 'regles.relevements', []);
        if ($regles === []) {
            return null;
        }
        $x = $mort->getOriginal('position_x') ?? $mort->position_x;
        $y = $mort->getOriginal('position_y') ?? $mort->position_y;
        if ($x === null || $y === null) {
            return null;
        }
        $grille = (array) $quete->carte?->grille;
        $index = Salles::indexDe((array) ($grille['salles'] ?? []), (int) $x, (int) $y);
        $salle = $index === null ? null : data_get($grille, "salles.{$index}.id");
        $slug = CatalogueConstructeur::slug((string) $mort->monstre->nom_base);

        foreach ($regles as $regle) {
            if (($regle['salle'] ?? null) !== $salle || ($regle['de'] ?? null) !== $slug
                || (isset($regle['sauf_drapeau']) && $quete->aDrapeau((string) $regle['sauf_drapeau']))) {
                continue;
            }
            $en = $this->catalogue->index('monstres')[(string) ($regle['en'] ?? '')] ?? null;
            if (! $en instanceof Monstre) {
                return null;
            }
            $releve = $quete->instancesMonstres()->create([
                'monstre_id' => $en->id, 'pv_body' => (int) $en->pv_body, 'pv_body_max' => (int) $en->pv_body,
                'pv_mind' => (int) $en->pv_mind, 'position_x' => (int) $x, 'position_y' => (int) $y,
                'etat' => 'actif', 'elite' => false, 'revele' => true,
            ]);
            $payload = ['type' => 'relevement', 'de' => $mort->nomAffiche(), 'en' => $releve->nomAffiche(),
                'instance_id' => (int) $releve->id, 'x' => (int) $x, 'y' => (int) $y];
            app(TamponAnnonces::class)->annoncer($quete->groupe, $payload, ['type' => 'monstre', 'id' => $releve->id, 'nom' => $releve->nomAffiche()]);

            return $payload;
        }

        return null;
    }
}
