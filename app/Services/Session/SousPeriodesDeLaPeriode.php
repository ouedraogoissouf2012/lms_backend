<?php

declare(strict_types=1);

namespace App\Services\Session;

use App\Exceptions\BusinessException;
use App\Models\SousPeriode;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Les sous-périodes d'une période de formation (#1806) : semestres, trimestres.
 *
 * - La période est celle de l'acteur, par {@see PeriodesDeLEcole} : la porte du catalogue local (403 en école
 *   KLASSCI), 404 hors de l'école. Une sous-période d'une autre période : 404.
 * - Une période qui ferme les inscriptions (annulée, close, archivée, purgée) ne se découpe plus : 409
 *   `training_session_not_editable`, le motif de sa propre modification.
 * - Une sous-période tient dans les dates de sa période (une borne absente ne borne rien) et ne chevauche aucune
 *   sœur, bornes incluses. Ces deux règles lisent d'autres lignes : elles sont vérifiées SOUS le verrou de la
 *   période, pour que deux écritures simultanées ne posent pas deux sœurs qui se chevauchent. `debut <= fin` est
 *   en plus garanti par la base.
 * - Le refus est un 422 sous le champ fautif : `debut` si le début tombe hors de la période ou dans une sœur,
 *   `fin` sinon.
 */
final class SousPeriodesDeLaPeriode
{
    public function __construct(
        private readonly PeriodesDeLEcole $periodes,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * @return list<SousPeriode>
     *
     * @throws BusinessException 403, 404
     */
    public function lister(User $acteur, int $periodeId): array
    {
        $periode = $this->periodes->sienne($acteur, $periodeId);

        return array_values($this->soeurs($periode)->orderBy('debut')->orderBy('id')->get()->all());
    }

    /**
     * @param  array{libelle: string, debut: string, fin: string, ordre?: ?int}  $donnees
     *
     * @throws BusinessException 403, 404, 409
     * @throws ValidationException 422
     */
    public function creer(User $acteur, int $periodeId, array $donnees): SousPeriode
    {
        $periode = $this->modifiable($acteur, $periodeId);
        $sousPeriode = new SousPeriode;
        $sousPeriode->forceFill(['institution_id' => $periode->institution_id, 'training_session_id' => $periode->id]);

        return $this->ecrire($periode, $sousPeriode, $donnees);
    }

    /**
     * @param  array{libelle?: string, debut?: string, fin?: string, ordre?: ?int}  $modifications
     *
     * @throws BusinessException 403, 404, 409
     * @throws ValidationException 422
     */
    public function modifier(User $acteur, int $periodeId, int $id, array $modifications): SousPeriode
    {
        $periode = $this->modifiable($acteur, $periodeId);

        return $this->ecrire($periode, $this->sienne($periode, $id), $modifications);
    }

    /** @throws BusinessException 403, 404, 409 */
    public function supprimer(User $acteur, int $periodeId, int $id): void
    {
        $periode = $this->modifiable($acteur, $periodeId);
        $this->sienne($periode, $id)->delete();
    }

    /**
     * Les nouvelles dates d'une période doivent contenir ses sous-périodes : sinon 409 `sub_periods_outside_period`.
     * Lue par la modification de la période ({@see EditionDeLaPeriode}).
     *
     * @throws BusinessException 409
     */
    public function exigerQueLaPeriodeLesContienne(TrainingSession $periode, ?string $debut, ?string $fin): void
    {
        $hors = $this->soeurs($periode)
            ->where(function ($q) use ($debut, $fin): void {
                if ($debut !== null) {
                    $q->orWhere('debut', '<', $debut);
                }
                if ($fin !== null) {
                    $q->orWhere('fin', '>', $fin);
                }
            })
            ->when($debut === null && $fin === null, fn ($q) => $q->whereRaw('1 = 0'))
            ->exists();

        if ($hors) {
            throw new BusinessException(
                'Une sous-période sortirait de ces dates : modifiez-la ou supprimez-la d\'abord.',
                409,
                reason: 'sub_periods_outside_period',
            );
        }
    }

    /** @throws BusinessException 403, 404, 409 */
    private function modifiable(User $acteur, int $periodeId): TrainingSession
    {
        $periode = $this->periodes->sienne($acteur, $periodeId);

        if ($periode->status->fermeLesInscriptions()) {
            throw new BusinessException(
                sprintf('Une période « %s » ne se découpe plus.', $periode->status->value),
                409,
                reason: 'training_session_not_editable',
            );
        }

        return $periode;
    }

    /** @throws BusinessException 404 */
    private function sienne(TrainingSession $periode, int $id): SousPeriode
    {
        $sousPeriode = $this->soeurs($periode)->whereKey($id)->first();

        if (! $sousPeriode instanceof SousPeriode) {
            throw new BusinessException('Sous-période introuvable dans cette période.', 404);
        }

        return $sousPeriode;
    }

    /** @return Builder<SousPeriode> */
    private function soeurs(TrainingSession $periode): Builder
    {
        return SousPeriode::query()->withoutGlobalScopes()
            ->where('institution_id', $periode->institution_id)
            ->where('training_session_id', $periode->id);
    }

    /**
     * @param  array{libelle?: string, debut?: string, fin?: string, ordre?: ?int}  $donnees
     *
     * @throws ValidationException
     */
    private function ecrire(TrainingSession $periode, SousPeriode $sousPeriode, array $donnees): SousPeriode
    {
        $this->db->transaction(function () use ($periode, $sousPeriode, $donnees): void {
            $verrouillee = TrainingSession::query()->withoutGlobalScopes()->whereKey($periode->id)->lockForUpdate()->firstOrFail();
            $sousPeriode->fill(array_intersect_key($donnees, array_flip(['libelle', 'debut', 'fin', 'ordre'])));
            $this->exigerDesBornesJustes($verrouillee, $sousPeriode);
            $sousPeriode->save();
        });

        return $sousPeriode;
    }

    /** @throws ValidationException */
    private function exigerDesBornesJustes(TrainingSession $periode, SousPeriode $sousPeriode): void
    {
        $debut = Carbon::parse($sousPeriode->debut)->startOfDay();
        $fin = Carbon::parse($sousPeriode->fin)->startOfDay();

        if ($fin->lt($debut)) {
            throw ValidationException::withMessages(['fin' => 'La fin précède le début.']);
        }

        if ($periode->starts_on instanceof CarbonInterface && $debut->lt($periode->starts_on->copy()->startOfDay())) {
            throw ValidationException::withMessages(['debut' => 'La sous-période commence avant sa période.']);
        }

        if ($periode->ends_on instanceof CarbonInterface && $fin->gt($periode->ends_on->copy()->startOfDay())) {
            throw ValidationException::withMessages(['fin' => 'La sous-période finit après sa période.']);
        }

        $soeur = $this->soeurs($periode)
            ->when($sousPeriode->exists, fn ($q) => $q->whereKeyNot($sousPeriode->id))
            ->where('debut', '<=', $fin->toDateString())
            ->where('fin', '>=', $debut->toDateString())
            ->first();

        if (false && $soeur instanceof SousPeriode) {
            $champ = $debut->between($soeur->debut, $soeur->fin) ? 'debut' : 'fin';
            throw ValidationException::withMessages([$champ => sprintf('Elle chevauche « %s ».', $soeur->libelle)]);
        }
    }
}
