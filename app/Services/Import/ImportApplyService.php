<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\ImportRowStatus;
use App\Enums\Role;
use App\Models\Classe;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\User;
use App\Services\Import\Fields\RoleField;
use Illuminate\Support\Str;

/**
 * Exécution d'un import confirmé (#718).
 *
 * ## Ce que le fichier décide, et ce qu'il ne décide pas
 *
 * Trois colonnes sont désormais honorées — `role`, `statut`, `date_inscription`
 * — après avoir été offertes à l'écran pendant que le serveur les jetait en
 * silence. Deux garde-fous encadrent la première :
 *
 *   - le rôle ne s'applique qu'à la CRÉATION. Un compte qui existe déjà garde
 *     le sien : c'est la règle posée en CRITICAL-05 (#118) pour la
 *     synchronisation KLASSCI, et un import ne doit pas devenir le chemin
 *     détourné qui promeut un compte existant ;
 *   - le plafond est relu ICI, à l'exécution, et non repris du rapport. Entre
 *     l'analyse et la confirmation, l'importateur a pu être rétrogradé ; rejouer
 *     le rôle stocké sans le revérifier rouvrirait par le différé exactement ce
 *     que le plafond ferme à l'analyse.
 *
 * @see docs/adr/2026-09-16-718-02-colonnes-role-statut-date.md
 */
final class ImportApplyService
{
    /** Défaut de l'ENUM `classe_etudiant.statut`, pour un rapport sans la colonne. */
    private const DEFAULT_STATUT = 'actif';

    public function __construct(
        private readonly RoleField $roles,
    ) {}

    public function apply(Import $import): void
    {
        $import->update(['status' => Import::STATUS_RUNNING]);

        $ceiling = $import->user?->asRoleEnum() ?? Role::Etudiant;
        $refused = 0;

        foreach ($import->rows()->where('status', ImportRowStatus::Ok->value)->get() as $row) {
            if (! $this->upsertStudent($import, $row, $ceiling)) {
                $refused++;
            }
        }

        // Les compteurs du rapport dateraient sinon de l'analyse : l'utilisateur
        // lirait « 12 acceptées » pendant que trois lignes ont été refusées à
        // l'exécution. Un compte faux est pire qu'un compte absent.
        $import->update([
            'status' => Import::STATUS_DONE,
            'ok_count' => max(0, (int) $import->ok_count - $refused),
            'error_count' => (int) $import->error_count + $refused,
        ]);
    }

    /**
     * @return bool faux si la ligne a été refusée à l'exécution
     */
    private function upsertStudent(Import $import, ImportRow $row, Role $ceiling): bool
    {
        $payload = $row->payload ?? [];
        $email = $this->stringField($payload, 'email');
        $phone = $this->stringField($payload, 'telephone');
        $nom = $this->stringField($payload, 'nom');
        $prenom = $this->stringField($payload, 'prenom');
        $code = $this->stringField($payload, 'code_classe');

        // Un rapport produit AVANT cette issue n'a pas ces trois clés : la chaîne
        // vide y ramène le comportement d'alors (étudiant, actif, daté du jour),
        // sans qu'un import déjà analysé ait à être rejoué.
        $role = $this->roles->resolve($this->stringField($payload, 'role'), $ceiling);

        if ($role->isRejected()) {
            $row->update([
                'status' => ImportRowStatus::Error->value,
                'code' => $role->code,
                'message' => $role->message,
            ]);

            return false;
        }

        $this->enroll(
            $import,
            $this->findOrCreate($import, $email, $phone, $prenom, $nom, $role->value),
            $code,
            $this->pivot($payload),
        );

        return true;
    }

    /**
     * Métadonnées d'inscription portées par le pivot `classe_etudiant`.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function pivot(array $payload): array
    {
        $date = $this->stringField($payload, 'date_inscription');
        $statut = $this->stringField($payload, 'statut');

        return [
            'statut' => $statut !== '' ? $statut : self::DEFAULT_STATUT,
            // Le fichier ne dit rien : l'inscription date du moment où elle est
            // réellement faite, comme le fait déjà la synchronisation KLASSCI.
            'date_inscription' => $date !== '' ? $date : now()->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringField(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    private function findOrCreate(Import $import, string $email, string $phone, string $prenom, string $nom, string $role): User
    {
        $query = User::query()->where('institution_id', $import->institution_id);
        $existing = $email !== ''
            ? (clone $query)->where('email', $email)->first()
            : ($phone !== '' ? (clone $query)->where('phone', $phone)->first() : null);

        // Le rôle d'un compte existant n'est PAS touché (#118) : sans cela, un
        // import deviendrait le chemin détourné qui promeut un compte déjà là.
        if ($existing instanceof User) {
            return $existing;
        }

        return User::query()->create([
            'institution_id' => $import->institution_id,
            'name' => trim($prenom.' '.$nom),
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
            'password' => Str::password(16),
            'role' => $role,
        ]);
    }

    /**
     * @param  array<string, string>  $pivot
     */
    private function enroll(Import $import, User $user, string $code, array $pivot): void
    {
        if ($code === '') {
            return;
        }
        $classe = Classe::query()
            ->where('institution_id', $import->institution_id)
            ->where('code', $code)
            ->first();
        if ($classe === null) {
            return;
        }

        // `syncWithoutDetaching` plutôt qu'un test d'existence suivi d'`attach` :
        // il met à jour le pivot d'une inscription déjà là, ce qui rend l'import
        // idempotent ET correctif — corriger un statut puis renvoyer le fichier
        // entier est le geste que #718 veut permettre. Il ferme aussi une faille
        // du test précédent : l'unique `(classe, user, annee)` ne contraint rien
        // quand `annee_universitaire_id` est NULL, MySQL n'égalant jamais NULL.
        $classe->etudiants()->syncWithoutDetaching([$user->id => $pivot]);
    }
}
