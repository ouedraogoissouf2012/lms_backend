<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksForumAuthorization;
use App\Http\Requests\Concerns\ReservesLessonContentToTeacher;
use App\Models\ForumPost;
use App\Models\ForumTopic;
use App\Models\User;
use App\Services\Search\TeacherOwnershipScope;
use App\Support\Upload\UploadLimits;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates file upload requests.
 *
 * ## Purpose
 * Centralizes file validation to prevent:
 * - File upload DOS (30MB max)
 * - Malicious file types (only allow: pdf, doc, docx, ppt, pptx, xls, xlsx, jpg, png, gif)
 * - Invalid file extensions (whitelist only)
 *
 * ## File Size
 * Maximum 30 MB = 30 720 Ko. La règle `max` de Laravel s'exprime en kilo-octets
 * pour un fichier ; la valeur est centralisée dans {@see UploadLimits} (#576).
 * Rationale: Balances user needs (presentations, documents) with server resource protection.
 *
 * Before CRITICAL-05: Inconsistency found
 * - FileController: 50MB max (implicit in `.env`)
 * - ChapterController: 100MB max (implicit)
 * This request standardizes to 30MB across all endpoints.
 *
 * ## Allowed MIME Types
 * Documents:
 *   - PDF (application/pdf)
 *   - Word (.doc, .docx - application/msword, application/vnd.openxmlformats-officedocument.wordprocessingml.document)
 *   - Excel (.xls, .xlsx - application/vnd.ms-excel, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet)
 *   - PowerPoint (.ppt, .pptx - application/vnd.ms-powerpoint, application/vnd.openxmlformats-officedocument.presentationml.presentation)
 *
 * Images:
 *   - JPG (image/jpeg)
 *   - PNG (image/png)
 *   - GIF (image/gif)
 *
 * ## 10-year perspective
 * If allowed file types change (e.g., add webp), update:
 * - mimes:... rule
 * - $allowedExtensions array
 * - mimetypeMap array
 * All file uploads automatically use new whitelist.
 *
 * Changes in one place = security policy updated everywhere.
 *
 * ## Authorization
 * Sans cible, le fichier est personnel : tout compte authentifié l'envoie.
 * Rattacher le fichier à une cible (`fileable_type` + `fileable_id`) exige le
 * droit d'ÉCRIRE sur cette cible, lu à la règle de son domaine :
 * - `lesson` : l'enseignant auteur, et lui seul ({@see ReservesLessonContentToTeacher}, #1167) ;
 * - `forum_topic`, `forum_post` : la règle des gardes de modification du forum
 *   ({@see ChecksForumAuthorization}), l'auteur ou un admin de l'école.
 * La cible est cherchée dans l'école du compte : absente ou ailleurs, elle reçoit
 * le refus d'une cible que le compte n'écrit pas.
 */
class UploadFileRequest extends FormRequest
{
    use ChecksForumAuthorization;
    use ReservesLessonContentToTeacher;

    /**
     * Une paire incomplète, un id invalide, ou un type hors de `config/fileables.php`,
     * passe à la validation (422). Un type de la carte sans règle ici est refusé : fermé par
     * défaut.
     */
    public function authorize(): bool
    {
        $type = $this->input('fileable_type');
        $id = filter_var($this->input('fileable_id'), FILTER_VALIDATE_INT);

        $carte = (array) config('fileables.morph_map', []);

        if (! is_string($type) || $id === false || $id < 1 || ! array_key_exists($type, $carte)) {
            return true;
        }

        return match ($type) {
            // La signature est partagée avec `UploadChapterFileRequest`, qui en hérite : le
            // port ne peut pas y être ajouté en paramètre. Il est résolu par le conteneur de
            // la requête, celui qui appelle `authorize()`.
            'lesson' => $this->enseignantAuteurDeLaLecon($id, $this->container->make(TeacherOwnershipScope::class)),
            'forum_topic' => $this->ecritSurLeForum(ForumTopic::class, $id),
            'forum_post' => $this->ecritSurLeForum(ForumPost::class, $id),
            default => false,
        };
    }

    /** Sur une leçon, un compte non enseignant reçoit le motif de #1167 ; ailleurs, le refus générique. */
    protected function failedAuthorization(): void
    {
        if ($this->input('fileable_type') === 'lesson') {
            $this->refuserUnCompteNonEnseignant();
        }

        parent::failedAuthorization();
    }

    /**
     * Le compte écrit-il sur ce sujet ou ce message de son école ? Le filtre
     * d'établissement est explicite : le scope global est ouvert sans tenant.
     *
     * @param  class-string<ForumTopic|ForumPost>  $modele
     */
    private function ecritSurLeForum(string $modele, int $id): bool
    {
        $user = $this->user();

        if (! $user instanceof User || ! is_int($user->institution_id)) {
            return false;
        }

        $cible = $modele::query()->whereKey($id)->where('institution_id', $user->institution_id)->first();

        return $cible !== null && $this->isForumActionAuthorized(
            ownerUserId: $cible->user_id,
            tenantInstitutionId: $cible->institution_id,
            user: $user,
        );
    }

    /**
     * Get the validation rules for file upload.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                // Kilo-octets (unité de la règle `max` d'un fichier) — source unique #576.
                UploadLimits::maxRule(),
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif',
            ],
            'category' => [
                'sometimes',
                'string',
                'in:course_material,assignment,resource,other,forum_attachment,profile_picture,general',
            ],
            // fileable_type whitelist comes from config/fileables.php (single
            // source of truth). Adding a fileable type → update the config,
            // both upload and listing validations adapt automatically.
            // Issue #10 (IDOR) — never accept an arbitrary class name here.
            // La paire va ensemble : une cible partielle n'est pas vérifiable.
            'fileable_type' => [
                'required_with:fileable_id',
                'string',
                'in:' . implode(',', array_keys((array) config('fileables.morph_map', []))),
            ],
            'fileable_id' => [
                'required_with:fileable_type',
                'integer',
                'min:1',
            ],
            'description' => [
                'sometimes',
                'string',
                'max:500',
            ],
            'is_public' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    /**
     * Custom error messages for file validation.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Un fichier est requis',
            'file.file' => 'Le champ doit être un fichier valide',
            'file.max' => 'Le fichier ne doit pas dépasser ' . UploadLimits::humanReadable(),
            'file.mimes' => 'Le type de fichier n\'est pas autorisé. Types acceptés: PDF, Word, Excel, PowerPoint, JPG, PNG, GIF',
            'category.in' => 'La catégorie sélectionnée est invalide',
            'fileable_type.in' => 'Le type de ressource est invalide',
            'fileable_id.min' => 'L\'identifiant de ressource doit être positif',
            'description.max' => 'La description ne doit pas dépasser 500 caractères',
            'is_public.boolean' => 'Le champ visibilité doit être un booléen',
        ];
    }


    /**
     * Get the file size limit in human-readable format.
     *
     * Used by controller for error messages.
     *
     * @return string
     */
    public static function getMaxFileSize(): string
    {
        return UploadLimits::humanReadable();
    }

    /**
     * Get allowed file extensions.
     *
     * Whitelist validation for extra security.
     * Even if MIME type is spoofed, extension must match.
     *
     * @return array<int, string>
     */
    public static function getAllowedExtensions(): array
    {
        return [
            // Documents
            'pdf',
            'doc',
            'docx',
            'xls',
            'xlsx',
            'ppt',
            'pptx',
            // Images
            'jpg',
            'jpeg',
            'png',
            'gif',
        ];
    }
}
