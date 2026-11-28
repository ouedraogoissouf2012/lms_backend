<?php

declare(strict_types=1);

namespace App\Services\Enseignant\Profil;

use App\Exceptions\BusinessException;
use App\Models\ProfilEnseignant;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Le profil d'un enseignant d'une école créée depuis le LMS (#1666). Une ligne au plus par compte, rattachée à son id
 * LOCAL. Le matricule est FACULTATIF et unique par école (ARRÊT 1, Q1) : un doublon est refusé en 422
 * `matricule_deja_pris`, que la course soit gagnée par la vérification ou par l'index unique.
 */
final class LocalProfilEnseignant implements ProfilEnseignantSource
{
    public const MATRICULE_PRIS = 'matricule_deja_pris';

    public function lire(User $enseignant): array
    {
        return self::presenter($enseignant, $this->profilDe($enseignant));
    }

    public function ecrire(User $enseignant, array $champs): array
    {
        $ecole = (int) $enseignant->institution_id;
        if ($champs['matricule'] !== null && $this->matriculePris($champs['matricule'], $ecole, (int) $enseignant->id)) {
            throw self::matriculePrisRefus();
        }
        try {
            $profil = ProfilEnseignant::query()->withoutGlobalScope('institution')->updateOrCreate(
                ['user_id' => $enseignant->id],
                ['institution_id' => $ecole] + $champs,
            );
        } catch (UniqueConstraintViolationException) {
            throw self::matriculePrisRefus();
        }

        return self::presenter($enseignant, $profil);
    }

    private function profilDe(User $enseignant): ?ProfilEnseignant
    {
        return ProfilEnseignant::query()->withoutGlobalScope('institution')
            ->where('institution_id', $enseignant->institution_id)->where('user_id', $enseignant->id)->first();
    }

    private function matriculePris(string $matricule, int $ecole, int $enseignant): bool
    {
        return ProfilEnseignant::query()->withoutGlobalScope('institution')
            ->where('matricule', $matricule)->where('user_id', '!=', $enseignant)->exists(); // MUTATION P2 : unicite globale
    }

    private static function matriculePrisRefus(): BusinessException
    {
        return new BusinessException('Ce matricule est déjà porté par un autre enseignant de l\'école.', 422, null, self::MATRICULE_PRIS);
    }

    /** @return array<string, mixed> */
    public static function presenter(User $enseignant, ?ProfilEnseignant $p): array
    {
        return [
            'enseignant_id' => (int) $enseignant->id,
            'matricule' => $p?->matricule,
            'specialite' => $p?->specialite,
            'titre' => $p?->titre,
            'grade' => $p?->grade?->value,
            'diplome' => $p?->diplome,
            'universite' => $p?->universite,
            'date_debut' => $p?->date_debut?->toDateString(),
            'statut' => $p?->statut?->value,
            'charge_hebdo_max' => $p?->charge_hebdo_max,
        ];
    }
}
