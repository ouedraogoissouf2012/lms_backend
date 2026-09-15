<?php

declare(strict_types=1);

namespace App\Services\SchoolRegistration;

use App\Enums\SchoolRequestStatus;
use App\Models\SchoolRegistrationRequest;
use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;

/**
 * Dépôt d'une demande d'ouverture d'école (#803, ADR-803-01).
 *
 * ## Une seule demande en attente par adresse
 *
 * Redéposer met à jour la demande existante au lieu d'en créer une seconde :
 * sans cela, un formulaire soumis deux fois — double-clic, retour arrière,
 * réseau capricieux — polluerait la file du supradmin de doublons qu'il devrait
 * trancher un par un.
 *
 * La contrainte est aussi posée EN BASE (unicité partielle sur
 * `email_demandeur` + colonne générée). Les deux ne font pas double emploi :
 *
 *   - la base garantit l'INTÉGRITÉ, y compris quand deux requêtes simultanées
 *     franchissent toutes deux la lecture avant qu'aucune n'ait écrit ;
 *   - le code garantit l'EXPÉRIENCE : sans le rattrapage ci-dessous, la seconde
 *     requête remonterait la violation en 500 sur un endpoint public, là où un
 *     double-clic est le comportement le plus banal qui soit.
 *
 * Les demandes déjà tranchées ne sont jamais touchées : un dossier refusé doit
 * pouvoir être redéposé, et un dossier validé reste l'archive de sa décision.
 *
 * @see docs/adr/2026-09-15-803-01-demande-publique.md
 */
final class SchoolRegistrationRequestService
{
    /** SQLSTATE de violation de contrainte d'intégrité — commun à MySQL et SQLite. */
    private const VIOLATION_INTEGRITE = '23000';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  array<string, mixed>  $valide  Charge déjà validée par le FormRequest.
     */
    public function deposer(array $valide): SchoolRegistrationRequest
    {
        $email = is_string($valide['email_demandeur'] ?? null) ? $valide['email_demandeur'] : '';

        $demande = $this->enAttentePour($email);

        if ($demande instanceof SchoolRegistrationRequest) {
            $demande->fill($valide)->save();
        } else {
            $demande = $this->creerOuRattraper($valide, $email);
        }

        // Journalisé sans l'adresse : la file du supradmin est la source de
        // vérité, le journal n'a pas à dupliquer une donnée personnelle.
        $this->logger->info('Demande d\'ouverture d\'école déposée', [
            'demande_id' => $demande->getKey(),
            'nom_ecole' => $demande->nom_ecole,
        ]);

        return $demande;
    }

    private function enAttentePour(string $email): ?SchoolRegistrationRequest
    {
        return SchoolRegistrationRequest::query()
            ->where('email_demandeur', $email)
            ->where('statut', SchoolRequestStatus::EnAttente->value)
            ->first();
    }

    /**
     * Crée la demande, ou rattrape la course perdue contre une requête jumelle.
     *
     * @param  array<string, mixed>  $valide
     */
    private function creerOuRattraper(array $valide, string $email): SchoolRegistrationRequest
    {
        try {
            return SchoolRegistrationRequest::query()->create($valide);
        } catch (QueryException $e) {
            if ((string) $e->getCode() !== self::VIOLATION_INTEGRITE) {
                throw $e;
            }

            // Une requête jumelle a écrit entre notre lecture et notre écriture.
            // Sa ligne fait désormais foi : on la met à jour au lieu d'échouer.
            $concurrente = $this->enAttentePour($email);

            if (! $concurrente instanceof SchoolRegistrationRequest) {
                // La violation ne venait pas de l'unicité partielle : ne pas
                // l'avaler, ce serait masquer un défaut d'intégrité réel.
                throw $e;
            }

            $concurrente->fill($valide)->save();

            return $concurrente;
        }
    }
}
