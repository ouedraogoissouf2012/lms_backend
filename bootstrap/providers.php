<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthPasswordServiceProvider;
use App\Providers\FileConversionServiceProvider;
use App\Providers\RateLimitServiceProvider;
use App\Providers\RetentionServiceProvider;
use App\Providers\RosterServiceProvider;
use App\Providers\SSLVerificationProvider;

return [
    AppServiceProvider::class,
    AuthPasswordServiceProvider::class,
    FileConversionServiceProvider::class,
    RateLimitServiceProvider::class,
    RosterServiceProvider::class,
    RetentionServiceProvider::class,
    SSLVerificationProvider::class,
];
