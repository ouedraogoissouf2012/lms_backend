<?php

declare(strict_types=1);

namespace App\Services\Enseignant\Profil;

use App\Exceptions\BusinessException;
use App\Models\DisponibiliteEnseignant;
use App\Models\ExceptionDisponibilite;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Les disponibilités d'un enseignant (#1666), dans les DEUX volets, toujours rattachées à son id LOCAL (exigence de la
 * superviseure). L'enseignant les remplit pour lui-même ; la gestion les relit et les corrige (28/11, front#673) : la semaine type, remplacée d'un bloc (deux créneaux d'un même jour ne se chevauchent pas, 422
 * `disponibilites_en_conflit`), et des exceptions datées, ajoutées et retirées une à une. Toute lecture est bornée
 * EXPLICITEMENT à l'école de l'enseignant.
 */
final class DisponibilitesEnseignant
{
    public const EN_CONFLIT = 'disponibilites_en_conflit';

    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return array{enseignant_id: int, semaine: list<array<string, mixed>>, exceptions: list<array<string, mixed>>} */
    public function lire(User $enseignant): array
    {
        $semaine = DisponibiliteEnseignant::query()->withoutGlobalScope('institution')
            ->where('institution_id', $enseignant->institution_id)->where('user_id', $enseignant->id)
            ->orderBy('jour')->orderBy('debut')->orderBy('id')->get();
        $exceptions = ExceptionDisponibilite::query()->withoutGlobalScope('institution')
            ->where('institution_id', $enseignant->institution_id)->where('user_id', $enseignant->id)
            ->orderBy('date')->orderBy('debut')->orderBy('id')->get();

        return [
            'enseignant_id' => (int) $enseignant->id,
            'semaine' => array_values($semaine->map(static fn (DisponibiliteEnseignant $d): array => [
                'id' => (int) $d->id, 'jour' => $d->jour, 'debut' => substr($d->debut, 0, 5), 'fin' => substr($d->fin, 0, 5), 'disponible' => $d->disponible,
            ])->all()),
            'exceptions' => array_values($exceptions->map(fn (ExceptionDisponibilite $e): array => $this->exception($e))->all()),
        ];
    }

    /** @param  list<array{jour: int, debut: string, fin: string, disponible: bool}>  $semaine */
    public function remplacerSemaine(User $enseignant, array $semaine): void
    {
        self::exigerSansChevauchement($semaine);
        $this->db->transaction(static function () use ($enseignant, $semaine): void {
            DisponibiliteEnseignant::query()->withoutGlobalScope('institution')
                ->where('institution_id', $enseignant->institution_id)->where('user_id', $enseignant->id)->delete();
            foreach ($semaine as $creneau) {
                DisponibiliteEnseignant::query()->create(['institution_id' => $enseignant->institution_id, 'user_id' => $enseignant->id] + $creneau);
            }
        });
    }

    /**
     * @param  array{date: string, debut: ?string, fin: ?string, disponible: bool, motif: ?string}  $champs
     * @return array<string, mixed>
     */
    public function ajouterException(User $enseignant, array $champs): array
    {
        $exception = ExceptionDisponibilite::query()->create(['institution_id' => $enseignant->institution_id, 'user_id' => $enseignant->id] + $champs);

        return $this->exception($exception);
    }

    /** @throws BusinessException 404 : une exception d'un autre enseignant ne se retire pas par celui-ci */
    public function retirerException(User $enseignant, int $id): void
    {
        $retirees = ExceptionDisponibilite::query()->withoutGlobalScope('institution')
            ->where('institution_id', $enseignant->institution_id)->where('user_id', $enseignant->id)->whereKey($id)->delete();
        if ($retirees === 0) {
            throw new BusinessException('Exception introuvable', 404);
        }
    }

    /** @return array<string, mixed> */
    private function exception(ExceptionDisponibilite $e): array
    {
        return [
            'id' => (int) $e->id,
            'date' => $e->date->toDateString(),
            'debut' => $e->debut === null ? null : substr($e->debut, 0, 5),
            'fin' => $e->fin === null ? null : substr($e->fin, 0, 5),
            'disponible' => $e->disponible,
            'motif' => $e->motif,
        ];
    }

    /** @param  list<array{jour: int, debut: string, fin: string}>  $semaine */
    private static function exigerSansChevauchement(array $semaine): void
    {
        foreach ($semaine as $i => $a) {
            foreach (array_slice($semaine, $i + 1) as $b) {
                if ($a['jour'] === $b['jour'] && $a['debut'] < $b['fin'] && $b['debut'] < $a['fin']) {
                    throw new BusinessException(sprintf('Deux créneaux se chevauchent le jour %d.', $a['jour']), 422, null, self::EN_CONFLIT);
                }
            }
        }
    }
}
