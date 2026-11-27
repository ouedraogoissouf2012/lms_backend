<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Exceptions\BusinessException;
use App\Models\User;

/**
 * Un compte change SON mot de passe (#1667), selon son école (SourceDuCompte).
 *
 * Le mot de passe ne figure jamais ni dans la réponse ni dans un journal.
 */
interface ChangementDuMotDePasse
{
    /**
     * La porte, AVANT toute lecture du corps : un compte dont le mot de passe n'est pas local est refusé avec son motif,
     * même si le corps est invalide (sinon il recevrait un 422 sur un mot de passe qu'il ne possède pas ici).
     *
     * @throws BusinessException
     */
    public function ouvert(User $compte): void;

    /**
     * @param  int|null  $jetonCourant  l'identifiant du jeton de la requête : il survit, les autres sont révoqués
     */
    public function changer(User $compte, string $nouveau, ?int $jetonCourant): void;
}
