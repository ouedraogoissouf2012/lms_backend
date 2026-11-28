<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Enums\ClasseEtudiantStatut;
use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\Classe\ClasseEtudiantsReader;
use App\Services\Classe\InstitutionClassesReader;

/**
 * Les listes d'une école, prêtes à exporter (#1656) : les élèves d'une classe, les classes.
 *
 * Chaque liste est lue par EXACTEMENT le lecteur de sa route JSON (`GET /lms/classes/{id}/etudiants`,
 * `GET /proxy/classes`), choisi par la capacité au point de liaison : la même portée (l'enseignant, ses classes ;
 * hors portée, 404), les mêmes deux volets, et la même forme de document. Aucune règle d'accès nouvelle ici.
 *
 * Un refus du lecteur (statut ≠ 200) est rendu tel quel par l'appelant : `refus` le porte. Au-delà de
 * {@see self::MAXIMUM} lignes, 422 `export_too_large` : la génération est synchrone.
 */
final class ExportsDeClasses
{
    public const MAXIMUM = 2000;

    public function __construct(
        private readonly ClasseEtudiantsReader $etudiants,
        private readonly InstitutionClassesReader $classes,
    ) {}

    /**
     * @return Tableau|array{status: int, payload: array<string, mixed>}
     *
     * @throws BusinessException 422 `export_too_large`
     */
    public function eleves(User $lecteur, int $classeId): Tableau|array
    {
        $resultat = $this->etudiants->getEtudiants($classeId, $lecteur);
        if ($resultat['status'] !== 200) {
            return $resultat;
        }

        $lignes = array_map(static fn (array $e): array => [
            self::nom($e),
            self::texte($e['email'] ?? null),
            self::texte($e['matricule'] ?? null),
            self::texte($e['statut'] ?? ClasseEtudiantStatut::Actif->value),
        ], self::liste(self::cle($resultat['payload']['data'] ?? null, 'etudiants')));

        return $this->tableau('Liste des élèves', ['Nom', 'Email', 'Matricule', 'Statut'], $lignes, "eleves-classe-{$classeId}");
    }

    /** @throws BusinessException 422 `export_too_large` */
    public function classes(User $lecteur): Tableau
    {
        $lignes = array_map(static fn (array $c): array => [
            self::texte($c['libelle'] ?? $c['name'] ?? null),
            self::texte($c['code'] ?? null),
            self::libelle($c['filiere'] ?? null),
            self::libelle($c['niveau'] ?? null),
            self::texte($c['places_occupees'] ?? null),
            self::texte($c['places_totales'] ?? null),
        ], self::liste(self::cle($this->classes->list($lecteur, []), 'data')));

        return $this->tableau('Liste des classes', ['Libellé', 'Code', 'Filière', 'Niveau', 'Inscrits', 'Places'], $lignes, 'classes');
    }

    /**
     * @param  list<string>  $entetes
     * @param  list<list<string>>  $lignes
     *
     * @throws BusinessException
     */
    private function tableau(string $titre, array $entetes, array $lignes, string $fichier): Tableau
    {
        if (count($lignes) > self::MAXIMUM) {
            throw new BusinessException(
                sprintf('Cette liste dépasse %d lignes : elle ne s\'exporte pas d\'un bloc.', self::MAXIMUM),
                422,
                reason: 'export_too_large',
            );
        }

        usort($lignes, static fn (array $a, array $b): int => strnatcasecmp($a[0], $b[0]));

        return new Tableau($titre, $entetes, $lignes, $fichier);
    }

    private static function cle(mixed $tableau, string $cle): mixed
    {
        return is_array($tableau) ? ($tableau[$cle] ?? null) : null;
    }

    /** @return list<array<mixed>> */
    private static function liste(mixed $valeur): array
    {
        return is_array($valeur) ? array_values(array_filter($valeur, 'is_array')) : [];
    }

    /**
     * Le nom tel que les deux sources le portent : `name`, `nom_complet`, ou `prenom` + `nom`.
     *
     * @param  array<mixed>  $eleve
     */
    private static function nom(array $eleve): string
    {
        foreach (['name', 'nom_complet'] as $cle) {
            if (is_string($eleve[$cle] ?? null) && trim($eleve[$cle]) !== '') {
                return trim($eleve[$cle]);
            }
        }

        return trim(self::texte($eleve['prenom'] ?? null).' '.self::texte($eleve['nom'] ?? null));
    }

    /** Une filière, un niveau : un objet KLASSCI `{nom|libelle|name}`, une chaîne, ou rien. */
    private static function libelle(mixed $valeur): string
    {
        if (is_array($valeur)) {
            return self::texte($valeur['nom'] ?? $valeur['libelle'] ?? $valeur['name'] ?? null);
        }

        return self::texte($valeur);
    }

    private static function texte(mixed $valeur): string
    {
        return is_scalar($valeur) ? (string) $valeur : '';
    }
}
