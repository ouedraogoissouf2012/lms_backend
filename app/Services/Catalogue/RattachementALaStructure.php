<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Models\Filiere;
use App\Models\NiveauEtude;
use Illuminate\Validation\ValidationException;

/**
 * Rattacher une classe ou une matière locale à une filière et à un niveau d'études LOCAUX (#1648, PR B).
 *
 * L'API parle `filiere_id` / `niveau_id` (les ids de #1648 A) ; ils s'écrivent dans `filiere_locale_id` /
 * `niveau_locale_id`, jamais dans les colonnes KLASSCI. Un id d'une autre école (ou inconnu) : 422 sous son champ,
 * borné EXPLICITEMENT à l'école (le scope multi-tenant est fail-OPEN). Nul : détaché. Absent : inchangé.
 */
final class RattachementALaStructure
{
    /** @var array<string, array{0: string, 1: class-string<Filiere|NiveauEtude>}> */
    private const CHAMPS = [
        'filiere_id' => ['filiere_locale_id', Filiere::class],
        'niveau_id' => ['niveau_locale_id', NiveauEtude::class],
    ];

    /**
     * Les colonnes à écrire, pour les seuls champs ENVOYÉS.
     *
     * @param  array<string, mixed>  $donnees
     * @return array<string, int|null>
     *
     * @throws ValidationException 422
     */
    public function colonnes(int $ecole, array $donnees): array
    {
        $colonnes = [];

        foreach (self::CHAMPS as $champ => [$colonne, $modele]) {
            if (! array_key_exists($champ, $donnees)) {
                continue;
            }

            $id = $donnees[$champ];
            if ($id !== null && ! $modele::query()->withoutGlobalScopes()->whereKey($id)->where('institution_id', $ecole)->exists()) {
                throw ValidationException::withMessages([$champ => 'Cet élément n\'existe pas dans cet établissement.']);
            }

            $colonnes[$colonne] = is_numeric($id) ? (int) $id : null;
        }

        return $colonnes;
    }
}
