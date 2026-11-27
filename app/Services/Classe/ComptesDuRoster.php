<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Models\User;
use App\Services\Seances\KlassciPayload;
use Illuminate\Support\Collection;

/**
 * Le compte LOCAL de chaque élève d'un roster KLASSCI : par son email, sinon par son identité
 * KLASSCI dans l'établissement (revue d'E3 : KLASSCI ne porte pas toujours le même email).
 *
 * Sorti de `TeacherEvaluationResultsService` (#503 : deux requêtes pour tout le roster, aucun
 * N+1) pour servir aussi le bilan d'une classe (#1676) : une seule règle de correspondance.
 */
final class ComptesDuRoster
{
    /**
     * @param  Collection<string, User>  $parEmail
     * @param  Collection<int, User>  $parKlassci
     */
    private function __construct(
        private readonly Collection $parEmail,
        private readonly Collection $parKlassci,
    ) {}

    /** @param  array<int, array<string, mixed>>  $etudiants */
    public static function de(array $etudiants, ?int $etablissement): self
    {
        $emails = array_values(array_filter(
            array_map(static fn (array $e): string => KlassciPayload::toStringOrNull($e['email'] ?? null) ?? '', $etudiants),
            static fn (string $email): bool => $email !== '',
        ));
        $ids = array_values(array_filter(
            array_map(static fn (array $e): ?int => KlassciPayload::toInt($e['id'] ?? null), $etudiants),
            static fn (?int $id): bool => $id !== null,
        ));

        /** @var Collection<string, User> $parEmail */
        $parEmail = $emails === [] ? collect() : User::query()->whereIn('email', $emails)->get()->keyBy('email');
        /** @var Collection<int, User> $parKlassci */
        $parKlassci = $etablissement === null || $ids === [] ? collect() : User::query()
            ->where('institution_id', $etablissement)
            ->whereIn('klassci_id', $ids)
            ->get()
            ->keyBy('klassci_id');

        return new self($parEmail, $parKlassci);
    }

    /** @param  array<string, mixed>  $etudiant */
    public function pour(array $etudiant): ?User
    {
        $email = KlassciPayload::toStringOrNull($etudiant['email'] ?? null) ?? '';

        return ($email !== '' ? $this->parEmail->get($email) : null)
            ?? $this->parKlassci->get(KlassciPayload::toInt($etudiant['id'] ?? null) ?? 0);
    }
}
