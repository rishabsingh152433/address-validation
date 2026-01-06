<?php


namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAddressRequest;
use App\Services\AddressValidationService;
use Illuminate\Http\JsonResponse;

class ValidationController extends Controller
{
    public function __construct(private AddressValidationService $validationService) {}

    public function validateAddress(StoreAddressRequest $request): JsonResponse
    {
        \Log::info('validateAddress reached', ['payload' => $request->all()]);
        $data = $request->validated();

        $result = $this->validationService->validate(
            $data['raw_address'],
            $data['tenant_id'] ?? null,
            $data['place_id'] ?? null // ✅ NEW (optional)
        );

        return response()->json([
            'success' => empty($result['error']),
            'data'    => $result,
        ]);
    }
 }



