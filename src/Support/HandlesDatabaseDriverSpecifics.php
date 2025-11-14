<?php

namespace Pillar\Support;

use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

trait HandlesDatabaseDriverSpecifics
{
    private ?string $dbDriver = null; // lazy cache

    public function dbDriver(): string
    {
        return $this->dbDriver ??= DB::connection()->getDriverName();
    }

    public function dbNow(): Expression
    {
        return match ($this->dbDriver()) {
            'mysql' => DB::raw('UTC_TIMESTAMP()'),
            'pgsql' => DB::raw("NOW() AT TIME ZONE 'UTC'"),
            'sqlite' => DB::raw("datetime('now')"),
            'sqlsrv' => DB::raw('SYSUTCDATETIME()'),
            default => DB::raw('CURRENT_TIMESTAMP'),
        };
    }

    public function dbPlusSeconds(int $seconds): Expression
    {
        return match ($this->dbDriver()) {
            'mysql' => DB::raw("DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$seconds} SECOND)"),
            'pgsql' => DB::raw("NOW() + INTERVAL '{$seconds} seconds'"),
            'sqlsrv' => DB::raw("DATEADD(SECOND, {$seconds}, SYSUTCDATETIME())"),
            // SQLite and portable fallback
            default => DB::raw("datetime('now', '" . sprintf('%+d seconds', $seconds) . "')"),
        };
    }
}