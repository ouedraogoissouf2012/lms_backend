<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesSeanceDeletion;
use App\Services\Seances\SeanceDesignee;
use App\Services\Visio\VisioActorAuthorization;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `DELETE /api/lms/seances/local/{seanceId}` (#1154, ADR-1154-01) : la séance
 * désignée par son id LOCAL, et seulement par lui, dans l'école du compte, quel
 * que soit le mode de l'école.
 *
 * La règle de suppression est celle de la porte historique
 * ({@see AuthorizesSeanceDeletion}) ; seule la source change.
 */
final class DeleteLocalSeanceRequest extends FormRequest
{
    use AuthorizesSeanceDeletion;

    public function authorize(SeanceDesignee $designee, VisioActorAuthorization $auteurs): bool
    {
        return $this->autoriseLaSuppression($designee, $auteurs);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
