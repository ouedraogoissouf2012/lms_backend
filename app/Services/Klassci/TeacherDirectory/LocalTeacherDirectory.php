<?php

declare(strict_types=1);

namespace App\Services\Klassci\TeacherDirectory;

use App\Models\User;
use App\Support\Catalogue\SourceLocale;

/**
 * La liste des enseignants lue dans la base locale, quand KLASSCI n'en rend
 * aucun : le repli de `GET /proxy/enseignants`.
 *
 * La requête passe par `TeacherDirectoryVisibility::restrictLocal()` : la même
 * grille, bornée explicitement à l'établissement du lecteur.
 */
final class LocalTeacherDirectory
{
    public function __construct(
        private readonly TeacherDirectoryVisibility $visibility,
    ) {}

    /** @return array<string, mixed> */
    public function forReader(User $lecteur): array
    {
        $lignes = $this->visibility
            ->restrictLocal($lecteur, User::query()->whereIn('role', ['enseignant', 'teacher']))
            ->get()
            ->map(static fn (User $user): array => [
                'id' => $user->klassci_id ?? $user->id,
                'nom' => $user->name,
                'prenom' => '',
                'email' => $user->email,
                'role' => $user->role,
                'source' => SourceLocale::LMS_LOCAL,
            ])
            ->values()
            ->all();

        return [
            'success' => true,
            'data' => $lignes,
            'meta' => [
                'total' => count($lignes),
                'source' => SourceLocale::LMS_LOCAL,
                'note' => 'Données provenant de la BDD locale car KLASSCI retourne 0',
            ],
        ];
    }
}
