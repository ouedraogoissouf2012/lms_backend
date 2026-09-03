<?php

return [
    'retention_days' => 365,
    'purge_chunk_size' => 100,

    // #514 — Délai de grâce (minutes) au-delà duquel un enregistrement arrêté
    // et resté en `Processing` (aucun webhook fournisseur pour le finaliser,
    // cf. #204) est marqué `Failed` par `recordings:fail-stale`.
    'stale_processing_minutes' => (int) env('RECORDINGS_STALE_PROCESSING_MINUTES', 30),

    // #680 — Un enregistrement reste en `Recording` tant que personne ne clique
    // « Arrêter ». Si l'enseignant ferme son onglet, la ligne ne se referme
    // JAMAIS : le verrou n'est pas rendu et l'écran affirme « en cours »
    // indéfiniment. Constaté en production le 2026-09-02.
    //
    // Ces deux seuils sont volontairement DISTINCTS de `stale_processing_minutes` :
    // réutiliser ses 30 minutes couperait tout cours de plus d'une demi-heure.

    // Grâce après la FIN de la visio (`seances.visio_ended_at`). Elle existe
    // parce que Jibri finalise encore son fichier quelques instants après le
    // départ du dernier participant : marquer `Failed` trop tôt détruirait un
    // enregistrement valide.
    'stale_recording_grace_minutes' => (int) env('RECORDINGS_STALE_RECORDING_GRACE_MINUTES', 15),

    // Plafond absolu, filet de dernier recours quand `visio_ended_at` reste nul
    // parce que la visio elle-même est restée bloquée en `active`. Doit rester
    // nettement au-dessus de la durée d'un cours réel.
    'max_recording_hours' => (int) env('RECORDINGS_MAX_RECORDING_HOURS', 6),
];
