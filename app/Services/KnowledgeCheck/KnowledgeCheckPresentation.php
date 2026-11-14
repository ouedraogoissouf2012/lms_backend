<?php

declare(strict_types=1);

namespace App\Services\KnowledgeCheck;

use Random\Randomizer;

/**
 * C44 — l'ordre présenté à l'élève, et la traduction entre cet ordre et l'ordre
 * enregistré.
 *
 * ## La présentation
 *
 * `questions` liste les indices d'ORIGINE des questions dans l'ordre affiché ;
 * `options[q]` liste les indices d'origine des options de la question d'origine
 * `q`, dans l'ordre affiché. Sans mélange, ce sont des identités : la
 * traduction et les bornes valent pour tout quiz. Elle est écrite sur la
 * tentative au démarrage.
 *
 * ## Pourquoi traduire côté serveur
 *
 * L'élève remet par position AFFICHÉE (le contrat du front ne change pas), et
 * la charge de démarrage n'expose aucun indice d'origine : sans quoi la bonne
 * réponse se lirait dans les outils du navigateur, quel que soit l'ordre
 * affiché, et le mélange ne protégerait plus rien.
 *
 * Le hasard est injecté : un test fixe la graine.
 *
 * @phpstan-type Presentation array{questions: list<int|string>, options: array<int|string, list<int>>}
 */
final class KnowledgeCheckPresentation
{
    public function __construct(private readonly Randomizer $hasard)
    {
    }

    /**
     * @param  array<int|string, mixed>  $questions  les questions enregistrées
     * @return Presentation
     */
    public function tirer(array $questions, bool $melangerQuestions, bool $melangerOptions): array
    {
        $ordre = array_keys($questions);
        if ($melangerQuestions) {
            $ordre = $this->melanger($ordre);
        }

        $options = [];
        foreach ($questions as $q => $question) {
            $indices = array_keys(self::options($question));
            $options[$q] = $melangerOptions ? $this->melanger($indices) : $indices;
        }

        return ['questions' => $ordre, 'options' => $options];
    }

    /**
     * `Randomizer::shuffleArray()` rend un `array` sans type d'éléments : la
     * permutation d'une liste est une liste des mêmes éléments.
     *
     * @template T
     *
     * @param  list<T>  $liste
     * @return list<T>
     */
    private function melanger(array $liste): array
    {
        /** @var list<T> $melangee */
        $melangee = array_values($this->hasard->shuffleArray($liste));

        return $melangee;
    }

    /**
     * Les questions dans l'ordre affiché, chacune avec ses options dans l'ordre affiché.
     *
     * @param  array<int|string, mixed>  $questions
     * @param  Presentation  $presentation  concordante ({@see concorde()})
     * @return list<array<string, mixed>>
     */
    public function presenter(array $questions, array $presentation): array
    {
        $affichees = [];
        foreach ($presentation['questions'] as $q) {
            $question = $questions[$q] ?? null;
            if (! is_array($question)) {
                $question = [];
            }
            $options = self::options($question);
            $question['options'] = array_map(static fn (int $o): mixed => $options[$o] ?? null, $presentation['options'][$q] ?? []);
            $affichees[] = $question;
        }

        return $affichees;
    }

    /**
     * La présentation désigne-t-elle encore le quiz tel qu'il est enregistré ?
     * Non, dès que l'enseignant a ajouté ou retiré une question ou une option
     * depuis le démarrage : la remise ne se traduit plus.
     *
     * @param  array<int|string, mixed>  $questions
     * @param  Presentation  $presentation
     */
    public function concorde(array $questions, array $presentation): bool
    {
        if (count($presentation['questions']) !== count($questions)) {
            return false;
        }

        foreach ($presentation['questions'] as $q) {
            if (! array_key_exists($q, $questions)
                || count(self::options($questions[$q])) !== count($presentation['options'][$q] ?? [])) {
                return false;
            }
        }

        return true;
    }

    /**
     * La présentation relue sur la tentative, ou `null` si elle n'en a pas la forme
     * (aucune tentative d'avant C44 n'en porte).
     *
     * @return Presentation|null
     */
    public static function relire(mixed $brute): ?array
    {
        if (! is_array($brute)) {
            return null;
        }
        $questionsBrutes = $brute['questions'] ?? null;
        $optionsBrutes = $brute['options'] ?? null;
        if (! is_array($questionsBrutes) || ! is_array($optionsBrutes)) {
            return null;
        }

        $questions = [];
        foreach ($questionsBrutes as $q) {
            if (! is_int($q) && ! is_string($q)) {
                return null;
            }
            $questions[] = $q;
        }

        $options = [];
        foreach ($optionsBrutes as $q => $ordre) {
            if (! is_array($ordre)) {
                return null;
            }
            $liste = [];
            foreach ($ordre as $o) {
                if (! is_int($o)) {
                    return null;
                }
                $liste[] = $o;
            }
            $options[$q] = $liste;
        }

        return ['questions' => $questions, 'options' => $options];
    }

