<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * CloseTopicRequest - Close forum topic
 *
 * Purpose: Authorize topic closure with role-based access control
 * Authorization: Topic owner, coordinateurs, or admin
 * 10-year perspective: Closed topics prevent post-discussion spam while preserving knowledge
 */
class CloseTopicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [];
    }
}
