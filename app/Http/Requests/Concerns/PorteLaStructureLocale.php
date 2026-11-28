<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * `filiere_id` et `niveau_id` d'une classe ou d'une matière locale (#1648, PR B), partagés par la création et la
 * modification des deux. Facultatifs, nuls compris (détacher). Qu'ils soient de l'école, c'est le service qui le dit
 * (`RattachementALaStructure`, 422 sous leur champ) : un `exists` ici ne lirait pas l'école.
 */
trait PorteLaStructureLocale
{
    /** @return array<string, list<string>> */
    protected function reglesDeLaStructure(): array
    {
        return [
            'filiere_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'niveau_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Les seuls champs ENVOYÉS.
     *
     * @return array{filiere_id?: int|null, niveau_id?: int|null}
     */
    protected function structure(): array
    {
        $structure = [];
        foreach (['filiere_id', 'niveau_id'] as $champ) {
            if ($this->has($champ)) {
                $structure[$champ] = $this->input($champ) === null ? null : $this->integer($champ);
            }
        }

        return $structure;
    }
}
