<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pincode;
use App\Models\University;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public reference-data lookups backing the address/university autofill
 * on application (college + student portal) and registration forms:
 *   - PIN code -> district/state auto-fill        (Pincode master)
 *   - University name autocomplete                (University master)
 *
 * These are read-only master data endpoints and are intentionally
 * unauthenticated (see routes/api.php) — the same forms they serve
 * (e.g. the public student registration draft) are themselves public,
 * mirroring the existing bank/search + bank/ifsc/{code} routes.
 */
class LookupController extends Controller
{
    /**
     * GET /api/pincode/{code}
     * Resolve a 6-digit PIN code to district/state for address auto-fill.
     */
    public function pincode(string $code): JsonResponse
    {
        $code = trim($code);

        if (!preg_match('/^\d{4,10}$/', $code)) {
            return response()->json(['message' => 'Invalid PIN code.'], 422);
        }

        $result = Pincode::resolve($code);

        if (!$result) {
            return response()->json(['message' => 'PIN code not found.'], 404);
        }

        return response()->json($result);
    }

    /**
     * GET /api/pincode/search?q=...
     * Free-text search across PIN code / post office / district (min 2 chars).
     * Used when the user wants to look up a PIN by locality name instead of
     * typing the 6 digits directly.
     */
    public function pincodeSearch(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $like = '%' . $q . '%';

        $rows = DB::table('pincodes')
            ->where(function ($query) use ($like, $q) {
                $query->where('pincode', 'like', $q . '%')
                    ->orWhereRaw('post_office_name ILIKE ?', [$like])
                    ->orWhereRaw('district ILIKE ?', [$like]);
            })
            ->select('pincode', 'post_office_name', 'district', 'state_name')
            ->orderBy('pincode')
            ->limit(20)
            ->get();

        return response()->json($rows);
    }

    /**
     * GET /api/pincode/states
     * Distinct states derived from the PIN code master — used to populate
     * the State dropdown from real data instead of a hardcoded list.
     */
    public function states(): JsonResponse
    {
        return response()->json(Pincode::states());
    }

    /**
     * GET /api/pincode/districts?state=...
     * Distinct districts for a state — dependent District dropdown.
     */
    public function districts(Request $request): JsonResponse
    {
        $state = trim((string) $request->query('state', ''));

        if ($state === '') {
            return response()->json([]);
        }

        return response()->json(Pincode::districtsForState($state));
    }

    /**
     * GET /api/university/search?q=...&state=...
     * Autocomplete for "Name of University" fields (min 1 char, since the
     * source list only has ~330 entries — no need to gate behind 2+ chars).
     */
    public function universitySearch(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $state = $request->query('state') ? trim((string) $request->query('state')) : null;
        // Default 25 for typeahead search; frontend passes a higher limit
        // (up to 500) to pull the full ~330-row list once for a <datalist>.
        $limit = min((int) $request->query('limit', 25), 500);

        $universities = University::search($q, $state, $limit);

        return response()->json($universities->map(fn ($u) => [
            'id'    => $u->id,
            'name'  => $u->name,
            'state' => $u->state,
        ]));
    }

    /**
     * GET /api/university/states
     * Distinct states that have at least one university — optional filter
     * for the university autocomplete.
     */
    public function universityStates(): JsonResponse
    {
        return response()->json(University::states());
    }
}
