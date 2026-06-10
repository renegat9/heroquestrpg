<?php

declare(strict_types=1);

namespace App\Engine\Des;

/**
 * Lanceur déterministe pour les tests : rejoue une file de valeurs de d6
 * fournie à l'avance (1-6). Les dés de combat sont dérivés de la même file
 * via la répartition officielle (1-3 crâne, 4-5 bouclier blanc, 6 bouclier noir).
 *
 * Lève une exception si la file est épuisée : un test ne doit jamais
 * consommer plus de hasard que prévu.
 */
final class LanceurDeterministe implements LanceurDes
{
    /** @var list<int> */
    private array $valeurs = [];

    /**
     * @param list<int> $valeurs valeurs de d6 (1-6) servies dans l'ordre
     */
    public function __construct(array $valeurs = [])
    {
        $this->ajouter(...$valeurs);
    }

    /**
     * Construit la file directement depuis des faces de combat (tests lisibles).
     */
    public static function depuisFaces(FaceDeCombat ...$faces): self
    {
        return new self(array_map(
            static fn (FaceDeCombat $face): int => $face->versD6(),
            $faces,
        ));
    }

    public function ajouter(int ...$valeurs): void
    {
        foreach ($valeurs as $valeur) {
            if ($valeur < 1 || $valeur > 6) {
                throw new \InvalidArgumentException(
                    "Valeur de d6 invalide : {$valeur} (attendu 1-6)."
                );
            }
            $this->valeurs[] = $valeur;
        }
    }

    public function d6(): int
    {
        if ($this->valeurs === []) {
            throw new \RuntimeException(
                'Lanceur déterministe épuisé : aucune valeur restante.'
            );
        }

        return array_shift($this->valeurs);
    }

    public function deCombat(): FaceDeCombat
    {
        return FaceDeCombat::depuisD6($this->d6());
    }

    public function desCombat(int $nombre): array
    {
        if ($nombre < 0) {
            throw new \InvalidArgumentException("Nombre de dés invalide : {$nombre}.");
        }

        $faces = [];
        for ($i = 0; $i < $nombre; $i++) {
            $faces[] = $this->deCombat();
        }

        return $faces;
    }

    public function valeursRestantes(): int
    {
        return count($this->valeurs);
    }
}
