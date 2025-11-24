<?php

namespace App\Repositories\Contracts;

use App\Models\MasterAddress;

interface MasterAddressInterface
{
    public function findById(int $id): ?MasterAddress;

    public function findByNormalizedKey(string $key): ?MasterAddress;

    public function create(array $data): MasterAddress;

    public function update(MasterAddress $address, array $data): bool;

    public function all(): iterable;
}
