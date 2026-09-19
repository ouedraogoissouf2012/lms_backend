<?php

declare(strict_types=1);

namespace App\Services\Classe;

/**
 * Lecture DÉFENSIVE de l'enveloppe KLASSCI `GET classes/{id}`.
 *
 * KLASSCI livre `data` sous forme d'enveloppe — `{classe, etudiants, matieres,
 * evaluations, emploi_temps_semaine, statistiques}` — et non comme la classe à
 * plat. Chaque bloc y est déjà présent : le redemander par un appel séparé est
 * superflu, et parfois refusé en amont (`classes/{id}/etudiants` → 403 par
 * classe) ou tout simplement faux (le catalogue `matieres?…` ignore ses filtres).
 *
 * Ce lecteur est PUR (aucune dépendance, aucune I/O), sur le modèle de
 * {@see \App\Services\Seances\KlassciPayload} : il narrowe du `mixed` non typé
 * sans jamais lever d'exception.
 *
 * Distinction structurante : `optionalList()` renvoie `null` quand le bloc est
 * ABSENT, `[]` quand il est livré et réellement vide. Sans elle, une absence
 * amont se publierait comme une mesure (« 0 matière »).
 *
 * @see app/Services/Classe/ClasseDetailsQueryService.php
 */
final class ClasseEnvelope
{
    /**
     * Marqueurs d'absence observés à la place d'un nom, comparés à la chaîne
     * entière et sans tenir compte de la casse.
     *
     * @var list<string>
     */
    private const MARQUEURS_D_ABSENCE = ['n/a', 'null', 'none', 'neant', 'néant', '-', '--', '?', '.'];

    /**
     * Bloc `classe` de l'enveloppe, ou `null` s'il est absent/malformé.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public static function classe(?array $payload): ?array
    {
        $classe = is_array($payload) ? ($payload['classe'] ?? null) : null;

        /** @var array<string, mixed>|null $classe */
        return is_array($classe) ? $classe : null;
    }

    /**
     * Roster livré avec la classe. Toujours un tableau : l'appelant distingue
     * lui-même « aucun étudiant » d'une classe introuvable (statut 404).
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<int, array<string, mixed>>
     */
    public static function etudiants(?array $payload): array
    {
        return self::optionalList($payload, 'etudiants') ?? [];
    }

    /**
     * Nom affichable d'un étudiant du roster.
     *
     * L'enveloppe n'expose QUE `nom_complet` — jamais `nom` ni `prenom`. Un
     * appelant qui composait le nom depuis ces deux clés absentes obtenait une
     * chaîne vide : en production, l'écran des notes a affiché six lignes sans
     * personne. La connaissance vit ici, avec le reste de la forme de cette
     * enveloppe, pour qu'aucun appelant n'ait à la redécouvrir.
     *
     * Le repli `nom` + `prenom` couvre les sources qui séparent les deux — la
     * règle est déjà celle de
     * {@see \App\Services\Sync\Classes\ClasseStudentsSynchronizer}.
     *
     * On ne DÉCOUPE jamais `nom_complet` pour en déduire un prénom : les noms
     * composés sont la règle ici, et découper sur la première espace
     * fabriquerait une identité fausse.
     *
     * @param  array<string, mixed>  $etudiant
     */
    public static function nomEtudiant(array $etudiant): string
    {
        $complet = self::valeurNommante($etudiant['nom_complet'] ?? null);
        if ($complet !== null) {
            return $complet;
        }

        $nom = self::valeurNommante($etudiant['nom'] ?? null) ?? '';
        $prenom = self::valeurNommante($etudiant['prenom'] ?? null) ?? '';

        return trim($nom.' '.$prenom);
    }

    /**
     * De quoi DÉSIGNER l'étudiant dans un tableau, à défaut de le nommer.
     *
     * Le matricule identifie la même personne : il ne l'invente pas, il la
     * repère autrement. Sans nom NI matricule, on rend une chaîne vide plutôt
     * qu'un libellé de remplacement — substituer un marqueur à un autre ne
     * ferait que déplacer le mensonge.
     *
     * @param  array<string, mixed>  $etudiant
     */
    public static function identiteAffichable(array $etudiant): string
    {
        $nom = self::nomEtudiant($etudiant);

        if ($nom !== '') {
            return $nom;
        }

        return self::valeurNommante($etudiant['matricule'] ?? null) ?? '';
    }

    /**
     * La valeur nomme-t-elle réellement quelqu'un, ou est-ce un marqueur
     * d'absence ? `null` si elle ne nomme personne.
     *
     * Mesuré en production le 2026-09-18 : KLASSCI renvoie littéralement
     * `"N/A"` dans `nom_complet` quand il n'a pas le nom, et le tableau des
     * notes affichait une ligne « N/A » avec « N/ » en initiales — une absence
     * présentée comme une personne.
     *
     * La liste est volontairement COURTE et comparée à la chaîne ENTIÈRE :
     * l'élargir ferait disparaître de vrais noms, ce qui serait pire que le
     * défaut corrigé. On n'y met que ce qui a été observé, ou ce qu'aucun nom
     * ne peut valoir.
     */
    private static function valeurNommante(mixed $valeur): ?string
    {
        if (! is_string($valeur)) {
            return null;
        }

        $propre = trim($valeur);

        if ($propre === '' || in_array(mb_strtolower($propre), self::MARQUEURS_D_ABSENCE, true)) {
            return null;
        }

        return $propre;
    }

    /**
     * Bloc de liste, en distinguant ABSENT (`null`) de VIDE (`[]`).
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<int, array<string, mixed>>|null
     */
    public static function optionalList(?array $payload, string $key): ?array
    {
        if (!is_array($payload) || !is_array($payload[$key] ?? null)) {
            return null;
        }

        /** @var array<int, array<string, mixed>> $list */
        $list = array_values(array_filter(
            $payload[$key],
            static fn (mixed $item): bool => is_array($item),
        ));

        return $list;
    }
}
