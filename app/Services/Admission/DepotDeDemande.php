<?php

declare(strict_types=1);

namespace App\Services\Admission;

use App\Enums\EtatDeLaDemande;
use App\Models\DemandeInscription;
use App\Models\Institution;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Le dépôt d'une demande d'inscription par la porte publique (#1659). Il ne CONFIRME RIEN à l'appelant : école
 * inconnue, inactive, KLASSCI, plafond du jour atteint ou demande enregistrée, l'appelant reçoit la même réponse.
 * Seule une école autonome et active enregistre la demande ; le reste est écarté en silence, et le journal le dit
 * (sans donnée personnelle).
 */
final class DepotDeDemande
{
    public function __construct(
        private readonly PorteDesAdmissions $porte,
        private readonly Config $config,
        private readonly LoggerInterface $journal,
    ) {}

    /** @param  array<string, mixed>  $demande  validée par `DeposerDemandeInscriptionRequest` */
    public function deposer(string $slug, array $demande): void
    {
        $ecole = Institution::query()->where('slug', $slug)->where('is_active', true)->first();
        if (! $ecole instanceof Institution || ! $this->porte->ouvertePour($ecole)) {
            $this->journal->info('Demande d\'inscription écartée : école fermée aux demandes', ['ecole' => $slug]);

            return;
        }

        $plafond = $this->config->get('admissions.plafond_journalier', 100);
        $duJour = DemandeInscription::query()->withoutGlobalScopes()
            ->where('institution_id', $ecole->id)->where('created_at', '>=', Carbon::today())->count();
        if (is_int($plafond) && $duJour >= $plafond) {
            $this->journal->warning('Demande d\'inscription écartée : plafond journalier de l\'école atteint', ['institution_id' => $ecole->id]);

            return;
        }

        DemandeInscription::query()->create($demande + [
            'institution_id' => $ecole->id,
            'etat' => EtatDeLaDemande::Recue,
            'consentement_le' => Carbon::now(),
        ]);
    }
}
