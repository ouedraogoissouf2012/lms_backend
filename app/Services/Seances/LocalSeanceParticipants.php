<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Seance;
use App\Models\User;
use App\Services\Audience\ClasseAudienceSource;
use App\Services\Visio\VisioActorAuthorization;

/**
 * Les participants d'une séance d'école en mode autonome (relevé par Fille 1, parcours
 * des présences). Avant, la route exigeait le jeton KLASSCI : 401.
 *
 * - La séance est cherchée sous son id LOCAL, bornée explicitement à l'établissement de
 *   l'appelant (le scope global est fail-open sans tenant).
 * - Accès : qui gère la séance ({@see VisioActorAuthorization::canManage()}, son auteur
 *   ou la gestion), comme la source KLASSCI, où l'enseignant ne trouve que les séances
 *   de ses matières. Sinon : introuvable (404), sans dire si elle existe.
 * - Élèves : la règle de l'admission ({@see ClasseAudienceSource::studentsOf()}).
 * - `teacher` : l'auteur de la séance. Même forme que la source KLASSCI.
 *
 * Aucun appel à KLASSCI.
 */
final class LocalSeanceParticipants implements SeanceParticipantsReader
{
    public function __construct(
        private readonly ClasseAudienceSource $audience,
        private readonly VisioActorAuthorization $autorisation,
    ) {}

    public function getSeanceParticipantsArray(int $seanceId, User $user): ?array
    {
        $etablissement = $user->institution_id;
        if (! is_int($etablissement)) {
            return null;
        }

        $seance = Seance::query()->where('institution_id', $etablissement)->whereKey($seanceId)->first();
        if ($seance === null || ! $this->autorisation->canManage($seance, $user)) {
            return null;
        }

        $auteur = is_numeric($seance->created_by)
            ? User::query()->where('institution_id', $etablissement)->find((int) $seance->created_by)
            : null;
        $eleves = $this->audience->studentsOf($seance)
            ->map(static fn (User $eleve): array => [
                'id' => $eleve->id,
                'nom' => $eleve->name,
                'email' => $eleve->email,
                'statut' => 'actif',
            ])
            ->values()
            ->all();

        return [
            'seance' => [
                'id' => $seance->id,
                'titre' => $seance->titre,
                'date_seance' => $seance->date_seance,
                'matiere_nom' => $seance->matiere_nom,
                'classe' => ['id' => $seance->classe_id, 'nom' => $seance->classe_nom],
            ],
            'teacher' => $auteur instanceof User ? ['id' => $auteur->id, 'nom' => $auteur->name] : null,
            'students' => $eleves,
            'total_participants' => ($auteur instanceof User ? 1 : 0) + count($eleves),
        ];
    }
}
