<?php

namespace App\Repositories;

use App\Models\MasterAddress;
use App\Repositories\Contracts\MasterAddressInterface;

class MasterAddressRepository implements MasterAddressInterface
{
    public function findById(int $id): ?MasterAddress
    {
        return MasterAddress::find($id);
    }

    public function findByNormalizedKey(string $key): ?MasterAddress
    {
        return MasterAddress::where('normalized_key', $key)->first();
    }

    public function create(array $data): MasterAddress
    {
        return MasterAddress::create($data);
    }

    public function update(MasterAddress $address, array $data): bool
    {
        return $address->update($data);
    }

    public function all(): iterable
    {
        return MasterAddress::all();
    }
}
