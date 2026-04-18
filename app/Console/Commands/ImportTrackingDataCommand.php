<?php

namespace App\Console\Commands;

use App\Models\MasterAddress;
use App\Models\NormalizedAddress;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ImportTrackingDataCommand extends Command
{
    protected $signature = 'tracking:import
                            {folder=storage/app/imports/tracking-data : Relative folder path from project root}
                            {--chunk=1000 : Number of rows to process per file chunk}
                            {--source=manual : master_addresses.source => manual|api|lmt}
                            {--default-tenant=default : Fallback tenant slug for generic files like packages_lat_lon.csv}
                            {--dry-run : Validate and simulate import without inserting into DB}';

    protected $description = 'Import tracking CSV files, skip duplicates, and generate skipped/failed report files.';

    /**
     * File name => tenant slug mapping.
     * Aapke uploaded files ke according banaya gaya hai.
     */
    protected array $tenantMap = [
        'packages_telollevofast.csv' => 'telollevofast',
        'packages_movifarma.csv'     => 'movifarma',
        'packages_ebox.csv'          => 'ebox',
        'packages_move.csv'          => 'move',
        'packages_gemexpress.csv'    => 'gemexpress',
        'packages_relampagoes.csv'   => 'relampagos', // file name typo handled
        'packages_lonquiexpress.csv' => 'lonquiexpress',
        'packages_envioselrey.csv'   => 'envioselrey',
        'packages_corehx.csv'        => 'corhex',     // file name typo handled
        'packages_lat_lon.csv'       => 'default',    // generic dataset
    ];

    public function handle(): int
    {
        $folderArg = (string) $this->argument('folder');
        $folder = base_path($folderArg);
        $chunkSize = max(1, (int) $this->option('chunk'));
        $source = (string) $this->option('source');
        $defaultTenantSlug = (string) $this->option('default-tenant');
        $dryRun = (bool) $this->option('dry-run');

        if (!in_array($source, ['manual', 'api', 'lmt'], true)) {
            $this->error("Invalid --source value. Allowed: manual, api, lmt");
            return self::FAILURE;
        }

        if (!File::exists($folder) || !File::isDirectory($folder)) {
            $this->error("Import folder not found: {$folder}");
            $this->line("Create it using:");
            $this->line("mkdir -p {$folderArg}");
            return self::FAILURE;
        }

        $files = collect(File::files($folder))
            ->filter(function ($file) {
                $name = $file->getFilename();

                if (Str::startsWith($name, '._')) {
                    return false;
                }

                return strtolower($file->getExtension()) === 'csv';
            })
            ->sortBy(fn ($file) => $file->getFilename())
            ->values();

        if ($files->isEmpty()) {
            $this->warn("No CSV files found in: {$folder}");
            return self::SUCCESS;
        }

        $reportDir = storage_path('app/import-reports');
        if (!File::exists($reportDir)) {
            File::makeDirectory($reportDir, 0755, true);
        }

        $timestamp = now()->format('Y-m-d_H-i-s');

        $skippedFile = $reportDir . "/skipped_duplicates_{$timestamp}.csv";
        $failedFile  = $reportDir . "/failed_rows_{$timestamp}.csv";
        $summaryFile = $reportDir . "/import_summary_{$timestamp}.csv";

        $skippedHandle = fopen($skippedFile, 'w');
        $failedHandle  = fopen($failedFile, 'w');
        $summaryHandle = fopen($summaryFile, 'w');

        if (!$skippedHandle || !$failedHandle || !$summaryHandle) {
            $this->error('Unable to create report files in storage/app/import-reports');
            return self::FAILURE;
        }

        fputcsv($skippedHandle, [
            'file_name',
            'row_number',
            'tenant_slug',
            'address',
            'lat',
            'lon',
            'canonical_key_hash',
            'reason',
        ]);

        fputcsv($failedHandle, [
            'file_name',
            'row_number',
            'tenant_slug',
            'address',
            'lat',
            'lon',
            'reason',
        ]);

        fputcsv($summaryHandle, [
            'file_name',
            'tenant_slug',
            'total_rows',
            'inserted_rows',
            'skipped_rows',
            'failed_rows',
        ]);

        $grandTotal = 0;
        $grandInserted = 0;
        $grandSkipped = 0;
        $grandFailed = 0;

        $this->info('Starting import...');
        $this->line("Folder      : {$folder}");
        $this->line("Chunk size  : {$chunkSize}");
        $this->line("Dry run     : " . ($dryRun ? 'YES' : 'NO'));
        $this->newLine();

        foreach ($files as $file) {
            $fileName = $file->getFilename();
            $tenantSlug = $this->resolveTenantSlug($fileName, $defaultTenantSlug);
            $tenant = $this->resolveTenant($tenantSlug, $dryRun);

            if (!$tenant) {
                $this->warn("Skipping {$fileName}: tenant not found and could not be created.");
                fputcsv($failedHandle, [
                    $fileName,
                    '',
                    $tenantSlug,
                    '',
                    '',
                    '',
                    'Tenant not found and creation failed',
                ]);
                continue;
            }

            $totalRows = 0;
            $insertedRows = 0;
            $skippedRows = 0;
            $failedRows = 0;

            $this->info("Processing: {$fileName} | tenant: {$tenant->slug} | tenant_id: {$tenant->id}");

            $rowCount = $this->countDataRows($file->getPathname());
            $progressBar = $this->output->createProgressBar($rowCount > 0 ? $rowCount : 1);
            $progressBar->start();

            $handle = fopen($file->getPathname(), 'r');
            if ($handle === false) {
                $this->error(" Unable to open file: {$fileName}");
                continue;
            }

            $header = fgetcsv($handle);
            if (!$header) {
                fclose($handle);
                $this->warn(" Empty CSV skipped: {$fileName}");
                continue;
            }

            $header = $this->normalizeHeader($header);

            if (!$this->validateHeader($header)) {
                fclose($handle);
                $this->error(" Invalid CSV header in {$fileName}. Expected columns: address, lat, lon");
                fputcsv($failedHandle, [
                    $fileName,
                    '',
                    $tenant->slug,
                    '',
                    '',
                    '',
                    'Invalid header. Expected columns: address, lat, lon',
                ]);
                continue;
            }

            $rowNumber = 1;

            while (($chunk = $this->readChunk($handle, $header, $chunkSize, $rowNumber)) !== []) {
                foreach ($chunk as $item) {
                    $rowNumber = $item['_row_number'];
                    $row = $item['_data'];

                    $totalRows++;
                    $progressBar->advance();

                    $address = trim((string) ($row['address'] ?? ''));
                    $lat = $this->nullableFloat($row['lat'] ?? null);
                    $lon = $this->nullableFloat($row['lon'] ?? null);

                    if ($address === '') {
                        $failedRows++;
                        fputcsv($failedHandle, [
                            $fileName,
                            $rowNumber,
                            $tenant->slug,
                            $address,
                            $row['lat'] ?? '',
                            $row['lon'] ?? '',
                            'Address is empty',
                        ]);
                        continue;
                    }

                    $canonicalKey = $this->canonicalKey($address);
                    $canonicalHash = $this->canonicalHash($canonicalKey);

                    // Same command run ke andar duplicate avoid
                    // Existing DB + soft deleted rows dono check honge
                    $exists = NormalizedAddress::withTrashed()
                        ->where('canonical_key_hash', $canonicalHash)
                        ->exists();

                    if ($exists) {
                        $skippedRows++;
                        fputcsv($skippedHandle, [
                            $fileName,
                            $rowNumber,
                            $tenant->slug,
                            $address,
                            $row['lat'] ?? '',
                            $row['lon'] ?? '',
                            $canonicalHash,
                            'Duplicate canonical_key_hash already exists',
                        ]);
                        continue;
                    }

                    if ($dryRun) {
                        $insertedRows++;
                        continue;
                    }

                    try {
                        DB::transaction(function () use (
                            $address,
                            $lat,
                            $lon,
                            $source,
                            $tenant,
                            $canonicalKey,
                            $canonicalHash
                        ) {
                            $master = MasterAddress::create([
                                'formatted_address'                => $address,
                                'google_lat'                       => $lat,
                                'google_lng'                       => $lon,
                                'source'                           => $source,
                                'validation_count'                 => 0,
                                'is_trusted'                       => false,
                                'concordance_level'                => 0,
                                'last_validated_at'                => now(),
                                'final_navigation_coordinate_lat'  => $lat,
                                'final_navigation_coordinate_lng'  => $lon,
                                'created_from_history'             => true,
                                'tenant_id'                        => $tenant->id,
                            ]);

                            NormalizedAddress::create([
                                'original_address'   => $address,
                                'validated_address'  => $address,
                                'normalized_key'     => $canonicalKey,
                                'canonical_key'      => $canonicalKey,
                                'canonical_key_hash' => $canonicalHash,
                                'street'             => null,
                                'number'             => null,
                                'unit'               => null,
                                'google_lat'         => $lat,
                                'google_lng'         => $lon,
                                'master_address_id'  => $master->id,
                                'place_id'           => null,
                            ]);
                        });

                        $insertedRows++;
                    } catch (QueryException $e) {
                        // Unique index race / duplicate found during insert
                        if ($this->isUniqueConstraintViolation($e)) {
                            $skippedRows++;
                            fputcsv($skippedHandle, [
                                $fileName,
                                $rowNumber,
                                $tenant->slug,
                                $address,
                                $row['lat'] ?? '',
                                $row['lon'] ?? '',
                                $canonicalHash,
                                'Duplicate canonical_key_hash caught by DB unique index',
                            ]);
                            continue;
                        }

                        $failedRows++;
                        fputcsv($failedHandle, [
                            $fileName,
                            $rowNumber,
                            $tenant->slug,
                            $address,
                            $row['lat'] ?? '',
                            $row['lon'] ?? '',
                            $this->truncate($e->getMessage(), 1000),
                        ]);
                    } catch (\Throwable $e) {
                        $failedRows++;
                        fputcsv($failedHandle, [
                            $fileName,
                            $rowNumber,
                            $tenant->slug,
                            $address,
                            $row['lat'] ?? '',
                            $row['lon'] ?? '',
                            $this->truncate($e->getMessage(), 1000),
                        ]);
                    }
                }
            }

            fclose($handle);
            $progressBar->finish();
            $this->newLine(2);

            fputcsv($summaryHandle, [
                $fileName,
                $tenant->slug,
                $totalRows,
                $insertedRows,
                $skippedRows,
                $failedRows,
            ]);

            $grandTotal += $totalRows;
            $grandInserted += $insertedRows;
            $grandSkipped += $skippedRows;
            $grandFailed += $failedRows;

            $this->line("Completed {$fileName}");
            $this->line("  Total    : {$totalRows}");
            $this->line("  Inserted : {$insertedRows}");
            $this->line("  Skipped  : {$skippedRows}");
            $this->line("  Failed   : {$failedRows}");
            $this->newLine();
        }

        fclose($skippedHandle);
        fclose($failedHandle);
        fclose($summaryHandle);

        $this->info('Import finished.');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Rows', $grandTotal],
                ['Inserted', $grandInserted],
                ['Skipped', $grandSkipped],
                ['Failed', $grandFailed],
            ]
        );

        $this->line("Skipped Report : {$skippedFile}");
        $this->line("Failed Report  : {$failedFile}");
        $this->line("Summary Report : {$summaryFile}");

        return self::SUCCESS;
    }

    protected function resolveTenantSlug(string $fileName, string $defaultTenantSlug): string
    {
        return $this->tenantMap[$fileName] ?? $defaultTenantSlug;
    }

    protected function resolveTenant(string $slug, bool $dryRun = false): ?Tenant
    {
        $slug = trim($slug);

        if ($slug === '') {
            return null;
        }

        $tenant = Tenant::withTrashed()->where('slug', $slug)->first();

        if ($tenant) {
            if ($tenant->trashed() && !$dryRun) {
                $tenant->restore();
            }
            return $tenant;
        }

        if ($dryRun) {
            return new Tenant([
                'id' => 0,
                'name' => Str::headline($slug),
                'slug' => $slug,
            ]);
        }

        return Tenant::create([
            'name' => Str::headline($slug),
            'slug' => $slug,
        ]);
    }

    protected function countDataRows(string $filePath): int
    {
        $count = 0;
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            return 0;
        }

        // header skip
        fgetcsv($handle);

        while (fgetcsv($handle) !== false) {
            $count++;
        }

        fclose($handle);

        return $count;
    }

    protected function normalizeHeader(array $header): array
    {
        return array_map(function ($value) {
            $value = trim((string) $value);
            $value = strtolower($value);
            $value = preg_replace('/^\xEF\xBB\xBF/', '', $value); // BOM remove
            return $value;
        }, $header);
    }

    protected function validateHeader(array $header): bool
    {
        $required = ['address', 'lat', 'lon'];

        foreach ($required as $column) {
            if (!in_array($column, $header, true)) {
                return false;
            }
        }

        return true;
    }

    protected function readChunk($handle, array $header, int $chunkSize, int &$rowNumber): array
    {
        $rows = [];

        while (count($rows) < $chunkSize && ($data = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if ($data === [null] || $data === false) {
                continue;
            }

            $assoc = [];
            foreach ($header as $index => $column) {
                $assoc[$column] = $data[$index] ?? null;
            }

            $rows[] = [
                '_row_number' => $rowNumber,
                '_data' => $assoc,
            ];
        }

        return $rows;
    }

    protected function nullableFloat($value): ?float
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Ye logic aapke AddressValidationService ke canonicalKey() se aligned hai.
     */
    protected function canonicalKey(string $raw): string
    {
        if (mb_strlen($raw) > 10000) {
            $raw = mb_substr($raw, 0, 10000);
        }

        $s = Str::of($raw)->lower();
        $s = Str::ascii((string) $s);
        $s = preg_replace('/[^\pL\pN\s]/u', ' ', $s);
        $s = preg_replace('/\s+/', ' ', trim($s));

        $tokens = array_values(array_filter(explode(' ', $s), fn ($t) => $t !== ''));

        $stop = ['chile', 'region', 'provincia', 'comuna', 'metropolitana', 'región'];
        $tokens = array_values(array_filter($tokens, fn ($t) => !in_array($t, $stop, true)));

        sort($tokens, SORT_STRING);

        return implode(' ', $tokens);
    }

    protected function canonicalHash(string $canonicalKey): string
    {
        return hash('sha256', $canonicalKey);
    }

    protected function isUniqueConstraintViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate key')
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'na_canonical_hash_unique');
    }

    protected function truncate(string $text, int $limit = 1000): string
    {
        return mb_strlen($text) <= $limit
            ? $text
            : mb_substr($text, 0, $limit) . '...';
    }
}