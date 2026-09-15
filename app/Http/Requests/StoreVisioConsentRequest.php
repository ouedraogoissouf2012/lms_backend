<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Visio\Recording\VisioConsentService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Le dépôt de consentement visio (#716 ↔ front #333).
 *
 * Les trois finalités sont **obligatoires** et booléennes. Accepter d'être
 * filmé pour le groupe n'est pas accepter la réutilisation sur une autre
 * session : un consentement partiellement renseigné n'aurait pas de sens
 * juridique, et un défaut implicite en aurait encore moins.
 *
 * Aucun `user_id` n'est accepté : le sujet est toujours le porteur du jeton.
 * {@see VisioConsentService::record()}
 */
final class StoreVisioConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'captation' => ['required', 'boolean'],
            'diffusion' => ['required', 'boolean'],
            'reutilisation' => ['required', 'boolean'],
        ];
    }

    /**
     * Les trois choix, normalisés — et RIEN d'autre que les trois.
     *
     * @return array<string, bool>
     */
    public function choix(): array
    {
        return [
            'captation' => $this->boolean('captation'),
            'diffusion' => $this->boolean('diffusion'),
            'reutilisation' => $this->boolean('reutilisation'),
        ];
    }

    /**
     * Ce qui rend le consentement opposable : quand, depuis où, par quel agent.
     *
     * @return array<string, mixed>
     */
    public function preuve(): array
    {
        return [
            'ip' => $this->ip(),
            'user_agent' => substr((string) $this->userAgent(), 0, 255),
            'recueilli_a' => now()->toIso8601String(),
        ];
    }
}
