<?php

namespace App\Repositories\Contracts;

use App\Models\NormalizedAddress;

interface NormalizedAddressInterface
{
    public function findById(int $id): ?NormalizedAddress;

    public function findByNormalizedKey(string $key): ?NormalizedAddress;

    public function create(array $data): NormalizedAddress;

    public function update(NormalizedAddress $address, array $data): bool;

    public function all(): iterable;
}
