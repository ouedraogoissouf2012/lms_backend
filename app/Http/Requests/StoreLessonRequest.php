<?php

namespace App\Http\Requests;

use App\Enums\LessonStatus;
use App\Models\Classe;
use App\Models\Matiere;
use App\Rules\MirroredIdentifierExists;
use App\Rules\PositiveInteger;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates lesson creation request.
 *
 * ## Purpose
 * Validates and authorizes POST /api/lessons requests. Ensures:
 * - User is authenticated + is teacher/coordinator
 * - Institution ownership (multi-tenant safety)
 * - Title/description are valid and sanitized
 * - Lesson type is allowed
 *
 * ## Why FormRequest (10-year perspective)
 * - Single source of truth: if validation rules change, update only here
 * - Testable independently: validation logic separated from controller
 * - Reusable: same rules can be used by update endpoint
 * - Secure: authorize() prevents unauthorized access before controller
 * - Maintainable: new devs see contract (input -> output) clearly
 *
 * ## Security Checks Applied
 * 1. Authorization: only teachers/coordinators can create
 * 2. Institution: classe must belong to user's institution
 * 3. Input validation: all fields type-checked (length, type, enum)
 * 4. DOS prevention: title/description/content max length enforced
 *
 * ## XSS model (#212)
 * Défense principale (commune à toute l'API) : les réponses sont du JSON
 * (`Content-Type: application/json`) que le navigateur n'interprète pas
 * comme HTML, le frontend Vue échappe à l'affichage, et les vues Blade
 * (rapports/PDF) échappent via `{{ }}`.
 *
 * Spécificité lesson : `description` et `content` passent un `strip_tags`
 * (sanitization HTML héritée, propre à ce FormRequest). Le reste du projet
 * (forum topics/posts, chapters) stocke VERBATIM — incohérence historique
 * tracée, à uniformiser dans une décision produit dédiée. `title` reste
 * verbatim. Couvert par tests/Feature/Security/XssTest.php.
 */
final class StoreLessonRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = auth()->user();

        // Check 1: User must be authenticated (middleware ensures, but explicit)
        if (! $user) {
            return false;
        }

        // Check 2: User must be teacher or coordinator (not student)
        if (! $user->isTeacher() && ! $user->isCoordinator()) {
            return false;
        }

        // Check 3: Multi-tenant safety — la classe doit appartenir à l'institution
        // du user. #265 : le frontend envoie un id KLASSCI de classe (comme pour
        // les évaluations), pas l'id local ; les deux espaces sont acceptés.
        //
        // La décision appartient au modèle lui-même (contrat MirroredFromKlassci),
        // ici comme dans `rules()` et comme dans le service qui TRADUIT ensuite vers
        // l'id local (#740) : c'est la même question, elle n'a qu'une seule réponse.
        return Classe::existsFor($this->input('classe_id'), $user->institution_id);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = auth()->user();

        return [
            'title' => [
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
            'content' => [
                'nullable',
                'string',
                'max:10000',
            ],
            'type' => [
                'required',
                'string',
                'in:cours,tp,td,projet,autre',
            ],
            'classe_id' => [
                'required',
                new PositiveInteger,
            ],
            // #740 — SYMÉTRIE avec `classe_id` : le frontend envoie un id
            // KLASSCI de matière, pas l'id local. La règle n'acceptait que
            // l'espace LOCAL : toute leçon portant une matière échouait en 422
            // « La matière n'existe pas », sur une matière pourtant présente en
            // base — MatiereSyncService l'y écrit à chaque connexion (#258).
            // La cause n'était donc pas une table vide, mais une divergence
            // d'espace d'identifiants.
            //
            // La traduction vers l'id LOCAL reste faite par
            // LessonCrudOperationsService, car `lessons.matiere_id` est un
            // identifiant local et ne doit jamais recevoir un id KLASSCI (#707).
            'matiere_id' => [
                'nullable',
                new PositiveInteger,
                new MirroredIdentifierExists(Matiere::class, $user?->institution_id, 'La matière n\'existe pas'),
            ],
            'niveau_difficulte' => [
                'nullable',
                'string',
                'in:debutant,intermediaire,avance,expert',
            ],
            'duree_estimee_minutes' => [
                'nullable',
                'integer',
                'min:1',
                'max:480', // 8 hours max
            ],
            'status' => [
                'nullable',
                'string',
                'in:'.implode(',', LessonStatus::values()),
            ],
        ];
    }

    /**
     * Get custom messages for validation errors.
     *
     * Human-friendly messages (not default Laravel messages).
     * Helps users understand what went wrong.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Le titre du cours est requis',
            'title.min' => 'Le titre doit contenir au moins 3 caractères',
            'title.max' => 'Le titre ne doit pas dépasser 255 caractères',
            'type.required' => 'Le type de cours est requis',
            'type.in' => 'Le type de cours doit être: cours, tp, td, projet ou autre',
            'classe_id.required' => 'Une classe doit être sélectionnée',
            'matiere_id.exists' => 'La matière n\'existe pas',
            'duree_estimee_minutes.max' => 'La durée estimée ne doit pas dépasser 480 minutes',
        ];
    }

    /**
     * Prepare data for validation.
     *
     * Called BEFORE validation rules are checked.
     * Used to trim whitespace and normalize inputs.
     *
     * Why trim here:
     * - Prevents " " (only spaces) being valid input
     * - Removes accidental leading/trailing whitespace
     * - Consistent data format in database
     */
    protected function prepareForValidation(): void
    {
        $title = $this->input('title');
        $description = $this->input('description');
        $content = $this->input('content');

        $this->merge([
            'title' => is_string($title) ? trim($title) : ($title ?? ''),
            'description' => is_string($description) ? strip_tags(trim($description)) : ($description ?? ''),
            // Fix #212 : `$this->content` = corps HTTP brut, pas l'input nomme.
            'content' => is_string($content) ? strip_tags(trim($content)) : ($content ?? ''),
        ]);
    }
}
