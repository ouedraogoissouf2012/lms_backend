<?php

declare(strict_types=1);

namespace App\Services\EmploiDuTemps;

use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\EmploiDuTemps;
use App\Models\LigneEmploiDuTemps;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Catalogue\PorteDuCatalogueLocal;
use Illuminate\Support\Carbon;

/**
 * Les emplois du temps d'une école (#1669) : désigner, créer, modifier, supprimer, lister, les alertes, la forme
 * rendue. Chaque entrée passe par la porte du catalogue local ({@see PorteDuCatalogueLocal}) : une école KLASSCI compose
 * dans KLASSCI (403 `catalogue_managed_by_klassci`). Toute lecture est bornée EXPLICITEMENT à l'école de l'acteur.
 *
 * Les dates d'un emploi du temps restent dans la période de sa classe (ARRÊT 1, Q1) ; une classe sans période les
 * accepte telles quelles.
 */
final class EmploisDuTemps
{
    public const HORS_PERIODE = 'emploi_du_temps_hors_periode';

    public function __construct(private readonly PorteDuCatalogueLocal $porte) {}

    public function ecoleDe(User $acteur): int
    {
        return $this->porte->etablissementDe($acteur);
    }

    /** @throws BusinessException 404 hors de l'école */
    public function designer(int $id, User $acteur): EmploiDuTemps
    {
        $emploi = EmploiDuTemps::query()->withoutGlobalScope('institution')
            ->where('institution_id', $this->ecoleDe($acteur))->whereKey($id)->with('lignes')->first();
        if (! $emploi instanceof EmploiDuTemps) {
            throw new BusinessException('Emploi du temps introuvable', 404);
        }

        return $emploi;
    }

    /** @throws BusinessException 404 hors de l'école */
    public function classeDeLEcole(int $classeId, int $ecole): Classe
    {
        $classe = Classe::query()->withoutGlobalScope('institution')->where('institution_id', $ecole)->whereKey($classeId)->first();
        if (! $classe instanceof Classe) {
            throw new BusinessException('Classe introuvable', 404);
        }

        return $classe;
    }

    /** @param  array{classe_id: int, titre: string, du: string, au: string, en_vigueur: bool}  $champs */
    public function creer(User $acteur, array $champs): EmploiDuTemps
    {
        $ecole = $this->ecoleDe($acteur);
        $classe = $this->classeDeLEcole($champs['classe_id'], $ecole);
        $this->exigerDansLaPeriode($classe, $champs['du'], $champs['au']);

        return EmploiDuTemps::query()->create(['institution_id' => $ecole, 'classe_id' => $classe->id] + $champs)->load('lignes');
    }

    /** @param  array{titre: string, du: string, au: string, en_vigueur: bool}  $champs */
    public function modifier(int $id, User $acteur, array $champs): EmploiDuTemps
    {
        $emploi = $this->designer($id, $acteur);
        $this->exigerDansLaPeriode($this->classeDeLEcole($emploi->classe_id, $emploi->institution_id), $champs['du'], $champs['au']);
        $emploi->fill($champs)->save();

        return $emploi->load('lignes');
    }

    public function supprimer(int $id, User $acteur): void
    {
        $this->designer($id, $acteur)->delete();
    }

    /** @return list<array<string, mixed>> */
    public function lister(User $acteur, ?int $classeId): array
    {
        return array_values(EmploiDuTemps::query()->withoutGlobalScope('institution')
            ->where('institution_id', $this->ecoleDe($acteur))
            ->when($classeId !== null, static fn ($q) => $q->where('classe_id', $classeId))
            ->with('lignes')->orderByDesc('du')->orderByDesc('id')->get()
            ->map(fn (EmploiDuTemps $e): array => $this->presenter($e))->all());
    }

    /** @return array{sans_emploi_du_temps: list<array{classe_id: int, libelle: ?string}>, expires: list<array{id: int, classe_id: int, titre: string, au: string}>} */
    public function alertes(User $acteur): array
    {
        $ecole = $this->ecoleDe($acteur);
        $aujourdhui = now()->toDateString();
        $couvertes = EmploiDuTemps::query()->withoutGlobalScope('institution')->where('institution_id', $ecole)
            ->where('en_vigueur', true)->whereDate('du', '<=', $aujourdhui)->whereDate('au', '>=', $aujourdhui)->select('classe_id');
        $sans = Classe::query()->withoutGlobalScope('institution')->where('institution_id', $ecole)
            ->whereNotIn('id', $couvertes)->orderBy('libelle')->orderBy('id')->get(['id', 'libelle']);
        $expires = EmploiDuTemps::query()->withoutGlobalScope('institution')->where('institution_id', $ecole)
            ->where('en_vigueur', true)->whereDate('au', '<', $aujourdhui)->orderBy('au')->orderBy('id')->get();

        return [
            'sans_emploi_du_temps' => array_values($sans->map(static fn (Classe $c): array => ['classe_id' => (int) $c->id, 'libelle' => $c->libelle])->all()),
            'expires' => array_values($expires->map(static fn (EmploiDuTemps $e): array => [
                'id' => (int) $e->id, 'classe_id' => (int) $e->classe_id, 'titre' => $e->titre, 'au' => $e->au->toDateString(),
            ])->all()),
        ];
    }

    /** @throws BusinessException 422 `emploi_du_temps_hors_periode` */
    public function exigerDansLaPeriode(Classe $classe, string $du, string $au): void
    {
        $periode = $classe->trainingSession;
        if (! $periode instanceof TrainingSession || $periode->starts_on === null || $periode->ends_on === null) {
            return;
        }
        if (Carbon::parse($du)->lt($periode->starts_on) || Carbon::parse($au)->gt($periode->ends_on)) {
            throw new BusinessException('Les dates sortent de la période de la classe.', 422, null, self::HORS_PERIODE);
        }
    }

    /** @return array<string, mixed> */
    public function presenter(EmploiDuTemps $emploi): array
    {
        return [
            'id' => (int) $emploi->id,
            'classe_id' => (int) $emploi->classe_id,
            'titre' => $emploi->titre,
            'du' => $emploi->du->toDateString(),
            'au' => $emploi->au->toDateString(),
            'en_vigueur' => $emploi->en_vigueur,
            'lignes' => array_values($emploi->lignes->map(static fn (LigneEmploiDuTemps $l): array => [
                'id' => (int) $l->id,
                'jour' => $l->jour,
                'debut' => substr($l->debut, 0, 5),
                'fin' => substr($l->fin, 0, 5),
                'matiere_id' => $l->matiere_id,
                'enseignant_id' => $l->enseignant_id,
                'type_seance' => $l->type_seance,
                'libelle' => $l->libelle,
            ])->all()),
        ];
    }
}
