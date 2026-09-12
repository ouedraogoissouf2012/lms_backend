<?php

declare(strict_types=1);

namespace App\Services\Retention\Policies;

use App\Models\SeanceRecording;
use App\Services\Retention\Concerns\NamesTheRow;
use App\Services\Retention\RetentionPolicy;
use App\Services\Visio\Recording\SeanceRecordingRetentionService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Purge des enregistrements de visioconférence échus (#690).
 *
 * ## Elle enveloppe, elle ne réécrit pas
 *
 * `SeanceRecordingRetentionService` porte déjà la logique métier : éligibilité
 * fine, transaction avec verrou, suppression des médias et des artefacts. Cette
 * politique ne la duplique pas — elle la présente au moteur.
 *
 * La raison est technique autant que prudente. Ses méthodes sont typées sur
 * `SeanceRecording` ; PHP interdit d'élargir un paramètre en implémentant une
 * interface, donc lui faire porter {@see RetentionPolicy} directement casserait
 * ses appelants. Et sur de la destruction définitive, on ne réécrit pas une
 * logique éprouvée pour satisfaire une abstraction.
 *
 * ## L'écart rattrapé
 *
 * `recordings:purge` n'écrivait **aucune trace d'audit** — seule des quatre
 * commandes dans ce cas. Le moteur en écrit désormais une avant chaque
 * destruction, sans que cette classe ait à s'en soucier.
 */
final class SeanceRecordingsPolicy implements RetentionPolicy
{
    use NamesTheRow;

    public function __construct(private readonly SeanceRecordingRetentionService $service) {}

    public function key(): string
    {
        return 'recordings';
    }

    public function label(): string
    {
        return 'enregistrement(s) de visio';
    }

    /**
     * Un an. L'ancienne commande le lisait dans `recordings.retention_days` ;
     * `purge:run --days=` permet toujours de l'imposer au coup par coup.
     */
    public function defaultGraceDays(): int
    {
        $brut = config('recordings.retention_days', 365);

        return is_numeric($brut) && (int) $brut > 0 ? (int) $brut : 365;
    }

    public function eligible(CarbonInterface $cutoff): Builder
    {
        // `withoutGlobalScope` : la purge est une opération de plateforme, elle
        // traverse les tenants. Le scope masquerait l'essentiel de l'arriéré.
        return SeanceRecording::withoutGlobalScope('institution')
            ->with(['seance', 'chapter'])
            ->where('created_at', '<', $cutoff);
    }

    public function describe(Model $item): string
    {
        if (! $item instanceof SeanceRecording) {
            return 'ligne inattendue';
        }

        $cree = $item->created_at?->toDateString() ?? 'date inconnue';

        return "enregistrement #{$this->identifiant($item)}, créé le {$cree}";
    }

    public function auditAction(): string
    {
        return 'recording.purged';
    }

    /**
     * Le service décide, et son verdict est plus fin que la date : un
     * enregistrement encore ACTIF n'est jamais éligible, quel que soit son âge.
     * La requête ne peut pas l'exprimer — d'où ce second filtre.
     */
    public function refuses(Model $item, CarbonInterface $cutoff): ?string
    {
        if (! $item instanceof SeanceRecording) {
            return 'ligne inattendue';
        }

        return $this->service->eligible($item, $cutoff)
            ? null
            : 'non éligible — enregistrement encore actif, ou trop récent selon son ancrage';
    }

    public function purge(Model $item, CarbonInterface $cutoff): void
    {
        if ($item instanceof SeanceRecording) {
            $this->service->purge($item, $cutoff);
        }
    }
}
