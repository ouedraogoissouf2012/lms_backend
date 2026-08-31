<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

/**
 * #469 — d'où le LMS lit le média produit par le fournisseur d'enregistrement.
 *
 * ## Pourquoi une interface pour une seule implémentation
 *
 * Ce n'est pas de l'abstraction spéculative : c'est **la frontière de
 * déploiement**, et elle est déjà sous tension.
 *
 * Aujourd'hui Jibri et le LMS partagent une machine, donc le média se lit sur un
 * chemin monté — sans transiter par HTTP, ce qui évite de calculer un HMAC sur
 * plusieurs centaines de mégaoctets et de faire traverser le réseau à un fichier
 * qui n'en a pas besoin.
 *
 * Le plan d'infrastructure du projet prévoit explicitement de séparer le nœud
 * visio (steal time des vCPU partagés, incompatible avec un mixage audio temps
 * réel). Le jour où cela arrive, un `HttpRecordingMediaSource` s'ajoute et
 * **aucun appelant ne change** : ni le job d'import, ni le webhook, ni les tests
 * qui, eux, utilisent un double en mémoire et ne touchent jamais au disque.
 *
 * @see \App\Services\Visio\Recording\LocalDirectoryRecordingMediaSource
 * @see PRODUCTION_STANDARDS.md §1.6 (DIP — dépendre d'abstractions)
 */
interface RecordingMediaSource
{
    /**
     * Chemin absolu du média de cette session, ou `null` s'il est introuvable,
     * ambigu, ou si l'identifiant n'a pas la forme attendue.
     *
     * L'implémentation **ne doit jamais** dériver un chemin d'un identifiant non
     * validé : la valeur vient du réseau.
     *
     * @param  string  $sessionId  identifiant de session du fournisseur
     */
    public function locate(string $sessionId): ?string;
}
