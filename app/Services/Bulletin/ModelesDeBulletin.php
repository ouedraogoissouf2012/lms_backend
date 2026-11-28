<?php

declare(strict_types=1);

namespace App\Services\Bulletin;

use App\Models\ModeleDeBulletin;
use App\Models\User;

/**
 * #1677 (B1) : le modèle de bulletin de l'école — un par école, créé à la première écriture. Sans
 * modèle, des valeurs neutres : aucun en-tête ni signataire, aucun barème, aucun bloc (les matières
 * dans l'ordre du programme), toutes les mentions affichées.
 */
final class ModelesDeBulletin
{
    public const MENTIONS = ['rang' => true, 'moyenne_classe' => true, 'assiduite' => true];

    public function __construct(private readonly PorteDesBulletins $porte) {}

    /** @return array<string, mixed> */
    public function lire(User $lecteur): array
    {
        $ecole = $this->porte->ecoleDe($lecteur);

        return self::enTableau(ModeleDeBulletin::query()->withoutGlobalScope('institution')->where('institution_id', $ecole)->first());
    }

    /**
     * @param  array<string, mixed>  $champs  validés par la requête
     * @return array<string, mixed>
     */
    public function enregistrer(User $gestionnaire, array $champs): array
    {
        $ecole = $this->porte->ecoleDe($gestionnaire);
        $modele = ModeleDeBulletin::query()->withoutGlobalScope('institution')->firstOrNew(['institution_id' => $ecole]);
        $modele->fill([
            'entete' => $champs['entete'] ?? [],
            'signataires' => $champs['signataires'] ?? [],
            'bareme' => $champs['bareme'] ?? [],
            'blocs' => $champs['blocs'] ?? [],
            'mentions' => array_merge(self::MENTIONS, is_array($champs['mentions'] ?? null) ? $champs['mentions'] : []),
        ])->save();

        return self::enTableau($modele);
    }

    /** @return array<string, mixed> */
    private static function enTableau(?ModeleDeBulletin $modele): array
    {
        return [
            'entete' => $modele->entete ?? [],
            'signataires' => $modele->signataires ?? [],
            'bareme' => $modele->bareme ?? [],
            'blocs' => $modele->blocs ?? [],
            'mentions' => array_merge(self::MENTIONS, $modele->mentions ?? []),
        ];
    }
}
