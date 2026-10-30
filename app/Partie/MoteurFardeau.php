<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Des\LanceurDes;
use App\Engine\MotsClesEquipement;
use App\Events\SceneTable;
use App\Models\EtatPersonnageQuete;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Personnage;
use App\Models\Quete;

/**
 * LE FARDEAU DU PORTEUR — le jet automatique du début du tour d'un porteur d'objet maudit (Talisman du Savoir maudit,
 * Prophecy of Telor p. 10). Ancrage GÉNÉRIQUE « objet porté, début du tour du porteur », clé
 * `MotsClesEquipement::FARDEAU_DEBUT_DE_TOUR` ; précédent : le voile d'ombre (`MoteurOmbre::debutDeTour()`), appelé
 * depuis le menu du porteur, sur la même garde (ni tombé, ni déjà joué, c'est son tour).
 *
 * Règles (texte du livret, mot pour mot dans `MotsClesEquipement::FARDEAU_DEBUT_DE_TOUR`) :
 *  - deux dés rouges ; total ≥ Mind ACTUEL → rien ; total < Mind ACTUEL → 1 PV de Body perdu ;
 *  - +1 au total par héros dans la même salle ou le même couloir, +2 par héros ADJACENT (contact orthogonal). Un
 *    compagnon à la fois dans la salle et au contact compte pour les deux : le texte dit « and », pas « or ».
 *
 * Décisions d'interprétation, écrites : seuls les compagnons DEBOUT comptent (un héros tombé ne fait pas compagnie) ;
 * le jet a lieu une fois par tour du porteur (marqueur `capacites_tour` de SON état, remis à zéro à chaque manche par
 * `ResolveurTour::ouvrirNouveauTour()` : état durable en base, jamais en cache). Le marqueur est posé AVANT le jet, si
 * bien qu'un menu régénéré ne relance rien, même si le premier passage a fait tomber le porteur. Un porteur TOMBÉ ne
 * lance pas.
 *
 * Annoncé à trois endroits, tous lus sur le même payload : le journal (rejeu), le fil (`TamponAnnonces`, texte de
 * `JournalCombat::ligneType()`), et la table (`SceneDeTable::fardeau()`, scène diffusée tout de suite — le menu n'a pas
 * de résolution ouverte pour la porter).
 */
final class MoteurFardeau
{
    /** Marqueur de `capacites_tour` : « ce porteur a déjà lancé son fardeau cette manche », suffixé de l'inventaire. */
    public const MARQUEUR = 'fardeau_porteur';

    public function __construct(
        private readonly LanceurDes $des,
        private readonly MoteurDegats $degats,
        private readonly TamponAnnonces $annonces,
        private readonly SceneDeTable $scenes,
    ) {}

    /**
     * Le jet de début de tour, si ce porteur en a un à faire maintenant.
     *
     * @return array<string, mixed>|null le payload annoncé, ou `null` si rien ne se joue à ce moment
     */
    public function debutDeTour(Groupe $groupe, Quete $quete, Personnage $porteur, EtatPersonnageQuete $etat): ?array
    {
        if ($etat->tombe) {
            return null; // un porteur tombé ne joue pas : il ne subit pas non plus son début de tour
        }

        $ligne = $this->pieceAvecFardeau($porteur);

        if ($ligne === null) {
            return null;
        }

        $marqueur = self::MARQUEUR.':'.$ligne->id;
        $duTour = (array) ($etat->capacites_tour ?? []);

        if (in_array($marqueur, $duTour, true)) {
            return null; // déjà lancé cette manche : le menu se régénère, le jet ne se refait pas
        }

        $etat->update(['capacites_tour' => array_values(array_unique([...$duTour, $marqueur]))]);

        $params = (array) $ligne->objet->effet[MotsClesEquipement::FARDEAU_DEBUT_DE_TOUR];

        $des = [];

        for ($i = 0, $nombre = (int) ($params['des'] ?? 2); $i < $nombre; $i++) {
            $des[] = $this->des->d6();
        }

        $bonus = $this->bonusDesCompagnons($quete, $porteur, $etat, $params);
        $total = array_sum($des) + $bonus;
        $mind = (int) $porteur->pv_mind;
        $perd = $total < $mind;

        $pertes = $perd
            ? $this->degats->infligerAHeros(
                $porteur,
                (int) ($params['perte_pv_body'] ?? 1),
                MoteurDegats::SOURCE_FARDEAU,
                ['piece' => (string) $ligne->objet->nom],
            )
            : 0;

        // À 0 PV de Body, le porteur TOMBE : relevable, il ne meurt pas. Même chute que le poison
        // (`ResolveurTour::saignerParConditions()`, « C4 : à terre, relevable ») — `MoteurDegats` ne pose pas `tombe`.
        if ($perd && (int) $porteur->fresh()->pv_body === 0) {
            $etat->update(['tombe' => true]);
        }

        $etat->refresh();

        $payload = [
            'type' => 'fardeau_porteur',
            'personnage' => (string) $porteur->nom,
            'personnage_id' => (int) $porteur->id,
            'piece' => (string) $ligne->objet->nom,
            'des' => $des,
            'compagnons' => $bonus,
            'total' => $total,
            'mind' => $mind,
            'perd_pv_body' => $perd,
            'pv_body_perdus' => $pertes,
            'tombe' => (bool) $etat->tombe,
            'texte' => $this->texte((string) $porteur->nom, $total, $mind, $perd, $pertes, (bool) $etat->tombe),
        ];

        $this->annonces->annoncer($groupe, $payload, [
            'type' => 'personnage', 'id' => (int) $porteur->id, 'nom' => (string) $porteur->nom,
        ]);

        $sequence = (int) Evenement::query()->where('groupe_id', $groupe->id)->max('sequence');
        broadcast(new SceneTable($groupe, $this->scenes->fardeau($payload, $porteur), $sequence));

        return $payload;
    }

