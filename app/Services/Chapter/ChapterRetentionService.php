<?php

declare(strict_types=1);

namespace App\Services\Chapter;

use App\Models\Chapter;
use App\Models\SeanceRecording;
use App\Services\FileConversion\ChapterArtifactStorage;
use App\Services\Visio\Recording\SeanceRecordingRetentionService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Psr\Log\LoggerInterface;
use Tests\Feature\Chapter\ChapterRetentionPurgeTest;
use Throwable;

/**
 * Vide la corbeille des chapitres à échéance (#674).
 *
 * ## Ce qui manquait
 *
 * #689 a rendu la corbeille réelle : les fichiers d'un chapitre survivent à sa
 * suppression, pour qu'une restauration rende un cours complet. Mais **rien ne
 * la vidait jamais** — un seul endroit dans tout `app/` détruisait définitivement
 * un chapitre, et seulement ceux engendrés par un enregistrement visio.
 *
 * C'est un sujet de conformité avant d'être un sujet d'espace disque :
 * conserver au-delà de la durée déclarée est une infraction en soi, aucune
 * demande d'effacement ne pouvait être honorée, et les URL de diapositives étant
 * prédictibles (#598), un cours supprimé restait énumérable sans
 * authentification pour toujours.
 *
 * ## Le partage avec la rétention visio — lu en base, pas sur un marqueur
 *
 * Un chapitre engendré par un enregistrement appartient à
 * {@see SeanceRecordingRetentionService} : lui seul
 * sait quand le média expire. L'appartenance se lit donc sur **l'existence d'une
 * ligne `seance_recordings` qui le référence**, et non sur le marqueur JSON
 * `notes_enseignant['source']`.
 *
 * Ce choix n'est pas esthétique. `SeanceRecording::chapter()` est un `belongsTo`
 * simple, donc **aveugle aux chapitres à la corbeille** : un chapitre visio
 * supprimé par son enseignant est déjà invisible pour la rétention visio.
 * L'écarter ici sur son marqueur créerait une classe d'orphelins que *personne*
 * ne purgerait jamais — précisément le défaut que ce service corrige.
 *
 * Avec le critère en base, les deux mécanismes se composent : `seance_recordings`
 * n'étant pas en suppression réversible, la ligne disparaît vraiment à la purge
 * visio, et le chapitre nous revient au passage suivant.
 *
 * @see ChapterRetentionPurgeTest
 */
