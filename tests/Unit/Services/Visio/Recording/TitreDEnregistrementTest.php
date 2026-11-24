<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Visio\Recording;

use App\Models\Chapter;
use App\Models\Seance;
use App\Services\Visio\Recording\TitreDEnregistrement;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * #854 — le titre d'un chapitre d'enregistrement distingue sa séance.
 *
 * Mesuré en production le 2026-09-18 : deux séances de « Marketing digital » donnaient deux
 * chapitres « Recording - Marketing digital ». Sur un semestre, vingt chapitres du même nom.
 *
 * Les deux volets : une séance KLASSCI, et une séance locale d'école autonome, qui n'a pas de
 * `klassci_seance_id` et souvent pas de titre. Le rattachement d'une séance locale est bloqué
 * en amont (#1483) ; la règle du titre est donc prouvée ici, sur la séance elle-même.
 */
#[CoversClass(TitreDEnregistrement::class)]
final class TitreDEnregistrementTest extends TestCase
{
    public function test_une_seance_klassci_porte_sa_date_et_son_heure(): void
    {
        $seance = $this->seance(['titre' => 'Marketing digital', 'klassci_seance_id' => 501, 'date_seance' => '2026-09-18 08:00:00']);

        self::assertSame('Enregistrement - Marketing digital - 18/09/2026 08:00', $this->titres()->automatique($seance, null));
    }

    public function test_une_seance_locale_sans_titre_prend_sa_matiere(): void
    {
        $seance = $this->seance(['titre' => '', 'matiere_nom' => 'Comptabilité', 'klassci_seance_id' => null, 'date_seance' => '2026-09-18 10:30:00']);

        self::assertSame('Enregistrement - Comptabilité - 18/09/2026 10:30', $this->titres()->automatique($seance, null));
    }

    public function test_sans_titre_ni_matiere_la_seance_reste_nommee(): void
    {
        $seance = $this->seance(['titre' => null, 'matiere_nom' => null, 'klassci_seance_id' => null, 'date_seance' => '2026-09-18 10:30:00']);

        self::assertSame('Enregistrement - Séance - 18/09/2026 10:30', $this->titres()->automatique($seance, null));
    }

    public function test_sans_date_de_seance_le_debut_de_la_visio_fait_foi(): void
    {
        $seance = $this->seance(['titre' => 'Marketing digital', 'date_seance' => null, 'visio_started_at' => '2026-09-19 14:05:00']);

        self::assertSame('Enregistrement - Marketing digital - 19/09/2026 14:05', $this->titres()->automatique($seance, null));
    }

    public function test_sans_aucune_date_le_numero_local_distingue(): void
    {
        $seance = $this->seance(['id' => 42, 'titre' => 'Marketing digital', 'klassci_seance_id' => null, 'date_seance' => null, 'visio_started_at' => null]);

        self::assertSame('Enregistrement - Marketing digital - séance n°42', $this->titres()->automatique($seance, null));
    }

    public function test_deux_seances_le_meme_jour_ont_deux_titres(): void
    {
        $matin = $this->seance(['titre' => 'Marketing digital', 'date_seance' => '2026-09-18 08:00:00']);
        $soir = $this->seance(['titre' => 'Marketing digital', 'date_seance' => '2026-09-18 14:00:00']);

        self::assertNotSame($this->titres()->automatique($matin, null), $this->titres()->automatique($soir, null));
    }

    public function test_un_titre_fourni_par_le_fournisseur_est_repris_tel_quel(): void
    {
        $seance = $this->seance(['titre' => 'Marketing digital', 'date_seance' => '2026-09-18 08:00:00']);

        self::assertSame('Replay live', $this->titres()->automatique($seance, 'Replay live'));
    }

    public function test_un_nouveau_chapitre_prend_le_titre_automatique(): void
    {
        self::assertSame('Nouveau', $this->titres()->retenu(new Chapter, $this->seance([]), 'Nouveau'));
    }

    public function test_un_chapitre_encore_automatique_suit_le_nouveau_titre(): void
    {
        $chapitre = $this->chapitre('Enregistrement - Marketing digital - 18/09/2026 08:00', ['titre_auto' => 'Enregistrement - Marketing digital - 18/09/2026 08:00']);

        self::assertSame('Nouveau', $this->titres()->retenu($chapitre, $this->seance([]), 'Nouveau'));
    }

    public function test_un_chapitre_renomme_par_l_enseignant_garde_son_titre(): void
    {
        $chapitre = $this->chapitre('Cours du lundi', ['titre_auto' => 'Enregistrement - Marketing digital - 18/09/2026 08:00']);

        self::assertSame('Cours du lundi', $this->titres()->retenu($chapitre, $this->seance([]), 'Nouveau'));
    }

    public function test_un_chapitre_a_l_ancien_defaut_est_renomme(): void
    {
        $seance = $this->seance(['titre' => 'Marketing digital', 'klassci_seance_id' => 501]);
        $chapitre = $this->chapitre('Recording - Marketing digital', []);

        self::assertSame('Nouveau', $this->titres()->retenu($chapitre, $seance, 'Nouveau'));
    }

    public function test_un_ancien_chapitre_renomme_garde_son_titre(): void
    {
        $seance = $this->seance(['titre' => 'Marketing digital', 'klassci_seance_id' => 501]);
        $chapitre = $this->chapitre('Cours du lundi', []);

        self::assertSame('Cours du lundi', $this->titres()->retenu($chapitre, $seance, 'Nouveau'));
    }

    private function titres(): TitreDEnregistrement
    {
        return new TitreDEnregistrement;
    }

    /** @param  array<string, mixed>  $attributs */
    private function seance(array $attributs): Seance
    {
        foreach (['date_seance', 'visio_started_at'] as $date) {
            if (is_string($attributs[$date] ?? null)) {
                $attributs[$date] = Carbon::parse($attributs[$date]);
            }
        }

        return (new Seance)->forceFill($attributs + ['id' => 7, 'titre' => 'Marketing digital']);
    }

    /** @param  array<string, mixed>  $notes */
    private function chapitre(string $titre, array $notes): Chapter
    {
        $chapitre = (new Chapter)->forceFill([
            'id' => 3,
            'title' => $titre,
            'notes_enseignant' => ['source' => 'visio_recording', 'seance_id' => 7] + $notes,
        ]);
        $chapitre->exists = true;

        return $chapitre;
    }
}
