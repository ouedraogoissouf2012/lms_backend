<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Models\User;
use App\Rules\AssignableRole;
use Tests\TestCase;

/**
 * Test unitaire pur (sans DB) de la règle {@see AssignableRole}.
 *
 * ## Faille couverte (audit sécurité 2026-07-02 — OWASP A01)
 *
 * `CreateUserRequest` / `UpdateUserRequest` autorisaient `role in:...,superAdmin`
 * tandis que leur `authorize()` n'exige que `isCoordinator() || isAdmin()`. Un
 * coordinateur (permissivité 3) pouvait donc créer — ou s'auto-promouvoir en —
 * `superAdmin`, chaîne mappée par {@see \App\Enums\Role::tryFromString} vers
 * `Role::Supradmin` (permissivité 5, cross-tenant suprême). Escalade verticale
 * directe.
 *
 * La règle impose le **moindre privilège** : on ne peut assigner qu'un rôle
 * STRICTEMENT moins permissif que le sien. Chaque cas limite est figé ici.
 *
 * @see app/Rules/AssignableRole.php
 */
final class AssignableRoleTest extends TestCase
{
    /**
     * Construit un User en mémoire (aucune persistance) avec le rôle voulu.
     * `asRoleEnum()` lit l'attribut `role` sans toucher la base.
     */
    private function actorWithRole(?string $role): User
    {
        $user = new User();
        if ($role !== null) {
            $user->role = $role;
        }

        return $user;
    }

    /**
     * Exécute la règle et retourne le message d'échec, ou null si elle passe.
     */
    private function runRule(?User $actor, mixed $requestedRole): ?string
    {
        $failure = null;
        (new AssignableRole($actor))->validate(
            'role',
            $requestedRole,
            function (string $message) use (&$failure): void {
                $failure = $message;
            },
        );

        return $failure;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function allowedAssignmentsProvider(): array
    {
        return [
            'coordinateur → etudiant'   => ['coordinateur', 'etudiant'],
            'coordinateur → enseignant' => ['coordinateur', 'enseignant'],
            'admin → coordinateur'      => ['admin', 'coordinateur'],
            'admin → enseignant'        => ['admin', 'enseignant'],
            'supradmin → admin'         => ['supradmin', 'admin'],
            'supradmin → coordinateur'  => ['supradmin', 'coordinateur'],
            // Les alias EN doivent être acceptés comme leur équivalent canonique.
            'admin → student (alias)'   => ['admin', 'student'],
        ];
    }

    /**
     * @dataProvider allowedAssignmentsProvider
     */
    public function test_allows_assigning_a_strictly_lower_role(string $actorRole, string $requested): void
    {
        $this->assertNull(
            $this->runRule($this->actorWithRole($actorRole), $requested),
            "L'acteur {$actorRole} devrait pouvoir assigner {$requested}.",
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function blockedAssignmentsProvider(): array
    {
        return [
            // Le cœur de la faille : escalade coordinateur → superAdmin.
            'coordinateur → superAdmin (escalade)' => ['coordinateur', 'superAdmin'],
            'coordinateur → supradmin'             => ['coordinateur', 'supradmin'],
            'coordinateur → admin'                 => ['coordinateur', 'admin'],
            // Auto-promotion / création d'un pair de même niveau : interdite (égalité).
            'coordinateur → coordinateur (égal)'   => ['coordinateur', 'coordinateur'],
            'admin → admin (égal)'                 => ['admin', 'admin'],
            'admin → supradmin'                    => ['admin', 'supradmin'],
            // Personne ne crée un supradmin via cet endpoint (égalité au sommet).
            'supradmin → supradmin (égal)'         => ['supradmin', 'supradmin'],
            // Un enseignant ne peut rien assigner d'égal ou supérieur.
            'enseignant → enseignant (égal)'       => ['enseignant', 'enseignant'],
            'enseignant → coordinateur'            => ['enseignant', 'coordinateur'],
        ];
    }

    /**
     * @dataProvider blockedAssignmentsProvider
     */
    public function test_blocks_assigning_an_equal_or_higher_role(string $actorRole, string $requested): void
    {
        $this->assertNotNull(
            $this->runRule($this->actorWithRole($actorRole), $requested),
            "L'acteur {$actorRole} ne devrait PAS pouvoir assigner {$requested}.",
        );
    }

    public function test_fails_closed_when_actor_is_null(): void
    {
        // Aucun utilisateur authentifié → refus (défense fail-closed).
        $this->assertNotNull($this->runRule(null, 'etudiant'));
    }

    public function test_fails_closed_when_actor_role_is_unrecognized(): void
    {
        // Rôle acteur corrompu / inconnu → aucune assignation permise.
        $this->assertNotNull($this->runRule($this->actorWithRole('hacker'), 'etudiant'));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidRequestedRoleProvider(): array
    {
        return [
            'rôle inconnu'       => ['superuser'],
            'chaîne vide'        => [''],
            'non-string (int)'   => [5],
            'non-string (array)' => [['etudiant']],
            'null'               => [null],
        ];
    }

    /**
     * @dataProvider invalidRequestedRoleProvider
     */
    public function test_rejects_unrecognized_or_non_string_requested_role(mixed $requested): void
    {
        // Acteur légitime le plus permissif, pour isoler la validation de la cible.
        $this->assertNotNull($this->runRule($this->actorWithRole('supradmin'), $requested));
    }
}
