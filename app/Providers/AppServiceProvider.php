<?php

namespace App\Providers;

use App\Services\Visio\VisioAccessTokenIssuer;
use App\Services\Seances\Sync\StaleSeanceArchiver;
use App\Services\Seances\Sync\StaleSeanceArchiverInterface;
use App\Models\PersonalAccessToken;
use App\Services\Cache\Purge\TenantCachePurgerFactory;
use App\Services\Cache\Purge\TenantCachePurgerInterface;
use App\Services\Cache\TenantScopedCache;
use App\Services\Cache\TenantScopedCacheInterface;
use App\Services\Klassci\KlassciConfigResolver;
use App\Services\Klassci\KlassciRequestMemo;
use App\Services\Klassci\KlassciTargetResolver;
use App\Services\Seances\Sync\Cursor\EloquentSeanceSyncCursorStore;
use App\Services\Seances\Sync\Cursor\SeanceSyncCursorStore;
use App\Services\Tenancy\InstitutionIntegrityInspector;
use App\Services\Tenancy\InstitutionIntegrityInspectorInterface;
use App\Services\Integrity\ArchivedRowWriter;
use App\Services\Integrity\ArchivedRowWriterInterface;
use App\Services\TenantManager;
use App\Services\Visio\Recording\LocalDirectoryRecordingMediaSource;
use App\Services\Visio\Recording\RecordingMediaSource;
use App\Support\Shell\ShellExecutor;
use App\Support\Shell\ShellExecutorInterface;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantManager::class);
        $this->app->scoped(KlassciRequestMemo::class);

        // #578 — Circuit breaker KLASSCI cloisonné par cible réseau.
        // Le résolveur de config est mémoïsé PAR INSTANCE (« singleton implicite
        // par requête », cf. son docblock) : on le lie en `scoped` pour que le
        // breaker et le KlassciHttpClient partagent LA MÊME instance dans une
        // requête — donc la même cible résolue (partition cohérente) et une
        // seule résolution 3-tiers (pas de double lookup guard/tenant).
        $this->app->scoped(KlassciConfigResolver::class);

        // Le breaker dépend de l'abstraction fine ; le concret est le résolveur.
        // Sans ce binding, l'auto-résolution KlassciHttpClient → KlassciCircuitBreaker
        // → KlassciTargetResolver échouerait (interface non instanciable).
        $this->app->bind(KlassciTargetResolver::class, KlassciConfigResolver::class);

        // ShellExecutor — sole entry point for external process execution
        // (issue #79 Phase A). Singleton because it is stateless and we want
        // any optional logger (debug) to be the same instance across callers.
        // Bound to the interface so consumers depend on the abstraction
        // (§1.6 D — Dependency Inversion) and tests can swap a mock.
        $this->app->singleton(ShellExecutor::class);
        $this->app->bind(ShellExecutorInterface::class, ShellExecutor::class);

        // Archivage des seances : depuis le retrait de `CleanObsoleteSeances`
        // (#516, qui sondait une route KLASSCI inexistante), c'est l'unique
        // chemin d'archivage. Le binding a l'interface rend son garde de
        // souillure verifiable (\App\Services\Seances\Sync\TenantArchiveCoordinator).
        $this->app->bind(StaleSeanceArchiverInterface::class, StaleSeanceArchiver::class);

        $this->bindVisioAccessTokenIssuer();
        $this->bindRecordingMediaSource();

        // TenantScopedCache (#374, spec redis-runtime). Le conteneur ne sait
        // pas résoudre la classe concrète Illuminate\Cache\Repository par
        // réflexion (son constructeur attend un Store non bindé) : on la
        // fait pointer sur la même instance que le contrat — le store par
        // défaut selon config('cache.default') — pour que TenantScopedCache
        // puisse utiliser tags()/supportsTags(), absents du contrat.
        $this->app->bind(
            Repository::class,
            fn ($app) => $app->make(\Illuminate\Contracts\Cache\Repository::class)
        );

        // #547 — la stratégie de purge tenant est choisie par capacité du store
        // ACTIF (tags/database/no-op), à partir du même Repository concret que
        // TenantScopedCache consomme. Résolu paresseusement : la config cache
        // peut différer entre requête HTTP et worker de queue.
        $this->app->bind(
            TenantCachePurgerInterface::class,
            function ($app) {
                $table = config('cache.stores.database.table', 'cache');
                $factory = new TenantCachePurgerFactory(
                    $app->make(\Psr\Log\LoggerInterface::class),
                    is_string($table) ? $table : 'cache',
                );

                return $factory->make($app->make(Repository::class));
            }
        );
        $this->app->bind(
            TenantScopedCacheInterface::class,
            TenantScopedCache::class
        );

        // Curseur de reprise de la sync des séances (#582). Bindé sur
        // l'interface pour que le service de sync dépende du contrat et non
        // d'Eloquent (§1.6-D), et que les tests substituent un double sans
        // toucher à la base.
        $this->app->bind(
            SeanceSyncCursorStore::class,
            EloquentSeanceSyncCursorStore::class
        );

        // Inspecteur d'intégrité institution_id (#583) — lecture seule, partagé
        // par la commande d'audit et la migration FK. Bindé sur l'interface pour
        // que la migration/commande dépendent de l'abstraction (§1.6-D) et que
        // les tests substituent un double (garde pré-vol sans orphelins réels).
        $this->app->bind(
            InstitutionIntegrityInspectorInterface::class,
            InstitutionIntegrityInspector::class
        );

        // Quarantaine des lignes retirées par les migrations d'intégrité (#541).
        // `RowQuarantine` dépend de l'abstraction pour que le test puisse lui
        // substituer un écrivain défaillant et prouver le point non négociable :
        // si l'archive n'a pas été écrite, AUCUNE ligne n'est supprimée
        // (RowQuarantineTest::test_nothing_is_deleted_when_the_archive_is_incomplete).
        $this->app->bind(
            ArchivedRowWriterInterface::class,
            ArchivedRowWriter::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fix pour MySQL < 5.7.7 et MariaDB < 10.2.2
        // Limite la longueur par défaut des chaînes indexées
        Schema::defaultStringLength(191);

        // Sanctum : utiliser notre modèle PersonalAccessToken
        // qui bypass le Global Scope institution sur la relation tokenable.
        // Sans ça, le supradmin (institution_id = NULL) ne serait pas trouvé
        // quand le header X-Institution résout une institution spécifique.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // L'observer d'invariant #481 de Lesson est désormais auto-enregistré par
        // le trait Publishable (convention `bootXxx`, cf. Auditable) — plus de
        // Model::observe() dispersé ici. Voir app/Models/Concerns/Publishable.php.

        // Morph map des modèles attachables à un File (issue #10 IDOR).
        // Alias court ↔ classe pour la cohérence du stockage `fileable_type`.
        //
        // Note : morphMap() est utilisé en mode NON-strict (pas enforceMorphMap)
        // pour rester compatible avec d'éventuelles rows historiques stockant
        // le FQCN (`App\Models\Lesson` etc.). La sécurité contre l'IDOR vient
        // de la whitelist dans UploadFileRequest et ListFilesRequest — qui
        // n'acceptent QUE les clés courtes listées dans config/fileables.php.
        $morphMap = [];
        $configuredMorphMap = config('fileables.morph_map', []);

        if (is_array($configuredMorphMap)) {
            foreach ($configuredMorphMap as $alias => $class) {
                if (
                    is_string($alias)
                    && is_string($class)
                    && is_subclass_of($class, Model::class)
                ) {
                    $morphMap[$alias] = $class;
                }
            }
        }

        Relation::morphMap($morphMap);

        // #549 — SSRF : Dompdf ne doit jamais fetcher d'URL distante.
        $this->app->afterResolving(DomPdf::class, function (DomPdf $pdf): void {
            $pdf->setOption('enable_remote', false);
            $pdf->setOption('isRemoteEnabled', false);
        });
    }

    /**
     * Le signeur d'acces visio prend des scalaires : le conteneur ne peut pas
     * les deviner par reflexion, d'ou ce binding explicite.
     *
     * Extrait de register() : le garde-fou 5 refuse qu'une methode deja longue
     * s'allonge encore a chaque nouveau binding.
     */
    private function bindVisioAccessTokenIssuer(): void
    {
            $this->app->singleton(VisioAccessTokenIssuer::class, static function (): VisioAccessTokenIssuer {
                /** @var array<string, mixed> $c */
                $c = (array) config('services.visio.jitsi', []);

                return new VisioAccessTokenIssuer(
                    appId: is_string($c['app_id'] ?? null) ? $c['app_id'] : 'lms-klassci',
                    appSecret: is_string($c['app_secret'] ?? null) ? $c['app_secret'] : null,
                    audience: is_string($c['audience'] ?? null) ? $c['audience'] : 'visio-klassci',
                    xmppDomain: is_string($c['xmpp_domain'] ?? null) ? $c['xmpp_domain'] : 'meet.jitsi',
                    lifetimeSeconds: is_numeric($c['token_lifetime'] ?? null) ? (int) $c['token_lifetime'] : 7200,
                );
            });
    }

    /**
     * D'ou le LMS lit le media produit par Jibri (#469).
     *
     * La racine est un SCALAIRE issu de la configuration : le conteneur ne peut
     * pas la deviner par reflexion, d'ou ce binding explicite — meme raison que
     * pour le signeur de jetons ci-dessus.
     *
     * Volontairement `bind()` et non `singleton()` : la valeur de configuration
     * doit pouvoir changer entre deux tests (`config([...])`) sans qu'une
     * instance figee au premier appel continue de pointer sur l'ancienne racine.
     *
     * L'implementation locale suppose Jibri et le LMS sur la MEME machine. Le
     * jour ou le noeud visio est separe, seul ce binding change.
     */
    private function bindRecordingMediaSource(): void
    {
        $this->app->bind(RecordingMediaSource::class, static function ($app): RecordingMediaSource {
            $root = config('services.visio.recordings_root');

            return new LocalDirectoryRecordingMediaSource(
                $app->make(Filesystem::class),
                is_string($root) ? $root : null,
            );
        });
    }
}
