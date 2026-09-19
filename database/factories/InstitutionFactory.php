<?php

namespace Database\Factories;

use App\Enums\InstitutionMode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class InstitutionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => Str::slug(fake()->unique()->company()),
            'name' => fake()->company(),
            'klassci_api_url' => fake()->url(),
            'klassci_api_token_encrypted' => Str::random(64),
            'logo_url' => fake()->imageUrl(),
            'primary_color' => fake()->hexColor(),
            'is_active' => true,
            'settings' => json_encode([
                'timezone' => 'UTC',
                'academic_year' => 2026,
            ]),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Une école réellement autonome (#802).
     *
     * ## Les TROIS attributs, et pourquoi pas deux
     *
     * L'issue ne demande que `klassci_api_url` et le jeton à null. Poser aussi
     * `mode` est un écart assumé : sans lui, la factory fabriquerait une école
     * autonome pour le résolveur de cible et **KLASSCI pour l'autorité de
     * roster** — `mode` vaut `klassci` par défaut en mémoire, via le
     * `$attributes` du modèle `Institution`. Un état nommé d'après une colonne
     * qu'il ne pose pas est un piège pour son lecteur.
     *
     * La production fait exactement cela : `SchoolRequestDecisionService` ouvre
     * une école avec `mode = Standalone` ET `klassci_api_url = null`. Une
     * factory qui produirait autre chose fabriquerait une école qui n'existe
     * pas.
     *
     * Références citées EN PROSE et non en `{@see}` : le fixer
     * `fully_qualified_strict_types` de Pint transforme une référence en
     * import, et ce fichier se retrouverait à importer une classe de `tests/`
     * — une dépendance à l'envers. Le dépôt connaît déjà ce piège
     * (`tests/Feature/Docs/openapi-coverage-baseline.php`).
     *
     * Le jeton est explicitement nulé : `definition()` en pose un, et « école
     * autonome avec un jeton KLASSCI » est une chimère.
     *
     * ## À NE PAS utiliser pour un test qui vise UN SEUL axe
     *
     * Les deux axes sont volontairement orthogonaux — la liaison réseau
     * (`klassci_api_url`) et l'intention déclarée (`mode`). Un test qui vérifie
     * qu'une URL vidée ne rend PAS un tenant autonome doit continuer de poser
     * ses attributs à la main : cet état combiné le rendrait vert pour la
     * mauvaise raison. Voir `tests/Feature/Roster/RosterAuthorityResolutionTest.php`,
     * qui épingle les deux cas croisés.
     */
    public function standalone(): static
    {
        return $this->state(fn (array $attributes) => [
            'mode' => InstitutionMode::Standalone,
            'klassci_api_url' => null,
            'klassci_api_token_encrypted' => null,
        ]);
    }
}
