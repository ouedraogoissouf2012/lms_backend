<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Enums\ConsentPurpose;
use App\Models\User;

/**
 * Dépose et relit le consentement visio d'un compte — le raccord qui manquait
 * entre le recueil (front #333) et le garde (#716).
 *
 * ## Pourquoi cette classe existe
 *
 * Les deux moitiés avaient été livrées séparément, et jamais reliées :
 *
 * - le **frontend** recueille les trois finalités dans un écran de réglages,
 *   puis les range dans `localStorage`. Son propre docblock l'annonce comme
 *   provisoire : « Persistance locale scopée (cacheKey) en attendant
 *   lms_backend#716 » ;
 * - le **backend** a la table `consents`, le garde append-only et
 *   {@see RecordingConsentGuard::record()} — mais aucune route n'y menait, et
 *   aucun service ne l'appelait.
 *
 * Résultat : `allowsStart()` rendait toujours `false`, tout enregistrement
 * repartait en 422, et le `.mp4` que Jibri produisait malgré tout finissait
 * orphelin — le webhook de fin ne trouvant aucune ligne active. Mesuré : depuis
 * le déploiement de #716 le 2026-09-06, aucun enregistrement n'a pu aboutir.
 *
 * ## Ce que cette classe ne fait pas
 *
 * Elle n'assouplit PAS le garde. Sans consentement, l'enregistrement reste
 * refusé — c'est la décision de #716, protégée par
 * `RecordingConsentGuardTest::test_start_without_consent_is_rejected`. On ouvre
 * seulement la porte qui permet de donner ce consentement.
 *
 * ## Le consentement porte sur le COMPTE, pas sur une séance
 *
 * C'est la forme que le frontend recueille — un réglage de profil — et le garde
 * la gère déjà nativement : il accepte `seance_id = X` **ou** `seance_id IS NULL`.
 * Un dépôt vaut donc pour toutes les séances du porteur, jusqu'à révocation.
 *
 * Vérifié par tests/Feature/Visio/VisioConsentPersistenceTest.php.
 */
final class VisioConsentService
{
    /**
     * La clé du frontend pour chaque finalité. `captation` est le seul écart de
     * vocabulaire entre les deux côtés ; les deux autres sont identiques.
     *
     * @var array<string, ConsentPurpose>
     */
    private const FINALITES = [
        'captation' => ConsentPurpose::Capture,
        'diffusion' => ConsentPurpose::Diffusion,
        'reutilisation' => ConsentPurpose::Reutilisation,
    ];

    public function __construct(
        private readonly RecordingConsentGuard $guard,
    ) {}

    /**
     * Enregistre les trois finalités, en append-only.
     *
     * Une ligne par finalité et par dépôt, y compris pour un refus : « a refusé
     * la réutilisation le 14 septembre » est une information aussi opposable
     * que l'acceptation, et la reconstruire a posteriori serait impossible si
     * l'on n'écrivait que les acceptations.
     *
     * @param  array<string, bool>  $choix
     * @param  array<string, mixed>  $preuve  Contexte de recueil (politique affichée, origine).
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function record(User $user, array $choix, array $preuve = []): array
    {
        // `consents.institution_id` est `constrained()`, donc NOT NULL. Un
        // compte hors etablissement — un supradmin — produirait une violation
        // de contrainte, c'est-a-dire un 500 sur un geste parfaitement legitime
        // a refuser. On le refuse proprement, en disant pourquoi.
        if ($user->institution_id === null) {
            return [
                'status' => 422,
                'payload' => [
                    'success' => false,
                    'message' => 'Un consentement se rattache a un etablissement : ce compte n en a aucun.',
                ],
            ];
        }

        foreach (self::FINALITES as $cle => $finalite) {
            $this->guard->record(
                subject: $user,
                // Le porteur consent pour lui-même : sujet et acteur sont la
                // même personne. Un consentement déposé POUR autrui — le cas du
                // représentant légal d'un mineur — passerait par un autre
                // chemin, avec sa propre autorisation.
                actor: $user,
                purpose: $finalite,
                granted: (bool) ($choix[$cle] ?? false),
                seance: null,
                evidence: $preuve + ['politique' => $this->guard->defaultLayout()],
            );
        }

        return [
            'status' => 200,
            'payload' => ['success' => true, 'data' => $this->etat($user)],
        ];
    }

    /**
     * L'état courant des trois finalités.
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function current(User $user): array
    {
        return [
            'status' => 200,
            'payload' => ['success' => true, 'data' => $this->etat($user)],
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function etat(User $user): array
    {
        $etat = [];

        foreach (self::FINALITES as $cle => $finalite) {
            $etat[$cle] = $this->guard->grantedAt($user, $finalite);
        }

        return $etat;
    }
}
