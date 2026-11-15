<?php

declare(strict_types=1);

namespace App\Rules\Concerns;

/**
 * Une règle posée sur `questions.N.correct_answers` lit la question N entière (son `type`, ses
 * `options`) dans les données de la requête (`DataAwareRule`).
 */
trait ReadsSiblingQuestion
{
    /** @var array<mixed> */
    private array $data = [];

    /** @param  array<mixed>  $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /** @return array<mixed>|null la question dont `$attribute` (`questions.N.correct_answers`) est la clé */
    private function questionDe(string $attribute): ?array
    {
        if (preg_match('/^questions\.(\d+)\.correct_answers$/', $attribute, $m) !== 1) {
            return null;
        }
        $questions = $this->data['questions'] ?? null;
        $question = is_array($questions) ? ($questions[(int) $m[1]] ?? null) : null;

        return is_array($question) ? $question : null;
    }
}
