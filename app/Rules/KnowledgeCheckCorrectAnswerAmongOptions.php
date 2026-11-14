<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * La bonne réponse d'une question de quiz de leçon désigne une de ses options
 * (partie 3).
 *
 * C'est l'INDICE d'une option : l'éditeur range `optionIndex`, l'élève remet un
 * indice (C45), et la notation compare en strict
 * (`KnowledgeCheckGradingService::gradeAnswer`). Un indice hors des options, un
 * texte ou un doublon rend la question impossible à réussir. Admis :
 *
 * - `single` : un entier dans [0, n - 1] ;
 * - `true_false` : un entier dans [0, 1], avec exactement deux options ;
 * - `multiple` : une liste non vide d'entiers distincts, chacun dans les bornes.
 *
 * La règle lit la question voisine (`DataAwareRule`). Elle laisse les options mal
 * formées et le type inconnu à leurs propres règles, et l'obligation à
 * `required`. Son échec porte un motif, posé par la requête
 * (`ExplainsKnowledgeCheckAnswerRefusal`).
 */
final class KnowledgeCheckCorrectAnswerAmongOptions implements DataAwareRule, ValidationRule
{
    public const MESSAGE = 'Une bonne réponse doit être une des options proposées.';

    /** @var array<mixed> */
    private array $data = [];

    /** @param  array<mixed>  $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $question = $this->questionDe($attribute);
        $options = $question['options'] ?? null;
        if (! is_array($options) || ! array_is_list($options)) {
            return;
        }

        $nombre = count($options);
        $designe = match ($question['type'] ?? null) {
            'single' => self::estUnIndice($value, $nombre),
            'true_false' => $nombre === 2 && self::estUnIndice($value, $nombre),
            'multiple' => self::estUneListeDIndices($value, $nombre),
            default => true,
        };

        if (! $designe) {
            $fail(self::MESSAGE);
        }
    }

    private static function estUnIndice(mixed $valeur, int $nombre): bool
    {
        return is_int($valeur) && $valeur >= 0 && $valeur < $nombre;
    }

    private static function estUneListeDIndices(mixed $valeur, int $nombre): bool
    {
        if (! is_array($valeur) || ! array_is_list($valeur) || $valeur === []) {
            return false;
        }

        foreach ($valeur as $indice) {
            if (! self::estUnIndice($indice, $nombre)) {
                return false;
            }
        }

        return count(array_unique($valeur)) === count($valeur);
    }

    /** @return array<mixed>|null la question dont `$attribute` (`questions.N.correct_answer`) est la clé */
    private function questionDe(string $attribute): ?array
    {
        if (preg_match('/^questions\.(\d+)\.correct_answer$/', $attribute, $m) !== 1) {
            return null;
        }
        $questions = $this->data['questions'] ?? null;
        $question = is_array($questions) ? ($questions[(int) $m[1]] ?? null) : null;

        return is_array($question) ? $question : null;
    }
}
