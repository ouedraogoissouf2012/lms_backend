<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Matiere;

use App\Services\Matiere\MatiereClassesExtractor;
use PHPUnit\Framework\TestCase;

/**
 * `classes_concernees` dérivées des séances — corrige le 403 à la création de
 * leçon (le frontend envoyait `classe_id` null faute de cette clé dans le
 * contrat `/lms/matieres/{id}`).
 *
 * @see app/Services/Matiere/MatiereClassesExtractor.php
 */
final class MatiereClassesExtractorTest extends TestCase
{
    public function test_dedoublonne_par_id_et_conserve_l_ordre(): void
    {
        $seances = [
            ['classe' => ['id' => 1, 'nom' => 'B2 COM']],
            ['classe' => ['id' => 2, 'nom' => 'B3 COM']],
            ['classe' => ['id' => 1, 'nom' => 'B2 COM']], // doublon
        ];

        $this->assertSame(
            [['id' => 1, 'nom' => 'B2 COM'], ['id' => 2, 'nom' => 'B3 COM']],
            MatiereClassesExtractor::fromSeances($seances),
        );
    }

    public function test_ignore_les_seances_sans_classe_valide(): void
    {
        $seances = [
            ['classe' => null],
            ['classe' => ['nom' => 'Sans id']],
            ['classe' => ['id' => 'abc']],   // id non numérique
            ['programmation' => []],          // pas de clé classe
            ['classe' => ['id' => 5, 'nom' => 'OK']],
        ];

        $this->assertSame(
            [['id' => 5, 'nom' => 'OK']],
            MatiereClassesExtractor::fromSeances($seances),
        );
    }

    public function test_fallback_libelle_puis_na(): void
    {
        $seances = [
            ['classe' => ['id' => 1, 'libelle' => 'Via libelle']],
            ['classe' => ['id' => 2]],
        ];

        $this->assertSame(
            [['id' => 1, 'nom' => 'Via libelle'], ['id' => 2, 'nom' => 'N/A']],
            MatiereClassesExtractor::fromSeances($seances),
        );
    }

    public function test_liste_vide_donne_tableau_vide(): void
    {
        $this->assertSame([], MatiereClassesExtractor::fromSeances([]));
    }
}
