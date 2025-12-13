<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class LocationController extends Controller
{
    /**
     * Get all locations from the database grouped hierarchically by continent, country, and city.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $cacheKey = 'locations_hierarchical';
        
        $locations = Cache::remember($cacheKey, 604800, function () {
            $dbLocations = Location::select('region_name', 'country', 'country_code', 'continent')
                ->orderBy('continent')
                ->orderBy('country')
                ->orderBy('region_name')
                ->get();

            $grouped = [];
            
            foreach ($dbLocations as $location) {
                $continent = $location->continent ?? 'Unknown';
                $country = $location->country ?? 'Unknown';
                $city = $location->region_name ?? 'Unknown';
                
                if (!isset($grouped[$continent])) {
                    $grouped[$continent] = [];
                }
                
                if (!isset($grouped[$continent][$country])) {
                    $grouped[$continent][$country] = [];
                }
                
                if (!isset($grouped[$continent][$country][$city])) {
                    $identifier = "{$continent}|{$country}|{$city}";
                    $grouped[$continent][$country][$city] = [
                        'identifier' => $identifier,
                        'type' => 'city',
                        'region_name' => $city,
                        'country' => $country,
                        'country_code' => $location->country_code,
                        'continent' => $continent,
                    ];
                }
            }
            
            return $grouped;
        });

        return response()->json($locations);
    }
}

