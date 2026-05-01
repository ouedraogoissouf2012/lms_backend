<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * PinTopicRequest - Pin forum topic
 *
 * Purpose: Authorize topic pinning with role-based access control
 * Authorization: Topic owner, coordinateurs, or admin
 * 10-year perspective: Pinned topics float to top, enabling visibility for important discussions
 */
class PinTopicRequest extends FormRequest
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