final class ChapterRetentionService
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly DatabaseManager $database,
        private readonly LoggerInterface $logger,
        private readonly ChapterArtifactStorage $artifacts,
    ) {}

    /** Date avant laquelle une mise à la corbeille est arrivée à échéance. */
    public function cutoff(): CarbonInterface
    {
        return now()->subDays($this->retentionDays());
    }

    public function retentionDays(): int
    {
        return $this->positiveInt($this->config->get('chapters.retention_days'), 30);
    }

    public function chunkSize(): int
    {
        return $this->positiveInt($this->config->get('chapters.purge_chunk_size'), 100);
    }

    /**
     * Les chapitres à la corbeille arrivés à échéance, toutes institutions
     * confondues.
     *
     * ## Pourquoi le périmètre est déclaré ICI et nulle part ailleurs
     *
     * `withoutGlobalScope('institution')` n'est pas un détail d'optimisation :
     * c'est ce qui empêche la purge de devenir borgne. `BelongsToInstitution`
     * étant fail-open, l'oubli ne provoque aucune erreur — la purge se contente
     * de ne plus voir certains chapitres, et se déclare en succès.
     *
     * Laisser cette incantation se recopier au fil des appelants, c'est
     * garantir qu'un jour l'un d'eux l'omettra. Elle vit donc en un seul point.
     *
     * @return Builder<Chapter>
     */
    public function trashedBeyond(CarbonInterface $cutoff): Builder
    {
        return $this->allInstitutions()
            ->onlyTrashed()
            ->where('deleted_at', '<', $cutoff);
    }

    /**
     * Inventaire du parc à la corbeille, indépendant de l'échéance.
     *
     * Répond à la question de #674 — « combien s'accumulent, depuis quand » —
     * que les compteurs de purge ne donnent pas : un parc de 4 000 chapitres
     * supprimés et un parc de 4 n'appellent pas la même décision, alors que les
     * deux affichent `eligible: 0` au lendemain d'un premier passage.
     *
     * Deux agrégats plutôt qu'un parcours : le volume disque n'est
     * délibérément pas mesuré — il faudrait ouvrir deux disques pour chaque
     * chapitre, à chaque passage nocturne, pour un chiffre qui ne change aucune
     * décision que ces deux-là ne portent déjà.
     */
    public function fillInventory(ChapterRetentionResult $result): void
    {
        $result->trashedTotal = $this->allInstitutions()->onlyTrashed()->count();

        $oldest = $this->allInstitutions()->onlyTrashed()->min('deleted_at');
        $result->oldestTrashedAt = is_string($oldest) ? $oldest : null;
    }

    public function eligible(Chapter $chapter, CarbonInterface $cutoff): bool
    {
        $deletedAt = $chapter->deleted_at;

        if ($deletedAt === null || ! $deletedAt->lt($cutoff)) {
            return false;
        }

        return ! $this->ownedByRecordingRetention($chapter);
    }

    /**
     * Détruit un chapitre échu : ses **fichiers**, puis sa ligne.
     *
     * ## Pourquoi les fichiers sont effacés DANS la transaction, avant la ligne
     *
     * Une suppression de fichier n'est pas transactionnelle : les deux ordres
     * possibles laissent un état incohérent si quelque chose casse au milieu. Le
     * choix se fait donc sur la nature du résidu, pas sur l'élégance.
     *
     * - **Effacer après le commit** : si l'effacement échoue, la ligne a disparu
     *   et plus rien ne référence les fichiers — aucune purge ultérieure ne
     *   saura les retrouver. Les diapositives restent servies, **définitivement**,
     *   à des URL prédictibles.
     * - **Effacer avant, dans la transaction** (retenu) : si la transaction est
     *   annulée, la ligne subsiste en pointant sur des fichiers absents. Le
     *   chapitre s'affiche cassé — visible, journalisé, réparable, et il repassera
     *   au prochain cycle.
     *
     * Ce service existe pour faire disparaître des données à échéance. Quand les
     * deux issues sont mauvaises, la bonne direction est « effacé quoi qu'il
     * arrive », jamais « ligne perdue, fichiers encore en ligne » : cette seconde
     * forme *atteste* d'un effacement qui n'a pas eu lieu. Même arbitrage que
     * {@see SeanceRecordingRetentionService::purge()}.
     *
     * @return bool vrai si le chapitre a été détruit ; faux s'il ne l'était plus
     *              (course entre la lecture du lot et le verrou)
     */
    public function purge(Chapter $chapter, CarbonInterface $cutoff): bool
    {
        return $this->database->transaction(function () use ($chapter, $cutoff): bool {
            $locked = $this->allInstitutions()
                ->withTrashed()
                ->whereKey($chapter->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof Chapter || ! $this->eligible($locked, $cutoff)) {
                return false;
            }

            $this->artifacts->purgeChapter($locked->id);
            $locked->forceDelete();

            return true;
        });
    }

    public function logFailure(Chapter $chapter, Throwable $exception): void
    {
        $this->logger->error('Chapter retention purge failed', [
            'chapter_id' => $chapter->getKey(),
            'lesson_id' => $chapter->lesson_id,
            'institution_id' => $chapter->institution_id,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    /**
     * Un enregistrement le référence-t-il encore ?
     *
     * `withoutGlobalScope` est indispensable : la commande tourne sans tenant
     * résolu. Sans lui, la requête ne verrait aucune ligne et **tout** chapitre
     * paraîtrait nous appartenir — on détruirait des chapitres dont la rétention
     * visio est encore propriétaire.
     */
    private function ownedByRecordingRetention(Chapter $chapter): bool
    {
        return SeanceRecording::withoutGlobalScope('institution')
            ->where('chapter_id', $chapter->getKey())
            ->exists();
    }

    /**
     * Le point UNIQUE où le cloisonnement multi-tenant est levé.
     *
     * @return Builder<Chapter>
     */
    private function allInstitutions(): Builder
    {
        return Chapter::withoutGlobalScope('institution');
    }

    private function positiveInt(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
