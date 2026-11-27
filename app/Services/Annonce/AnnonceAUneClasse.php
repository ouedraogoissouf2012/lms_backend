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
use App\Services\Classe\KlassciClasseDeLEspaceSource;
use App\Services\Classe\LocalClasseDeLEspaceSource;
use App\Services\Enrollment\EnrollmentSource;
use App\Services\Mail\CanalDeCourriel;
use App\Services\Notification\NotificationDispatcher;
use App\Services\Roster\RosterAuthorityFactory;
use Illuminate\Database\DatabaseManager;

/**
 * Le personnel annonce à TOUTE une classe (#1650), dans les deux types d'école.
 *
 * - La classe se désigne par l'id de l'ESPACE SOURCE de l'école de l'acteur (`ClasseDeLEspaceSource`, #1676) : l'id
 *   KLASSCI dans une école KLASSCI, celui que donnent ses écrans de classe ; l'id local dans une école autonome. Elle
 *   se cherche dans l'école de l'acteur, explicitement : hors de son école, elle est introuvable. La source est
 *   choisie à l'appel, par l'école du compte (ADR-EI-01, règle 3).
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
        private readonly RosterAuthorityFactory $autorites,
    ) {}

    /** La classe désignée par son id dans l'espace source, si elle existe dans l'école de l'acteur. */
    public function classeDe(User $acteur, int $idSource): ?Classe
    {
        if (! is_int($acteur->institution_id)) {
            return null;
        }

        $source = $this->autorites->sourceForAccount($acteur, KlassciClasseDeLEspaceSource::class, LocalClasseDeLEspaceSource::class);

        return $source->trouver($idSource, $acteur->institution_id);
    }

    /** L'acteur peut-il annoncer à cette classe de son école ? */
    public function autorise(User $acteur, Classe $classe): bool
    {
        $role = $acteur->asRoleEnum();

        if (in_array($role, self::TOUTE_CLASSE, true)) {
            return true;
        }

        // SONDE #1650 : la borne « ses classes » retirée.
        return $role === Role::Enseignant;
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
