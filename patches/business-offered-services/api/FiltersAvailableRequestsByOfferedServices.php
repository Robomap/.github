<?php

namespace App\Http\Controllers;

use App\Services\OnDemandCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reference snippet for filtering GET /on-demand-services/business/available.
 * Merge into the existing On-demand business controller method.
 */
trait FiltersAvailableRequestsByOfferedServices
{
    public function available(Request $request): JsonResponse
    {
        $business = $request->user()->business; // adjust relation/name as in your API
        $offered = $business->offered_services ?? [];

        if (count($offered) === 0) {
            return response()->json(['requests' => []]);
        }

        // Replace with your existing pending/open requests query.
        $pending = $this->pendingUnclaimedRequestsQuery()->get();

        $matched = $pending
            ->filter(function ($row) use ($offered) {
                return OnDemandCatalog::businessOffers(
                    $offered,
                    (string) $row->service_category,
                    (string) $row->service_type,
                );
            })
            ->values();

        return response()->json(['requests' => $matched]);
    }
}
