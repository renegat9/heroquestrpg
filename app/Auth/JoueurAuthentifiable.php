<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\Joueur;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Adaptateur d'authentification du joueur (login simple, doc 11 §11).
 *
 * Le modèle métier Joueur reste un Model pur ; cette sous-classe (même
 * table `joueurs`) le rend Authenticatable pour le guard de session
 * `joueur` (config/auth.php) et l'autorisation des canaux privés
 * (routes/channels.php). Sanctum n'étant pas installé, l'API s'appuie
 * sur la session (SPA même origine, cookies + CSRF).
 */
class JoueurAuthentifiable extends Joueur implements Authenticatable
{
    use AuthenticatableTrait;

    /** Colonne du hash — `mot_de_passe`, pas `password`. */
    public function getAuthPassword(): string
    {
        return (string) $this->mot_de_passe;
    }

    public function getAuthPasswordName(): string
    {
        return 'mot_de_passe';
    }

    /** Pas de « se souvenir de moi » (pas de colonne remember_token). */
    public function getRememberTokenName(): string
    {
        return '';
    }
}
