<?php

namespace Tests;

use App\Http\Middleware\EnsureKlassciSync;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Skip the KLASSCI sync middleware for tests that don't need
     * a real connection to the KLASSCI external API.
     *
     * Used by feature tests that focus on FormRequest validation,
     * authorization or HTTP routing — not on the KLASSCI sync flow itself.
     */
    protected function disableKlassciMiddleware(): void
    {
        $this->withoutMiddleware(EnsureKlassciSync::class);
    }
}
