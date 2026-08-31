<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use Illuminate\Filesystem\Filesystem;

/**
 * #469 — lit le média Jibri sur un chemin monté en lecture seule.
 *
 * ## L'invariant : le client nomme une session, jamais un chemin
 *
 * `$sessionId` arrive du réseau, dans le corps du webhook. Concaténé tel quel à
 * une racine, `../../../etc` produirait un chemin parfaitement lisible que le
 * job d'import rangerait ensuite dans un cours. La validation de forme précède
 * donc **toute** construction de chemin — et le test le prouve avec un double du
 * système de fichiers qui échoue s'il est seulement interrogé.
 *
 * Le format est celui des identifiants de session Jibri : un UUID en minuscules.
 * Refuser les majuscules n'est pas du zèle — c'est refuser tout ce qui n'a pas
 * exactement la forme émise, plutôt que d'essayer de deviner les variantes
 * acceptables.
 *
 * ## Ce que cette classe ne fait pas
 *
 * Elle ne lit pas la configuration. La racine est **injectée** : la classe reste
 * testable sans conteneur, et le choix de configuration se déclare une seule
 * fois, dans le fournisseur de services (§1.6).
 *
 * @see RecordingMediaSource
 */
final class LocalDirectoryRecordingMediaSource implements RecordingMediaSource
{
    /** Identifiant de session Jibri : UUID minuscule, rien d'autre. */
    private const SESSION_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** Extension écrite par Jibri (`jibri.ffmpeg.recording-extension`). */
    private const MEDIA_EXTENSION = 'mp4';

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly ?string $root,
    ) {
    }

    public function locate(string $sessionId): ?string
    {
        if (! $this->isConfigured() || ! $this->isWellFormed($sessionId)) {
            return null;
        }

        $media = $this->filesystem->glob(sprintf(
            '%s/%s/*.%s',
            rtrim($this->root ?? '', '/\\'),
            $sessionId,
            self::MEDIA_EXTENSION,
        ));

        // Exactement un média attendu. Zéro ou plusieurs = état imprévu : on
        // refuse au lieu de choisir, cf. le docblock.
        if (count($media) !== 1) {
            return null;
        }

        $path = reset($media);

        return is_string($path) ? $path : null;
    }

    /**
     * Racine absente = voie Jibri éteinte. C'est un choix : sans elle, la seule
     * alternative serait un chemin deviné, donc une lecture de répertoire
     * arbitraire déclenchée par une requête réseau.
     */
    private function isConfigured(): bool
    {
        return is_string($this->root) && trim($this->root) !== '';
    }

    private function isWellFormed(string $sessionId): bool
    {
        return preg_match(self::SESSION_PATTERN, $sessionId) === 1;
    }
}
