<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Creator\ImpersonateCreatorRequest;
use App\Http\Responses\ErrorResponse;
use App\Http\Responses\SuccessResponse;
use App\Models\Creator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handles admin-only creator impersonation: list creators and obtain a token
 * for another creator so the admin can act as that creator in the client.
 */
class CreatorImpersonationController extends Controller
{
    /**
     * List creators for the impersonation dropdown. Returns minimal fields.
     * Only admins may call this.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Creator $admin */
        $admin = $request->user();

        if (!$admin instanceof Creator || !$admin->is_admin) {
            return ErrorResponse::make('Forbidden. Admin access required.', 403);
        }

        $creators = Creator::query()
            ->orderBy('business_name')
            ->get(['id', 'email', 'business_name', 'name']);

        $list = $creators->map(fn (Creator $c) => [
            'id' => $c->id,
            'email' => $c->email,
            'business_name' => $c->business_name,
            'name' => $c->name,
        ]);

        return SuccessResponse::make('Creators retrieved successfully', [
            'creators' => $list,
        ]);
    }

    /**
     * Issue a token for the target creator so the client can log in as that creator.
     * Only admins may call this. The client should replace the current token with
     * the returned token and redirect so the user is now acting as the target.
     *
     * @param ImpersonateCreatorRequest $request
     * @return JsonResponse
     */
    public function impersonate(ImpersonateCreatorRequest $request): JsonResponse
    {
        /** @var Creator $admin */
        $admin = $request->user();

        if (!$admin instanceof Creator || !$admin->is_admin) {
            return ErrorResponse::make('Forbidden. Admin access required.', 403);
        }

        $target = Creator::find($request->validated('creator_id'));

        if (!$target) {
            return ErrorResponse::make('Creator not found.', 404);
        }

        $token = $target->createToken('creator-auth-token')->plainTextToken;

        Log::info('Creator impersonation', [
            'admin_id' => $admin->id,
            'admin_email' => $admin->email,
            'target_id' => $target->id,
            'target_email' => $target->email,
        ]);

        return SuccessResponse::make(
            'Impersonation successful',
            [
                'creator' => [
                    'id' => $target->id,
                    'name' => $target->name,
                    'business_name' => $target->business_name,
                    'email' => $target->email,
                    'email_verified_at' => $target->email_verified_at,
                    'is_admin' => $target->is_admin,
                ],
                'token' => $token,
            ]
        );
    }
}
