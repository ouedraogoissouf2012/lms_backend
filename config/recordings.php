<?php

return [
    'retention_days' => 365,
    'purge_chunk_size' => 100,

    // #514 — Délai de grâce (minutes) au-delà duquel un enregistrement arrêté
    // et resté en `Processing` (aucun webhook fournisseur pour le finaliser,
    // cf. #204) est marqué `Failed` par `recordings:fail-stale-processing`.
    'stale_processing_minutes' => (int) env('RECORDINGS_STALE_PROCESSING_MINUTES', 30),
];
