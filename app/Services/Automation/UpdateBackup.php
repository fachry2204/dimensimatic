<?php

namespace App\Services\Automation;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

class UpdateBackup
{
    public function snapshotDatabase(PDO $connection, string $destination): void
    {
        if ($connection->inTransaction()) throw new RuntimeException('Database masih dipakai transaksi. Coba update lagi.');
        $connection->exec('VACUUM INTO '.$connection->quote($destination));
        if (!is_file($destination)) throw new RuntimeException('Cadangan database gagal dibuat.');
    }

    public function capture(): string
    {
        $directory = storage_path('app/updates/backup-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)));
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Folder cadangan tidak dapat dibuat.');
        if (DB::connection()->getDriverName() !== 'sqlite') throw new RuntimeException('Cadangan otomatis memerlukan SQLite lokal.');
        $this->snapshotDatabase(DB::connection()->getPdo(), $directory.'/database.sqlite');
        foreach (['.env', 'automation-worker/.env'] as $file) {
            if (is_file(base_path($file)) && !copy(base_path($file), $directory.'/'.str_replace('/', '-', $file))) {
                throw new RuntimeException('Cadangan pengaturan gagal dibuat.');
            }
        }
        return $directory;
    }
}
