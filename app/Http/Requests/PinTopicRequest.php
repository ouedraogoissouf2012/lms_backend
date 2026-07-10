<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksForumAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * PinTopicRequest - Pin forum topic
 *
 * Purpose: Authorize topic pinning with ownership + tenant isolation.
 * Authorization: Topic owner OR institution-scoped admin/coordinateur within
 *                the same tenant. Supradmin bypasses tenant isolation.
 * 10-year perspective: Pinned topics float to top, enabling visibility for
 *                      important discussions.
 */
class PinTopicRequest extends FormRequest
{
    use ChecksForumAuthorization;

    public function authorize(): bool
    {
        $topic = $this->route('topic');
        $topic = $topic instanceof \App\Models\ForumTopic ? $topic : null;

        return $this->isForumActionAuthorized(
            ownerUserId: $topic?->user_id,
            tenantInstitutionId: $topic?->institution_id,
            user: Auth::user(),
            // Fix E2E #211 flow 4 : la route est declaree pour les formateurs
            // (role:enseignant,coordinateur,admin) mais la FormRequest les
            // excluait -> close/pin de facto inaccessibles aux enseignants.
            moderatorRoles: ['admin', 'administrateur', 'superAdmin', 'coordinateur', 'enseignant'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
