<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthPasswordServiceProvider;
use App\Providers\CatalogueServiceProvider;
use App\Providers\EvaluationServiceProvider;
use App\Providers\FileConversionServiceProvider;
use App\Providers\QueueFailureServiceProvider;
use App\Providers\RateLimitServiceProvider;
use App\Providers\RetentionServiceProvider;
use App\Providers\RosterServiceProvider;
use App\Providers\SSLVerificationProvider;
use App\Providers\VisioServiceProvider;

return [
    AppServiceProvider::class,
    AuthPasswordServiceProvider::class,
    CatalogueServiceProvider::class,
    EvaluationServiceProvider::class,
    FileConversionServiceProvider::class,
    QueueFailureServiceProvider::class,
    RateLimitServiceProvider::class,
    RosterServiceProvider::class,
    RetentionServiceProvider::class,
    SSLVerificationProvider::class,
    VisioServiceProvider::class,
];
