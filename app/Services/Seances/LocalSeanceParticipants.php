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
 * - L'auteur, les élèves et le total : UNE règle, partagée avec le détail de la séance
 *   ({@see ParticipantsDeSeanceLocale}). Cette route ne garde que sa forme (`email`,
 *   `statut`, `total_participants`).
 *   Écart déclaré, antérieur : les élèves sont ceux du pivot ACTIF
 *   ({@see ClasseAudienceSource::studentsOf()}), alors que l'admission à la visio
 *   ({@see ClasseAudienceSource::containsStudent()}) passe par la source composite des
 *   inscriptions, qui retombe sur `user_classes` dès que l'élève n'a aucune ligne de pivot
 *   ACTIVE. Dans une école d'origine KLASSCI passée en mode autonome, un tel élève est
 *   admis à la visio, mais absent d'ici. Dans une école créée depuis le LMS (pivot seul),
 *   les deux règles coïncident.
 * - `teacher` : l'auteur de la séance, `null` sans auteur valide. Même forme que la
 *   source KLASSCI, pas le même sens : KLASSCI y nomme l'appelant.
 * - `titre` : `null` s'il est absent ou vide, la règle de la forme du calendrier (#1061).
 *
 * Aucun appel à KLASSCI.
 */
final class LocalSeanceParticipants implements SeanceParticipantsReader
{
    public function __construct(
        private readonly ParticipantsDeSeanceLocale $participants,
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

        $lus = $this->participants->de($seance);
        $auteur = $lus['auteur'];

        return [
            'seance' => [
                'id' => $seance->id,
                'titre' => is_string($seance->titre) && $seance->titre !== '' ? $seance->titre : null,
                'date_seance' => $seance->date_seance,
                'matiere_nom' => $seance->matiere_nom,
                'classe' => ['id' => $seance->classe_id, 'nom' => $seance->classe_nom],
            ],
            'teacher' => $auteur === null ? null : ['id' => $auteur->id, 'nom' => $auteur->name],
            'students' => array_map(static fn (User $eleve): array => [
                'id' => $eleve->id,
                'nom' => $eleve->name,
                'email' => $eleve->email,
                'statut' => 'actif',
            ], $lus['eleves']),
            'total_participants' => $lus['total'],
        ];
    }
}
