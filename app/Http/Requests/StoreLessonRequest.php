<?php

namespace App\Http\Requests;

use App\Enums\LessonStatus;
use App\Http\Requests\Concerns\ExigeUneClasseDeLaMatiere;
use App\Http\Requests\Concerns\ReservesLessonContentToTeacher;
use App\Models\Classe;
use App\Models\Matiere;
use App\Models\User;
use App\Rules\PositiveInteger;
use App\Services\Matiere\ClassesDeLaMatiere;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates lesson creation request.
 *
 * ## Purpose
 * Validates and authorizes POST /api/lessons requests. Ensures:
 * - User is authenticated + is a teacher (#1167 : seul l'enseignant crée une leçon)
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
 * 1. Authorization: only teachers can create (#1167)
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
    use ExigeUneClasseDeLaMatiere;
    use ReservesLessonContentToTeacher;

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

        // Check 2: l'enseignant seul (#1167, décisions du user du 17/11). D502 (« l'admin
        // d'école a les droits du coordinateur ») reste vrai, mais ne donne pas
        // l'écriture du contenu : la gestion consulte. Son refus porte un motif
        // (ReservesLessonContentToTeacher).
        if (! $user->isTeacher()) {
            return false;
        }

        // Check 3: Multi-tenant safety — la classe appartient à l'institution du
        // user, désignée par son id LOCAL et seulement par lui (#873). Le numéro
        // KLASSCI n'est plus accepté : les deux numérotations se recouvrent, et
        // « le local d'abord » rangeait la leçon dans une AUTRE classe. La page de
        // la matière propose des ids locaux depuis #749, comme la modification.
        $classeId = $this->input('classe_id');

        return is_numeric($classeId)
            && Classe::query()->where('institution_id', $user->institution_id)->whereKey((int) $classeId)->exists();
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
            // #873 — l'id LOCAL de la matière, comme pour `classe_id` et comme à la
            // modification (UpdateLessonRequest) : la page de la matière le donne
            // (`matiere_id_local`, #749). `lessons.matiere_id` le stocke tel quel.
            'matiere_id' => [
                'nullable',
                new PositiveInteger,
                Rule::exists('matieres', 'id')->where('institution_id', $user?->institution_id),
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
     * La classe est une classe de la matière (#1103), quand la matière est
     * donnée. Le port est reçu ici, à l'appel, et non au constructeur : la
     * source dépend de l'école servie (ADR-EI-01).
     *
     * @return list<callable(Validator): void>
     */
    public function after(ClassesDeLaMatiere $classes): array
    {
        return [function (Validator $validator) use ($classes): void {
            $user = auth()->user();
            if ($validator->errors()->isNotEmpty() || ! $user instanceof User) {
                return;
            }

            // Sans matière, la règle reste celle de l'établissement : on ne sait
            // pas de quelle matière la classe devrait être. Les deux ids sont
            // LOCAUX (#873), déjà vérifiés par `authorize()` et `rules()`.
            $matiereId = $this->input('matiere_id');
            $matiere = is_numeric($matiereId)
                ? Matiere::query()->where('institution_id', $user->institution_id)->whereKey((int) $matiereId)->first()
                : null;
            if (! $matiere instanceof Matiere) {
                return;
            }

            $classeId = $this->input('classe_id');
            $this->exigerUneClasseDeLaMatiere($validator, $classes, is_numeric($classeId) ? (int) $classeId : null, $matiere, $user);
        }];
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
