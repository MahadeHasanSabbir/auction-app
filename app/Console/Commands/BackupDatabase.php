<?php

namespace App\Console\Commands;

use App\Support\OpsAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * A copy of the database (mysqldump, gzipped) and of the uploaded files
 * (public pictures and private identity documents, zipped) in
 * auction.backup.path, keeping auction.backup.keep_days of them. Scheduled
 * daily; copy the folder off the server (rsync, object storage) as well,
 * because a backup on the same disk does not survive the disk.
 */
class BackupDatabase extends Command
{
    protected $signature = 'backup:run {--no-files : Only the database}';

    protected $description = 'Dump the database and zip the uploaded files into the backup folder';

    public function handle(): int
    {
        $dir = (string) config('auction.backup.path');
        File::ensureDirectoryExists($dir);
        $stamp = now()->format('Y-m-d-His');

        try {
            $sql = $this->dumpDatabase($dir, $stamp);
            $this->info("Database: {$sql}");

            if (! $this->option('no-files')) {
                $zip = $this->zipUploads($dir, $stamp);
                $this->info("Files: {$zip}");
            }
        } catch (\Throwable $e) {
            Log::critical('The backup failed.', ['error' => $e->getMessage()]);
            OpsAlert::send('The backup failed', $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->prune($dir);

        return self::SUCCESS;
    }

    private function dumpDatabase(string $dir, string $stamp): string
    {
        $connection = config('database.connections.'.config('database.default'));

        if (($connection['driver'] ?? null) !== 'mysql') {
            throw new \RuntimeException('backup:run only dumps MySQL databases.');
        }

        $raw = "{$dir}/db-{$stamp}.sql";
        $process = new Process([
            (string) config('auction.backup.mysqldump'),
            '--host='.$connection['host'],
            '--port='.$connection['port'],
            '--user='.$connection['username'],
            '--single-transaction',
            '--quick',
            '--routines',
            '--no-tablespaces',
            '--default-character-set=utf8mb4',
            '--result-file='.$raw,
            $connection['database'],
        ], env: ['MYSQL_PWD' => (string) $connection['password']]);
        $process->setTimeout(3600)->mustRun();

        // gzip in chunks, so a large dump never sits in memory.
        $gz = $raw.'.gz';
        $in = fopen($raw, 'rb');
        $out = gzopen($gz, 'wb6');

        if ($in === false || $out === false) {
            throw new \RuntimeException('Could not compress the dump.');
        }

        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1 << 20));
        }

        fclose($in);
        gzclose($out);
        unlink($raw);

        return $gz;
    }

    private function zipUploads(string $dir, string $stamp): string
    {
        $path = "{$dir}/files-{$stamp}.zip";
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Could not create {$path}.");
        }

        foreach (['public' => storage_path('app/public'), 'kyc' => storage_path('app/private/kyc')] as $name => $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                $zip->addFile($file->getPathname(), $name.'/'.str_replace('\\', '/', $file->getRelativePathname()));
            }
        }

        $zip->close();

        return $path;
    }

    private function prune(string $dir): void
    {
        $cutoff = now()->subDays((int) config('auction.backup.keep_days'))->getTimestamp();

        foreach (File::files($dir) as $file) {
            if (preg_match('/^(db|files)-\d{4}-\d{2}-\d{2}-\d{6}\./', $file->getFilename()) === 1 && $file->getMTime() < $cutoff) {
                File::delete($file->getPathname());
            }
        }
    }
}
