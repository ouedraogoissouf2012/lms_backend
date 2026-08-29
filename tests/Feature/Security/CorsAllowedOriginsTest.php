<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Tests\TestCase;

final class CorsAllowedOriginsTest extends TestCase
{
    public function test_configured_frontend_origin_is_reflected_in_cors_header(): void
    {
        $response = $this->withHeaders(['Origin' => 'https://edu.klassci.com'])
            ->getJson('/api/ping');

        $response->assertHeader('Access-Control-Allow-Origin', 'https://edu.klassci.com');
    }

    public function test_untrusted_origin_never_receives_its_own_origin_reflected_back(): void
    {
        $response = $this->withHeaders(['Origin' => 'https://attaquant.example'])
            ->getJson('/api/ping');

        $header = $response->headers->get('Access-Control-Allow-Origin');

        $this->assertNotSame(
            'https://attaquant.example',
            $header,
            'Le navigateur bloquerait la lecture de la réponse côté JS uniquement si ce '
                . 'header ne matche pas https://attaquant.example — jamais l\'inverse.'
        );
    }
}
