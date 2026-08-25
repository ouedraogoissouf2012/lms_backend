<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * #579 — rattrapage des notifications écrites sans `institution_id`.
 *
 * Toute notification émise par un worker ou un cron avant le correctif de
 * `NotificationDispatcher::send()` porte `institution_id = NULL`. Le scope
 * global `BelongsToInstitution` les exclut de toute lecture authentifiée :
 * elles existent en base et leur destinataire ne les a jamais vues. Cette
 * migration les rend visibles en leur rendant l'institution de leur
 * destinataire.
 *
 * ## Ce qui est volontairement LAISSÉ de côté
 *
 * Les notifications de type `evaluation_approaching` sont **exclues**.
 * `NotifyUpcomingEvaluations::extractStudents()` écrit dans `user_id` un id
 * **KLASSCI**, pas un `users.id` local (la traduction faite par
 * `DispatchLessonPublishedNotifications:102` y manque). Ces lignes sont donc
 * déjà mal adressées : leur donner l'institution du porteur local qui partage
 * par hasard cet identifiant les rendrait visibles à quelqu'un d'autre —
 * éventuellement dans une autre institution. Elles restent inertes jusqu'à ce
 * que l'adressage soit corrigé (issue de suivi).
 *
 * Réparer la visibilité d'une ligne mal adressée en fait une fuite, pas un
 * correctif.
 *
 * ## Propriétés
 *
 * - **Idempotente** : ne cible que les lignes `NULL`.
 * - **Par lots** : `notifications` n'est jamais purgée pour les lignes non lues
 *   (`routes/console.php:61-67` ne supprime que les lues de plus de 30 j). Une
 *   seule transaction longue au déploiement bloquerait les workers qui
 *   insèrent ; le découpage borne la durée de verrou et rend l'opération
 *   reprenable.
 * - **Les destinataires sans institution (supradmin) sont laissés à NULL** :
 *   c'est leur état légitime, pas une anomalie.
 *
 * @see app/Services/Notification/NotificationDispatcher.php
 * @see .claude/specs/579-notif-institution-id/design.md
 */
return new class extends Migration
{
    /** Taille de lot : borne la durée de verrou sur mutualisé. */
    private const CHUNK = 1000;

    /** Type mal adressé, exclu tant que l'adressage n'est pas corrigé. */
    private const MISADDRESSED_TYPE = 'evaluation_approaching';

    public function up(): void
    {
        $repaired = 0;

        // Sous-requête corrélée, et NON `UPDATE ... JOIN` : SQLite ne connaît
        // pas cette forme et Laravel la réécrit en `where rowid in (...)`, où
        // la référence `users.institution_id` du SET sort de portée. La forme
        // ci-dessous s'exécute à l'identique sur SQLite et sur MySQL 8.4.
        $institutionOfRecipient = DB::raw(
            '(select institution_id from users where users.id = notifications.user_id)'
        );

        while (true) {
            $ids = $this->nextOrphanBatch();

            if ($ids === []) {
                break;
            }

            $repaired += DB::table('notifications')
                ->whereIn('id', $ids)
                ->update(['institution_id' => $institutionOfRecipient]);
        }

        // Décompte journalisé : une migration de données muette est
        // invérifiable après coup.
        Log::info('#579 backfill notifications : terminé.', [
            'reparees' => $repaired,
            'restantes_sans_institution' => DB::table('notifications')->whereNull('institution_id')->count(),
            'note' => 'Restantes = destinataire supradmin (légitime) ou type '
                .self::MISADDRESSED_TYPE.' (mal adressé, exclu volontairement).',
        ]);
    }

    /**
     * Prochain lot d'identifiants réparables.
     *
     * Le `whereExists` n'est pas redondant : sans lui, les lignes dont le
     * destinataire n'a pas d'institution seraient « mises à jour » à NULL et
     * la boucle ne terminerait jamais (elles resteraient éligibles).
     *
     * @return list<int>
     */
    private function nextOrphanBatch(): array
    {
        /** @var list<int> $ids */
        $ids = DB::table('notifications')
            ->whereNull('institution_id')
            ->where('type', '!=', self::MISADDRESSED_TYPE)
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('users')
                ->whereColumn('users.id', 'notifications.user_id')
                ->whereNotNull('users.institution_id'))
            ->orderBy('id')
            ->limit(self::CHUNK)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return $ids;
    }

    /**
     * Volontairement sans effet.
     *
     * Remettre ces lignes à NULL restaurerait le bug : ce ne serait pas un
     * rollback mais une régression. L'information « était NULL » n'a par
     * ailleurs aucune valeur métier.
     */
    public function down(): void
    {
        Log::info('#579 backfill notifications : down() sans effet (voir migration).');
    }
};
