<?php

namespace App\Repositories;

use App\Models\NormalizedAddress;
use App\Repositories\Contracts\NormalizedAddressInterface;

class NormalizedAddressRepository implements NormalizedAddressInterface
{
    public function findById(int $id): ?NormalizedAddress
    {
        return NormalizedAddress::find($id);
    }

    public function findByNormalizedKey(string $key): ?NormalizedAddress
    {
        return NormalizedAddress::where('normalized_key', $key)->first();
    }

    public function create(array $data): NormalizedAddress
    {
        return NormalizedAddress::create($data);
    }

    public function update(NormalizedAddress $address, array $data): bool
    {
        return $address->update($data);
    }

    public function all(): iterable
    {
        return NormalizedAddress::all();
    }
}
