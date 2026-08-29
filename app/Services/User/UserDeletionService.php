<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\ConnectionInterface;

/**
 * Suppression LOGIQUE d'un utilisateur (#566 / #571).
 *
 * `DELETE /users/{id}` était un hard delete : les FK `onDelete('cascade')`
 * détruisaient le dossier académique (quiz_attempts, evaluation_submissions,
 * forum_posts, notifications). Ce service transforme la suppression en soft delete
 * réversible, révoque les SESSIONS EN COURS (jetons Sanctum) et trace l'opération
 * avec le décompte des enregistrements conservés.
 *
 * Portée de la révocation : elle coupe l'accès immédiat (jetons courants + login
 * local refusé via le SoftDeletingScope). Ce n'est PAS un bannissement définitif :
 * KLASSCI reste la source de vérité de l'identité — un titulaire encore valide côté
 * KLASSCI est RESTAURÉ à son prochain login ({@see KlassciUserSynchronizer}).
 * L'exclusion d'accès permanente relève de la désactivation côté KLASSCI (IdP).
 *
 * Pourquoi un service et pas le contrôleur (§5) : le contrôleur ne porte pas de
 * logique métier ; l'atomicité (révocation ↔ soft delete) et l'audit doivent être
 * testables unitairement.
 *
 * DI strict (§1.6 D) : `ConnectionInterface` pour l'atomicité, `AuditLogger` pour
 * la traçabilité — aucune Facade, aucun `new`.
 *
 * @see app/Http/Controllers/API/AdminController.php
 * @see app/Console/Commands/PurgeSoftDeletedUsers.php (purge physique délibérée)
 */
final class UserDeletionService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Soft delete l'utilisateur : jetons révoqués + audit, atomiquement.
     *
     * L'ordre importe : on compte AVANT de supprimer, on révoque les jetons et on
     * soft-delete dans la MÊME transaction — jamais « supprimé mais jetons vivants ».
     */
    public function softDelete(User $user): void
    {
        $this->db->transaction(function () use ($user): void {
            $preserved = $this->countPreservedRecords($user);

            // R3 : couper l'accès immédiatement (sinon jeton Sanctum valide 7 j).
            $user->tokens()->delete();
            $user->delete();

            // R4 : trace « qui/quoi » + preuve que rien n'a été détruit en cascade.
            $this->audit->logSecurityEvent('user.soft_deleted', $user, [
                'preserved' => $preserved,
            ]);
        });
    }

    /**
     * Décompte des enregistrements liés conservés.
     *
     * On lève le scope tenant : ce sont les enregistrements DE l'utilisateur,
     * indépendamment du tenant courant (le décompte doit être exact même appelé
     * hors contexte HTTP, ex. commande console).
     *
     * @return array<string, int>
     */
    private function countPreservedRecords(User $user): array
    {
        return [
            'quiz_attempts' => $user->quizAttempts()->withoutGlobalScope('institution')->count(),
            'evaluation_submissions' => $user->evaluationSubmissions()->withoutGlobalScope('institution')->count(),
            'forum_posts' => $user->forumPosts()->withoutGlobalScope('institution')->count(),
            'notifications' => $user->notifications()->withoutGlobalScope('institution')->count(),
        ];
    }
}
