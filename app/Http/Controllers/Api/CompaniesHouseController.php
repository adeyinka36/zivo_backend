<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CompaniesHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompaniesHouseController extends Controller
{
    public function __construct(
        private readonly CompaniesHouseService $companiesHouseService
    ) {}

    /**
     * Get company details by Company Registration Number (CRN).
     *
     * @param Request $request
     * @param string $crn
     * @return JsonResponse
     */
    public function getCompanyDetails(Request $request, string $crn): JsonResponse
    {
        $validatedCrn = $this->validateAndNormalizeCrn($crn);

        if (!$validatedCrn) {
            return response()->json([
                'error' => 'Invalid Company Registration Number',
                'message' => 'The CRN must be a valid UK company number (8 digits, may include leading zeros).',
            ], 422);
        }

        $result = $this->companiesHouseService->getCompanyDetails($validatedCrn);

        if ($result['data'] === null) {
            $statusCode = $result['status'] === 404 ? 404 : ($result['status'] >= 500 ? 503 : 400);
            
            return response()->json([
                'error' => $this->getErrorTitle($result['status']),
                'message' => $result['error'] ?? 'Unable to retrieve company details.',
                'status_code' => $result['status'],
            ], $statusCode);
        }

        return response()->json([
            'success' => true,
            'data' => $result['data'],
        ]);
    }

    /**
     * Validate and normalize CRN format.
     *
     * @param string $crn
     * @return string|null
     */
    private function validateAndNormalizeCrn(string $crn): ?string
    {
        $normalized = preg_replace('/\s+/', '', strtoupper(trim($crn)));

        if (preg_match('/^[0-9]{8}$/', $normalized)) {
            return str_pad($normalized, 8, '0', STR_PAD_LEFT);
        }

        return null;
    }

    /**
     * Get error title based on HTTP status code.
     *
     * @param int $statusCode
     * @return string
     */
    private function getErrorTitle(int $statusCode): string
    {
        return match ($statusCode) {
            401 => 'Authentication failed',
            404 => 'Company not found',
            429 => 'Rate limit exceeded',
            500, 502, 503 => 'Service unavailable',
            default => 'Error retrieving company details',
        };
    }
}
