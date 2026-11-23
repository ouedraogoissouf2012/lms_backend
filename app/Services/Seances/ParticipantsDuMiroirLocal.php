<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Enums\Role;
use App\Models\Seance;
use App\Models\UserClass;
use App\Support\Logging\CaughtExceptionLog;

/**
 * Les élèves d'une séance lus dans le miroir local de sa classe KLASSCI : le repli de
 * {@see SeanceVisioEnricher::resolveParticipants()} quand KLASSCI ne rend personne.
 *
 * Extrait tel quel de `SeanceVisioEnricher` (#1455) : la méthode y dépassait 40 lignes, ce que
 * le garde de longueur ne voyait pas, et le fichier aurait dépassé 300 lignes.
 */
final class ParticipantsDuMiroirLocal
{
    public function __construct(
        private readonly CaughtExceptionLog $journal,
    ) {}

    /**
     * Aucun élève si la lecture échoue : l'échec est journalisé.
     *
     * @return array<int, array<string, mixed>>
     */
    public function de(Seance $visioData): array
    {
        try {
            $localStudents = UserClass::where('klassci_classe_id', $visioData->klassci_classe_id)
                ->join('users', 'user_classes.user_id', '=', 'users.id')
                ->whereIn('users.role', Role::Etudiant->aliases())
                ->select('users.id', 'users.name', 'users.email', 'users.klassci_id')
                ->get();

            return $localStudents->map(function ($student): array {
                $nameParts = explode(' ', $student->name, 2);

                return [
                    'id' => $student->id,
                    'nom' => $nameParts[0] ?? $student->name,
                    'prenom' => $nameParts[1] ?? '',
                    'email' => $student->email,
                    'klassci_id' => $student->klassci_id,
                    'statut' => 'actif',
                ];
            })->toArray();
        } catch (\Exception $e) {
            $this->journal->error('Erreur fallback BDD pour étudiants', $e, [
                'classe_id' => $visioData->klassci_classe_id,
            ]);

            return [];
        }
    }
}
