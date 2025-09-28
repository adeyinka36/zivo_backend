<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

class ErrorResponse
{
    public static function make(string $message, int $status = 400, array $errors = []): JsonResponse
    {
        $response = ['message' => $message];
        
        if (!empty($errors)) {
            $response['errors'] = $errors;
        }
        
        return response()->json($response, $status);
    }
}
