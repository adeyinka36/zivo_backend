<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

class SuccessResponse
{
    public static function make(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        $response = ['message' => $message];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        return response()->json($response, $status);
    }
}
