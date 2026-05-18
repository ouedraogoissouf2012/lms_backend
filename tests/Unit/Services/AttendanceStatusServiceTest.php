<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AttendanceStatusService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pure-function tests for {@see AttendanceStatusService}.
 *
 * No DB, no mocks, no Laravel container — just inputs → outputs.
 *
 * Reference: .claude/specs/lms-data-controller-split/design.md §2.3
 */
#[CoversClass(AttendanceStatusService::class)]
final class AttendanceStatusServiceTest extends TestCase
{
    private AttendanceStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AttendanceStatusService();
    }

    public function test_full_attendance_100_percent(): void
    {
        $result = $this->service->determine(100, null, null, null, null);

        self::assertSame('Présent (complet)', $result['label']);
        self::assertSame('✅', $result['icon']);
        self::assertFalse($result['is_late']);
        self::assertFalse($result['left_early']);
    }

    public function test_high_attendance_90_percent_active_session(): void
    {
        // visio still active → label without percentage suffix
        $result = $this->service->determine(95, null, null, null, null, 'active');

        self::assertSame('Présent', $result['label']);
        self::assertSame('✅', $result['icon']);
    }

    public function test_high_attendance_90_percent_terminated_session(): void
    {
        // visio terminée → label includes percentage
        $result = $this->service->determine(95, null, null, null, null, 'terminee');

        self::assertSame('Présent (95%)', $result['label']);
        self::assertSame('✅', $result['icon']);
    }

    public function test_late_join_detected(): void
    {
        // Joined 6 minutes after scheduled start → late (threshold = 5 min)
        $result = $this->service->determine(
            percentage: 80,
            joinedAt: '2026-05-18T08:06:00+00:00',
            leftAt: null,
            heureDebut: '2026-05-18T08:00:00+00:00',
            heureFin: '2026-05-18T10:00:00+00:00',
            visioStatus: 'terminee',
        );

        self::assertTrue($result['is_late']);
        self::assertFalse($result['left_early']);
        self::assertSame('Retard (80%)', $result['label']);
        self::assertSame('⚠️', $result['icon']);
    }

    public function test_late_join_within_threshold_not_late(): void
    {
        // Joined exactly 5 minutes after → tolerated (strictly > 5 to be late)
        $result = $this->service->determine(
            percentage: 80,
            joinedAt: '2026-05-18T08:05:00+00:00',
            leftAt: null,
            heureDebut: '2026-05-18T08:00:00+00:00',
            heureFin: '2026-05-18T10:00:00+00:00',
            visioStatus: 'terminee',
        );

        self::assertFalse($result['is_late']);
    }

    public function test_early_leave_detected(): void
    {
        $result = $this->service->determine(
            percentage: 70,
            joinedAt: null,
            leftAt: '2026-05-18T09:50:00+00:00',
            heureDebut: '2026-05-18T08:00:00+00:00',
            heureFin: '2026-05-18T10:00:00+00:00',
            visioStatus: 'terminee',
        );

        self::assertFalse($result['is_late']);
        self::assertTrue($result['left_early']);
        self::assertSame('Départ anticipé (70%)', $result['label']);
    }

    public function test_both_late_and_early_leave_partial(): void
    {
        $result = $this->service->determine(
            percentage: 60,
            joinedAt: '2026-05-18T08:10:00+00:00',
            leftAt: '2026-05-18T09:45:00+00:00',
            heureDebut: '2026-05-18T08:00:00+00:00',
            heureFin: '2026-05-18T10:00:00+00:00',
            visioStatus: 'terminee',
        );

        self::assertTrue($result['is_late']);
        self::assertTrue($result['left_early']);
        self::assertSame('Partiel (60%)', $result['label']);
    }

    public function test_active_session_partial_no_percentage(): void
    {
        $result = $this->service->determine(
            percentage: 60,
            joinedAt: '2026-05-18T08:10:00+00:00',
            leftAt: null,
            heureDebut: '2026-05-18T08:00:00+00:00',
            heureFin: '2026-05-18T10:00:00+00:00',
            visioStatus: 'active',
        );

        self::assertSame('Retard', $result['label']);
        self::assertSame('⚠️', $result['icon']);
    }

    public function test_low_attendance_below_50(): void
    {
        $result = $this->service->determine(30, null, null, null, null, 'terminee');

        self::assertSame('Présent (30%)', $result['label']);
        self::assertSame('⚠️', $result['icon']);
    }

    public function test_null_percentage_treated_as_zero(): void
    {
        $result = $this->service->determine(null, null, null, null, null, 'terminee');

        self::assertSame('Présent (0%)', $result['label']);
        self::assertSame('⚠️', $result['icon']);
    }

    public function test_unparseable_timestamp_does_not_throw(): void
    {
        // Defensive: invalid ISO-8601 should not crash, just skip the check
        $result = $this->service->determine(
            percentage: 80,
            joinedAt: 'not-a-date',
            leftAt: null,
            heureDebut: '2026-05-18T08:00:00+00:00',
            heureFin: null,
        );

        self::assertFalse($result['is_late']);
    }

    public function test_missing_heureDebut_skips_late_check(): void
    {
        $result = $this->service->determine(
            percentage: 80,
            joinedAt: '2026-05-18T08:30:00+00:00',
            leftAt: null,
            heureDebut: null,
            heureFin: null,
        );

        self::assertFalse($result['is_late']);
    }
}
