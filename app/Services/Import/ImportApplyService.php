<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\ImportRowStatus;
use App\Enums\Role;
use App\Models\Classe;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\User;
use Illuminate\Support\Str;

final class ImportApplyService
{
    public function apply(Import $import): void
    {
        $import->update(['status' => Import::STATUS_RUNNING]);

        foreach ($import->rows()->where('status', ImportRowStatus::Ok->value)->get() as $row) {
            $this->upsertStudent($import, $row);
        }

        $import->update(['status' => Import::STATUS_DONE]);
    }

    private function upsertStudent(Import $import, ImportRow $row): void
    {
        $payload = is_array($row->payload) ? $row->payload : [];
        $email = is_string($payload['email'] ?? null) ? $payload['email'] : '';
        $phone = is_string($payload['telephone'] ?? null) ? $payload['telephone'] : '';
        $nom = is_string($payload['nom'] ?? null) ? $payload['nom'] : '';
        $prenom = is_string($payload['prenom'] ?? null) ? $payload['prenom'] : '';
        $code = is_string($payload['code_classe'] ?? null) ? $payload['code_classe'] : '';

        $user = $this->findOrCreate($import, $email, $phone, $prenom, $nom);
        if ($user === null) {
            return;
        }
        $this->enroll($import, $user, $code);
    }

    private function findOrCreate(Import $import, string $email, string $phone, string $prenom, string $nom): ?User
    {
        $query = User::query()->where('institution_id', $import->institution_id);
        $existing = $email !== ''
            ? (clone $query)->where('email', $email)->first()
            : ($phone !== '' ? (clone $query)->where('phone', $phone)->first() : null);
        if ($existing instanceof User) {
            return $existing;
        }

        return User::query()->create([
            'institution_id' => $import->institution_id,
            'name' => trim($prenom.' '.$nom),
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
            'password' => Str::password(16),
            'role' => Role::Etudiant->value,
        ]);
    }

    private function enroll(Import $import, User $user, string $code): void
    {
        if ($code === '') {
            return;
        }
        $classe = Classe::query()
            ->where('institution_id', $import->institution_id)
            ->where('code', $code)
            ->first();
        if ($classe === null) {
            return;
        }
        if ($classe->etudiants()->where('users.id', $user->id)->exists()) {
            return;
        }
        $classe->etudiants()->attach($user->id, ['statut' => 'actif']);
    }
}
