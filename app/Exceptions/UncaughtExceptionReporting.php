<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * #948 — ce que le journal garde d'une exception non attrapée, selon son statut.
 *
 * - **5xx, ou exception sans statut** : une panne. Le rapporteur de Laravel
 *   l'écrit UNE fois, en `error`, avec la trace. Le rendu JSON de
 *   `bootstrap/app.php` ne journalise plus rien : il l'écrivait une seconde fois.
 * - **403** : un refus n'est pas une panne, mais c'est la seule trace d'un refus
 *   levé en exception (FormRequest, `abort`) ; les middlewares de rôle écrivent
 *   les leurs, et aucun refus n'est audité par `AuditLogger`. Il est écrit en
 *   `warning`, SANS trace : c'est le signal d'un sondage d'accès.
 * - **Autres 4xx** (404, 405, 429…) : rien. Un balayage d'URL ne remplit plus le
 *   journal.
 *
 * Le rapporteur reçoit l'exception AVANT `prepareException()` : un refus de
 * FormRequest y arrive en `AuthorizationException` brute, pas en
 * `AccessDeniedHttpException`. Le statut est donc lu sur les deux formes.
 *
 * De la requête, seuls la route (nommée, ou le chemin sans chaîne de requête) et
 * la méthode sont écrits ; `request_id` l'est déjà par `AssignRequestId`
 * (`Log::shareContext`). Ni corps, ni chaîne de requête, ni en-tête, ni compte ;
 * seul le rapport d'une panne porte encore le `userId` que Laravel y ajoute
 * (`Handler::context()`), dont le retrait est différé au lot Handler.
 *
 * Résolue par `bootstrap/app.php`, racine de composition, dans chaque callback.
 */
final class UncaughtExceptionReporting
{
    public const ACCESS_DENIED_MESSAGE = 'Accès refusé';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /** Un 4xx autre qu'un refus d'accès : rien à journaliser. */
    public function isSilentClientError(Throwable $e): bool
    {
        $status = $this->statusOf($e);

        return $status !== null && $status < 500 && $status !== 403;
    }

    /**
     * Écrit le refus d'accès en `warning`, sans trace, et arrête le rapport par
     * défaut (`false`). Toute autre exception poursuit son rapport (`null`).
     */
    public function reportAccessDenied(Throwable $e, Request $request): ?bool
    {
        if ($this->statusOf($e) !== 403) {
            return null;
        }

        $this->logger->warning(self::ACCESS_DENIED_MESSAGE, $this->requestContext($request));

        return false;
    }

    /**
     * La route et la méthode de la requête en cours, si une requête HTTP a été
     * routée ; rien pour une commande ou un job.
     *
     * @return array{route?: string, method?: string}
     */
    public function requestContext(Request $request): array
    {
        $route = $request->route();
        if (! $route instanceof Route) {
            return [];
        }

        return [
            'route' => $route->getName() ?? $request->path(),
            'method' => $request->getMethod(),
        ];
    }

    /** Le statut HTTP que portera la réponse, ou null pour une panne sans statut. */
    private function statusOf(Throwable $e): ?int
    {
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        if ($e instanceof AuthorizationException) {
            return $e->status() ?? 403;
        }

        return null;
    }
}
