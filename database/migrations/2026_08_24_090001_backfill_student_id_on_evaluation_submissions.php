<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * #540 — répare les soumissions historiques dont `student_id` est NULL.
 *
 * `POST /evaluations/{id}/start` créait la soumission avec `klassci_etudiant_id`
 * seul, sans jamais renseigner `student_id`. Tout le code qui lit l'identité
 * LOCALE de l'étudiant — `User::evaluationSubmissions()`, le regroupement par
 * `student_id` de `ReportGenerationService` — ignorait donc silencieusement ces
 * lignes. Le correctif applicatif rend l'invariant vrai pour les nouvelles
 * lignes ; cette migration le rend vrai pour les anciennes.
 *
 * ## Le parcours est piloté par `users`, pas par les soumissions
 *
 * Première écriture de cette migration : parcourir en `chunk()` les couples
 * distincts `(klassci_etudiant_id, institution_id)` filtrés par
 * `whereNull('student_id')`, et réparer dans le callback. **Bug** :
 * `chunk()` pagine par OFFSET, et chaque ligne réparée quitte le jeu de
 * résultats — l'offset saute alors autant de lignes qu'il en a réparé.
 * Sur 500 couples orphelins, la moitié n'était jamais traitée, et la migration
 * se déclarait pourtant réussie. On itère donc sur `users`, une table que la
 * migration **ne modifie pas** : le prédicat reste stable pendant tout le
 * parcours et `chunkById` est sûr.
 *
 * ## Prudence délibérée sur l'ambiguïté
 *
 * Une ligne n'est réparée que si l'étudiant est identifié SANS AMBIGUÏTÉ. Les
 * couples `(klassci_id, institution_id)` portés par plusieurs comptes sont
 * écartés d'avance : mieux vaut une donnée absente qu'une note rattachée au
 * mauvais étudiant.
 *
 * `DB::table('users')` est utilisé volontairement : le query builder ignore le
 * global scope d'institution et le soft-delete du modèle, or une soumission
 * d'un étudiant depuis désactivé doit rester rattachable.
 */
return new class extends Migration
{
    /** Taille de lot — borne l'empreinte mémoire sur une grosse table. */
    private const CHUNK = 500;

    public function up(): void
    {
        $ambiguous = $this->ambiguousKeys();

        DB::table('users')
            ->whereNotNull('klassci_id')
            ->select('id', 'klassci_id', 'institution_id')
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($users) use ($ambiguous): void {
                foreach ($users as $user) {
                    if (isset($ambiguous[$this->key($user->klassci_id, $user->institution_id)])) {
                        continue;
                    }

                    $this->attachSubmissions(
                        (int) $user->id,
                        (int) $user->klassci_id,
                        $user->institution_id === null ? null : (int) $user->institution_id,
                    );
                }
            });
    }

    /**
     * `down()` volontairement inerte : re-vider `student_id` re-casserait des
     * données réparées sans rien restaurer d'utile. La migration est un
     * correctif de données, pas un changement de schéma réversible.
     */
    public function down(): void
    {
        // Aucune action : voir le commentaire ci-dessus.
    }

    /**
     * Couples `(klassci_id, institution_id)` portés par PLUSIEURS comptes : on
     * ne peut pas trancher, ils sont exclus de la réparation.
     *
     * @return array<string, true>
     */
    private function ambiguousKeys(): array
    {
        $duplicates = DB::table('users')
            ->whereNotNull('klassci_id')
            ->select('klassci_id', 'institution_id')
            ->groupBy('klassci_id', 'institution_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $keys = [];
        foreach ($duplicates as $duplicate) {
            $keys[$this->key($duplicate->klassci_id, $duplicate->institution_id)] = true;
        }

        return $keys;
    }

    /**
     * Rattache les soumissions orphelines de cet étudiant.
     *
     * L'institution du compte doit correspondre à celle de la soumission —
     * un même `klassci_id` peut exister dans deux institutions (frontière
     * multi-tenant). Les soumissions dont l'`institution_id` est resté NULL
     * (lignes antérieures à `2026_02_11_000002`) sont rattachées uniquement si
     * le `klassci_id` est unique dans TOUTE la base.
     */
    private function attachSubmissions(int $userId, int $klassciEtudiantId, ?int $institutionId): void
    {
        DB::table('evaluation_submissions')
            ->whereNull('student_id')
            ->where('klassci_etudiant_id', $klassciEtudiantId)
            ->when(
                $institutionId === null,
                static fn ($query) => $query->whereNull('institution_id'),
                static fn ($query) => $query->where('institution_id', $institutionId),
            )
            ->update(['student_id' => $userId]);

        if ($institutionId === null || $this->klassciIdIsGloballyUnique($klassciEtudiantId)) {
            DB::table('evaluation_submissions')
                ->whereNull('student_id')
                ->whereNull('institution_id')
                ->where('klassci_etudiant_id', $klassciEtudiantId)
                ->update(['student_id' => $userId]);
        }
    }

    /** Un seul compte, toutes institutions confondues, porte ce `klassci_id` ? */
    private function klassciIdIsGloballyUnique(int $klassciEtudiantId): bool
    {
        return DB::table('users')->where('klassci_id', $klassciEtudiantId)->count() === 1;
    }

    private function key(mixed $klassciId, mixed $institutionId): string
    {
        return $klassciId . ':' . ($institutionId ?? 'null');
    }
};
