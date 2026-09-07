<?php

declare(strict_types=1);

namespace App\Services\Matiere;

/**
 * Dérive les classes distinctes d'une matière à partir de ses séances
 * enrichies.
 *
 * Le frontend (création de leçon → `classe_id` requis ; onglet « Classes »)
 * attend la clé `classes_concernees` dans la réponse `/lms/matieres/{id}`, mais
 * elle était absente du contrat → `classe_id` envoyé null → 403 à la création.
 *
 * ## ⚠️ Les identifiants rendus ici sont ceux de KLASSCI
 *
 * Ce docblock a longtemps affirmé le contraire — « des ids de `Classe` LOCALE,
 * donc acceptés par la garde d'autorisation ». **C'était faux**, et l'erreur
 * était d'autant plus coûteuse qu'elle servait de justification écrite à la
 * sûreté de `StoreLessonRequest::authorize()`.
 *
 * Les séances proviennent de l'`emploi-temps` KLASSCI, dont
 * `KlassciEmploiTempsSeances::adapt()` CONSERVE le payload d'origine : le bloc
 * `classe` est donc celui de KLASSCI, `id` compris.
 *
 * La traduction vers l'espace local appartient à {@see MatiereClassesResolver},
 * seul responsable de ce qui sort de l'API. Cette classe-ci reste une
 * extraction brute, et le dit.
 *
 * Fonction PURE : dédoublonne par id, conserve l'ordre de première apparition,
 * ignore les séances sans classe valide, ne lève jamais.
 */
final class MatiereClassesExtractor
{
    /**
     * @param  array<int, array<string, mixed>>  $seances
     * @return array<int, array{id: int, nom: string}>
     */
    public static function fromSeances(array $seances): array
    {
        $byId = [];

        foreach ($seances as $seance) {
            $classe = is_array($seance['classe'] ?? null) ? $seance['classe'] : [];
            $id = $classe['id'] ?? null;
            if (! is_numeric($id)) {
                continue;
            }
            $id = (int) $id;

            $byId[$id] ??= [
                'id' => $id,
                'nom' => self::resolveNom($classe),
            ];
        }

        return array_values($byId);
    }

    /**
     * @param  array<string, mixed>  $classe
     */
    private static function resolveNom(array $classe): string
    {
        if (is_string($classe['nom'] ?? null)) {
            return $classe['nom'];
        }
        if (is_string($classe['libelle'] ?? null)) {
            return $classe['libelle'];
        }

        return 'N/A';
    }
}
