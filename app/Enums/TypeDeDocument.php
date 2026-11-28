<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Les documents de scolarité qu'une école émet (#1662). La fiche d'inscription et la fiche vierge sont hors de ce
 * lot (décision du 28/11).
 */
enum TypeDeDocument: string
{
    case CertificatScolarite = 'certificat_scolarite';
    case AttestationFrequentation = 'attestation_frequentation';

    /** Le préfixe de la numérotation : `CS-2026-0001`. */
    public function prefixe(): string
    {
        return match ($this) {
            self::CertificatScolarite => 'CS',
            self::AttestationFrequentation => 'AF',
        };
    }

    public function titre(): string
    {
        return match ($this) {
            self::CertificatScolarite => 'Certificat de scolarité',
            self::AttestationFrequentation => 'Attestation de fréquentation',
        };
    }
}
