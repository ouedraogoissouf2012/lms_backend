<?php

declare(strict_types=1);

/**
 * Activation de compte par lien à usage unique (#803, ADR-803-02).
 *
 * Variables d'environnement attendues :
 *
 *   ACTIVATION_URL_FRONT      URL de base de l'application web, SANS slash final.
 *                             Exemple : https://lms.klassci.com
 *   ACTIVATION_VALIDITE_JOURS Durée de vie du lien, en jours. Défaut : 7.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Où vit l'écran d'activation
    |--------------------------------------------------------------------------
    |
    | Le lien remis au supradmin mène à une page de l'application WEB, pas à
    | l'API : c'est là que le titulaire saisit son mot de passe.
    |
    | Volontairement SANS repli sur `app.url`. Ce repli produirait un lien
    | pointant sur l'hôte du back, où aucune page d'activation n'existe — donc
    | un lien mort, transmis de bonne foi, et un compte définitivement
    | inaccessible. Mieux vaut un refus bruyant à la validation qu'un lien qui
    | échoue chez le destinataire, hors de portée de tout journal.
    |
    */
    'url_front' => env('ACTIVATION_URL_FRONT'),

    /*
    |--------------------------------------------------------------------------
    | Durée de vie du lien
    |--------------------------------------------------------------------------
    |
    | Le lien circule hors bande — WhatsApp, SMS, de vive voix — parce que le
    | produit n'a aucun canal de courriel. Sept jours laissent le temps de le
    | transmettre et de l'utiliser, sans le laisser traîner indéfiniment dans
    | une conversation.
    |
    | L'expiration ne suffit pas à elle seule : la consommation est marquée en
    | base (`activation_tokens.consumed_at`), sans quoi le lien resterait
    | rejouable pendant toute sa fenêtre.
    |
    */
    'validite_jours' => (int) env('ACTIVATION_VALIDITE_JOURS', 7),

];
