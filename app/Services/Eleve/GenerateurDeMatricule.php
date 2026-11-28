<?php

declare(strict_types=1);

namespace App\Services\Eleve;

use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Le matricule d'un élève d'une école autonome (#1657), selon le GABARIT de l'école.
 *
 * Jetons : `{AAAA}` (année sur 4 chiffres), `{AA}` (sur 2), et une suite de `N` entre accolades, la séquence complétée
 * de zéros à cette largeur (`{NNNN}` → `0007`). Défaut : `{AAAA}-{NNNN}`. Un gabarit sans séquence en reçoit une
 * (`-{NNNN}`) : sinon, il rendrait toujours le même matricule.
 *
 * La séquence vit dans `matricules_sequences` (école, année), incrémentée SOUS VERROU : deux créations simultanées n'ont
 * jamais le même numéro. Un numéro déjà pris (un matricule saisi à la main qui suit le gabarit) est sauté.
 *
 * À appeler dans une transaction.
 */
final class GenerateurDeMatricule
{
    public const GABARIT_PAR_DEFAUT = '{AAAA}-{NNNN}';

    private const ESSAIS = 1000;

    public function __construct(private readonly DatabaseManager $db) {}

    public function suivant(int $ecole, ?string $gabarit, CarbonInterface $quand): string
    {
        $modele = $this->modele($gabarit);
        $annee = (int) $quand->format('Y');

        for ($essai = 0; $essai < self::ESSAIS; $essai++) {
            $matricule = $this->rendre($modele, $annee, $this->incrementer($ecole, $annee));
            if (! $this->db->table('dossiers_eleves')->where('institution_id', $ecole)->where('matricule', $matricule)->exists()) {
                return $matricule;
            }
        }

        throw new \RuntimeException("Aucun matricule libre pour l'école {$ecole} après ".self::ESSAIS.' essais.');
    }

    private function modele(?string $gabarit): string
    {
        $modele = is_string($gabarit) && trim($gabarit) !== '' ? trim($gabarit) : self::GABARIT_PAR_DEFAUT;

        return preg_match('/\{N+\}/', $modele) === 1 ? $modele : $modele.'-{NNNN}';
    }

    private function rendre(string $modele, int $annee, int $numero): string
    {
        $texte = str_replace(['{AAAA}', '{AA}'], [(string) $annee, substr((string) $annee, -2)], $modele);

        return (string) preg_replace_callback(
            '/\{(N+)\}/',
            static fn (array $m): string => str_pad((string) $numero, strlen($m[1]), '0', STR_PAD_LEFT),
            $texte,
        );
    }

    /** Le numéro suivant de la séquence (école, année), sous verrou de sa ligne. */
    private function incrementer(int $ecole, int $annee): int
    {
        $dernier = data_get($this->ligne($ecole, $annee), 'dernier');
        $suivant = (is_numeric($dernier) ? (int) $dernier : 0) + 1;
        $this->db->table('matricules_sequences')->where('institution_id', $ecole)->where('annee', $annee)
            ->update(['dernier' => $suivant, 'updated_at' => now()]);

        return $suivant;
    }

    private function ligne(int $ecole, int $annee): ?object
    {
        $lire = fn (): ?object => $this->db->table('matricules_sequences')
            ->where('institution_id', $ecole)->where('annee', $annee)->lockForUpdate()->first();

        $ligne = $lire();
        if ($ligne !== null) {
            return $ligne;
        }

        try {
            $this->db->table('matricules_sequences')->insert([
                'institution_id' => $ecole, 'annee' => $annee, 'dernier' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Une autre création vient de l'insérer : on lit la sienne, sous verrou.
        }

        return $lire();
    }
}
