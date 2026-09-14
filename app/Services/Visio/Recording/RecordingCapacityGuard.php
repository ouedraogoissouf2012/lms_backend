<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Enums\SeanceRecordingStatus;
use App\Models\Seance;
use App\Models\SeanceRecording;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * #706 — la capacité d'enregistrement de la plateforme est finie, et le code
 * l'ignorait.
 *
 * ## Le défaut corrigé
 *
 * Le verrou existant est PAR SÉANCE : {@see SeanceRecording::activeLockKeyForSeance()}
 * retourne `'seance:'.$id`, garanti par un index unique sur `active_lock_key`.
 * Il empêche deux enregistrements de la MÊME séance — il ne dit rien de deux
 * séances différentes. Or Jibri ne supporte qu'un enregistrement à la fois par
 * instance (« Only one recording at a time is supported on a single jibri »).
 *
 * Deux enseignants qui démarrent sur deux séances passaient donc tous les deux ;
 * le second échouait côté fournisseur pendant que l'interface affichait « en
 * cours », et la perte n'était constatée qu'après 15 minutes à 6 heures. Ce
 * garde refuse le second AVANT toute écriture, avec un message lisible.
 *
 * ## Pourquoi le comptage ignore le scope tenant
 *
 * {@see SeanceRecording} utilise `BelongsToInstitution` : un comptage nominal
 * serait scopé à l'établissement courant, et l'école B démarrerait pendant que
 * l'école A enregistre — le défaut reproduit un cran plus haut, en pire, parce
 * qu'invisible. La capacité est une propriété d'INFRASTRUCTURE et non du tenant
 * (article 2 de l'épique #697) : le comptage est donc explicitement hors scope.
 *
 * ## Pourquoi les trois statuts actifs comptent, et pas seulement `Recording`
 *
 * On réutilise {@see SeanceRecordingStatus::activeValues()} — la définition qui
 * pilote déjà `active_lock_key`. Introduire ici une seconde notion d'« actif »
 * créerait deux vérités concurrentes sur le même fait, la classe de défaut que
 * ce dépôt paie le plus cher. Conséquence assumée : un enregistrement resté en
 * `Processing` occupe un créneau jusqu'à `recordings:fail-stale` — c'est le sens
 * fail-secure, et c'est le comportement voulu tant que la capacité vaut 1.
 */
final class RecordingCapacityGuard
{
    /** Nom du verrou atomique ; global à la plateforme, jamais par tenant. */
    private const LOCK_KEY = 'recordings:capacity';

    private const LOCK_WAIT_SECONDS = 5;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * Nombre d'enregistrements simultanés que la plateforme peut porter.
     *
     * Une capacité nulle ou absurde ne doit jamais désactiver le garde : elle
     * retombe sur 1, la seule valeur défendable tant que #700 n'a pas relevé le
     * nombre réel d'instances Jibri.
     */
    public function capacity(): int
    {
        return $this->positiveInt($this->config->get('recordings.max_concurrent'), 1);
    }

    /**
     * Enregistrements actifs sur TOUTE la plateforme, tous établissements
     * confondus. `withoutGlobalScope` est le cœur du garde, pas un détail.
     */
    public function activeCount(): int
    {
        return SeanceRecording::query()
            ->withoutGlobalScope('institution')
            ->whereIn('status', SeanceRecordingStatus::activeValues())
            ->count();
    }

    /**
     * Réserve un créneau d'enregistrement puis exécute `$create`.
     *
     * Retourne `null` — et n'exécute RIEN — quand la plateforme est saturée :
     * l'appelant doit refuser explicitement, sans créer de ligne vouée à
     * l'échec silencieux.
     *
     * La séance déjà porteuse d'un enregistrement actif ne consomme pas un
     * second créneau : elle occupe le sien. Sans cette exclusion, une reprise
     * après rechargement de page serait refusée à tort.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $create
     * @return TResult|null
     */
    public function claimSlotFor(Seance $seance, Closure $create): mixed
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            // Aucun store de verrou disponible : on contrôle quand même la
            // capacité. La fenêtre de course résiduelle reste bornée par
            // l'index unique `active_lock_key` côté même séance.
            return $this->attempt($seance, $create);
        }

        return $store->lock(self::LOCK_KEY, self::LOCK_WAIT_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, fn () => $this->attempt($seance, $create));
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $create
     * @return TResult|null
     */
    private function attempt(Seance $seance, Closure $create): mixed
    {
        if (! $this->hasFreeSlotFor($seance)) {
            return null;
        }

        return $create();
    }

    /** Un créneau est-il disponible pour cette séance précise ? */
    public function hasFreeSlotFor(Seance $seance): bool
    {
        if ($this->holdsSlot($seance)) {
            return true;
        }

        return $this->activeCount() < $this->capacity();
    }

    /**
     * Patron déjà en vigueur dans le dépôt pour lire une configuration entière
     * (`FailStaleRecordings`, `PurgeSeanceRecordings`, `KlassciCircuitBreaker`…) :
     * `config()` rend `mixed`, et PHPStan niveau 9 refuse le cast direct.
     */
    private function positiveInt(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    /** La séance occupe-t-elle déjà un créneau ? */
    private function holdsSlot(Seance $seance): bool
    {
        return SeanceRecording::query()
            ->withoutGlobalScope('institution')
            ->where('seance_id', $seance->id)
            ->whereIn('status', SeanceRecordingStatus::activeValues())
            ->exists();
    }
}
