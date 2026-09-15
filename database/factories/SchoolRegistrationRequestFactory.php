<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SchoolRequestStatus;
use App\Models\SchoolRegistrationRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Demandes d'ouverture d'école (#803).
 *
 * Aucun identifiant KLASSCI n'est posé, et c'est le point : une demande
 * appartient au monde autonome par construction. Elle n'a pas non plus
 * d'`institution_id` — l'établissement n'existe qu'après validation.
 *
 * L'état par défaut est `en_attente`. Les deux états tranchés existent pour
 * les tests de la validation (ADR-803-02) : sans eux, un test ne pourrait pas
 * vérifier qu'une demande déjà décidée n'est plus modifiable, ni qu'une
 * seconde demande est permise après un refus.
 *
 * @extends Factory<SchoolRegistrationRequest>
 */
final class SchoolRegistrationRequestFactory extends Factory
{
    /** @var class-string<SchoolRegistrationRequest> */
    protected $model = SchoolRegistrationRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ecole = $this->faker->company();

        return [
            'nom_demandeur' => $this->faker->name(),
            'email_demandeur' => $this->faker->unique()->safeEmail(),
            'telephone_demandeur' => '+225 07 '.$this->faker->numerify('## ## ## ##'),
            'nom_ecole' => $ecole,
            'slug_souhaite' => mb_substr($this->faker->unique()->slug(2), 0, 50),
            'usage_prevu' => $this->faker->sentence(12),
            'statut' => SchoolRequestStatus::EnAttente->value,
        ];
    }

    public function validee(): self
    {
        return $this->state(fn (): array => [
            'statut' => SchoolRequestStatus::Validee->value,
            'decide_le' => now(),
        ]);
    }

    public function refusee(): self
    {
        return $this->state(fn (): array => [
            'statut' => SchoolRequestStatus::Refusee->value,
            'decide_le' => now(),
            'motif_refus' => $this->faker->sentence(8),
        ]);
    }
}
