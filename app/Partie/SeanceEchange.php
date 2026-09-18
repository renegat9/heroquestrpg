<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Inventaire;
use App\Models\Personnage;
use App\Partie\Marche\CapaciteSac;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La SÉANCE d'échange en quête (doc 01 §7, révision René 2026-09-17) :
 * « transférer armes/armures ENTRE LES DEUX inventaires, dans la limite des
 * capacités RESPECTIVES » — bidirectionnelle, multiple, pour UNE seule
 * action. Remplace la première livraison de `MenuMoteur::generer()` /
 * `ResolveurTour::resoudreEchange()`, qui appelait `DonObjet::donner()` un
 * objet à la fois, cible par cible : la forme du DON AU HUB (unidirectionnel,
 * à l'unité), pas celle de la RÈGLE.
 *
 * ⚠ **La capacité se juge sur l'ÉTAT FINAL, jamais mouvement par mouvement.**
 * C'est la raison d'être de cette classe : deux sacs PLEINS qui échangent
 * deux armures est légal au canon, et pourtant AUCUN ordre d'application ne
 * passerait un contrôle pièce par pièce — `DonObjet::donner()` vérifie
 * `peutRanger()` AVANT chaque mouvement, donc le premier échoue quel que soit
 * le sens choisi. Deadlock garanti sur le cas le plus naturel de la règle.
 * Ici, on calcule le NET des deux sacs (occupation − encombrants sortants +
 * encombrants entrants) UNE SEULE FOIS, sur TOUS les mouvements demandés à la
 * fois, puis on applique — ou on refuse et rien ne bouge.
 *
 * ⚠ **Le seuil n'est PAS `final ≤ capacité` tout court** (correction du
 * contrat, 2026-09-17) : un sac peut être LÉGITIMEMENT en dépassement — un
 * butin de quête passe outre la capacité, et `DonObjet::donner()` le dit dans
 * son propre docblock, « donner est justement la façon de régulariser ». Un
 * seuil naïf interdirait pour toujours à ce héros de se servir de la séance
 * pour se délester — une régression par rapport au don au hub, qui
 * l'autorise déjà. La règle exacte, PAR HÉROS : `final ≤ capacité` **OU**
 * `final ≤ occupation de départ` — « on ne finit pas au-dessus de la
 * capacité, ou on ne s'est pas AGGRAVÉ ». Donner (delta ≤ 0) passe toujours
 * la seconde branche ; recevoir (delta > 0) retombe donc TOUJOURS sur la
 * première, strictement — exactement « le receveur reste vérifié
 * strictement, sinon on déplacerait le problème ».
 *
 * ⚠ **Sans dupliquer `DonObjet`** : la mécanique du mouvement lui-même (pile
 * de consommable fusionnée, ligne déplacée pour préserver les
 * `ameliorations` de Forge) reste UNIQUEMENT dans
 * {@see DonObjet::transferer()} — cette classe ne fait que la CONTRÔLER à une
 * échelle différente (le net de deux sacs plutôt qu'un mouvement unitaire)
 * puis l'appeler, une fois par mouvement, dans UNE transaction atomique.
 * Une règle, un point de passage.
 */
final class SeanceEchange
{
    public function __construct(private readonly DonObjet $donObjet) {}

    /**
     * @param  list<array{inventaire_id: int, vers_personnage_id: int, quantite: int}>  $transferts
     * @return array{donne: list<array{objet: string, quantite: int}>, recu: list<array{objet: string, quantite: int}>}
     */
    public function resoudre(Personnage $moi, Personnage $allie, array $transferts): array
    {
        if ($transferts === []) {
            throw ValidationException::withMessages([
                'parametres' => 'Aucun objet à échanger.',
            ]);
        }

        $ids = array_values(array_unique(array_map(
            fn (array $t) => (int) ($t['inventaire_id'] ?? 0), $transferts,
        )));

        if (count($ids) !== count($transferts)) {
            // Un même `inventaire_id` deux fois rendrait le contrôle de
            // capacité ambigu (quelle quantité RESTE-t-il après le premier
            // mouvement, pour juger le second ?) : la manette publie une
            // pile PAR LIGNE, un mouvement par ligne suffit à tout exprimer.
            throw ValidationException::withMessages([
                'parametres' => 'Un même objet ne peut apparaître qu\'une fois dans l\'échange.',
            ]);
        }

        // Chargées UNE fois, avec leur objet : revalide au passage que
        // `inventaire_id` (whitelist, un client peut inventer le triplet en
        // entier) appartient bien à L'UN DES DEUX héros de la séance.
        $lignes = Inventaire::query()->with('objet')
            ->whereIn('id', $ids)
            ->whereIn('personnage_id', [$moi->id, $allie->id])
            ->get()->keyBy('id');

        // Delta d'ENCOMBREMENT par héros — les consommables ne comptent
        // JAMAIS (doc 01 §7, même filtre que `CapaciteSac::occupation()` :
        // seul l'emplacement `sac` compte). Sortant se retire à celui qui
        // possède la ligne, entrant s'ajoute à l'autre.
        $deltaMoi = 0;
        $deltaAllie = 0;
        $mouvements = [];

        foreach ($transferts as $t) {
            $inventaireId = (int) ($t['inventaire_id'] ?? 0);
            $versId = (int) ($t['vers_personnage_id'] ?? 0);
            $quantiteDemandee = $t['quantite'] ?? 1;

            $ligne = $lignes->get($inventaireId);

            if ($ligne === null) {
                throw ValidationException::withMessages([
                    'parametres' => "Cet objet n'appartient à aucun des deux sacs de l'échange.",
                ]);
            }

            // Une pièce ÉQUIPÉE ne part pas : la ranger d'abord révoque
            // proprement ses dés (`Equipement::desequiper()`) — même règle
            // que `DonObjet::donner()`, non redite, juste revérifiée ici
            // parce que la séance vise DEUX sacs et non un seul.
            if (in_array($ligne->emplacement, Equipement::SLOTS, true)) {
                throw ValidationException::withMessages([
                    'parametres' => "« {$ligne->objet?->nom} » est équipé : range-le d'abord.",
                ]);
            }

            $proprietaireId = (int) $ligne->personnage_id;

            // `vers_personnage_id` doit être L'AUTRE héros de la séance —
            // whitelist n°2, par mouvement : un transfert est un triplet
            // qu'un client peut inventer entièrement.
            $destinataire = match (true) {
                $proprietaireId === (int) $moi->id && $versId === (int) $allie->id => $allie,
                $proprietaireId === (int) $allie->id && $versId === (int) $moi->id => $moi,
                default => null,
            };

            if ($destinataire === null) {
                throw ValidationException::withMessages([
                    'parametres' => 'Transfert illégal : vérifie qui possède quoi, et vers qui.',
                ]);
            }

            if (! is_numeric($quantiteDemandee) || (int) $quantiteDemandee < 1) {
                throw ValidationException::withMessages([
                    'parametres' => 'Quantité invalide : au moins 1.',
                ]);
            }

            $quantite = (int) $quantiteDemandee;

            // ⚠ Reborné contre la ligne EN BASE, jamais contre un chiffre
            // publié plus tôt par le menu (règle R2, valable ici aussi) : la
            // pile a pu bouger entre la proposition et la soumission.
            if ($quantite > (int) $ligne->quantite) {
                throw ValidationException::withMessages([
                    'parametres' => "« {$ligne->objet?->nom} » : il n'y en a pas autant dans ce sac.",
                ]);
            }

            $encombrant = $ligne->emplacement === 'sac';

            if ($encombrant) {
                if ($proprietaireId === (int) $moi->id) {
                    $deltaMoi -= $quantite;
                    $deltaAllie += $quantite;
                } else {
                    $deltaAllie -= $quantite;
                    $deltaMoi += $quantite;
                }
            }

            $mouvements[] = [
                'ligne' => $ligne,
                'destinataire' => $destinataire,
                'quantite' => $quantite,
                'donneur_id' => $proprietaireId,
            ];
        }

        // ⚠ ÉTAT FINAL, jamais coup par coup — voir le docblock de la classe.
        // Refus 422 EN NOMMANT le sac qui déborde, rien n'est appliqué (on
        // n'a encore rien écrit en base à ce stade).
        //
        // ⚠ Seuil à DEUX branches (correction du contrat, 2026-09-17) :
        // `final > capacité` NE SUFFIT PAS à refuser — il faut AUSSI que
        // `final > occupation de départ`, sinon un héros déjà en dépassement
        // (butin de quête) ne pourrait plus jamais se délester par la séance.
        // Donner (delta ≤ 0) rend `final ≤ occupation de départ` toujours
        // vrai : la seconde branche l'accepte quoi qu'il arrive. Recevoir
        // (delta > 0) rend cette branche toujours fausse : seule la
        // première tranche alors, strictement — le receveur reste vérifié
        // sans tolérance, pour ne pas déplacer le problème.
        $capaciteMoi = CapaciteSac::pour($moi);
        $occupationDepartMoi = CapaciteSac::occupation($moi);
        $occupationFinaleMoi = $occupationDepartMoi + $deltaMoi;

        if ($occupationFinaleMoi > $capaciteMoi && $occupationFinaleMoi > $occupationDepartMoi) {
            throw ValidationException::withMessages([
                'parametres' => "Ton sac déborde : {$occupationFinaleMoi}/{$capaciteMoi} après cet échange.",
            ]);
        }

        $capaciteAllie = CapaciteSac::pour($allie);
        $occupationDepartAllie = CapaciteSac::occupation($allie);
        $occupationFinaleAllie = $occupationDepartAllie + $deltaAllie;

        if ($occupationFinaleAllie > $capaciteAllie && $occupationFinaleAllie > $occupationDepartAllie) {
            throw ValidationException::withMessages([
                'parametres' => "Le sac de {$allie->nom} déborde : {$occupationFinaleAllie}/{$capaciteAllie} après cet échange.",
            ]);
        }

        $donne = [];
        $recu = [];

        // UNE seule transaction pour toute la séance : soit tous les
        // mouvements passent, soit aucun — l'atomicité que le contrôle par
        // mouvement de `donner()` ne pouvait pas offrir à un échange croisé.
        DB::transaction(function () use ($mouvements, $moi, &$donne, &$recu) {
            foreach ($mouvements as $m) {
                $nom = $m['ligne']->objet?->nom ?? 'un objet';

                $this->donObjet->transferer($m['ligne'], $m['destinataire'], $m['quantite']);

                if ($m['donneur_id'] === (int) $moi->id) {
                    $donne[] = ['objet' => $nom, 'quantite' => $m['quantite']];
                } else {
                    $recu[] = ['objet' => $nom, 'quantite' => $m['quantite']];
                }
            }
        });

        return ['donne' => $donne, 'recu' => $recu];
    }
}
