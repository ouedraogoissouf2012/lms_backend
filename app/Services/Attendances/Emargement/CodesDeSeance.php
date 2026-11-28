<?php

declare(strict_types=1);

namespace App\Services\Attendances\Emargement;

use App\Exceptions\BusinessException;
use App\Models\CodeEmargement;
use App\Models\Seance;
use App\Models\User;
use App\Services\Enrollment\ClasseEnrolmentCode;
use App\Services\Seances\LocalSeanceDesignee;
use App\Services\Seances\ParticipantsDeSeanceLocale;
use App\Services\Visio\VisioActorAuthorization;

/**
 * Les codes d'émargement d'une séance, côté auteur ou gestion (#1680) : générer, lister, révoquer.
 *
 * - Qui : {@see VisioActorAuthorization::canManage()}, la règle de l'appel.
 * - Le code : 6 caractères dictables ({@see ClasseEnrolmentCode}), rendu en clair une seule fois et gardé en
 *   empreinte ({@see EmpreinteDeCode}). Un tirage déjà actif dans l'école est refait.
 * - Les statistiques : signatures nouvelles du code, élèves actifs attendus, taux arrondi.
 */
final class CodesDeSeance
{
    /** Au-delà, l'école a déjà tant de codes actifs que le tirage est à revoir : jamais en pratique (31⁶ codes). */
    private const TIRAGES_MAX = 5;

    public function __construct(
        private readonly LocalSeanceDesignee $seances,
        private readonly VisioActorAuthorization $acteurs,
        private readonly ParticipantsDeSeanceLocale $participants,
        private readonly EmpreinteDeCode $empreinte,
    ) {}

    /** @return array{id: int, code: string, expire_le: string} */
    public function generer(int $seanceId, User $auteur, int $dureeMinutes, ?int $retardApresMinutes): array
    {
        $seance = $this->seanceGeree($seanceId, $auteur);
        [$code, $empreinte] = $this->tirage((int) $seance->institution_id);

        $ligne = CodeEmargement::query()->create([
            'institution_id' => $seance->institution_id,
            'seance_id' => $seance->id,
            'auteur_id' => $auteur->id,
            'code_hash' => $empreinte,
            'expire_le' => now()->addMinutes($dureeMinutes),
            'retard_apres_minutes' => $retardApresMinutes,
        ]);

        return ['id' => (int) $ligne->id, 'code' => $code, 'expire_le' => $ligne->expire_le->toIso8601String()];
    }

    /** @return list<array{id: int, expire_le: string, revoque_le: ?string, valable: bool, signatures: int, attendus: int, taux: int}> */
    public function lister(int $seanceId, User $auteur): array
    {
        $seance = $this->seanceGeree($seanceId, $auteur);
        $attendus = count($this->participants->de($seance)['eleves']);

        $codes = CodeEmargement::query()->withoutGlobalScope('institution')
            ->where('institution_id', $seance->institution_id)
            ->where('seance_id', $seance->id)
            ->orderByDesc('id')
            ->get();

        $lignes = [];
        foreach ($codes as $c) {
            $lignes[] = [
                'id' => (int) $c->id,
                'expire_le' => $c->expire_le->toIso8601String(),
                'revoque_le' => $c->revoque_le?->toIso8601String(),
                'valable' => $c->revoque_le === null && $c->expire_le->isFuture(),
                'signatures' => $c->signatures,
                'attendus' => $attendus,
                'taux' => $attendus > 0 ? (int) round($c->signatures / $attendus * 100) : 0,
            ];
        }

        return $lignes;
    }

    public function revoquer(int $codeId, User $auteur): void
    {
        $code = CodeEmargement::query()->withoutGlobalScope('institution')
            ->where('institution_id', $auteur->institution_id)
            ->whereKey($codeId)
            ->first();
        if (! $code instanceof CodeEmargement) {
            throw new BusinessException('Code introuvable', 404);
        }
        $this->seanceGeree((int) $code->seance_id, $auteur);
        $code->forceFill(['revoque_le' => $code->revoque_le ?? now()])->save();
    }

    /**
     * Un code tiré au sort, et son empreinte, qui n'est celle d'aucun code ACTIF de l'école.
     *
     * @return array{0: string, 1: string}
     */
    private function tirage(int $institutionId): array
    {
        for ($i = 0; $i < self::TIRAGES_MAX; $i++) {
            $code = ClasseEnrolmentCode::tirer();
            $empreinte = $this->empreinte->de($code);
            $actif = CodeEmargement::query()->withoutGlobalScope('institution')
                ->where('institution_id', $institutionId)
                ->where('code_hash', $empreinte)
                ->whereNull('revoque_le')
                ->where('expire_le', '>', now())
                ->exists();
            if (! $actif) {
                return [$code, $empreinte];
            }
        }

        throw new BusinessException('Aucun code libre n\'a pu être tiré. Réessayez.', 503);
    }

    /** @throws BusinessException 404 hors de l'école, 403 hors de l'auteur et de la gestion */
    private function seanceGeree(int $seanceId, User $acteur): Seance
    {
        $seance = $this->seances->pour($seanceId, $acteur);
        if (! $seance instanceof Seance) {
            throw new BusinessException('Séance non trouvée', 404);
        }
        if (! $this->acteurs->canManage($seance, $acteur)) {
            throw new BusinessException('Vous ne pouvez pas gérer l\'émargement de cette séance.', 403);
        }

        return $seance;
    }
}
