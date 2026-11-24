<?php

declare(strict_types=1);

namespace App\Services\Retention\Policies;

use App\Models\Institution;
use App\Services\Retention\Concerns\NamesTheRow;
use App\Services\Retention\RetentionPolicy;
use App\Services\Tenancy\LignesDUneInstitution;
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

    /** @param  list<string>  $tablesDeclarees  `tenancy.institution_scoped_tables` */
    public function __construct(
        private readonly LignesDUneInstitution $lignes,
        private readonly array $tablesDeclarees,
    ) {}

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
     * Une école qui possède encore une ligne, dans N'IMPORTE LAQUELLE des tables déclarées
     * (`config/tenancy.php`), est épargnée, et le refus nomme ces tables (#719).
     *
     * Ce docblock disait le contraire jusqu'ici : « `institution_id` ne porte pas de clé
     * étrangère ». C'est faux depuis #583 : les tables déclarées la portent en RESTRICT.
     * Seules quatre relations étaient lues (users, classes, lessons, evaluations) ; une école
     * vide de celles-là mais peuplée ailleurs faisait échouer le `forceDelete()` sur la
     * contrainte. Détruire une école peuplée, fichiers compris, est une autre décision (#1487).
     */
    public function refuses(Model $item, CarbonInterface $cutoff): ?string
    {
        if (! $item instanceof Institution) {
            return 'ligne inattendue';
        }

        $id = $item->getKey();
        if (! is_numeric($id)) {
            return 'identifiant illisible';
        }

        $peuplees = $this->lignes->tablesPeuplees((int) $id, $this->tablesDeclarees);

        return $peuplees === []
            ? null
            : 'des lignes subsistent dans : '.implode(', ', $peuplees).' — la purge détruirait leurs données (#1487)';
    }

    public function purge(Model $item, CarbonInterface $cutoff): void
    {
        if ($item instanceof Institution) {
            $item->forceDelete();
        }
    }
}
