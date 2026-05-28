<?php

namespace Admon\LogShipper\Commands;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PullLogs extends Command
{
    protected $signature = 'logs:pull
        {--project=* : Project name(s) to pull (default: all). Fuzzy matches against folder names.}
        {--days=1 : How many days back to pull}
        {--date= : Specific date (YYYY-MM-DD) to pull}
        {--output= : Output directory (default: ~/.claude/logs)}
        {--list : Just list available projects, do not download}';

    protected $description = 'Pull log files from the centralized log server';

    protected $aliases = ['logs:pull-from-s3'];

    private int $downloaded = 0;
    private int $failed = 0;

    public function handle(): int
    {
        if (empty(config('log-shipper.token'))) {
            $this->error('LOG_SHIPPER_TOKEN is not set. Add it to your .env (ask the log-server admin for the value).');
            return 1;
        }

        $client = $this->makeClient();
        $outputBase = $this->option('output') ?: $this->defaultOutputDir();

        // List mode
        if ($this->option('list')) {
            return $this->listProjects($client);
        }

        // Discover projects
        $allProjects = $this->discoverProjects($client);

        if (empty($allProjects)) {
            $this->warn('No projects found on the log server.');
            return 0;
        }

        // Filter projects if specified
        $requestedProjects = $this->option('project');
        $projects = $allProjects;

        if (!empty($requestedProjects)) {
            $projects = $this->filterProjects($allProjects, $requestedProjects);
            if (empty($projects)) {
                $this->error('No matching projects found. Available: ' . implode(', ', $allProjects));
                return 1;
            }
        }

        // Determine dates
        $dates = $this->getDates();

        $this->info('Pulling logs from server...');
        $this->info('Projects: ' . implode(', ', $projects));
        $this->info('Dates: ' . implode(', ', $dates));
        $this->info('Output: ' . $outputBase);
        $this->output->writeln('');

        // Download files
        foreach ($projects as $project) {
            foreach ($dates as $date) {
                $this->pullProjectDate($client, $project, $date, $outputBase);
            }
        }

        // Summary
        $this->output->writeln('');
        $this->info('--- Summary ---');
        $this->info("Downloaded: {$this->downloaded}");
        if ($this->failed > 0) $this->warn("Failed: {$this->failed}");

        return $this->failed > 0 ? 1 : 0;
    }

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

    private function listProjects(Client $client): int
    {
        try {
            $projects = $this->discoverProjects($client);
        } catch (\Throwable $e) {
            $this->error("Failed to connect to log server: {$e->getMessage()}");
            return 1;
        }

        if (empty($projects)) {
            $this->warn('No projects found on the log server.');
            return 0;
        }

        $this->info('Available projects:');
        foreach ($projects as $project) {
            $this->line("  • {$project}");
        }

        return 0;
    }

    private function discoverProjects(Client $client): array
    {
        $response = $client->get('/api/projects');
        return json_decode($response->getBody()->getContents(), true) ?: [];
    }

    private function filterProjects(array $available, array $requested): array
    {
        $matched = [];

        foreach ($requested as $query) {
            $query = strtolower(trim($query));
            foreach ($available as $project) {
                if (str_contains(strtolower($project), $query)) {
                    $matched[] = $project;
                }
            }
        }

        return array_unique($matched);
    }

    private function getDates(): array
    {
        if ($specificDate = $this->option('date')) {
            return [$specificDate];
        }

        $days = max(1, (int) $this->option('days'));
        $dates = [];

        for ($i = 0; $i < $days; $i++) {
            $dates[] = Carbon::today()->subDays($i)->format('Y-m-d');
        }

        return $dates;
    }

    private function pullProjectDate(Client $client, string $project, string $date, string $outputBase): void
    {
        // List files for this project/date
        try {
            $response = $client->get('/api/logs', [
                'query' => ['project' => $project, 'date' => $date],
            ]);
            $files = json_decode($response->getBody()->getContents(), true) ?: [];
        } catch (\Throwable $e) {
            return; // No files for this date — that's fine
        }

        if (empty($files)) {
            return;
        }

        // Create local output directory
        $localDir = $outputBase . '/' . $project . '/' . $date;
        if (!File::isDirectory($localDir)) {
            File::makeDirectory($localDir, 0755, true);
        }

        foreach ($files as $fileInfo) {
            $filename = $fileInfo['filename'];

            if (str_starts_with($filename, '.')) {
                continue;
            }

            $localPath = $localDir . '/' . $filename;

            try {
                $response = $client->get('/api/logs/download', [
                    'query' => [
                        'project' => $project,
                        'date' => $date,
                        'filename' => $filename,
                    ],
                ]);

                $contents = $response->getBody()->getContents();
                File::put($localPath, $contents);
                $size = $this->humanFileSize(strlen($contents));
                $this->line("  ↓ {$project}/{$date}/{$filename} ({$size})");
                $this->downloaded++;
            } catch (\Throwable $e) {
                $this->error("  ✗ {$project}/{$date}/{$filename} — {$e->getMessage()}");
                $this->failed++;
            }
        }
    }

    private function defaultOutputDir(): string
    {
        $home = $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? '/tmp';
        return $home . '/.claude/logs';
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
