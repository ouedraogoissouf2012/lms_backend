<?php

declare(strict_types=1);

namespace App\Services\Eleve;

use App\Models\DossierEleve;
use App\Models\Tuteur;
use App\Models\User;

/**
 * La forme d'un dossier d'élève rendue au client (#1657), UNE pour les deux écoles :
 * - école autonome : le dossier local et ses tuteurs, ou `null` tant qu'il n'existe pas ;
 * - école KLASSCI : ce que le LMS reçoit de KLASSCI, et rien de plus (nom, prénom). Les autres champs valent `null` :
 *   non fournis, jamais inventés.
 * La photo (`photo_url`, PR 2) : le chemin de sa route authentifiée, ou `null` sans photo.
 */
final class PresentationDuDossier
{
    /** @return array<string, mixed>|null */
    public function locale(User $eleve): ?array
    {
        $dossier = DossierEleve::query()->withoutGlobalScopes()->where('user_id', $eleve->id)->with('tuteurs')->first();

        if (! $dossier instanceof DossierEleve) {
            return null;
        }

        return [
            'matricule' => $dossier->matricule,
            'nom' => $dossier->nom,
            'prenoms' => $dossier->prenoms,
            'genre' => $dossier->genre,
            'date_naissance' => $dossier->date_naissance?->toDateString(),
            'lieu_naissance' => $dossier->lieu_naissance,
            'nationalite' => $dossier->nationalite,
            'adresse' => $dossier->adresse,
            'ville' => $dossier->ville,
            'commune' => $dossier->commune,
            'statut' => $dossier->statut->value,
            // La photo se sert par une route authentifiée, selon les règles du dossier ; jamais par une URL publique.
            'photo_url' => is_string($dossier->photo_chemin) ? "/api/eleves/{$eleve->id}/photo" : null,
            'tuteurs' => $dossier->tuteurs->map(fn (Tuteur $tuteur): array => $this->tuteur($tuteur))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function klassci(User $eleve): array
    {
        $donnees = $eleve->klassci_data;
        $texte = static fn (mixed $valeur): ?string => is_string($valeur) && trim($valeur) !== '' ? trim($valeur) : null;

        return [
            'matricule' => null,
            'nom' => $texte($donnees['nom'] ?? null) ?? $texte($eleve->name),
            'prenoms' => $texte($donnees['prenom'] ?? null),
            'genre' => null, 'date_naissance' => null, 'lieu_naissance' => null, 'nationalite' => null,
            'adresse' => null, 'ville' => null, 'commune' => null, 'statut' => null,
            'photo_url' => null,
            'tuteurs' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function tuteur(Tuteur $tuteur, ?string $relation = null): array
    {
        $pivot = $tuteur->relationLoaded('pivot') ? $tuteur->getRelation('pivot') : null;
        $lue = is_object($pivot) && isset($pivot->relation) && is_string($pivot->relation) ? $pivot->relation : null;

        return [
            'id' => $tuteur->id,
            'relation' => $relation ?? $lue,
            'nom' => $tuteur->nom,
            'telephone' => $tuteur->telephone,
            'courriel' => $tuteur->courriel,
            'profession' => $tuteur->profession,
            'adresse' => $tuteur->adresse,
        ];
    }
}
