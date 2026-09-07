<?php

declare(strict_types=1);

namespace Tests\Feature\Klassci;

use App\Models\Traits\ResolvesMirroredIdentifier;
use Tests\Feature\Lesson\KlassciIdentifierDualityTest;
use Tests\TestCase;
use Tests\Unit\Models\Traits\ResolvesMirroredIdentifierTest;

/**
 * Garde structurel : la résolution « id local OU `klassci_id` » n'a qu'UN seul
 * lieu, {@see ResolvesMirroredIdentifier}.
 *
 * ## Ce que ce garde protège
 *
 * Les deux autres gardes de ce lot verrouillent des COMPORTEMENTS :
 * {@see KlassciIdentifierDualityTest} exige que les deux
 * espaces d'identifiants aboutissent, {@see ResolvesMirroredIdentifierTest}
 * fige la précédence et le bornage tenant. Aucun des deux n'empêche qu'on
 * réécrive DEMAIN une cinquième copie de la règle dans un coin — et c'est
 * précisément la duplication qui a produit le défaut :
 *
 * | Site d'origine | Sémantique |
 * |---|---|
 * | `StoreLessonRequest::authorize()` | `where(id)->orWhere(klassci_id)` — ambiguë |
 * | `StoreLessonRequest::rules()` | idem, dans une closure |
 * | `LessonCrudOperationsService` (matière) | deux requêtes, précédence locale |
 * | `LessonCrudOperationsService` (classe) | deux requêtes, précédence locale |
 *
 * Quatre copies, deux sémantiques. `matiere_id` a fini par ne plus accepter
 * que l'espace local, et toute création de leçon portant une matière échouait.
 *
 * ## Pourquoi cette forme précise est bannie
 *
 * `where('id', $v)->orWhere('klassci_id', $v)` est **indéterministe** : rien
 * n'interdit que l'id local d'une ligne égale le `klassci_id` d'une autre — ce
 * sont deux numérotations indépendantes — et la ligne renvoyée dépend alors de
 * l'ordre du moteur, donc différemment sous SQLite et sous MySQL. La forme est
 * interdite sans exception ; le besoin légitime passe par le trait
 * {@see ResolvesMirroredIdentifier}, qui tranche en faveur de l'espace local.
 *
 * ## Ouvert à l'extension
 *
 * Ce garde n'interdit pas d'ajouter des entités duales : il impose seulement
 * qu'elles passent par le résolveur commun. Rendre une entité duale, c'est
 * DÉCLARER le contrat sur son modèle — `implements MirroredFromKlassci` et
 * `use ResolvesMirroredIdentifier` — puis appeler
 * `Evaluation::localIdFor($id, $institutionId)`. Jamais recopier la requête.
 */
final class SingleMirroredIdentifierResolverTest extends TestCase
{
    /**
     * La forme ambiguë, écrite en morceaux pour ne pas se déclencher sur
     * elle-même.
     */
    private const FORME_BANNIE = 'orWhere('."'".'klassci_'.'id'."'";

    public function test_no_file_resolves_the_two_identifier_spaces_by_itself(): void
    {
        $coupables = $this->filesUsingTheBannedForm();

        self::assertSame(
            [],
            $coupables,
            "Ces fichiers résolvent eux-mêmes les deux espaces d'identifiants :\n  - "
            .implode("\n  - ", $coupables)
            ."\n\nLa forme `".self::FORME_BANNIE.', $v)` est indéterministe quand un id local'
            ." percute un klassci_id.\n"
            .'Déclare le modèle `implements MirroredFromKlassci`, ajoute `use ResolvesMirroredIdentifier`, '
            .'puis appelle `Modele::localIdFor($id, $institutionId)` : le trait tranche en faveur de '
            ."l'espace local et borne à l'institution."
        );
    }

    /**
     * Les fichiers de `app/` employant la forme bannie. Les commentaires n'en
     * sont pas : un docblock qui décrit le défaut ne doit pas déclencher le
     * garde qui l'interdit.
     *
     * @return list<string>
     */
    private function filesUsingTheBannedForm(): array
    {
        $racine = base_path();
        $coupables = [];

        /** @var iterable<\SplFileInfo> $fichiers */
        $fichiers = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())),
            '/\.php$/'
        );

        foreach ($fichiers as $fichier) {
            $source = (string) file_get_contents($fichier->getPathname());

            foreach (explode("\n", $source) as $ligne) {
                $nue = ltrim($ligne);

                if ($nue === '' || str_starts_with($nue, '*') || str_starts_with($nue, '//') || str_starts_with($nue, '/*')) {
                    continue;
                }

                if (str_contains($ligne, self::FORME_BANNIE)) {
                    $coupables[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($fichier->getPathname(), strlen($racine) + 1));
                    break;
                }
            }
        }

        sort($coupables);

        return $coupables;
    }
}
