<?php

declare(strict_types=1);

namespace App\Services\Retention\Policies;

use App\Enums\EtatDeLaDemande;
use App\Models\DemandeInscription;
use App\Services\Retention\RetentionPolicy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * La rétention des demandes d'inscription (#1659, loi 2013-450), en DEUX politiques, une par délai :
 * - `demandes-decidees` : rejetée ou inscrite, purgée 90 jours après sa DÉCISION. Une fois l'élève inscrit, ses
 *   données vivent dans son dossier ;
 * - `demandes-non-traitees` : reçue ou en examen, purgée 365 jours après son DÉPÔT.
 * Une demande `acceptee` (en cours d'inscription, PR 2) n'est jamais éligible.
 *
 * Planifiée chaque jour par `admissions:purge --apply` ; `purge:run` la simule et l'applique à la main, comme les
 * autres domaines.
 */
final class DemandesInscriptionPolicy implements RetentionPolicy
{
    private function __construct(
        private readonly string $cle,
        private readonly int $jours,
        private readonly bool $decidees,
    ) {}

    /** La durée vient du tableau unique des durées (#1705, `config/conservation.php`). */
    public static function decidees(): self
    {
        return new self('demandes-decidees', self::jours('conservation.demandes_decidees_jours', 90), true);
    }

    public static function nonTraitees(): self
    {
        return new self('demandes-non-traitees', self::jours('conservation.demandes_non_traitees_jours', 365), false);
    }

    /**
     * Une durée du tableau. Absente ou invalide : la durée de repli, JAMAIS zéro (zéro purgerait toutes les demandes).
     */
    private static function jours(string $cle, int $repli): int
    {
        $jours = config($cle);

        return is_numeric($jours) && (int) $jours > 0 ? (int) $jours : $repli;
    }

    public function key(): string
    {
        return $this->cle;
    }

    public function label(): string
    {
        return $this->decidees ? 'demande(s) d\'inscription décidée(s)' : 'demande(s) d\'inscription jamais traitée(s)';
    }

    public function defaultGraceDays(): int
    {
        return $this->jours;
    }

    public function eligible(CarbonInterface $cutoff): Builder
    {
        $requete = DemandeInscription::query()->withoutGlobalScopes();

        return $this->decidees
            ? $requete->whereIn('etat', [EtatDeLaDemande::Rejetee->value, EtatDeLaDemande::Inscrite->value])->where('traite_le', '<', $cutoff)
            : $requete->whereIn('etat', [EtatDeLaDemande::Recue->value, EtatDeLaDemande::EnExamen->value])->where('created_at', '<', $cutoff);
    }

    /** Aucune donnée personnelle : l'identifiant, l'école et l'état suffisent à relire la simulation. */
    public function describe(Model $item): string
    {
        if (! $item instanceof DemandeInscription) {
            return 'ligne inattendue';
        }

        return "demande #{$item->id} (école #{$item->institution_id}, {$item->etat->value})";
    }

    public function auditAction(): string
    {
        return 'demande_inscription.purged';
    }

    /** L'état relu au moment d'agir : une demande reprise entre-temps n'est plus du même délai. */
    public function refuses(Model $item, CarbonInterface $cutoff): ?string
    {
        if (! $item instanceof DemandeInscription) {
            return 'ligne inattendue';
        }

        $etat = $item->fresh()?->etat;

        return $etat instanceof EtatDeLaDemande && ($this->decidees ? $etat->decidee() : $etat->aTraiter())
            ? null
            : 'état changé depuis la lecture';
    }

    public function purge(Model $item, CarbonInterface $cutoff): void
    {
        $item->delete();
    }
}