    /** La pièce PORTÉE qui porte la clé de fardeau, ou `null`. Une pièce au sac ne pèse pas. */
    private function pieceAvecFardeau(Personnage $porteur): ?Inventaire
    {
        return $porteur->inventaire()->with('objet')
            ->whereIn('emplacement', Equipement::SLOTS)
            ->get()
            ->first(fn (Inventaire $l) => $l->objet !== null
                && isset($l->objet->effet[MotsClesEquipement::FARDEAU_DEBUT_DE_TOUR]));
    }

    /**
     * Le bonus des compagnons : `compagnon_meme_zone` par héros DEBOUT dans la même salle ou le même couloir, et
     * `compagnon_adjacent` par héros debout AU CONTACT (`Grille::sontAdjacentes()`, le seul test de contact).
     *
     * @param  array<string, mixed>  $params
     */
    private function bonusDesCompagnons(Quete $quete, Personnage $porteur, EtatPersonnageQuete $etat, array $params): int
    {
        if ($etat->position_x === null || $etat->position_y === null) {
            return 0;
        }

        $x = (int) $etat->position_x;
        $y = (int) $etat->position_y;
        $salles = (array) ($quete->carte?->grille['salles'] ?? []);
        $salle = Salles::indexDe($salles, $x, $y);
        $couloir = $salle === null ? Salles::couloirDe($salles, FabriqueGrille::pour($quete), $x, $y) : [];

        $bonus = 0;

        $compagnons = $quete->etatsPersonnages()
            ->where('tombe', false)
            ->where('personnage_id', '!=', $porteur->id)
            ->get();

        foreach ($compagnons as $compagnon) {
            if ($compagnon->position_x === null || $compagnon->position_y === null) {
                continue;
            }

            $cx = (int) $compagnon->position_x;
            $cy = (int) $compagnon->position_y;

            $memeZone = $salle !== null
                ? Salles::indexDe($salles, $cx, $cy) === $salle
                : Salles::indexDe($salles, $cx, $cy) === null && $this->dans($couloir, $cx, $cy);

            if ($memeZone) {
                $bonus += (int) ($params['compagnon_meme_zone'] ?? 0);
            }

            if (Grille::sontAdjacentes($x, $y, $cx, $cy)) {
                $bonus += (int) ($params['compagnon_adjacent'] ?? 0);
            }
        }

        return $bonus;
    }

    /** @param  list<array{x: int, y: int}>  $cases */
    private function dans(array $cases, int $x, int $y): bool
    {
        foreach ($cases as $case) {
            if ($case['x'] === $x && $case['y'] === $y) {
                return true;
            }
        }

        return false;
    }

    private function texte(string $nom, int $total, int $mind, bool $perd, int $pertes, bool $tombe): string
    {
        if (! $perd) {
            return "{$nom} tient bon : {$total}, pas sous son Mind de {$mind}.";
        }

        return "{$nom} sent le talisman se resserrer : {$total} sous son Mind de {$mind} — il perd {$pertes} PV de Body"
            .($tombe ? ' et tombe.' : '.');
    }
}
