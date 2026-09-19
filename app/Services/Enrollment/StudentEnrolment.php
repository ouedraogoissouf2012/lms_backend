<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

/**
 * Issue d'une inscription : faite, sans objet, ou refusée avec sa raison (#846).
 *
 * Trois issues et non deux. « Sans objet » n'est pas un succès déguisé : un
 * fichier d'import peut légitimement ne porter aucune colonne de classe — il
 * crée alors des comptes sans les rattacher. Le confondre avec une réussite
 * ferait compter une inscription qui n'a pas eu lieu ; le confondre avec un
 * refus ferait échouer des lignes valides.
 *
 * Même forme que `Import\Fields\FieldOutcome` — code machine ET message pour
 * quelqu'un qui a son tableur ouvert — mais type distinct : celui-là porte la
 * valeur d'une COLONNE, celui-ci l'issue d'une ÉCRITURE.
 *
 * @see docs/adr/2026-09-15-803-03-trois-portes-un-service.md
 */
final class StudentEnrolment
{
    private function __construct(
        public readonly bool $inscrit,
        public readonly ?string $code,
        public readonly ?string $message,
    ) {}

    public static function faite(): self
    {
        return new self(true, null, null);
    }

    /** Aucune classe n'était demandée : le compte existe, sans rattachement. */
    public static function sansObjet(): self
    {
        return new self(false, null, null);
    }

    public static function refusee(string $code, string $message): self
    {
        return new self(false, $code, $message);
    }

    public function estRefusee(): bool
    {
        return $this->code !== null;
    }
}
