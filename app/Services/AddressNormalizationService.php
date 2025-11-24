<?php

namespace App\Services;

class AddressNormalizationService
{
    protected array $abbreviationMap = [
        'st.' => 'street', 'rd.' => 'road', 'ave.' => 'avenue', 'av.' => 'avenida',
        'apartment' => 'apt', 'apt' => 'apt', 'flr' => 'floor', 'of.' => 'of', 'of' => 'of',
        'oficina' => 'of', 'suite' => 'suite', 'unit' => 'unit', 'floor' => 'floor', 'piso' => 'floor',
    ];

    public function normalizeForMatch(string $raw): array
    {
        \Log::info('Normalization started', ['input' => $raw]);
        $original = trim($raw);
        $normalized = strtolower($original);
        $normalized = preg_replace('/[^\p{L}\p{N}\s#]/u', ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        foreach ($this->abbreviationMap as $abbr => $full) {
            $normalized = preg_replace('/\\b' . preg_quote($abbr, '/') . '\\b/i', $full, $normalized);
        }

        preg_match('/\\b(unit|apt|apartment|suite|floor|of)\\s*#?\\s*(\\w+)?/i', $normalized, $unitMatch);
        $unit = $unitMatch[2] ?? null;
        preg_match('/\\b(\\d+)\\b/', $normalized, $numberMatch);
        $number = $numberMatch[1] ?? null;
        $street = $number ? trim(str_replace($number, '', $normalized)) : $normalized;

        $parts = [];
        if ($unit) $parts[] = $unit;
        if ($street) $parts[] = $street;
        if ($number) $parts[] = $number;

        return [
            'normalized_key' => implode(' ', $parts),
            'components' => [ 'unit' => $unit, 'street' => $street, 'number' => $number ]
        ];
    }
}
