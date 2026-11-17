<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReservesLessonContentToTeacher;
use App\Services\Search\TeacherOwnershipScope;
use App\Support\Upload\UploadLimits;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates chapter creation request (POST /api/chapters).
 *
 * ## Purpose
 * Validates and authorizes chapter creation with:
 * - Chapter metadata (title, description, order)
 * - File attachment (PowerPoint, Word, PDF)
 * - Institution ownership (multi-tenant safety)
 *
 * ## Lesson vs Chapter hierarchy
 * Lesson = course container
 * Chapter = sub-unit within lesson with content
 * Example: Lesson "PHP Basics" → Chapters: "Variables", "Functions", "Arrays"
 *
 * ## File Upload Integration
 * Chapters can have file attachments (presentation slides, documents).
 * File validation handled here (not separate UploadFileRequest).
 * Size: Max 30 MB (consistent with UploadFileRequest)
 * Types: pdf, doc, docx, ppt, pptx (content-focused files)
 *
 * Before CRITICAL-05: ChapterController allowed 100MB (inconsistent)
 * Now standardized to 30MB via this request.
 *
 * ## 10-year perspective
 * If chapter requirements change (e.g., max chapters per lesson),
 * update rules() only. All validations automatically updated.
 *
 * Authorization checks:
 * - User must be authenticated
 * - User must be a teacher, author of the lesson (#1167)
 * - Lesson must belong to user's institution
 */
final class StoreChapterRequest extends FormRequest
{
    use ReservesLessonContentToTeacher;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(TeacherOwnershipScope $auteurs): bool
    {
        // L'enseignant auteur de la leçon (#1167) : la création d'un chapitre suit la
        // même règle d'auteur que les autres écritures. La gestion consulte.
        $user = auth()->user();

        return $user instanceof \App\Models\User && $user->isTeacher()
            && \App\Models\Lesson::query()->whereKey($this->route('lessonId'))->where('institution_id', $user->institution_id)->exists();
    }

    /**
     * Get the validation rules for chapter creation.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'titre' => [
                'required',
                'string',
                'min:3',
                'max:255',
            ],
            'description' => [
                'nullable',
                'string',
                'max:1000',
            ],
            // L'ordre part de ZÉRO — c'est la valeur par défaut de la colonne
            // (`chapters.order` → `->default(0)`, migration 2025_10_25_202933) et
            // c'est déjà ce qu'accepte `ReorderChaptersRequest` (`min:0`).
            //
            // L'ancienne règle exigeait > 0 : on pouvait DÉPLACER un chapitre en
            // position 0 mais pas en CRÉER un. Le frontend numérotant à partir de
            // zéro, la création du PREMIER chapitre échouait systématiquement.
            'ordre' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'fichier' => [
                'sometimes', // Optional: chapter may not have file
                'file',
                // Kilo-octets (unité de la règle `max` d'un fichier) — source unique #576.
                UploadLimits::maxRule(),
                'mimes:pdf,doc,docx,ppt,pptx',
            ],
            'type_contenu' => [
                'nullable',
                'string',
                'in:text,video,pdf,powerpoint,word,image,link,quiz',
            ],
        ] + $this->contentBodyRules();
    }

    /**
     * Corps du chapitre selon son type : texte/markdown, lien vidéo, lien
     * externe. Sans ces champs, un chapitre créé via l'API n'avait jamais de
     * contenu — seul l'upload de fichier était géré.
     *
     * Extrait de `rules()`, qui dépassait la garde des 40 lignes. « Le corps
     * du chapitre » est un groupe cohérent : c'est une décomposition par sens,
     * pas une découpe pour tenir dans un compteur.
     *
     * @return array<string, mixed>
     */
    private function contentBodyRules(): array
    {
        return [
            'content' => ['nullable', 'string', 'max:10000'],
            'video_url' => ['nullable', 'url'],
            'external_link' => ['nullable', 'url'],
            'autoplay_video' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Custom error messages for chapter validation.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'titre.required' => 'Le titre du chapitre est requis',
            'titre.min' => 'Le titre doit contenir au moins 3 caractères',
            'titre.max' => 'Le titre ne doit pas dépasser 255 caractères',
            'description.max' => 'La description ne doit pas dépasser 1000 caractères',
            'ordre.required' => 'L\'ordre doit être fourni',
            'ordre.integer' => 'L\'ordre doit être un nombre entier',
            // Même formulation que ReorderChaptersRequest : une seule colonne,
            // un seul message.
            'ordre.min' => 'L\'ordre ne peut pas être négatif',
            'fichier.max' => 'Le fichier ne doit pas dépasser '.UploadLimits::humanReadable(),
            'fichier.mimes' => 'Le fichier doit être: PDF, Word (doc/docx), ou PowerPoint (ppt/pptx)',
            'type_contenu.in' => 'Le type de contenu n\'est pas autorisé',
        ];
    }

    /**
     * Prepare data for validation.
     *
     * Normalize inputs:
     * - Trim title and description
     * - Convert ordre to integer
     */
    protected function prepareForValidation(): void
    {
        // Le frontend ET l'endpoint UPDATE emploient des clés EN (title,
        // content_type, order). On les normalise vers les clés FR validées ici,
        // en gardant la priorité aux clés FR (contrat existant + tests intacts).
        $titre = $this->input('titre', $this->input('title'));
        $description = $this->input('description');
        $typeContenu = $this->input('type_contenu', $this->input('content_type'));
        $ordre = $this->input('ordre', $this->input('order'));

        $this->merge([
            'titre' => trim(is_string($titre) ? $titre : ''),
            'description' => trim(is_string($description) ? $description : ''),
            'type_contenu' => $typeContenu,
            'ordre' => is_numeric($ordre) ? (int) $ordre : null,
        ]);
    }
}
