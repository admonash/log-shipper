<?php

namespace Admon\LogShipper\Commands;

use Admon\LogShipper\LogShipperServiceProvider;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ShipLogs extends Command
{
    protected $signature = 'logs:ship
        {--dry-run : Show what would be uploaded without actually uploading}
        {--no-cleanup : Skip local log cleanup}
        {--days-to-keep=7 : Delete local logs older than this many days}';

    protected $description = 'Ship log files from storage/logs to the centralized log server';

    protected $aliases = ['logs:ship-to-s3'];

    private int $uploaded = 0;
    private int $failed = 0;
    private int $cleaned = 0;
    private int $skipped = 0;

    public function handle(): int
    {
        // Fail loudly if the project hasn't set LOG_SHIPPER_TOKEN. Better to error
        // here than to fire off requests that all 401 against the server.
        if (empty(config('log-shipper.token'))) {
            $this->error('LOG_SHIPPER_TOKEN is not set. Add it to your .env (ask the log-server admin for the value).');
            return 1;
        }

        $projectId = LogShipperServiceProvider::projectId();
        $logsDir = storage_path('logs');
        $dryRun = $this->option('dry-run');
        $noCleanup = $this->option('no-cleanup');
        $daysToKeep = (int) $this->option('days-to-keep');

        $this->info("Log Shipper — Project: {$projectId}");
        $this->info("Scanning: {$logsDir}");

        if (!File::isDirectory($logsDir)) {
            $this->warn('No logs directory found. Nothing to do.');
            return 0;
        }

        // Verify server connectivity (unless dry run)
        if (!$dryRun) {
            try {
                $client = $this->makeClient();
                $client->get('/api/projects');
                $this->info('Server connection OK.');
            } catch (\Throwable $e) {
                $this->error("Server connection failed: {$e->getMessage()}");
                return 1;
            }
        }

        // Find all .log files recursively
        $logFiles = $this->findLogFiles($logsDir);

        if (empty($logFiles)) {
            $this->info('No log files found.');
            return 0;
        }

        $this->info(count($logFiles) . ' log file(s) found.');
        $this->newLine();

        // Group files by date and upload
        $grouped = $this->groupFilesByDate($logFiles, $logsDir);

        foreach ($grouped as $date => $files) {
            $this->uploadBatch($files, $projectId, $date, $dryRun);
        }

        // Cleanup old logs
        if (!$noCleanup && !$dryRun && $daysToKeep > 0) {
            $this->newLine();
            $this->info("Cleaning up logs older than {$daysToKeep} days...");
            $this->cleanupOldLogs($logFiles, $daysToKeep);
        }

        // Summary
        $this->newLine();
        $this->info('--- Summary ---');
        $this->info("Uploaded: {$this->uploaded}");
        if ($this->skipped > 0) $this->info("Skipped (empty): {$this->skipped}");
        if ($this->failed > 0) $this->warn("Failed: {$this->failed}");
        if ($this->cleaned > 0) $this->info("Cleaned up: {$this->cleaned}");

        if ($dryRun) {
            $this->newLine();
            $this->comment('(Dry run — nothing was actually uploaded or deleted)');
        }

        return $this->failed > 0 ? 1 : 0;
    }

    /**
     * Create a Guzzle client configured for the log server.
     */
    private function makeClient(): Client
    {
        return new Client([
            'base_uri' => config('log-shipper.url'),
            'headers' => [
                'Authorization' => 'Bearer ' . config('log-shipper.token'),
                'Accept' => 'application/json',
            ],
            'timeout' => 120,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * Group log files by their date for batch uploading.
     */
    private function groupFilesByDate(array $logFiles, string $logsDir): array
    {
        $grouped = [];

        foreach ($logFiles as $localPath) {
            if (filesize($localPath) === 0) {
                $this->skipped++;
                continue;
            }

            $relativePath = $this->normalizePath(
                str_replace($this->normalizePath($logsDir), '', $this->normalizePath($localPath))
            );
            $relativePath = ltrim($relativePath, '/');

            $date = $this->extractDateFromFilename(basename($localPath));
            if (!$date) {
                $date = Carbon::createFromTimestamp(filemtime($localPath))->format('Y-m-d');
            }

            $flattenedName = $this->flattenPath($relativePath);

            $grouped[$date][] = [
                'path' => $localPath,
                'relativePath' => $relativePath,
                'flatName' => $flattenedName,
            ];
        }

        return $grouped;
    }

    /**
     * Upload a batch of files for a single date.
     */
    private function uploadBatch(array $files, string $projectId, string $date, bool $dryRun): void
    {
        if ($dryRun) {
            foreach ($files as $file) {
                $size = $this->humanFileSize(filesize($file['path']));
                $this->line("  [DRY RUN] {$file['relativePath']} → {$projectId}/{$date}/{$file['flatName']} ({$size})");
                $this->uploaded++;
            }
            return;
        }

        $multipart = [
            ['name' => 'project', 'contents' => $projectId],
            ['name' => 'date', 'contents' => $date],
        ];

        // Send raw text — we want logs stored as text on S3 so they can be
        // grep'd / cat'd directly without a decompress step on the consumer side.
        // Use a streaming file handle so large logs don't get loaded into memory.
        foreach ($files as $file) {
            $multipart[] = [
                'name' => 'files[]',
                'contents' => fopen($file['path'], 'r'),
                'filename' => $file['flatName'],
            ];
        }

        try {
            $client = $this->makeClient();
            $response = $client->post('/api/ingest', ['multipart' => $multipart]);
            $body = json_decode($response->getBody()->getContents(), true);

            foreach ($files as $file) {
                $size = $this->humanFileSize(filesize($file['path']));
                $this->line("  ✓ {$file['relativePath']} ({$size})");
            }

            $this->uploaded += $body['files_received'] ?? count($files);
        } catch (ConnectException $e) {
            $this->error("  ✗ Connection failed for date {$date}: {$e->getMessage()}");
            $this->failed += count($files);
        } catch (RequestException $e) {
            $message = $e->hasResponse()
                ? $e->getResponse()->getBody()->getContents()
                : $e->getMessage();
            $this->error("  ✗ Upload failed for date {$date}: {$message}");
            $this->failed += count($files);
        }
    }

    /**
     * Recursively find all .log files in the logs directory.
     */
    private function findLogFiles(string $logsDir): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($logsDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'log') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Delete local log files older than the specified number of days.
     */
    private function cleanupOldLogs(array $logFiles, int $daysToKeep): void
    {
        $cutoff = Carbon::now()->subDays($daysToKeep)->timestamp;
        $today = Carbon::today()->format('Y-m-d');

        foreach ($logFiles as $localPath) {
            $filename = basename($localPath);

            if ($filename === '.gitignore') {
                continue;
            }

            $fileDate = $this->extractDateFromFilename($filename);
            if ($fileDate === $today) {
                continue;
            }

            if (filemtime($localPath) < $cutoff) {
                try {
                    unlink($localPath);
                    $this->line("  Deleted: {$filename}");
                    $this->cleaned++;
                } catch (\Throwable $e) {
                    $this->warn("  Could not delete {$filename}: {$e->getMessage()}");
                }
            }
        }
    }

    private function extractDateFromFilename(string $filename): ?string
    {
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $filename, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function flattenPath(string $relativePath): string
    {
        return str_replace('/', '--', $relativePath);
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function humanFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }
}
