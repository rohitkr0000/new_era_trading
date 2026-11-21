<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Snapshot;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;

/** Endpoints for visitors (home page, register page). No personal data. */
class PublicController extends Controller
{
    public function summary(Snapshot $snapshot): JsonResponse
    {
        return response()->json($snapshot->public());
    }

    /** The custom deposit QR image an admin uploaded (public on purpose: members scan it to pay). 404 = the site draws the QR itself. */
    public function depositQr()
    {
        $v = (string) Settings::get('depositQr');
        [$ext] = explode(':', $v . ':');
        $file = storage_path('app/private/branding/deposit-qr.' . $ext);
        if (! in_array($ext, ['png', 'jpg', 'webp'], true) || ! is_file($file)) return response()->json(['error' => 'No custom QR.'], 404);
        $type = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'][$ext];
        return response()->file($file, ['Content-Type' => $type, 'Cache-Control' => 'public, max-age=86400', 'Cross-Origin-Resource-Policy' => 'cross-origin']);
    }

    /** Sponsor check on the register page: returns only the sponsor's name. */
    public function sponsor(string $code): JsonResponse
    {
        $u = User::where('code', strtoupper(trim($code)))->where('role', 'member')->first();
        return $u ? response()->json(['name' => $u->name, 'code' => $u->code]) : response()->json(['error' => 'Sponsor code not found.'], 404);
    }
}
