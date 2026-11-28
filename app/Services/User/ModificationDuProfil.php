<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Exceptions\BusinessException;
use App\Models\User;

/**
 * Un compte modifie SON profil (#1667) : nom affiché et téléphone, selon son école (SourceDuCompte).
 */
interface ModificationDuProfil
{
    /**
     * La porte, AVANT toute lecture du corps : un profil qui n'est pas local est refusé avec son motif.
     *
     * @throws BusinessException
     */
    public function ouvert(User $compte): void;

    /**
     * @param  array{name?: string, phone?: string|null}  $champs  les seuls champs présents dans la requête
     */
    public function modifier(User $compte, array $champs): User;
}
