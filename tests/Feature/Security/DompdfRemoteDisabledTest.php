<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Barryvdh\DomPDF\PDF;
use Tests\TestCase;

/**
 * #549 — Dompdf ne charge pas de ressources distantes (SSRF).
 */
final class DompdfRemoteDisabledTest extends TestCase
{
    public function test_dompdf_remote_fetching_is_disabled(): void
    {
        $pdf = $this->app->make(PDF::class);
        $options = $pdf->getDomPDF()->getOptions();

        self::assertFalse($options->getIsRemoteEnabled());
    }
}
