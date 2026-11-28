<?php

declare(strict_types=1);

namespace App\Services\Eleve;

use GdImage;

/**
 * Ré-encode une photo d'élève (#1657) : décodée par GD, réduite à 512 px au plus de côté, puis écrite en JPEG neuf.
 *
 * Le fichier d'origine n'est jamais gardé : ni ses métadonnées (EXIF, position, segments de commentaire), ni un
 * contenu caché derrière l'image. Ce qui ne se décode pas comme une image n'en est pas une (`null`). Un fond
 * transparent (PNG, WebP) devient blanc : le JPEG n'a pas d'alpha.
 */
final class ReencodeurDePhoto
{
    public const COTE_MAX = 512;

    private const QUALITE = 85;

    public function jpeg(string $octets): ?string
    {
        $source = $octets === '' ? false : @imagecreatefromstring($octets);
        if (! $source instanceof GdImage) {
            return null;
        }

        [$largeur, $hauteur] = [imagesx($source), imagesy($source)];
        $echelle = min(1, self::COTE_MAX / max($largeur, $hauteur));
        [$l, $h] = [max(1, (int) round($largeur * $echelle)), max(1, (int) round($hauteur * $echelle))];

        $cible = imagecreatetruecolor($l, $h);
        imagefill($cible, 0, 0, (int) imagecolorallocate($cible, 255, 255, 255));
        imagecopyresampled($cible, $source, 0, 0, 0, 0, $l, $h, $largeur, $hauteur);

        ob_start();
        imagejpeg($cible, null, self::QUALITE);

        return (string) ob_get_clean();
    }
}
