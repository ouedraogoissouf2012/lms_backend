<?php

declare(strict_types=1);

namespace App\Services\Klassci\TeacherDirectory;

use App\Enums\Role;
use App\Models\User;
use App\Support\Catalogue\SourceLocale;
use Psr\Log\LoggerInterface;

/**
 * La liste des enseignants lue dans la base locale, quand KLASSCI n'en rend
 * aucun : le repli de `GET /proxy/enseignants`.
 *
 * La requête passe par `TeacherDirectoryVisibility::restrictLocal()` : la même
 * grille, bornée explicitement à l'établissement du lecteur.
 *
 * ## L'id est celui d'un ENSEIGNANT KLASSCI (#873)
 *
 * Ce repli ne sert qu'en école KLASSCI, et il imite la liste de KLASSCI : son
 * `id` est l'identifiant d'enseignant KLASSCI (`klassci_enseignant_id`), que le
 * front renvoie en `teacher_id` à `/lms/seances/upcoming`, lu dans ce même
 * espace. L'ancien `klassci_id ?? id` mêlait le numéro de COMPTE KLASSCI et l'id
 * local : le même nombre y désignait un autre enseignant.
 *
 * Un compte sans identité d'enseignant KLASSCI ne peut rien désigner dans ce
 * contrat : il est écarté (l'id reste non nul), et le journal le nomme par son
 * id seul.
 */
final class LocalTeacherDirectory
{
    public function __construct(
        private readonly TeacherDirectoryVisibility $visibility,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return array<string, mixed> */
    public function forReader(User $lecteur): array
    {
        $lignes = $this->visibility
            ->restrictLocal($lecteur, User::query()->whereIn('role', Role::Enseignant->aliases()))
            ->get()
            ->map(static fn (User $user): array => [
                'id' => $user->klassci_enseignant_id,
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

    private function aUneIdentiteDEnseignant(User $user): bool
    {
        if ($user->klassci_enseignant_id !== null) {
            return true;
        }

        $this->logger->warning('Annuaire des enseignants, repli local : compte sans identité d\'enseignant KLASSCI, écarté', [
            'user_id' => $user->id,
        ]);

        return false;
    }
}
