<?php

declare(strict_types=1);

namespace App\Services\Annonce;

use App\Enums\Role;
use App\Jobs\EnvoyerLAnnonceParCourriel;
use App\Models\Annonce;
use App\Models\Classe;
use App\Models\Notification;
use App\Models\User;
use App\Services\Audience\ElevesDeLaClasse;
use App\Services\Enrollment\EnrollmentSource;
use App\Services\Mail\CanalDeCourriel;
use App\Services\Notification\NotificationDispatcher;
use Illuminate\Database\DatabaseManager;

/**
 * Le personnel annonce à TOUTE une classe (#1650), dans les deux types d'école.
 *
 * - La classe se cherche dans l'école de l'acteur, explicitement : hors de son école, elle est introuvable.
 * - L'admin, le superAdmin et le coordinateur annoncent à toute classe de leur école ; l'enseignant à SES classes, celles
 *   que lui donne la source d'inscription de l'école (`EnrollmentSource::classeIdsForTeacher`).
 * - Les destinataires sont les élèves inscrits et actifs de la classe, selon la même source ({@see ElevesDeLaClasse}) :
 *   la règle qui admet un élève dans sa classe est celle qui le fait prévenir.
 * - L'annonce et ses notifications s'écrivent ensemble, dans une transaction. Le courriel part APRÈS, dans une tâche en
 *   file, et seulement si le canal existe ({@see CanalDeCourriel}) : une panne de courriel ne défait pas l'annonce.
 */
final class AnnonceAUneClasse
{
    /** Les rôles qui annoncent à toute classe de leur école. */
    private const TOUTE_CLASSE = [Role::Coordinateur, Role::Admin, Role::SuperAdmin];

    public function __construct(
        private readonly EnrollmentSource $inscriptions,
        private readonly ElevesDeLaClasse $eleves,
        private readonly NotificationDispatcher $notifications,
        private readonly CanalDeCourriel $courriel,
        private readonly DatabaseManager $db,
    ) {}

    /** La classe, si elle existe dans l'école de l'acteur. */
    public function classeDe(User $acteur, int $classeId): ?Classe
    {
        if (! is_int($acteur->institution_id)) {
            return null;
        }

        return Classe::query()->whereKey($classeId)->where('institution_id', $acteur->institution_id)->first();
    }

    /** L'acteur peut-il annoncer à cette classe de son école ? */
    public function autorise(User $acteur, Classe $classe): bool
    {
        $role = $acteur->asRoleEnum();

        if (in_array($role, self::TOUTE_CLASSE, true)) {
            return true;
        }

        return $role === Role::Enseignant
            && in_array($classe->getKey(), $this->inscriptions->classeIdsForTeacher($acteur), true);
    }

    /**
     * @return array{annonce: Annonce, courriel: bool}
     */
    public function publier(User $acteur, Classe $classe, string $titre, string $message): array
    {
        $ecole = (int) $classe->institution_id;
        $destinataires = $this->eleves->de($classe, $ecole);

        $annonce = $this->db->transaction(function () use ($acteur, $classe, $ecole, $titre, $message, $destinataires): Annonce {
            $annonce = Annonce::query()->create([
                'institution_id' => $ecole,
                'classe_id' => $classe->getKey(),
                'auteur_id' => $acteur->getKey(),
                'titre' => $titre,
                'message' => $message,
                'destinataires' => $destinataires->count(),
            ]);
            $this->notifications->sendToMany($destinataires, Notification::TYPE_ANNONCE, $titre, $message, [
                'annonce_id' => $annonce->getKey(),
                'classe_id' => $classe->getKey(),
            ]);

            return $annonce;
        });

        $courriel = $destinataires->isNotEmpty() && $this->courriel->estDisponible();
        if ($courriel) {
            $ids = [];
            foreach ($destinataires as $eleve) {
                $id = $eleve->getKey();
                if (is_int($id)) {
                    $ids[] = $id;
                }
            }
            EnvoyerLAnnonceParCourriel::dispatch($annonce->id, $ids);
        }

        return ['annonce' => $annonce, 'courriel' => $courriel];
    }
}
