<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function store(User $user, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'push_token' => 'required|string',
        ]);

        $user->push_token = $validated['push_token'];
        $user->save();

        return response()->json([], 201);
    }
}
