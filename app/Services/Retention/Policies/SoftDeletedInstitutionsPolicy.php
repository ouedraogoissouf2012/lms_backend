<?php

declare(strict_types=1);

namespace App\Services\Retention\Policies;

use App\Models\Institution;
use App\Services\Retention\Concerns\NamesTheRow;
use App\Services\Retention\RetentionPolicy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Purge définitive des établissements mis en corbeille (#567) — désormais une
 * politique (#690).
 *
 * ## Deux écarts rattrapés au passage
 *
 * L'ancienne commande faisait un `->get()` sur tout le trashed : la mémoire
 * dépendait de l'arriéré. Le moteur découpe maintenant en lots comme partout
 * ailleurs, sans que cette classe ait à s'en soucier.
 *
 * Et son message final annonçait comme purgées des institutions que la boucle
 * venait d'épargner. Le refus est désormais une réponse explicite — {@see
 * refuses()} — que le moteur compte à part de ce qu'il a réellement détruit.
 */
final class SoftDeletedInstitutionsPolicy implements RetentionPolicy
{
    use NamesTheRow;

    public function key(): string
    {
        return 'institutions';
    }

    public function label(): string
    {
        return 'établissement(s) supprimé(s)';
    }

    public function defaultGraceDays(): int
    {
        return 30;
    }

    public function eligible(CarbonInterface $cutoff): Builder
    {
        return Institution::onlyTrashed()->where('deleted_at', '<', $cutoff);
    }

    public function describe(Model $item): string
    {
        if (! $item instanceof Institution) {
            return 'ligne inattendue';
        }

        $supprimeLe = $item->deleted_at?->toDateString() ?? 'date inconnue';

        return "établissement #{$this->identifiant($item)} ({$item->slug}), supprimé le {$supprimeLe}";
    }

    public function auditAction(): string
    {
        return 'institution.purged';
    }

    /**
     * `institution_id` ne porte pas de clé étrangère : rien en base n'empêche de
     * détruire un établissement dont les lignes filles subsistent. Elles
     * deviendraient alors des orphelines rattachées à un identifiant qui
     * n'existe plus — invisibles, et impossibles à réattribuer.
     *
     * On vérifie donc les principales relations déclarées du tenant, hors portée
     * globale : le scope multi-tenant masquerait précisément ce qu'on cherche.
     */
    public function refuses(Model $item): ?string
    {
        if (! $item instanceof Institution) {
            return 'ligne inattendue';
        }

        $peuple = $item->users()->withoutGlobalScope('institution')->exists()
            || $item->classes()->withoutGlobalScope('institution')->exists()
            || $item->lessons()->withoutGlobalScope('institution')->exists()
            || $item->evaluations()->withoutGlobalScope('institution')->exists();

        return $peuple
            ? 'des lignes filles subsistent — la purge orphelinerait leurs données'
            : null;
    }

    public function purge(Model $item): void
    {
        if ($item instanceof Institution) {
            $item->forceDelete();
        }
    }
}
