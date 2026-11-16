<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Les colonnes mortes de `lessons` ne sont plus sérialisées.
 *
 * Le contenu d'une leçon vit dans ses chapitres depuis la restructuration de 2025-10 :
 * ces colonnes y ont été copiées, sans être retirées de `lessons`, et plus rien ne les
 * écrit ni ne les lit sur une leçon (le front lit `description`, `prerequis`,
 * `objectifs_pedagogiques`). Même nettoyage que `duration_minutes` (#945) : la base n'est
 * pas touchée ; la sérialisation de la leçon (fiche, listes, recherche, page d'une
 * matière) ne les porte plus.
 *
 * Initialisé par Eloquent (`initialize{Trait}`) à chaque construction du modèle.
 */
trait ColonnesMortesDeLecon
{
    /** @var list<string> */
    private const COLONNES_MORTES = [
        'content',
        'content_type',
        'video_url',
        'video_provider',
        'pdf_url',
        'audio_url',
        'presentation_url',
        'external_link',
        'attachments',
    ];

    public function initializeColonnesMortesDeLecon(): void
    {
        $this->hidden = array_values(array_unique([...$this->hidden, ...self::COLONNES_MORTES]));
    }
}
