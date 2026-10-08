<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Groupe extends Model
{
    protected $table = 'groupes';

    protected $fillable = [
        'identifiant',
        'nom',
        'theme',
        'longueur',
        'nb_quetes_total',
        'plan_campagne',
        'ton',
        'or',
        // Compteur de pitié du passage secret (2026-08-27) : 50 % de base,
        // +10 par carte sans passage, retour à 50 dès qu'on en pose un.
        'chance_passage_secret',
        // Boîte du bestiaire, FIGÉE pour toute la campagne (2026-09-06,
        // phase 6a) — écrite une seule fois par DemarreurQuete::demarrer(),
        // jamais recalculée ensuite. `null` tant qu'aucune quête n'a démarré
        // (ou pour une campagne antérieure à cette colonne) : lire via
        // DemarreurQuete::themeBestiaireDuGroupe(), qui retombe alors sur le
        // calcul historique plutôt que de traiter `null` comme une erreur.
        'theme_bestiaire',
        // Bestiaire MANUEL (2026-09-28) : boîtes cochées à la création, liste
        // vide = jeu de base seul ; `null` = automatique. Lire via
        // App\Partie\BestiaireGroupe::duGroupe(), jamais brut.
        'boites_bestiaire',
        'etat',
        'phase',
        'quete_courante_id',
    ];

    protected function casts(): array
    {
        return [
            'plan_campagne' => 'array',
            'ton' => 'array',
            'boites_bestiaire' => 'array',
        ];
    }

    /** Composition du groupe (+ initiative). */
    public function personnages(): BelongsToMany
    {
        return $this->belongsToMany(Personnage::class, 'groupe_personnages', 'groupe_id', 'personnage_id')
            ->withPivot(['ordre_initiative', 'actif']);
    }

    /** Personnages dont c'est le groupe actif. */
    public function personnagesActifs(): HasMany
    {
        return $this->hasMany(Personnage::class, 'groupe_actif_id');
    }

    public function quetes(): HasMany
    {
        return $this->hasMany(Quete::class, 'groupe_id');
    }

    public function queteCourante(): BelongsTo
    {
        return $this->belongsTo(Quete::class, 'quete_courante_id');
    }

    /** Journal rejouable. */
    public function evenements(): HasMany
    {
        return $this->hasMany(Evenement::class, 'groupe_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(Snapshot::class, 'groupe_id');
    }

    /**
     * Alliés recrutés (mercenaires + compagnons). Depuis le chantier 1c
     * (Wizards of Morcar, René 2026-10-06) les mercenaires RECRUTÉS (pas les
     * captifs scénarisés) PERSISTENT d'une quête à l'autre contre un
     * entretien — voir App\Partie\FaveursHopekins::reglerEntretien() et
     * ResolveurTour::terminerQuete()/echouerQuete(). Seuls les captifs
     * (`mercenaire.captif`) et les morts restent consommés en fin de quête.
     */
    public function mercenaires(): HasMany
    {
        return $this->hasMany(GroupeMercenaire::class, 'groupe_id');
    }

    /**
     * Statut de Gardien (« Warden », livret G1504 p. 8-9, Wizards of Morcar) —
     * débloqué pour TOUT le groupe dès que **2 quêtes sont achevées**
     * (`etat: 'terminee'`) : « Once a hero has become a Warden (after
     * completing Quest 2) ». Calculé en DIRECT sur `quetes` (jamais une
     * colonne ni un cache — ce décompte est déjà une lecture DB déterministe,
     * pas un état à dupliquer) : débloque le recrutement de mercenaires
     * (`MercenaireController::recruter()`) et l'entretien devient dû au
     * premier hub qui suit.
     *
     * ⚠ Décision de René (2026-10-06) : **l'entretien de 10 po/mercenaire/
     * quête s'applique à TOUS les groupes**, pas seulement au thème
     * `wizards_of_morcar` — mais le statut de Gardien (et donc la PORTE
     * d'entrée au recrutement) est lui aussi générique, puisque le modèle
     * économique remplace l'ancien (payant une fois, consommé en fin de
     * quête) PARTOUT. Une campagne déjà en cours garde ses mercenaires déjà
     * recrutés quel que soit ce compteur — jamais retirés rétroactivement.
     */
    public function estGardien(): bool
    {
        return $this->quetes()->where('etat', 'terminee')->count() >= 2;
    }
}
