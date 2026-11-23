<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\EtatPersonnageQuete;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Equipement;
use App\Partie\Salles;

/**
 * LES GLYPHES MAGIQUES d'une quête construite (mode classique, lot K6 — *First Light*, livret de quêtes).
 *
 * - q. 6 (*The Ring of Return*) note D : « The first player to search for traps discovers the arcane glyphs engraved into
 *   the altar, protecting it. If a hero uses Glyph Key Scroll to destroy the glyphs… » ; détruits par le *Glyph Key
 *   Scroll* (« destroy a discovered magical glyph, no action required. This scroll has no effect on magical glyphs
 *   disturbed by looting. After you use this scroll, it disappears »).
 * - q. 8 (*King Forgrin's Trove*) note C : « You discover a Dread glyph carved into a log. Throwing it into the fireplace
 *   quickly burns the glyph and disarms the trap. »
 *
 * Format : `glyphes: [{id, salle, detruit_par: parchemin|cheminee, drapeau}]`. Trois moments, état en base
 * (`quetes.drapeaux._glyphes`) :
 * - DÉCOUVERT par une fouille de la zone réussie dans sa salle (`ResolveurTour`, à côté de la note D7) ;
 * - DÉTRUIT par le parchemin (consommé) ou à la cheminée de sa salle — pose le drapeau de l'auteur, que ses notes lisent
 *   (`si_drapeau` / `sauf_drapeau` à la fouille du trésor) ;
 * - PILLÉ quand le trésor de sa salle est fouillé alors qu'il est actif : « no effect on magical glyphs disturbed by
 *   looting » — il ne se détruit plus. La conséquence (les flammes, la tempête) est celle que l'auteur écrit en note.
 *
 * Décisions écrites : détruire est un geste LIBRE dans les deux cas (« no action required » pour le parchemin ; le livret
 * ne dit rien pour la bûche) ; il faut se tenir dans la salle du glyphe ; le Feu, c'est la Cheminée posée dans la salle.
 */
final class Glyphes
{
    public const PARCHEMIN = 'parchemin';

    public const CHEMINEE = 'cheminee';

    public const MOYENS = [self::PARCHEMIN, self::CHEMINEE];

    private const ETATS = '_glyphes';

    public function __construct(private readonly Equipement $equipement) {}

    /** @return list<array{id: string, salle: string, detruit_par: string, drapeau: string}> */
    public function definitions(Quete $quete): array
    {
        return $quete->estConstruite() ? array_values((array) ($quete->documentConstruit()['glyphes'] ?? [])) : [];
    }

    public function etat(Quete $quete, string $id): ?string
    {
        $etat = data_get($quete->drapeaux, self::ETATS.'.'.$id);

        return is_string($etat) ? $etat : null;
    }

    /**
     * Une fouille de la zone a réussi dans la salle d'index `$salle` : ses glyphes encore cachés sont découverts.
     *
     * @return list<array<string, mixed>> les annonces
     */
    public function decouvrir(Quete $quete, int $salle, Personnage $personnage): array
    {
        $annonces = [];
        foreach ($this->dansLaSalle($quete, $salle) as $glyphe) {
            if ($this->etat($quete, $glyphe['id']) === null) {
                $this->poser($quete, $glyphe['id'], 'decouvert');
                $annonces[] = ['type' => 'glyphe_decouvert', 'personnage_id' => (int) $personnage->id, 'nom' => $personnage->nom, 'glyphe' => $glyphe['id']];
            }
        }

        return $annonces;
    }

    /** Le trésor de cette salle est fouillé : un glyphe encore actif est « disturbed by looting ». */
    public function piller(Quete $quete, int $salle): void
    {
        foreach ($this->dansLaSalle($quete, $salle) as $glyphe) {
            if ($this->etat($quete, $glyphe['id']) !== 'detruit') {
                $this->poser($quete, $glyphe['id'], 'pille');
            }
        }
    }

    /**
     * Les glyphes que CE héros peut détruire maintenant : découverts, ni détruits ni pillés, dans sa salle, avec le moyen
     * que l'auteur a désigné (le parchemin dans son sac, ou une Cheminée dans la salle). Le menu et le résolveur lisent ceci.
     *
     * @return list<array{id: string, salle: string, detruit_par: string, drapeau: string}>
     */
    public function destructibles(Quete $quete, EtatPersonnageQuete $etat, Personnage $personnage): array
    {
        if ($etat->tombe || $etat->horsPlateau()) {
            return [];
        }
        $ici = $this->indexSalle($quete, (int) $etat->position_x, (int) $etat->position_y);
        if ($ici === null) {
            return [];
        }

        return array_values(array_filter($this->dansLaSalle($quete, $ici), fn (array $g) => $this->etat($quete, $g['id']) === 'decouvert'
            && ($g['detruit_par'] === self::CHEMINEE ? $this->cheminee($quete, $ici) : $this->equipement->parcheminDeGlyphe($personnage) !== null)));
    }

    /**
     * Le héros détruit le glyphe : son drapeau est posé, le parchemin disparaît. Rend le résultat moteur.
     *
     * @return array<string, mixed>
     */
    public function detruire(Quete $quete, EtatPersonnageQuete $etat, Personnage $personnage, string $id): array
    {
        $glyphe = collect($this->destructibles($quete, $etat, $personnage))->firstWhere('id', $id);
        if ($glyphe === null) {
            throw \Illuminate\Validation\ValidationException::withMessages(['option_id' => 'Ce glyphe n\'est pas à ta portée.']);
        }

        if ($glyphe['detruit_par'] === self::PARCHEMIN) {
            // « After you use this scroll, it disappears. »
            $this->equipement->parcheminDeGlyphe($personnage)?->delete();
        }
        $this->poser($quete, $id, 'detruit');
        $quete->refresh()->poserDrapeau((string) $glyphe['drapeau']);

        return ['type' => 'glyphe_detruit', 'personnage_id' => (int) $personnage->id, 'nom' => $personnage->nom,
            'glyphe' => $id, 'par' => $glyphe['detruit_par']];
    }

    /** @return list<array{id: string, salle: string, detruit_par: string, drapeau: string}> */
    private function dansLaSalle(Quete $quete, int $salle): array
    {
        $id = ((array) ($quete->carte?->grille['salles'] ?? []))[$salle]['id'] ?? null;

        return array_values(array_filter($this->definitions($quete), fn (array $g) => ($g['salle'] ?? null) === $id));
    }

    private function indexSalle(Quete $quete, int $x, int $y): ?int
    {
        return Salles::indexDe((array) ($quete->carte?->grille['salles'] ?? []), $x, $y);
    }

    /** Une Cheminée est-elle posée dans cette salle ? */
    private function cheminee(Quete $quete, int $salle): bool
    {
        $ids = \App\Models\Mobilier::where('nom', 'Cheminée')->pluck('id')->map(fn ($i) => (int) $i)->all();

        return collect((array) ($quete->carte?->grille['mobilier'] ?? []))
            ->contains(fn (array $m) => in_array((int) ($m['mobilier_id'] ?? 0), $ids, true) && (int) ($m['salle'] ?? -1) === $salle);
    }

    private function poser(Quete $quete, string $id, string $etat): void
    {
        $drapeaux = (array) $quete->relire('drapeaux')->drapeaux;
        $drapeaux[self::ETATS][$id] = $etat;
        $quete->update(['drapeaux' => $drapeaux]);
    }
}
