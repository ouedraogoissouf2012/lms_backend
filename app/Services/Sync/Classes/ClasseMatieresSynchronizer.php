<?php

declare(strict_types=1);

namespace App\Services\Sync\Classes;

use App\Models\Classe;
use App\Models\Matiere;
use App\Services\MatiereSyncService;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

/**
 * Miroite localement les matières d'une classe KLASSCI, et le lien entre les
 * deux (#740).
 *
 * ## Ce que ce service apporte, et ce qu'il n'apporte PAS
 *
 * `classes/{id}` renvoie `classe` **et** `matieres`. `ClasseSyncService` ne
 * gardait que la première clé et jetait les secondes. Ce service récupère les
 * secondes — pour le **lien** classe ↔ matière, que rien d'autre n'écrit.
 *
 * ⚠️ Une version antérieure de ce docblock affirmait que la table `matieres`
 * « restait vide pour toujours, aucun autre écrivain n'existe dans le dépôt ».
 * **C'était faux**, et la prémisse a produit un bug de perte de données —
 * corrigé dans {@see self::mirror()}. {@see MatiereSyncService}
 * alimente `matieres` depuis #258, à chaque connexion enseignant, en lisant
 * l'endpoint COMPLET `matieres` ; un `grep` sur `Matiere::updateOrCreate` ne le
 * voyait pas parce qu'il écrit via `new Matiere()` + `save()`.
 *
 * Le vrai défaut du 422 « La matière n'existe pas » était ailleurs : le
 * frontend envoie un identifiant KLASSCI là où la règle n'acceptait que
 * l'espace local. Il est traité par `App\Rules\MirroredIdentifierExists`.
 *
 * ## Pourquoi ce miroir reste utile
 *
 * `MatiereSyncService` ne connaît que les matières de l'utilisateur connecté,
 * et n'écrit jamais `classe_matiere`. Sans ce service, une matière n'a aucune
 * classe rattachée tant qu'aucune séance ne l'a révélée — c'est ce lien, pas
 * l'existence de la ligne, qui débloque le choix de classe côté enseignant.
 *
 * ## Pourquoi miroiter plutôt qu'assouplir la validation
 *
 * `lessons.matiere_id` est un identifiant LOCAL. Y ranger un id KLASSCI
 * mélangerait deux espaces d'identifiants dans une même colonne — la faute
 * exacte qui a produit la fuite entre enseignants de #707. La traduction
 * KLASSCI → local existe déjà dans `LessonCrudOperationsService` ; il lui
 * manquait seulement une ligne locale vers laquelle traduire.
 *
 * ## Isolation
 *
 * Le `klassci_id` n'est unique QUE par institution : deux établissements
 * peuvent légitimement porter la matière 3. La clé de rapprochement est donc
 * toujours le couple `(klassci_id, institution_id)`, jamais le `klassci_id`
 * seul.
 *
 * Une entrée malformée est écartée sans interrompre le lot : une matière sans
 * identifiant ne doit pas priver ses sœurs de leur miroir.
 */
final class ClasseMatieresSynchronizer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  array<int, mixed>  $matieres  Bloc `matieres` du payload `classes/{id}`.
     */
    public function sync(Classe $classe, array $matieres): void
    {
        foreach ($matieres as $brut) {
            if (! is_array($brut)) {
                continue;
            }

            /** @var array<string, mixed> $brut */
            $klassciId = $this->klassciId($brut);
            if ($klassciId === null) {
                continue;
            }

            $matiere = $this->mirror($klassciId, $brut, (int) $classe->institution_id);
            $this->link($classe, $matiere);
        }
    }

    /**
     * Garantit l'EXISTENCE de la ligne, sans jamais appauvrir une fiche
     * complète.
     *
     * `matieres` a deux écrivains, et c'est {@see MatiereSyncService}
     * qui en détient le contenu : il lit l'endpoint COMPLET `matieres` à chaque
     * connexion enseignant (#258) et remplit code, description, coefficient,
     * crédit, filière, niveau, semestre et le payload intégral.
     *
     * Le bloc `matieres` de `classes/{id}` est, lui, un RÉSUMÉ. L'écrire
     * inconditionnellement remettait `code`, `description` et `klassci_data` à
     * `null` sur une fiche complète, et la perte se rejouait à chaque séance
     * créée. D'où la règle : le résumé ALIMENTE une ligne absente, il ne
     * REMPLACE jamais une ligne existante — seul le libellé, que le résumé
     * porte toujours, est rafraîchi.
     *
     * `last_klassci_sync` n'est jamais écrit ici : cette colonne gouverne
     * `Matiere::isKlassciDataFresh()` et signifie « la fiche complète a été
     * rafraîchie ». Un miroir partiel ne peut pas l'affirmer.
     *
     * @param  array<string, mixed>  $brut
     */
    private function mirror(int $klassciId, array $brut, int $institutionId): Matiere
    {
        /** @var Matiere $matiere */
        $matiere = Matiere::withoutGlobalScope('institution')->firstOrCreate(
            ['klassci_id' => $klassciId, 'institution_id' => $institutionId],
            [
                'libelle' => $this->libelle($brut) ?? 'Matière sans nom',
                'code' => is_string($brut['code'] ?? null) ? $brut['code'] : null,
                'description' => is_string($brut['description'] ?? null) ? $brut['description'] : null,
                'klassci_data' => $brut,
            ]
        );

        $libelle = $this->libelle($brut);

        if (! $matiere->wasRecentlyCreated && $libelle !== null && $libelle !== $matiere->libelle) {
            $matiere->update(['libelle' => $libelle]);
        }

        return $matiere;
    }

    /**
     * Le libellé porté par le résumé, sous l'une de ses trois clés observées,
     * ou `null` si l'entrée n'en porte aucune — auquel cas on ne remplace pas
     * un libellé existant par un intitulé de repli.
     *
     * @param  array<string, mixed>  $brut
     */
    private function libelle(array $brut): ?string
    {
        $libelle = $brut['libelle'] ?? $brut['nom'] ?? $brut['name'] ?? null;

        return is_string($libelle) && $libelle !== '' ? $libelle : null;
    }

    /**
     * Le lien classe ↔ matière, idempotent : le cycle de synchronisation tourne
     * toutes les cinq minutes et ne doit rien empiler.
     */
    private function link(Classe $classe, Matiere $matiere): void
    {
        DB::table('classe_matiere')->updateOrInsert(
            [
                'classe_id' => $classe->id,
                'matiere_id' => $matiere->id,
                'institution_id' => $classe->institution_id,
            ],
            ['updated_at' => now()],
        );
    }

    /**
     * @param  array<string, mixed>  $brut
     */
    private function klassciId(array $brut): ?int
    {
        $id = $brut['id'] ?? $brut['klassci_id'] ?? null;

        if (! is_numeric($id)) {
            $this->logger->warning('Matière KLASSCI sans identifiant exploitable — ignorée', [
                'payload' => $brut,
            ]);

            return null;
        }

        return (int) $id;
    }
}
