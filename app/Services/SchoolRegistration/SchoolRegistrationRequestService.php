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
            // LE PREMIER DÉPÔT FAIT FOI. Cet endpoint est anonyme et l'adresse
            // n'est jamais vérifiée — le projet n'a même pas de canal d'envoi.
            // La traiter comme une preuve de propriété permettait à n'importe
            // qui de réécrire la demande d'une école dont l'adresse est
            // publiée, sans laisser de trace. Depuis #815, ce sont ces champs
            // qui deviennent l'Institution et son premier compte `superAdmin`.
            //
            // Conséquence assumée : corriger une faute de frappe ne se fait
            // plus en redéposant. C'est au supradmin d'arbitrer, jusqu'à ce
            // qu'un canal vérifié existe.
            $this->journaliserDivergence($demande, $valide);

            return $demande;
        }

        $demande = $this->creerOuRattraper($valide, $email);

        // Journalisé sans l'adresse : la file du supradmin est la source de
        // vérité, le journal n'a pas à dupliquer une donnée personnelle.
        $this->logger->info('Demande d\'ouverture d\'école déposée', [
            'demande_id' => $demande->getKey(),
            'nom_ecole' => $demande->nom_ecole,
        ]);

        return $demande;
    }

    /**
     * Un second dépôt pour une adresse déjà en attente n'écrit rien, mais ne
     * disparaît pas non plus : le supradmin doit pouvoir voir qu'une demande a
     * été contestée avant de l'approuver.
     *
     * @param  array<string, mixed>  $valide
     */
    private function journaliserDivergence(SchoolRegistrationRequest $demande, array $valide): void
    {
        $diverge = [];

        foreach (['nom_demandeur', 'telephone_demandeur', 'nom_ecole', 'slug_souhaite'] as $champ) {
            $propose = $valide[$champ] ?? null;

            if (is_string($propose) && $propose !== (string) $demande->{$champ}) {
                $diverge[] = $champ;
            }
        }

        if ($diverge === []) {
            return;
        }

        // Les VALEURS proposées ne sont pas journalisées : elles viennent d'un
        // inconnu, et le journal n'a pas à devenir le canal par lequel il écrit
        // quelque chose. Seuls les champs concernés sont nommés.
        $this->logger->warning('Second dépôt divergent sur une demande en attente — ignoré', [
            'demande_id' => $demande->getKey(),
            'champs' => $diverge,
        ]);
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
            // Sa ligne fait foi — et « faire foi » veut dire qu'on n'y touche
            // pas. L'ancien code la mettait à jour juste après l'avoir déclarée
            // prioritaire, ce qui rouvrait par la course la primitive
            // d'écrasement fermée sur le chemin nominal : il suffisait de
            // déposer en même temps que sa victime.
            $concurrente = $this->enAttentePour($email);

            if (! $concurrente instanceof SchoolRegistrationRequest) {
                // La violation ne venait pas de l'unicité partielle : ne pas
                // l'avaler, ce serait masquer un défaut d'intégrité réel.
                throw $e;
            }

            $this->journaliserDivergence($concurrente, $valide);

            return $concurrente;
        }
    }
}
