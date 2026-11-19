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
 *   ACTIVATION_REINITIALISATION_MINUTES
 *                             Durée de vie d'un lien de réinitialisation. Défaut : 60.
 *   ACTIVATION_REINITIALISATION_INTERVALLE_MINUTES
 *                             Délai minimal entre deux liens pour un compte. Défaut : 2.
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
    | Le lien d'activation circule hors bande — WhatsApp, SMS, de vive voix —
    | entre le supradmin et le titulaire. Sept jours laissent le temps de le
    | transmettre et de l'utiliser, sans le laisser traîner indéfiniment dans
    | une conversation.
    |
    | L'expiration ne suffit pas à elle seule : la consommation est marquée en
    | base (`activation_tokens.consumed_at`), sans quoi le lien resterait
    | rejouable pendant toute sa fenêtre.
    |
    */
    'validite_jours' => (int) env('ACTIVATION_VALIDITE_JOURS', 7),

    /*
    |--------------------------------------------------------------------------
    | Lien de réinitialisation (#808)
    |--------------------------------------------------------------------------
    |
    | Le même jeton, envoyé par courriel à la demande du titulaire (« mot de
    | passe oublié »). Le courriel peut être lu par d'autres que lui : le lien
    | doit vivre COURT. La page front est la même (`/activation/{jeton}`).
    |
    | L'intervalle borne le bombardement d'une adresse : un jeton émis pour ce
    | compte depuis moins longtemps suffit, ni nouveau jeton ni courriel. La
    | réponse à la demande n'en dit rien.
    |
    */
    'reinitialisation_minutes' => (int) env('ACTIVATION_REINITIALISATION_MINUTES', 60),

    'reinitialisation_intervalle_minutes' => (int) env('ACTIVATION_REINITIALISATION_INTERVALLE_MINUTES', 2),

];
