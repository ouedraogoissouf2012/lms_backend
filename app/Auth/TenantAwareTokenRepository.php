<?php

declare(strict_types=1);

namespace App\Auth;

use App\Services\TenantManager;
use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Contracts\Hashing\Hasher as HasherContract;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Jeton de réinitialisation scopé par institution (#714).
 * Lit le tenant à chaque appel — ne le capture pas au constructeur.
 */
final class TenantAwareTokenRepository extends DatabaseTokenRepository
{
    public function __construct(
        ConnectionInterface $connection,
        HasherContract $hasher,
        string $table,
        string $hashKey,
        int $expires,
        int $throttle,
        private readonly TenantManager $tenants,
    ) {
        parent::__construct($connection, $hasher, $table, $hashKey, $expires, $throttle);
    }

    protected function deleteExisting(CanResetPasswordContract $user)
    {
        return $this->scopedQuery($user->getEmailForPasswordReset())->delete();
    }

    /**
     * @return array{email: string, institution_id: int, token: string, created_at: Carbon}
     */
    protected function getPayload($email, #[\SensitiveParameter] $token): array
    {
        return [
            'email' => $email,
            'institution_id' => $this->institutionId(),
            'token' => $this->hasher->make($token),
            'created_at' => new Carbon,
        ];
    }

    public function exists(CanResetPasswordContract $user, #[\SensitiveParameter] $token)
    {
        $record = (array) $this->scopedQuery($user->getEmailForPasswordReset())->first();

        return $record !== []
            && ! $this->tokenExpired($record['created_at'])
            && $this->hasher->check($token, $record['token']);
    }

    public function recentlyCreatedToken(CanResetPasswordContract $user)
    {
        $record = (array) $this->scopedQuery($user->getEmailForPasswordReset())->first();

        return $record !== [] && $this->tokenRecentlyCreated($record['created_at']);
    }

    private function scopedQuery(string $email): \Illuminate\Database\Query\Builder
    {
        return $this->getTable()
            ->where('email', $email)
            ->where('institution_id', $this->institutionId());
    }

    private function institutionId(): int
    {
        $id = $this->tenants->id();
        if (! is_int($id)) {
            throw new RuntimeException('Un tenant est requis pour un jeton de réinitialisation.');
        }

        return $id;
    }
}
