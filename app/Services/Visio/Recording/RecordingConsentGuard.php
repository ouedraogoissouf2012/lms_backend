<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Enums\ConsentPurpose;
use App\Models\Consent;
use App\Models\Seance;
use App\Models\User;
use DateTimeInterface;

/**
 * #716 — le consentement précède toujours tout enregistrement.
 */
final class RecordingConsentGuard
{
    public function allowsStart(Seance $seance, User $user, ?DateTimeInterface $at = null): bool
    {
        return $this->grantedAt($user, ConsentPurpose::Capture, $seance, $at);
    }

    public function grantedAt(
        User $user,
        ConsentPurpose $purpose,
        ?Seance $seance = null,
        ?DateTimeInterface $at = null,
    ): bool {
        $at = $at ?? now();

        $query = Consent::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose->value)
            ->where('granted_at', '<=', $at)
            ->orderByDesc('id');

        if ($seance !== null) {
            $query->where(function ($q) use ($seance): void {
                $q->where('seance_id', $seance->id)->orWhereNull('seance_id');
            });
        }

        $latest = $query->first();

        return $latest instanceof Consent && $latest->granted === true && $latest->revoked_at === null;
    }

    /**
     * @param  array<string, mixed>|null  $evidence
     */
    public function record(
        User $subject,
        User $actor,
        ConsentPurpose $purpose,
        bool $granted,
        ?Seance $seance = null,
        ?array $evidence = null,
    ): Consent {
        return Consent::query()->create([
            'institution_id' => $subject->institution_id,
            'user_id' => $subject->id,
            'seance_id' => $seance?->id,
            'purpose' => $purpose,
            'granted' => $granted,
            'granted_at' => now(),
            'revoked_at' => $granted ? null : now(),
            'actor_user_id' => $actor->id,
            'evidence' => $evidence,
        ]);
    }

    /**
     * @return array{capture_teacher_view: bool, capture_screen_share: bool, capture_learner_tiles: bool, capture_learner_audio: bool}
     */
    public function defaultLayout(): array
    {
        /** @var array{capture_teacher_view: bool, capture_screen_share: bool, capture_learner_tiles: bool, capture_learner_audio: bool} $layout */
        $layout = config('visio.recording');

        return $layout;
    }
}
