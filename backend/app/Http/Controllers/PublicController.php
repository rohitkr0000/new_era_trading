<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Snapshot;
use Illuminate\Http\JsonResponse;

/** Endpoints for visitors (home page, register page). No personal data. */
class PublicController extends Controller
{
    public function summary(Snapshot $snapshot): JsonResponse
    {
        return response()->json($snapshot->public());
    }

    /** Sponsor check on the register page: returns only the sponsor's name. */
    public function sponsor(string $code): JsonResponse
    {
        $u = User::where('code', strtoupper(trim($code)))->where('role', 'member')->first();
        return $u ? response()->json(['name' => $u->name, 'code' => $u->code]) : response()->json(['error' => 'Sponsor code not found.'], 404);
    }
}
