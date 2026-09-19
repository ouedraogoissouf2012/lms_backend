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
     * Les deux formes ambigues, ecrites en morceaux pour ne pas se declencher
     * sur elles-memes.
     *
     * ## Pourquoi deux, et pourquoi une expression plutot qu'une chaine
     *
     * La premiere version ne bannissait que la chaine litterale
     * `orWhere('klassci_id'`. Elle ne voyait donc AUCUN site de ce chantier :
     *
     *  - les colonnes miroir ne s'appellent pas toutes `klassci_id`. `Seance`
     *    porte `klassci_seance_id`, et trois sites l'arbitrent a la main ;
     *  - la forme INVERSEE `where(klassci_...)->orWhere('id')` est tout aussi
     *    indeterministe, et elle s'etale sur DEUX lignes — un balayage ligne a
     *    ligne ne pouvait pas la voir.
     *
     * Mesure a l'ecriture de ce garde : le cliquet passait au vert alors que
     * quatre sites vivants portaient la faute.
     */
    private const FORMES_BANNIES = [
        // cle locale PUIS colonne miroir
        '/(?:whereKey\(|where\(\s*\'id\')[^;]{0,200}?->\s*orWhere\(\s*\'klassci_\w*id\'/s',
        // colonne miroir PUIS cle locale (forme inversee, sur deux lignes)
        '/where\(\s*\'klassci_\w*id\'[^;]{0,200}?->\s*(?:orWhereKey\(|orWhere\(\s*\'id\')/s',
    ];

    /**
     * Les quatre sites heritees, geles — et le rang de la PR qui les retire.
     *
     * Le cliquet passe au vert sur ces quatre-la et rougit sur TOUT nouveau.
     * C'est la convention du depot : phpstan-baseline, method-length-baseline,
     * et les cinq cliquets du frontend fonctionnent ainsi.
     *
     * Chaque entree se retire avec sa PR. Une liste qui ne decroit pas est un
     * chantier qui n'avance pas — et cela se lit ici, sans rapport a tenir.
     *
     * N'AJOUTER AUCUNE ENTREE. Un site neuf doit employer le trait :
     * `Modele::localIdFor($id, $institutionId)`.
     */
    private const HERITEES = [
        // PR 6 — masquage etudiant, consequence bornee a un etudiant
        'app/Services/Seances/Mutations/SeanceHideService.php',
        // PR 5 — validation des participants : seul site ou l'ordre du moteur
        //        a une consequence d'AUTORISATION (role moderateur)
        'app/Services/Seances/Mutations/ParticipantValidationService.php',
        // PR 7 — presences : la route expose des emails
        'app/Services/Attendances/VideoSessionAttendancesSyncer.php',
        // PR 4 — forme INVERSEE, au coeur de la chaine visio
        'app/Services/Seances/SeanceVisioEnricher.php',
    ];

    public function test_no_file_resolves_the_two_identifier_spaces_by_itself(): void
    {
        $coupables = $this->filesUsingTheBannedForm();

        self::assertSame(
            [],
            $coupables,
            "Ces fichiers résolvent eux-mêmes les deux espaces d'identifiants :\n  - "
            .implode("\n  - ", $coupables)
            ."\n\nLa forme `".'la forme ambigue'.', $v)` est indéterministe quand un id local'
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

            $relatif = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                substr($fichier->getPathname(), strlen($racine) + 1),
            );

            if (in_array($relatif, self::HERITEES, true)) {
                continue;
            }

            if ($this->porteUneFormeBannie($source)) {
                $coupables[] = $relatif;
            }
        }

        sort($coupables);

        return $coupables;
    }

    /**
     * Le code seul, commentaires retires.
     *
     * Un docblock qui DECRIT le defaut ne doit pas declencher le garde qui
     * l'interdit : `MatiereSeancesFetcher` en cite un en prose, et le signaler
     * rendrait ce garde inutilisable.
     *
     * Le depouillement precede la recherche, et ne la suit pas : la forme
     * inversee s'etale sur deux lignes, elle ne peut donc pas etre cherchee
     * ligne a ligne.
     */
    private function porteUneFormeBannie(string $source): bool
    {
        $code = [];

        foreach (explode("\n", $source) as $ligne) {
            $nue = ltrim($ligne);

            if ($nue === '' || str_starts_with($nue, '*') || str_starts_with($nue, '//') || str_starts_with($nue, '/*')) {
                continue;
            }

            $code[] = $ligne;
        }

        $joint = implode("\n", $code);

        foreach (self::FORMES_BANNIES as $forme) {
            if (preg_match($forme, $joint) === 1) {
                return true;
            }
        }

        return false;
    }
}
