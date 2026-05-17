<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/**
 * Base class for controllers behind the `auth:sanctum` middleware.
 *
 * Exposes {@see authenticatedUser()} which returns a strictly typed
 * `App\Models\User` instance, eliminating the `User|null` PHPStan
 * findings that pollute the baseline whenever a controller calls
 * `Auth::user()` directly and then invokes a user method or property.
 *
 * The middleware guarantees an authenticated user has been resolved on
 * the request before the controller runs ; the role of this helper is
 * to convert that runtime guarantee into a **typed** API for the
 * static analyser. If for any reason the guarantee is violated (a
 * route without the middleware, a misconfigured guard, …) the helper
 * raises `AuthenticationException` rather than silently returning
 * null — Laravel's exception handler renders this as `401`, the same
 * status the middleware would have produced.
 *
 * Usage in a concrete controller :
 *
 *     final class MyController extends AuthenticatedController
 *     {
 *         public function index(Request $request): JsonResponse
 *         {
 *             $user = $this->authenticatedUser($request);   // typed User
 *             return response()->json($user->createToken('x'));
 *         }
 *     }
 *
 * @see PRODUCTION_STANDARDS.md §1.6 D — Dependency Inversion
 * @see docs/PHPSTAN_BASELINE_REDUCTION_PLAN.md
 */
abstract class AuthenticatedController extends Controller
{
    /**
     * Return the authenticated user as a strictly typed `User`.
     *
     * @throws AuthenticationException If the request carries no User
     *                                  (route reached without auth middleware,
     *                                  or guard misconfigured).
     */
    protected function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException(
                'Authenticated User required but missing from request.',
            );
        }

        return $user;
    }
}
