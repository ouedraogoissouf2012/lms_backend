<?php

declare(strict_types=1);

namespace App\Services\Eleve;

use App\Models\DossierEleve;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Les élèves SEMBLABLES déjà au dossier dans l'école (#1657), avant d'écrire une identité :
 * - mêmes nom, prénoms et date de naissance, comparés sans casse, sans accents et sans espaces superflus ;
 * - ou même téléphone de compte.
 * L'élève lui-même n'est jamais son propre doublon. La recherche est bornée à l'école, explicitement.
 */
final class DoublonsDEleve
{
    /**
     * @param  array<string, mixed>  $identite
     * @return list<array{eleve_id: int, nom: string, prenoms: string, matricule: string}>
     */
    public function pour(int $ecole, User $eleve, array $identite): array
    {
        /** @var Collection<int, DossierEleve> $trouves */
        $trouves = collect();

        $date = $identite['date_naissance'] ?? null;
        $cle = self::cle($identite['nom'] ?? null, $identite['prenoms'] ?? null);
        if (is_string($date) && $date !== '' && $cle !== '') {
            $trouves = $trouves->merge($this->dossiers($ecole, $eleve)->whereDate('date_naissance', $date)->get()
                ->filter(static fn (DossierEleve $d): bool => self::cle($d->nom, $d->prenoms) === $cle));
        }

        $telephone = $eleve->phone;
        if (is_string($telephone) && $telephone !== '') {
            $comptes = User::query()->withoutGlobalScopes()
                ->where('institution_id', $ecole)->where('phone', $telephone)->whereKeyNot($eleve->id)->pluck('id');
            $trouves = $trouves->merge($this->dossiers($ecole, $eleve)->whereIn('user_id', $comptes)->get());
        }

        $candidats = [];
        foreach ($trouves->unique('id')->sortBy('id') as $dossier) {
            $candidats[] = ['eleve_id' => $dossier->user_id, 'nom' => $dossier->nom, 'prenoms' => $dossier->prenoms, 'matricule' => $dossier->matricule];
        }

        return $candidats;
    }

    /** @return Builder<DossierEleve> */
    private function dossiers(int $ecole, User $eleve): Builder
    {
        return DossierEleve::query()->withoutGlobalScopes()->where('institution_id', $ecole)->where('user_id', '!=', $eleve->id);
    }

    /** Nom et prénoms, sans casse, sans accents, sans espaces superflus. */
    private static function cle(mixed $nom, mixed $prenoms): string
    {
        if (! is_string($nom) || ! is_string($prenoms)) {
            return '';
        }

        return Str::lower(Str::ascii(Str::squish("{$nom} {$prenoms}")));
    }
}
