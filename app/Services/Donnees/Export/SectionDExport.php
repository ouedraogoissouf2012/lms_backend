<?php

declare(strict_types=1);

namespace App\Services\Donnees\Export;

use App\Models\User;

/**
 * Une section de l'export des données d'un élève (#1705) : UNE source de données, bornée à l'élève ET à son école.
 * Toutes les sections vivent en un seul lieu, {@see SectionsDeLExport} : une table nouvelle qui porte des données d'un
 * élève s'y ajoute là.
 */
interface SectionDExport
{
    /** La clé de la section dans l'export JSON. */
    public function cle(): string;

    /** Le titre de la section dans l'export PDF. */
    public function titre(): string;

    /**
     * Les lignes que le LMS détient sur l'élève dans cette source, sans aucun secret.
     *
     * @return list<array<string, mixed>>
     */
    public function lignes(User $eleve, int $ecole): array;
}
