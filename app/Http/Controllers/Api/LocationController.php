<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Data\PredefinedLocations;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class LocationController extends Controller
{
    /**
     * Get all predefined locations grouped hierarchically by continent, country, and city.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $cacheKey = 'predefined_locations_hierarchical';
        
        $locations = Cache::remember($cacheKey, 86400, function () {
            $allLocations = PredefinedLocations::getAll();
            $grouped = [];
            
            foreach ($allLocations as $continent => $countries) {
                $grouped[$continent] = [];
                
                foreach ($countries as $country => $cities) {
                    $grouped[$continent][$country] = [];
                    
                    foreach ($cities as $city) {
                        $identifier = "{$continent}|{$country}|{$city}";
                        $grouped[$continent][$country][$city] = [
                            'identifier' => $identifier,
                            'type' => 'city',
                            'region_name' => $city,
                            'country' => $country,
                            'continent' => $continent,
                        ];
                    }
                }
            }
            
            return $grouped;
        });

        return response()->json($locations);
    }
}

