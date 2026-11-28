<?php

declare(strict_types=1);

namespace App\Services\Suivi\Decrochage;

use App\Models\Institution;

/**
 * #1704 : les seuils de l'alerte de décrochage d'une école, rangés dans
 * `institutions.settings.decrochage`. Chaque seuil a un défaut et des bornes ; une valeur stockée
 * absente, non entière ou hors bornes retombe sur le défaut, comme `settings.seuil_reussite`
 * (`BilanDeClasse::seuil`). Les mêmes bornes valident la saisie de l'administration.
 */
final readonly class ReglagesDuDecrochage
{
    public const CLE = 'decrochage';

    /** @var array<string, array{defaut: int, min: int, max: int}> */
    public const SEUILS = [
        'jours_sans_connexion' => ['defaut' => 7, 'min' => 1, 'max' => 90],
        'remises_manquees' => ['defaut' => 2, 'min' => 1, 'max' => 20],
        'part_chapitres_non_ouverts' => ['defaut' => 50, 'min' => 1, 'max' => 100],
    ];

    private function __construct(
        public int $joursSansConnexion,
        public int $remisesManquees,
        public int $partChapitresNonOuverts,
    ) {}

    public static function deLEcole(?int $institutionId): self
    {
        // Par le modèle : `value()` rendrait le JSON brut de la colonne, sans le cast `array`.
        $reglages = $institutionId === null ? null : Institution::query()->find($institutionId, ['id', 'settings'])?->settings;

        return self::depuis(is_array($reglages) ? ($reglages[self::CLE] ?? null) : null);
    }

    public static function depuis(mixed $stockes): self
    {
        $stockes = is_array($stockes) ? $stockes : [];
        $valeur = static function (string $cle) use ($stockes): int {
            $borne = self::SEUILS[$cle];
            $lue = $stockes[$cle] ?? null;

            return is_int($lue) && $lue >= $borne['min'] && $lue <= $borne['max'] ? $lue : $borne['defaut'];
        };

        return new self($valeur('jours_sans_connexion'), $valeur('remises_manquees'), $valeur('part_chapitres_non_ouverts'));
    }

    /** @return array<string, list<string>> les règles de validation de la saisie, des mêmes bornes */
    public static function regles(): array
    {
        $regles = [];
        foreach (self::SEUILS as $cle => $borne) {
            $regles[$cle] = ['required', 'integer', 'min:'.$borne['min'], 'max:'.$borne['max']];
        }

        return $regles;
    }

    /** @return array{jours_sans_connexion: int, remises_manquees: int, part_chapitres_non_ouverts: int} */
    public function enTableau(): array
    {
        return [
            'jours_sans_connexion' => $this->joursSansConnexion,
            'remises_manquees' => $this->remisesManquees,
            'part_chapitres_non_ouverts' => $this->partChapitresNonOuverts,
        ];
    }
}
