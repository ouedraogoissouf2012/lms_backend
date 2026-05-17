<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Concerns;

use App\Http\Requests\Concerns\ChecksFileAuthorization;
use App\Models\File;
use App\Models\User;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for {@see ChecksFileAuthorization}.
 *
 * Two methods (canReadFile, canModerateFile) with the same defense-in-depth
 * decision tree. canReadFile has an additional is_public bypass intra-tenant.
 *
 * Reference: .claude/specs/file-idor-cross-tenant/design.md §4.1
 */
#[CoversTrait(ChecksFileAuthorization::class)]
final class ChecksFileAuthorizationTest extends TestCase
{
    private function subject(): object
    {
        return new class () {
            use ChecksFileAuthorization {
                canReadFile as public;
                canModerateFile as public;
            }
        };
    }

    private function user(int $id, ?int $institutionId, string $role): User
    {
        return (new User())->forceFill([
            'id' => $id,
            'institution_id' => $institutionId,
            'role' => $role,
        ]);
    }

    private function file(int $userId, ?int $institutionId, bool $isPublic = false): File
    {
        return (new File())->forceFill([
            'user_id' => $userId,
            'institution_id' => $institutionId,
            'is_public' => $isPublic,
        ]);
    }

    // ---------------- canReadFile ----------------

    public function test_canReadFile_deny_when_all_null(): void
    {
        self::assertFalse($this->subject()->canReadFile(null, null));
    }

    public function test_canReadFile_deny_when_user_null(): void
    {
        self::assertFalse($this->subject()->canReadFile($this->file(1, 1), null));
    }

    public function test_canReadFile_allow_supradmin_cross_tenant(): void
    {
        self::assertTrue($this->subject()->canReadFile(
            $this->file(99, 7),
            $this->user(1, 1, 'supradmin'),
        ));
    }

    public function test_canReadFile_allow_owner_intra_tenant(): void
    {
        self::assertTrue($this->subject()->canReadFile(
            $this->file(42, 3),
            $this->user(42, 3, 'etudiant'),
        ));
    }

    public function test_canReadFile_allow_public_intra_tenant(): void
    {
        self::assertTrue($this->subject()->canReadFile(
            $this->file(99, 3, isPublic: true),
            $this->user(1, 3, 'etudiant'),
        ));
    }

    public function test_canReadFile_deny_student_non_public_non_owner_intra_tenant(): void
    {
        self::assertFalse($this->subject()->canReadFile(
            $this->file(99, 3, isPublic: false),
            $this->user(1, 3, 'etudiant'),
        ));
    }

    public function test_canReadFile_deny_admin_cross_tenant(): void
    {
        self::assertFalse($this->subject()->canReadFile(
            $this->file(99, 5),
            $this->user(1, 3, 'admin'),
        ));
    }

    public function test_canReadFile_allow_admin_intra_tenant(): void
    {
        self::assertTrue($this->subject()->canReadFile(
            $this->file(99, 3),
            $this->user(1, 3, 'admin'),
        ));
    }

    // ---------------- canModerateFile ----------------

    public function test_canModerateFile_deny_student_on_public_file_intra_tenant(): void
    {
        // is_public does NOT grant moderation rights
        self::assertFalse($this->subject()->canModerateFile(
            $this->file(99, 3, isPublic: true),
            $this->user(1, 3, 'etudiant'),
        ));
    }

    public function test_canModerateFile_allow_owner_intra_tenant(): void
    {
        self::assertTrue($this->subject()->canModerateFile(
            $this->file(42, 3),
            $this->user(42, 3, 'etudiant'),
        ));
    }

    public function test_canModerateFile_allow_admin_intra_tenant(): void
    {
        self::assertTrue($this->subject()->canModerateFile(
            $this->file(99, 3),
            $this->user(1, 3, 'admin'),
        ));
    }

    public function test_canModerateFile_deny_admin_cross_tenant(): void
    {
        self::assertFalse($this->subject()->canModerateFile(
            $this->file(99, 5),
            $this->user(1, 3, 'admin'),
        ));
    }

    public function test_canModerateFile_allow_supradmin_cross_tenant(): void
    {
        self::assertTrue($this->subject()->canModerateFile(
            $this->file(99, 5),
            $this->user(1, 3, 'supradmin'),
        ));
    }

    public function test_canModerateFile_deny_coordinateur_intra_tenant(): void
    {
        // Coordinateur is intentionally NOT in moderator roles for Files
        // (same as Files have no Forum-style coordination)
        self::assertFalse($this->subject()->canModerateFile(
            $this->file(99, 3),
            $this->user(1, 3, 'coordinateur'),
        ));
    }
}
