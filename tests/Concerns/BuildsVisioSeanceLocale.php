<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Une séance d'école en mode autonome, telle que `LocalSeanceCreator` l'écrit :
 * aucun identifiant KLASSCI, son auteur (`created_by`), sa classe locale
 * (`classe_id`). Les comptes n'ont ni jeton ni identifiant KLASSCI.
 */
trait BuildsVisioSeanceLocale
{
    private Institution $ecole;

    private Classe $classe;

    private function ecoleAutonome(): void
    {
        $this->ecole = Institution::factory()->standalone()->create();
        $this->classe = Classe::factory()->for($this->ecole)->create(['klassci_id' => null]);
    }

    private function compte(string $role, ?Institution $ecole = null): User
    {
        return User::factory()->{$role}()->for($ecole ?? $this->ecole)->create([
            'klassci_token' => null,
            'klassci_id' => null,
            'klassci_enseignant_id' => null,
        ]);
    }

    /** @param  array<string, mixed>  $attributs */
    private function seanceLocale(User $auteur, array $attributs = []): Seance
    {
        return Seance::factory()->for($this->ecole)->create($attributs + [
            'created_by' => $auteur->id,
            'classe_id' => $this->classe->id,
            'klassci_seance_id' => null,
            'klassci_enseignant_id' => null,
            'klassci_classe_id' => null,
            'klassci_matiere_id' => null,
            'visio_enabled' => false,
            'visio_status' => null,
            'visio_room_id' => null,
            'visio_active' => false,
        ]);
    }

    /** @param  array<string, mixed>  $attributs */
    private function seanceLocaleActive(User $auteur, array $attributs = []): Seance
    {
        return $this->seanceLocale($auteur, $attributs + [
            'visio_enabled' => true,
            'visio_status' => 'active',
            'visio_active' => true,
            'visio_room_id' => 'salon',
        ]);
    }

    private function eleveDe(Classe $classe, string $statut = 'actif'): User
    {
        $eleve = $this->compte('student', $classe->institution);
        $classe->etudiants()->attach($eleve->id, ['statut' => $statut]);

        return $eleve;
    }

    /** @return array<string, mixed> */
    private static function ligne(Seance $seance): array
    {
        return (array) DB::table('seances')->where('id', $seance->id)->first();
    }
}
