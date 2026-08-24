<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Attempts;

use App\Models\QuizAttempt;
use App\Services\Attempts\AttemptConflictGuard;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests unitaires de {@see AttemptConflictGuard} — le mécanisme partagé qui
 * transforme une violation d'unicité (course concurrente sur une table de
 * tentatives) en décision métier au lieu d'un 500 (#540).
 *
 * Aucun accès base : le guard ne reçoit que des closures, il est donc testable
 * en isolation stricte (§1.3 « happy path + edge case »).
 */
final class AttemptConflictGuardTest extends TestCase
{
    private AttemptConflictGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new AttemptConflictGuard();
    }

    /** Fabrique une violation d'unicité identique à celle levée par Laravel. */
    private function uniqueViolation(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException(
            'sqlite',
            'insert into "quiz_attempts" ...',
            [],
            new PDOException('UNIQUE constraint failed'),
        );
    }

    public function test_insertion_reussie_retourne_un_resultat_cree(): void
    {
        $attempt = new QuizAttempt();

        $outcome = $this->guard->insert(fn (): QuizAttempt => $attempt, fn (): ?QuizAttempt => null);

        $this->assertNotNull($outcome);
        $this->assertSame($attempt, $outcome->attempt());
        $this->assertFalse($outcome->isResolved());
    }

    public function test_violation_unicite_avec_gagnante_retrouvee_retourne_un_resultat_resolu(): void
    {
        $winner = new QuizAttempt();

        $outcome = $this->guard->insert(
            function (): QuizAttempt {
                throw $this->uniqueViolation();
            },
            fn (): QuizAttempt => $winner,
        );

        $this->assertNotNull($outcome);
        $this->assertSame($winner, $outcome->attempt());
        $this->assertTrue($outcome->isResolved());
    }

    /**
     * Course perdue et rien à reprendre : `null`. L'absence d'issue EST le
     * conflit — l'appelant n'a aucun moyen de confondre ce cas avec une
     * tentative valide.
     */
    public function test_violation_unicite_sans_gagnante_retourne_null(): void
    {
        $outcome = $this->guard->insert(
            function (): QuizAttempt {
                throw $this->uniqueViolation();
            },
            fn (): ?QuizAttempt => null,
        );

        $this->assertNull($outcome);
    }

    public function test_sans_resolveur_la_violation_retourne_null(): void
    {
        $outcome = $this->guard->insert(function (): QuizAttempt {
            throw $this->uniqueViolation();
        });

        $this->assertNull($outcome);
    }

    /**
     * Edge case critique : le guard ne doit PAS avaler les autres erreurs base.
     * Une colonne manquante ou une FK violée reste une anomalie à remonter — la
     * masquer en « conflit métier » cacherait un vrai bug (cf. le piège de la
     * colonne fantôme, verte sous SQLite et rouge sous MySQL).
     */
    public function test_une_erreur_sql_non_unicite_nest_pas_avalee(): void
    {
        $this->expectException(QueryException::class);

        $this->guard->insert(function (): QuizAttempt {
            throw new QueryException(
                'sqlite',
                'insert into "quiz_attempts" ...',
                [],
                new PDOException('no such column: ghost'),
            );
        });
    }

    /** Une exception métier quelconque traverse le guard sans transformation. */
    public function test_une_exception_metier_traverse_le_guard(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('panne applicative');

        $this->guard->insert(function (): QuizAttempt {
            throw new RuntimeException('panne applicative');
        });
    }
}
