<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rétention du journal d'audit (#215)
    |--------------------------------------------------------------------------
    |
    | Nombre de jours pendant lesquels les entrées d'audit sont conservées.
    | La commande `audit:purge` supprime les entrées plus anciennes. SOC2/GDPR
    | exigent un minimum ; ne pas descendre sous 90 jours sans décision produit.
    |
    */
    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),

    /*
    |--------------------------------------------------------------------------
    | Activation
    |--------------------------------------------------------------------------
    |
    | Permet de désactiver l'enregistrement (ex. tests de performance). Activé
    | par défaut — la traçabilité est un invariant de conformité.
    |
    */
    'enabled' => (bool) env('AUDIT_ENABLED', true),

];
