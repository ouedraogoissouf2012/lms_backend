<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Résout « champ canonique → valeur de l'enregistrement » (#718, ADR-718-01).
 *
 * Le client envoie le fichier tel quel et déclare séparément quelle colonne
 * porte quel champ. Sans déclaration, l'objet se réduit à l'identité : le champ
 * est cherché sous son propre nom, ce qui était le comportement d'avant. C'est
 * ce qui permet à `ImportPreviewService::classify()` de rester sans condition.
 */
final class ColumnMap
{
    /**
     * Les seuls champs que l'analyse lit réellement. Une clé envoyée hors de
     * cette liste est ignorée : un client ne peut pas détourner la lecture.
     *
     * @var list<string>
     */
    public const CANONICAL_FIELDS = ['nom', 'prenom', 'email', 'telephone', 'code_classe'];

    /**
     * @param  array<string, string>  $byField  champ canonique => en-tête normalisé
     */
    private function __construct(private readonly array $byField) {}

    /**
     * @param  array<array-key, mixed>  $raw  cartographie brute reçue du client
     */
    public static function fromRequest(array $raw): self
    {
        $byField = [];

        foreach (self::CANONICAL_FIELDS as $field) {
            $header = $raw[$field] ?? null;

            if (is_string($header) && trim($header) !== '') {
                $byField[$field] = self::normalizeHeader($header);
            }
        }

        return new self($byField);
    }

    /**
     * Normalisation unique des en-têtes, partagée avec la lecture du fichier.
     *
     * Les deux côtés DOIVENT passer par ici : si la cartographie et les en-têtes
     * lus étaient normalisés différemment, « PRÉNOM » ne retrouverait jamais sa
     * colonne. `mb_strtolower` et non `strtolower`, qui laisse les accents
     * majuscules intacts.
     */
    public static function normalizeHeader(string $header): string
    {
        // `trim()` ne retire que les blancs ASCII. Le client, lui, utilise le
        // `trim()` de JavaScript, qui retire AUSSI l'espace insécable — que les
        // tableurs sèment volontiers en bord de cellule. Sans cette égalisation,
        // le client enverrait « Nom » pour une colonne indexée ici « nom⍽ » :
        // introuvable, et toutes les lignes refusées sans explication.
        $sansBlancs = preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $header);

        return mb_strtolower($sansBlancs ?? trim($header));
    }

    /**
     * @param  array<array-key, mixed>  $record
     */
    public function value(array $record, string $field): string
    {
        $key = $this->byField[$field] ?? $field;
        $value = $record[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
