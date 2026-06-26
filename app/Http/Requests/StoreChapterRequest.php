<?php

namespace App\Http\Requests;

use App\Rules\PositiveInteger;
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
 * - User must be teacher/coordinator
 * - Lesson must belong to user's institution
 */
final class StoreChapterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $user = auth()->user();

        // Check 1: User must be authenticated
        if (!$user) {
            return false;
        }

        // Check 2: User must be teacher or coordinator (not student)
        if (!$user->isTeacher() && !$user->isCoordinator()) {
            return false;
        }

        // Check 3: Multi-tenant safety - lesson must belong to user's institution
        // Lesson ID comes from route: /api/lessons/{lessonId}/chapters
        if (!$this->route('lessonId')) {
            return false;
        }

        $lesson = \App\Models\Lesson::where('id', $this->route('lessonId'))
            ->where('institution_id', $user->institution_id)
            ->exists();

        if (!$lesson) {
            return false;
        }

        return true;
    }

    /**
     * Get the validation rules for chapter creation.
     *
     * @return array
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
            'ordre' => [
                'nullable',
                new PositiveInteger(),
            ],
            'fichier' => [
                'sometimes', // Optional: chapter may not have file
                'file',
                'max:31457280', // 30 MB max (standardized from 100MB)
                'mimes:pdf,doc,docx,ppt,pptx',
            ],
            'type_contenu' => [
                'nullable',
                'string',
                'in:text,video,pdf,powerpoint,word,image,link,quiz',
            ],
            // Corps du chapitre selon son type (texte/markdown, lien vidéo,
            // lien externe). Sans ces champs, un chapitre créé via l'API n'avait
            // jamais de contenu (seul l'upload de fichier était géré).
            'content' => [
                'nullable',
                'string',
                'max:10000',
            ],
            'video_url' => [
                'nullable',
                'url',
            ],
            'external_link' => [
                'nullable',
                'url',
            ],
            'autoplay_video' => [
                'nullable',
                'boolean',
            ],
        ];
    }

    /**
     * Custom error messages for chapter validation.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'titre.required' => 'Le titre du chapitre est requis',
            'titre.min' => 'Le titre doit contenir au moins 3 caractères',
            'titre.max' => 'Le titre ne doit pas dépasser 255 caractères',
            'description.max' => 'La description ne doit pas dépasser 1000 caractères',
            'ordre.required' => 'L\'ordre doit être fourni',
            'fichier.max' => 'Le fichier ne doit pas dépasser 30 MB',
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
     *
     * @return void
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
