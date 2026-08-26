<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Seances\KlassciPayload;
use UnexpectedValueException;

/**
 * Les deux sources de recherche servies par KLASSCI : classes et matières (#505).
 *
 * Extraites de {@see GlobalSearchService} pour deux raisons indissociables :
 *
 *  1. **Responsabilité** — ce sont les seules sources DISTANTES de la recherche.
 *     Elles sont les seules à pouvoir tomber, donc les seules à devoir être
 *     surveillées ; les isoler rend ce statut explicite plutôt qu'implicite.
 *  2. **Taille (§1.1)** — `GlobalSearchService` frôlait la limite de 300 lignes.
 *
 * ## Les pannes ne sont PAS avalées ici
 *
 * Avant #505, chaque méthode portait son propre `try/catch → return []`, si bien
 * qu'une panne KLASSCI ressortait sous la forme exacte d'un « aucun résultat ».
 * Cette classe laisse désormais **remonter** l'échec : c'est l'orchestrateur qui
 * décide d'en faire une dégradation nommée, parce que lui seul connaît les autres
 * sources et le cache.
 *
 * ## L'enveloppe KLASSCI est enfin déballée
 *
 * Le code d'origine itérait la réponse BRUTE du proxy. Or KLASSCI enveloppe ses
 * collections — `EvaluationCreationService:161,173`, `EvaluationEnrichmentService:64`
 * et `StudentGradesAggregator:59` lisent tous `['data']`. La recherche parcourait
 * donc `['success' => true, 'data' => [...]]` et ne trouvait jamais la moindre
 * classe. Corrigé ici : sans cela, le drapeau de dégradation introduit par cette
 * issue serait allumé en PERMANENCE sur un KLASSCI parfaitement sain — une
 * régression pire que le défaut qu'il documente.
 *
 * @see GlobalSearchService::runDegradableSource()
 */
final class KlassciSearchSources
{
    public function __construct(
        private readonly KlassciProxyService $klassci,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchClasses(string $query, User $user, int $limit): array
    {
        if (! $user->isStaff()) {
            return [];
        }

        return collect(self::records($this->klassci->getClasses(), 'classes'))
            ->filter(fn (array $classe): bool => self::matches($query, [
                self::text($classe, 'name'),
                self::nestedText($classe, 'filiere', 'name'),
                self::nestedText($classe, 'niveau', 'name'),
            ]))
            ->take($limit)
            ->map(fn (array $classe): array => [
                // `id` est relayé tel quel : c'est un IDENTIFIANT, jamais concaténé
                // ni comparé ici, et le contrat client le veut numérique.
                'id' => $classe['id'] ?? null,
                'title' => self::text($classe, 'name'),
                'subtitle' => 'Classe',
                'description' => self::nestedText($classe, 'filiere', 'name')
                    . ' - ' . self::nestedText($classe, 'niveau', 'name'),
                'type' => 'classe',
                'icon' => 'BuildingLibraryIcon',
                'url' => '/admin/classes/' . self::text($classe, 'id'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchMatieres(string $query, User $user, int $limit): array
    {
        if (! $user->isStaff()) {
            return [];
        }

        $token = $user->klassci_token;
        if (! is_string($token) || $token === '') {
            return [];
        }

        return collect(self::records($this->klassci->getMatieres($token), 'matieres'))
            ->filter(fn (array $matiere): bool => self::matches($query, [
                self::text($matiere, 'nom'),
                self::text($matiere, 'code'),
            ]))
            ->take($limit)
            ->map(fn (array $matiere): array => [
                'id' => $matiere['id'] ?? null,
                'title' => self::text($matiere, 'nom'),
                'subtitle' => 'Matière',
                // `?? 'N/A'` d'origine préservé au caractère près : seule une valeur
                // ABSENTE ou nulle bascule sur « N/A », pas une chaîne vide.
                'description' => 'Code: ' . (($matiere['code'] ?? null) === null ? 'N/A' : self::text($matiere, 'code')),
                'type' => 'matiere',
                'icon' => 'AcademicCapIcon',
                'url' => '/admin/matieres/' . self::text($matiere, 'id'),
            ])
            ->values()
            ->all();
    }

    /**
     * La requête apparaît-elle dans l'un des champs consultés ?
     *
     * @param  list<string>  $haystacks
     */
    private static function matches(string $query, array $haystacks): bool
    {
        foreach ($haystacks as $haystack) {
            if (stripos($haystack, $query) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lit un champ TEXTE d'un enregistrement KLASSCI.
     *
     * {@see KlassciPayload::toStringOrNull()} ne convient pas ici : il renvoie
     * `null` pour tout ce qui n'est pas une chaîne, or `code` et `id` arrivent
     * fréquemment en entier. On stringifie donc les scalaires, et une valeur
     * absente ou structurée vaut chaîne vide — jamais la conversion implicite
     * d'un `array` en `'Array'`.
     *
     * @param  array<string, mixed>  $row
     */
    private static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Lit un champ texte d'un sous-objet (`filiere.name`, `niveau.name`).
     *
     * @param  array<string, mixed>  $row
     */
    private static function nestedText(array $row, string $key, string $child): string
    {
        return self::text(KlassciPayload::asArray($row[$key] ?? null), $child);
    }

    /**
     * Extrait la LISTE d'enregistrements d'une réponse KLASSCI.
     *
     * Deux formes acceptées : la collection enveloppée `{success, data:[…]}` —
     * celle que renvoie réellement le proxy — et la liste nue, tolérée pour ne
     * pas dépendre d'un détail de version côté KLASSCI.
     *
     * Toute AUTRE forme est refusée explicitement plutôt que parcourue : la
     * traverser produirait un « 0 résultat » indiscernable d'une recherche
     * infructueuse, exactement l'ambiguïté que cette issue supprime. L'appelant
     * en fera une source DÉGRADÉE, donc nommée dans la réponse.
     *
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     *
     * @throws UnexpectedValueException si la réponse n'expose pas de liste d'enregistrements.
     */
    private static function records(array $response, string $source): array
    {
        $payload = array_key_exists('data', $response) ? $response['data'] : $response;

        if (! is_array($payload) || ! array_is_list($payload)) {
            throw new UnexpectedValueException(
                "Réponse KLASSCI inattendue pour `{$source}` : liste d'enregistrements attendue.",
            );
        }

        return KlassciPayload::listOfArrays($payload);
    }
}
