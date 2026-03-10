<?php

namespace App\Services;

use Illuminate\Support\Str;

class ChileGeoRegistry
{
    private ?array $registry = null;
    private ?array $regionMatchers = null;
    private ?array $comunaMatchers = null;
    private ?array $regionAliasIndex = null;
    private ?array $comunaAliasIndex = null;

    public function extractFromText(?string $text): array
    {
        $normalizedText = $this->normalizeText((string) $text);
        $matchedRegion = null;
        $matchedComuna = null;

        if ($normalizedText !== '') {
            foreach ($this->regionMatchers() as $region) {
                if ($this->containsAlias($normalizedText, $region['alias'])) {
                    $matchedRegion = $region;
                    break;
                }
            }

            foreach ($this->comunaMatchers() as $comuna) {
                if ($this->containsAlias($normalizedText, $comuna['alias'])) {
                    $matchedComuna = $comuna;
                    break;
                }
            }
        }

        $hasConflict = $matchedRegion && $matchedComuna
            && (string) $matchedRegion['code'] !== (string) $matchedComuna['region_code'];

        return [
            'has_geo' => (bool) ($matchedRegion || $matchedComuna),
            'text' => $text,
            'normalized_text' => $normalizedText,
            'region' => $matchedRegion['name'] ?? $matchedComuna['region_name'] ?? null,
            'region_code' => $matchedRegion['code'] ?? $matchedComuna['region_code'] ?? null,
            'region_explicit' => (bool) $matchedRegion,
            'comuna' => $matchedComuna['name'] ?? null,
            'comuna_code' => $matchedComuna['code'] ?? null,
            'comuna_explicit' => (bool) $matchedComuna,
            'conflict' => $hasConflict,
            'matched_region_alias' => $matchedRegion['alias'] ?? null,
            'matched_comuna_alias' => $matchedComuna['alias'] ?? null,
        ];
    }

    public function extractFromComponents(array $components, ?string $fallbackText = null): array
    {
        $matchedRegion = null;
        $matchedComuna = null;

        foreach ($components as $component) {
            $names = array_values(array_filter([
                $component['long_name'] ?? null,
                $component['short_name'] ?? null,
            ]));

            foreach ($names as $name) {
                if (!$matchedRegion) {
                    $matchedRegion = $this->matchRegionName($name);
                }

                if (!$matchedComuna) {
                    $matchedComuna = $this->matchComunaName($name);
                }

                if ($matchedRegion && $matchedComuna) {
                    break 2;
                }
            }
        }

        $componentGeo = [
            'has_geo' => (bool) ($matchedRegion || $matchedComuna),
            'text' => $fallbackText,
            'normalized_text' => $this->normalizeText((string) $fallbackText),
            'region' => $matchedRegion['name'] ?? $matchedComuna['region_name'] ?? null,
            'region_code' => $matchedRegion['code'] ?? $matchedComuna['region_code'] ?? null,
            'region_explicit' => (bool) $matchedRegion,
            'comuna' => $matchedComuna['name'] ?? null,
            'comuna_code' => $matchedComuna['code'] ?? null,
            'comuna_explicit' => (bool) $matchedComuna,
            'conflict' => $matchedRegion && $matchedComuna
                && (string) $matchedRegion['code'] !== (string) $matchedComuna['region_code'],
            'matched_region_alias' => $matchedRegion['alias'] ?? null,
            'matched_comuna_alias' => $matchedComuna['alias'] ?? null,
        ];

        if (!$componentGeo['has_geo'] && $fallbackText) {
            return $this->extractFromText($fallbackText);
        }

        if ($fallbackText) {
            $textGeo = $this->extractFromText($fallbackText);
            return $this->mergeGeo($componentGeo, $textGeo);
        }

        return $componentGeo;
    }

    public function hasGeo(array $geo): bool
    {
        return !empty($geo['has_geo']) && empty($geo['conflict']);
    }

    public function matches(array $rawGeo, array $candidateGeo): bool
    {
        if (!$this->hasGeo($rawGeo)) {
            return false;
        }

        if (!$this->hasGeo($candidateGeo)) {
            return false;
        }

        if (!empty($rawGeo['comuna_explicit'])) {
            if (empty($candidateGeo['comuna']) || !$this->sameName($rawGeo['comuna'], $candidateGeo['comuna'])) {
                return false;
            }
        }

        if (!empty($rawGeo['region_explicit'])) {
            if (empty($candidateGeo['region']) || !$this->sameName($rawGeo['region'], $candidateGeo['region'])) {
                return false;
            }
        }

        return true;
    }

    public function mismatchReasons(array $rawGeo, array $candidateGeo): array
    {
        $reasons = [];

        if (!$this->hasGeo($rawGeo)) {
            $reasons[] = 'raw_geo_missing';
            return $reasons;
        }

        if (!$this->hasGeo($candidateGeo)) {
            $reasons[] = 'candidate_geo_missing';
            return $reasons;
        }

        if (!empty($rawGeo['comuna_explicit']) && !$this->sameName($rawGeo['comuna'] ?? null, $candidateGeo['comuna'] ?? null)) {
            $reasons[] = 'comuna_mismatch';
        }

        if (!empty($rawGeo['region_explicit']) && !$this->sameName($rawGeo['region'] ?? null, $candidateGeo['region'] ?? null)) {
            $reasons[] = 'region_mismatch';
        }

        return $reasons;
    }

