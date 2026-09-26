<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Presenters\TooManyRequestsPresenter;
use Tests\TestCase;

/**
 * #906 — le 429 de débit a UN constructeur, et il n'ajoute un motif que si on
 * lui en donne un.
 *
 * La règle d'omission est ce qui garantit que les seaux sans motif — proxy,
 * recherche, demandes d'ouverture — gardent exactement leur forme d'avant #906.
 */
final class TooManyRequestsPresenterTest extends TestCase
{
    public function test_sans_motif_la_forme_d_avant_906_est_intacte(): void
    {
        $reponse = TooManyRequestsPresenter::json(['Retry-After' => '30']);

        self::assertSame(429, $reponse->getStatusCode());
        self::assertSame('30', $reponse->headers->get('Retry-After'));
        self::assertSame(
            ['success' => false, 'message' => 'Trop de requêtes. Veuillez réessayer plus tard.'],
            $reponse->getData(true),
        );
    }

    public function test_avec_motif_seul_le_motif_s_ajoute(): void
    {
        $reponse = TooManyRequestsPresenter::json(['Retry-After' => '30'], 'account_quota_exceeded');

        self::assertSame(
            [
                'success' => false,
                'message' => 'Trop de requêtes. Veuillez réessayer plus tard.',
                'reason' => 'account_quota_exceeded',
            ],
            $reponse->getData(true),
        );
        self::assertSame('30', $reponse->headers->get('Retry-After'));
    }
}
