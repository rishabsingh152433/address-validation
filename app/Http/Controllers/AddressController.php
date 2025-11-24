<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MasterAddress;
use App\Services\AddressNormalizationService;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    public function __construct(private AddressNormalizationService $normalizer) {}

    public function normalize(Request $request)
    {
        $request->validate(['raw_address' => ['required','string','min:3']]);
        $result = $this->normalizer->normalizeForMatch($request->string('raw_address'));
        return response()->json(['success' => true, 'data' => $result]);
    }

    public function show(int $id)
    {
        
        $addr = MasterAddress::with(['gpsEvents' => fn($q) => $q->latest()->limit(10)])
            ->findOrFail($id);
        return response()->json(['success' => true, 'data' => $addr]);
    }
}