<?php

declare(strict_types=1);

namespace App\Services\Retention\Policies;

use App\Models\AuditLog;
use App\Services\Retention\Concerns\NamesTheRow;
use App\Services\Retention\RetentionPolicy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Purge du journal d'audit au-delà du seuil de rétention (#215) — désormais une
 * politique (#690).
 *
 * ## Le seul domaine qui refuse une trace par élément
 *
 * Auditer chaque ligne d'audit supprimée écrirait une ligne pour chaque ligne
 * effacée : un net nul, qui croît indéfiniment et ne dit rien. La politique rend
 * donc `null` sur {@see auditAction()}, et le moteur écrit UNE entrée de
 * synthèse — combien, jusqu'à quelle date.
 *
 * C'est l'information qu'un auditeur vient chercher. Sans elle, la disparition
 * de lignes serait indiscernable d'une altération.
 *
 * ## Ce que le passage sous contrat rattrape
 *
 * L'ancienne commande faisait un `->delete()` en masse : la simulation comptait,
 * l'exécution supprimait, et rien n'était tracé. Le moteur découpe désormais en
 * lots — la suppression n'est plus un seul verrou sur une table qui peut
 * compter des millions de lignes — et la synthèse est écrite.
 *
 * Le coût est réel et assumé : détruire ligne par ligne est plus lent qu'un
 * `DELETE WHERE`. Sur une purge quotidienne dont l'arriéré est borné, la
 * traçabilité et l'absence de verrou long valent ce prix.
 */
final class AuditLogsPolicy implements RetentionPolicy
{
    use NamesTheRow;

    public function key(): string
    {
        return 'audit-logs';
    }

    public function label(): string
    {
        return 'entrée(s) de journal d\'audit';
    }

    public function defaultGraceDays(): int
    {
        $brut = config('audit.retention_days', 365);

        return is_numeric($brut) && (int) $brut > 0 ? (int) $brut : 365;
    }

    public function eligible(CarbonInterface $cutoff): Builder
    {
        // Le journal d'audit est cross-tenant par nature : le scope masquerait
        // les entrées des autres établissements, qui vieillissent aussi.
        return AuditLog::withoutGlobalScope('institution')->where('created_at', '<', $cutoff);
    }

    public function describe(Model $item): string
    {
        if (! $item instanceof AuditLog) {
            return 'ligne inattendue';
        }

        // `created_at` n'est pas nullable sur ce modèle — une entrée d'audit sans
        // date n'existe pas. Un `?->` ici serait du bruit qui laisse croire au
        // contraire.
        return "entrée #{$this->identifiant($item)} ({$item->action}), du {$item->created_at->toDateString()}";
    }

    /** Voir le docblock de classe : une trace par élément serait un net nul. */
    public function auditAction(): ?string
    {
        return null;
    }

    /** Aucun refus : une entrée au-delà du seuil de rétention est purgeable. */
    public function refuses(Model $item, CarbonInterface $cutoff): ?string
    {
        return null;
    }

    public function purge(Model $item, CarbonInterface $cutoff): void
    {
        if ($item instanceof AuditLog) {
            $item->delete();
        }
    }
}
