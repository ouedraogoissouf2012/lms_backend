<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthPasswordServiceProvider;
use App\Providers\CatalogueServiceProvider;
use App\Providers\FileConversionServiceProvider;
use App\Providers\QueueFailureServiceProvider;
use App\Providers\RateLimitServiceProvider;
use App\Providers\RetentionServiceProvider;
use App\Providers\RosterServiceProvider;
use App\Providers\SSLVerificationProvider;

return [
    AppServiceProvider::class,
    AuthPasswordServiceProvider::class,
    CatalogueServiceProvider::class,
    FileConversionServiceProvider::class,
    QueueFailureServiceProvider::class,
    RateLimitServiceProvider::class,
    RosterServiceProvider::class,
    RetentionServiceProvider::class,
    SSLVerificationProvider::class,
];
