<?php

declare(strict_types=1);

namespace App\Services\Attendances\Justification;

use App\Enums\EtatJustificatif;
use App\Exceptions\BusinessException;
use App\Models\JustificatifAbsence;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Les justificatifs d'une école (#1681), lus : la forme rendue, la liste de l'élève, la liste de la gestion avec ses
 * compteurs, et la désignation d'un justificatif par la gestion ou par son élève.
 *
 * - La gestion = coordinateur ou admin de l'école (ARRÊT 1 Q3) ; jamais l'enseignant (403), jamais le supradmin de
 *   plateforme ; un justificatif d'une autre école n'existe pas (404).
 * - Toute lecture est bornée EXPLICITEMENT à l'école du lecteur.
 */
final class JustificatifsDeLEcole
{
    public const PAR_PAGE = 25;

    /** @return list<array<string, mixed>> */
    public function deLEleve(User $eleve): array
    {
        return array_values($this->deLEcole($eleve)->where('eleve_id', $eleve->id)
            ->with('seances')->orderByDesc('id')->get()
            ->map(fn (JustificatifAbsence $j): array => $this->presenter($j))->all());
    }

    /** @return array{justificatifs: list<array<string, mixed>>, compteurs: array<string, int>, page: int, derniere_page: int, total: int} */
    public function pourLaGestion(User $gestion, FiltresDeJustificatifs $filtres): array
    {
        $this->exigerLaGestion($gestion);
        $filtrees = fn (): Builder => $this->filtrer($this->deLEcole($gestion), $filtres);
        $compteurs = array_fill_keys(array_map(static fn (EtatJustificatif $e): string => $e->value, EtatJustificatif::cases()), 0);
        foreach ($filtrees()->toBase()->selectRaw('etat, count(*) as n')->groupBy('etat')->get() as $ligne) {
            $compteurs[(string) $ligne->etat] = (int) $ligne->n;
        }
        $page = $filtrees()->when($filtres->etat !== null, static fn (Builder $q) => $q->where('etat', $filtres->etat?->value))
            ->with(['seances', 'eleve:id,name'])->orderByDesc('id')
            ->paginate(self::PAR_PAGE, ['*'], 'page', $filtres->page);

        return [
            'justificatifs' => array_values(array_map(fn (JustificatifAbsence $j): array => $this->presenter($j), $page->items())),
            'compteurs' => $compteurs,
            'page' => $page->currentPage(),
            'derniere_page' => $page->lastPage(),
            'total' => $page->total(),
        ];
    }

    /** @throws BusinessException 403 hors de la gestion, 404 hors de l'école */
    public function pourLaGestionParId(int $id, User $gestion): JustificatifAbsence
    {
        $this->exigerLaGestion($gestion);

        return $this->parId($id, $gestion);
    }

    /** @throws BusinessException 404 hors de l'école */
    public function parId(int $id, User $lecteur): JustificatifAbsence
    {
        $justificatif = $this->deLEcole($lecteur)->whereKey($id)->first();
        if (! $justificatif instanceof JustificatifAbsence) {
            throw new BusinessException('Justificatif introuvable', 404);
        }

        return $justificatif;
    }

    public function estLaGestion(User $acteur): bool
    {
        return $acteur->isManager() && ! $acteur->isPlatformSupradmin() && $acteur->institution_id !== null;
    }

    /** @return array<string, mixed> */
    public function presenter(JustificatifAbsence $j): array
    {
        return [
            'id' => (int) $j->id,
            'eleve_id' => (int) $j->eleve_id,
            'eleve_nom' => $j->relationLoaded('eleve') ? $j->eleve?->name : null,
            'motif' => $j->motif,
            'du' => $j->du?->toDateString(),
            'au' => $j->au?->toDateString(),
            'seances' => $j->seances->map(static fn (Seance $s): array => [
                'id' => (int) $s->id,
                'titre' => $s->titre,
                'date_seance' => $s->date_seance === null ? null : Carbon::parse($s->date_seance)->toIso8601String(),
            ])->values()->all(),
            'piece' => $j->piece_chemin !== null,
            'etat' => $j->etat->value,
            'motif_rejet' => $j->motif_rejet,
            'decide_le' => $j->decide_le?->toIso8601String(),
            'cree_le' => $j->created_at?->toIso8601String(),
        ];
    }

    /** @throws BusinessException 403 */
    private function exigerLaGestion(User $acteur): void
    {
        if (! $this->estLaGestion($acteur)) {
            throw new BusinessException('Seule la gestion de l\'école tranche les justificatifs.', 403);
        }
    }

    /** @return Builder<JustificatifAbsence> */
    private function deLEcole(User $lecteur): Builder
    {
        return JustificatifAbsence::query()->withoutGlobalScope('institution')->where('institution_id', $lecteur->institution_id);
    }

    /**
     * @param  Builder<JustificatifAbsence>  $requete
     * @return Builder<JustificatifAbsence>
     */
    private function filtrer(Builder $requete, FiltresDeJustificatifs $filtres): Builder
    {
        return $requete
            ->when($filtres->eleveId !== null, static fn (Builder $q) => $q->where('eleve_id', $filtres->eleveId))
            ->when($filtres->du !== null, static fn (Builder $q) => $q->where('created_at', '>=', Carbon::parse((string) $filtres->du)->startOfDay()))
            ->when($filtres->au !== null, static fn (Builder $q) => $q->where('created_at', '<=', Carbon::parse((string) $filtres->au)->endOfDay()));
    }
}
