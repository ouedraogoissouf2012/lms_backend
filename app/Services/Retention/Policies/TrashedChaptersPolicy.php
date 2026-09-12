<?php

declare(strict_types=1);

namespace App\Services\Retention\Policies;

use App\Models\Chapter;
use App\Services\Chapter\ChapterRetentionService;
use App\Services\Retention\Concerns\NamesTheRow;
use App\Services\Retention\RetentionPolicy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Purge définitive des chapitres mis à la corbeille (#674, #689) — désormais une
 * politique (#690).
 *
 * Comme {@see SeanceRecordingsPolicy}, elle **enveloppe**
 * `ChapterRetentionService` plutôt que de recopier sa logique : transaction avec
 * verrou, suppression des artefacts, garde sur les chapitres restaurés
 * entre-temps. Réécrire une logique de destruction éprouvée pour satisfaire une
 * abstraction serait le pire des deux mondes.
 */
final class TrashedChaptersPolicy implements RetentionPolicy
{
    use NamesTheRow;

    public function __construct(private readonly ChapterRetentionService $service) {}

    public function key(): string
    {
        return 'chapters';
    }

    public function label(): string
    {
        return 'chapitre(s) en corbeille';
    }

    public function defaultGraceDays(): int
    {
        return $this->service->retentionDays();
    }

    public function eligible(CarbonInterface $cutoff): Builder
    {
        return $this->service->trashedBeyond($cutoff);
    }

    public function describe(Model $item): string
    {
        if (! $item instanceof Chapter) {
            return 'ligne inattendue';
        }

        $supprimeLe = $item->deleted_at?->toDateString() ?? 'date inconnue';

        return "chapitre #{$this->identifiant($item)} ({$item->title}), supprimé le {$supprimeLe}";
    }

    public function auditAction(): string
    {
        return 'chapter.purged';
    }

    /**
     * Le service revérifie l'éligibilité ligne par ligne : un chapitre restauré
     * entre la lecture du lot et son traitement ne doit pas être détruit. La
     * requête, elle, ne voit qu'un instantané.
     */
    public function refuses(Model $item, CarbonInterface $cutoff): ?string
    {
        if (! $item instanceof Chapter) {
            return 'ligne inattendue';
        }

        return $this->service->eligible($item, $cutoff)
            ? null
            : 'non éligible — restauré depuis, ou hors du délai de grâce';
    }

    public function purge(Model $item, CarbonInterface $cutoff): void
    {
        if ($item instanceof Chapter) {
            $this->service->purge($item, $cutoff);
        }
    }
}