    private function mergeGeo(array $primary, array $fallback): array
    {
        return [
            'has_geo' => (bool) (($primary['has_geo'] ?? false) || ($fallback['has_geo'] ?? false)),
            'text' => $primary['text'] ?? $fallback['text'] ?? null,
            'normalized_text' => $primary['normalized_text'] ?? $fallback['normalized_text'] ?? null,
            'region' => $primary['region'] ?? $fallback['region'] ?? null,
            'region_code' => $primary['region_code'] ?? $fallback['region_code'] ?? null,
            'region_explicit' => (bool) (($primary['region_explicit'] ?? false) || ($fallback['region_explicit'] ?? false)),
            'comuna' => $primary['comuna'] ?? $fallback['comuna'] ?? null,
            'comuna_code' => $primary['comuna_code'] ?? $fallback['comuna_code'] ?? null,
            'comuna_explicit' => (bool) (($primary['comuna_explicit'] ?? false) || ($fallback['comuna_explicit'] ?? false)),
            'conflict' => (bool) (($primary['conflict'] ?? false) || ($fallback['conflict'] ?? false)),
            'matched_region_alias' => $primary['matched_region_alias'] ?? $fallback['matched_region_alias'] ?? null,
            'matched_comuna_alias' => $primary['matched_comuna_alias'] ?? $fallback['matched_comuna_alias'] ?? null,
        ];
    }

    private function matchRegionName(?string $name): ?array
    {
        $normalized = $this->normalizeText((string) $name);
        if ($normalized === '') {
            return null;
        }

        $candidate = $this->regionAliasIndex()[$normalized] ?? null;
        if ($candidate) {
            return $candidate;
        }

        return null;
    }

    private function matchComunaName(?string $name): ?array
    {
        $normalized = $this->normalizeText((string) $name);
        if ($normalized === '') {
            return null;
        }

        $candidate = $this->comunaAliasIndex()[$normalized] ?? null;
        if ($candidate) {
            return $candidate;
        }

        return null;
    }

    private function regionMatchers(): array
    {
        if ($this->regionMatchers !== null) {
            return $this->regionMatchers;
        }

        $matchers = [];
        foreach ($this->loadRegistry()['regions'] as $region) {
            foreach ($this->regionAliases($region['name']) as $alias) {
                $matchers[] = [
                    'code' => $region['code'],
                    'name' => $region['name'],
                    'alias' => $alias,
                ];
            }
        }

        usort($matchers, fn (array $a, array $b) => strlen($b['alias']) <=> strlen($a['alias']));

        return $this->regionMatchers = $matchers;
    }

    private function comunaMatchers(): array
    {
        if ($this->comunaMatchers !== null) {
            return $this->comunaMatchers;
        }

        $matchers = [];
        foreach ($this->loadRegistry()['regions'] as $region) {
            foreach ($region['comunas'] as $comuna) {
                foreach ($this->comunaAliases($comuna['name']) as $alias) {
                    $matchers[] = [
                        'code' => $comuna['code'],
                        'name' => $comuna['name'],
                        'alias' => $alias,
                        'region_code' => $region['code'],
                        'region_name' => $region['name'],
                    ];
                }
            }
        }

        usort($matchers, fn (array $a, array $b) => strlen($b['alias']) <=> strlen($a['alias']));

        return $this->comunaMatchers = $matchers;
    }

    private function regionAliasIndex(): array
    {
        if ($this->regionAliasIndex !== null) {
            return $this->regionAliasIndex;
        }

        $index = [];
        foreach ($this->regionMatchers() as $matcher) {
            $index[$matcher['alias']] = $matcher;
        }

        return $this->regionAliasIndex = $index;
    }

    private function comunaAliasIndex(): array
    {
        if ($this->comunaAliasIndex !== null) {
            return $this->comunaAliasIndex;
        }

        $index = [];
        foreach ($this->comunaMatchers() as $matcher) {
            $index[$matcher['alias']] = $matcher;
        }

        return $this->comunaAliasIndex = $index;
    }

    private function regionAliases(string $regionName): array
    {
        $base = $this->normalizeText($regionName);
        $aliases = [$base];

        if (!Str::startsWith($base, 'region ')) {
            $aliases[] = 'region ' . $base;
            $aliases[] = 'region de ' . $base;
            $aliases[] = 'region del ' . $base;
        }

        if ($base === 'metropolitana de santiago') {
            $aliases[] = 'region metropolitana';
            $aliases[] = 'metropolitana';
        }

        return array_values(array_unique(array_filter($aliases)));
    }

    private function comunaAliases(string $comunaName): array
    {
        return array_values(array_unique(array_filter([
            $this->normalizeText($comunaName),
        ])));
    }

    private function containsAlias(string $normalizedText, string $alias): bool
    {
        return str_contains(' ' . $normalizedText . ' ', ' ' . $alias . ' ');
    }

    private function sameName(?string $left, ?string $right): bool
    {
        return $this->normalizeText((string) $left) !== ''
            && $this->normalizeText((string) $left) === $this->normalizeText((string) $right);
    }

    private function normalizeText(string $value): string
    {
        $value = Str::ascii(Str::lower($value));
        $value = preg_replace('/[^a-z0-9\s]/', ' ', $value) ?? '';
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';

        return $value;
    }

    private function loadRegistry(): array
    {
        if ($this->registry !== null) {
            return $this->registry;
        }

        $path = (string) config('address_validation.geo_registry_path', resource_path('data/chile_regions_comunas.json'));

        if (!is_file($path)) {
            return $this->registry = ['regions' => []];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return $this->registry = is_array($decoded) ? $decoded : ['regions' => []];
    }
}
