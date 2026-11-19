<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Http\Requests\Concerns\ChecksForumAuthorization;
use App\Models\ForumTopic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * CloseTopicRequest - Close forum topic
 *
 * Purpose: Authorize topic closure with ownership + tenant isolation.
 * Authorization: Topic owner OR institution-scoped admin/coordinateur within
 *                the same tenant. Supradmin bypasses tenant isolation.
 * 10-year perspective: Closed topics prevent post-discussion spam while
 *                      preserving knowledge.
 */
class CloseTopicRequest extends FormRequest
{
    use ChecksForumAuthorization;

    public function authorize(): bool
    {
        $topic = $this->route('topic');
        $topic = $topic instanceof ForumTopic ? $topic : null;

        return $this->isForumActionAuthorized(
            ownerUserId: $topic?->user_id,
            tenantInstitutionId: $topic?->institution_id,
            user: Auth::user(),
            // Fix E2E #211 flow 4 : la route est declaree pour les formateurs
            // (role:enseignant,coordinateur,admin) mais la FormRequest les
            // excluait -> close/pin de facto inaccessibles aux enseignants.
            moderatorRoles: [Role::Admin, Role::SuperAdmin, Role::Coordinateur, Role::Enseignant],
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