    /**
     * La remise, de l'ordre affiché vers l'ordre enregistré. `null` : une réponse
     * désigne une option qui n'a pas été affichée, ou une question qui n'existe pas.
     *
     * @param  Presentation  $presentation
     * @param  array<int|string, mixed>  $reponses  validées par {@see \App\Rules\OptionIndexAnswer}
     * @return array<int|string, int|list<int>|null>|null  indexée par question d'origine
     */
    public function versOrigine(array $presentation, array $reponses): ?array
    {
        $origine = [];
        foreach ($reponses as $p => $reponse) {
            $q = $presentation['questions'][$p] ?? null;
            if ($q === null) {
                return null;
            }

            $traduite = self::versOptionsDOrigine($reponse, $presentation['options'][$q] ?? []);
            if ($traduite === false) {
                return null;
            }
            $origine[$q] = $traduite;
        }

        return $origine;
    }

    /**
     * Le détail noté, rendu dans l'ordre affiché : l'écran de correction lit
     * `questions[question_index].options[correct_answer]` sur la liste reçue au
     * démarrage. `user_answer` y est la remise telle qu'envoyée.
     *
     * @param  Presentation  $presentation
     * @param  list<array<string, mixed>>  $detail  dans l'ordre enregistré (KnowledgeCheckGradingService)
     * @param  array<int|string, mixed>  $reponses  la remise, dans l'ordre affiché
     * @return list<array<string, mixed>>
     */
    public function versAffiche(array $presentation, array $detail, array $reponses): array
    {
        $parOrigine = [];
        foreach ($detail as $ligne) {
            $index = $ligne['question_index'] ?? null;
            if (is_int($index) || is_string($index)) {
                $parOrigine[$index] = $ligne;
            }
        }

        $affiche = [];
        foreach ($presentation['questions'] as $p => $q) {
            $ligne = $parOrigine[$q] ?? [];
            $ligne['question_index'] = $p;
            $ligne['user_answer'] = $reponses[$p] ?? null;
            $ligne['correct_answer'] = self::versOptionsAffichees($ligne['correct_answer'] ?? null, $presentation['options'][$q] ?? []);
            $affiche[] = $ligne;
        }

        return $affiche;
    }

    /**
     * @param  list<int>  $ordre  indices d'origine, dans l'ordre affiché
     * @return int|list<int>|null|false  `false` : hors des options affichées
     */
    private static function versOptionsDOrigine(mixed $reponse, array $ordre): int|array|null|false
    {
        if ($reponse === null) {
            return null;
        }
        if (is_int($reponse)) {
            return $ordre[$reponse] ?? false;
        }
        if (! is_array($reponse)) {
            return false;
        }

        $liste = [];
        foreach ($reponse as $i) {
            if (! is_int($i) || ! array_key_exists($i, $ordre)) {
                return false;
            }
            $liste[] = $ordre[$i];
        }

        return $liste;
    }

    /**
     * La bonne réponse enregistrée, vue de l'élève. Un texte (donnée d'avant C45)
     * reste tel quel ; un indice hors des options ne désigne rien à l'écran.
     *
     * @param  list<int>  $ordre
     */
    private static function versOptionsAffichees(mixed $bonne, array $ordre): mixed
    {
        $place = array_flip($ordre);
        if (is_int($bonne)) {
            return $place[$bonne] ?? null;
        }
        if (is_array($bonne)) {
            return array_values(array_filter(
                array_map(static fn (mixed $o): ?int => is_int($o) ? ($place[$o] ?? null) : null, $bonne),
                static fn (?int $o): bool => $o !== null,
            ));
        }

        return $bonne;
    }

    /**
     * Les options d'une question, par position (0, 1, …) : la position est ce que
     * l'éditeur range comme bonne réponse et ce que l'élève remet.
     *
     * @return list<mixed>
     */
    private static function options(mixed $question): array
    {
        $options = is_array($question) ? ($question['options'] ?? null) : null;

        return is_array($options) ? array_values($options) : [];
    }
}
