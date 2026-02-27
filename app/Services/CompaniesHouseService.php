<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Client;

class CompaniesHouseService
{
    private const CACHE_DURATION = 86400; // 24 hours

    /**
     * Get base URL from configuration.
     * 
     * Companies House provides two environments:
     * - Live: https://api.company-information.service.gov.uk
     * - Sandbox: https://api-sandbox.company-information.service.gov.uk
     * 
     * Sandbox API keys must be used with the sandbox URL.
     * Live API keys must be used with the live URL.
     *
     * @return string
     */
    private function getBaseUrl(): string
    {
        return config('services.companies_house.base_url') 
            ?? 'https://api.company-information.service.gov.uk';
    }

    /**
     * Get company details by Company Registration Number (CRN).
     *
     * @param string $crn
     * @return array{data: array|null, status: int, error: string|null}
     */
    public function getCompanyDetails(string $crn): array
    {
        $apiKey = $this->getApiKey();
        
        if (!$apiKey) {
            Log::error('Companies House API key not configured');
            return [
                'data' => null,
                'status' => 500,
                'error' => 'API key not configured',
            ];
        }

        $cacheKey = "companies_house_{$crn}";
        
        return Cache::remember($cacheKey, self::CACHE_DURATION, function () use ($crn, $apiKey) {
            return $this->fetchCompanyDetails($crn, $apiKey);
        });
    }

    /**
     * Fetch company details from Companies House API.
     *
     * @param string $crn
     * @param string $apiKey
     * @return array{data: array|null, status: int, error: string|null}
     */
    private function fetchCompanyDetails(string $crn, string $apiKey): array
    {
        try {
            $baseUrl = $this->getBaseUrl();
            $apiKey = trim($apiKey);
            
            if (empty($apiKey)) {
                Log::error('Companies House API key is empty');
                return [
                    'data' => null,
                    'status' => 500,
                    'error' => 'API key is empty or not configured',
                ];
            }
            
            $authHeader = 'Basic ' . base64_encode($apiKey . ':');
            
            $client = new Client([
                'timeout' => 10,
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => $authHeader,
                ],
            ]);
            
            try {
                $guzzleResponse = $client->get("{$baseUrl}/company/{$crn}");
                $statusCode = $guzzleResponse->getStatusCode();
                $body = $guzzleResponse->getBody()->getContents();
                
                if ($statusCode >= 200 && $statusCode < 300) {
                    return [
                        'data' => json_decode($body, true),
                        'status' => $statusCode,
                        'error' => null,
                    ];
                }
                
                $this->logGuzzleError($guzzleResponse, $crn);
                return [
                    'data' => null,
                    'status' => $statusCode,
                    'error' => $this->getErrorMessage($statusCode),
                ];
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                $statusCode = $e->getResponse()->getStatusCode();
                $body = $e->getResponse()->getBody()->getContents();
                
                $this->logGuzzleError($e->getResponse(), $crn);
                return [
                    'data' => null,
                    'status' => $statusCode,
                    'error' => $this->getErrorMessage($statusCode),
                ];
            } catch (\GuzzleHttp\Exception\RequestException $e) {
                Log::error('Companies House API request failed', [
                    'crn' => $crn,
                    'error' => $e->getMessage(),
                ]);
                return [
                    'data' => null,
                    'status' => 500,
                    'error' => 'Network error: ' . $e->getMessage(),
                ];
            }
        } catch (\Exception $e) {
            Log::error('Companies House API request failed', [
                'crn' => $crn,
                'error' => $e->getMessage(),
            ]);
            return [
                'data' => null,
                'status' => 500,
                'error' => 'Network error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get API key from configuration.
     *
     * @return string|null
     */
    private function getApiKey(): ?string
    {
        $apiKey = config('services.companies_house.api_key') 
            ?? env('COMPANIES_HOUSE_API_KEY');
        
        return $apiKey ? trim($apiKey) : null;
    }

    /**
     * Log Guzzle error response.
     *
     * @param \Psr\Http\Message\ResponseInterface $response
     * @param string $crn
     * @return void
     */
    private function logGuzzleError(\Psr\Http\Message\ResponseInterface $response, string $crn): void
    {
        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();
        $response->getBody()->rewind();
        $decoded = json_decode($body, true);
        $errorMessage = $decoded['error'] ?? $body;

        Log::warning('Companies House API error', [
            'crn' => $crn,
            'status' => $statusCode,
            'error' => $errorMessage,
        ]);
    }

    /**
     * Get error message for HTTP status code.
     *
     * @param int $statusCode
     * @return string
     */
    public function getErrorMessage(int $statusCode): string
    {
        return match ($statusCode) {
            401 => 'Invalid API key. Please check your Companies House API key configuration.',
            404 => 'Company not found. Please verify the Company Registration Number.',
            429 => 'Rate limit exceeded. Please try again later.',
            500, 502, 503 => 'Companies House API is temporarily unavailable. Please try again later.',
            default => 'An error occurred while fetching company details.',
        };
    }
}
