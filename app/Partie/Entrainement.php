<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Groupe;
use App\Models\Personnage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * L'ENTRAÎNEMENT au hub : +1 à un attribut — Body ou Mind, les dés des jets de test — contre de l'or de la bourse
 * commune, UNE fois par attribut et par héros.
 *
 * René, 2026-11-07 : jusque-là les attributs ne montaient jamais (quatre talents seulement les touchaient) ; parmi les
 * options proposées il retient l'entraînement payant, « seulement +1 par attribut par joueur avec un coût de 500 (on se
 * rend compte qu'on a vite acheté le meilleur équipement pour nos personnages, car on gagne plus d'or que dans le jeu
 * normal) ». Aucune source au plateau : le prix est une décision du projet, écrite ici et nulle part ailleurs.
 *
 * Point de passage UNIQUE : `options()` publie la décision (`GET /moi`), `entrainer()` la revérifie avant de payer —
 * la manette n'offre jamais un bouton que la route refuserait.
 */
final class Entrainement
{
    public const PRIX = 500;

    /** @var array<string, array{colonne: string, marque: string, libelle: string}> */
    public const ATTRIBUTS = [
        'body' => ['colonne' => 'attribut_body', 'marque' => 'entraine_body', 'libelle' => 'Body'],
        'mind' => ['colonne' => 'attribut_mind', 'marque' => 'entraine_mind', 'libelle' => 'Mind'],
    ];

    /**
     * Ce que la fiche affiche : pour chaque attribut, sa valeur, et si l'entraînement est possible MAINTENANT (avec la
     * raison en clair sinon). Vide pour un héros hors groupe.
     *
     * @return list<array{attribut: string, libelle: string, valeur: int, prix: int, possible: bool, raison: ?string}>
     */
    public function options(Personnage $personnage, ?Groupe $groupe): array
    {
        if ($groupe === null) {
            return [];
        }

        return array_map(function (string $attribut) use ($personnage, $groupe) {
            $regle = self::ATTRIBUTS[$attribut];
            $raison = $this->refus($personnage, $groupe, $attribut);

            return [
                'attribut' => $attribut,
                'libelle' => $regle['libelle'],
                'valeur' => (int) $personnage->{$regle['colonne']},
                'prix' => self::PRIX,
                'possible' => $raison === null,
                'raison' => $raison,
            ];
        }, array_keys(self::ATTRIBUTS));
    }

    /**
     * Entraîne : +1 à l'attribut, la bourse paie, la marque est posée — dans UNE transaction, la bourse relue verrouillée
     * (deux manettes qui paient en même temps ne la font jamais passer sous zéro).
     */
    public function entrainer(Groupe $groupe, Personnage $personnage, string $attribut): Personnage
    {
        if (($raison = $this->refus($personnage, $groupe, $attribut)) !== null) {
            throw ValidationException::withMessages(['attribut' => $raison]);
        }

        $regle = self::ATTRIBUTS[$attribut];

        DB::transaction(function () use ($groupe, $personnage, $regle) {
            $bourse = Groupe::query()->whereKey($groupe->id)->lockForUpdate()->value('or');

            if ((int) $bourse < self::PRIX) {
                throw ValidationException::withMessages(['attribut' => 'La bourse commune ne couvre pas l\'entraînement.']);
            }

            Groupe::query()->whereKey($groupe->id)->decrement('or', self::PRIX);
            $personnage->update([
                $regle['colonne'] => (int) $personnage->{$regle['colonne']} + 1,
                $regle['marque'] => true,
            ]);
        });

        return $personnage->refresh();
    }

    /** Pourquoi pas, en clair — `null` quand l'entraînement est possible. */
    private function refus(Personnage $personnage, Groupe $groupe, string $attribut): ?string
    {
        $regle = self::ATTRIBUTS[$attribut] ?? null;

        return match (true) {
            $regle === null => "Attribut inconnu : « {$attribut} » (body ou mind).",
            $groupe->phase !== 'hub' => 'On ne s\'entraîne qu\'au hub, entre deux quêtes.',
            (bool) $personnage->{$regle['marque']} => "{$regle['libelle']} déjà entraîné : une seule fois par attribut.",
            (int) $groupe->or < self::PRIX => 'La bourse commune ne couvre pas l\'entraînement ('.self::PRIX.' po).',
            default => null,
        };
    }
}
