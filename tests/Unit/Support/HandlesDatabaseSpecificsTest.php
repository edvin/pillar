<?php

use Illuminate\Support\Facades\DB;
use Pillar\Support\HandlesDatabaseDriverSpecifics;

// Small helper that exposes the trait’s private methods and lets us pick a driver.
final class _FakeDriverSpecifics
{
    use HandlesDatabaseDriverSpecifics {
        dbNow as public nowExpr;
        dbPlusSeconds as public plusExpr;
    }

    public function __construct(private string $driver)
    {
    }

    // Pretend to be whatever driver we want to test.
    protected function dbDriver(): string
    {
        return $this->driver;
    }
}

/**
 * dbNow(): per-driver SQL
 */
dataset('dbNow', [
    ['mysql', "UTC_TIMESTAMP()"],
    ['pgsql', "NOW() AT TIME ZONE 'UTC'"],
    ['sqlite', "datetime('now')"],
    ['sqlsrv', "SYSUTCDATETIME()"],
    ['other', "CURRENT_TIMESTAMP"], // default fallback
]);

it('dbNow emits the correct per-driver expression', function (string $driver, string $expected) {
    $fake = new _FakeDriverSpecifics($driver);
    $sql = $fake->nowExpr()->getValue(DB::connection()->getQueryGrammar());
    expect($sql)->toBe($expected);
})->with('dbNow');

/**
 * dbPlusSeconds(): per-driver SQL (including negative seconds)
 */
dataset('dbPlus', [
    ['mysql', 10, "DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 SECOND)"],
    ['pgsql', 10, "NOW() + INTERVAL '10 seconds'"],
    ['sqlsrv', 10, "DATEADD(SECOND, 10, SYSUTCDATETIME())"],
    // In the trait, sqlite (and unknown) take the default portable fallback:
    ['sqlite', 10, "datetime('now', '+10 seconds')"],
    ['other', -5, "datetime('now', '-5 seconds')"],
]);

it('dbPlusSeconds emits the correct per-driver expression', function (string $driver, int $seconds, string $expected) {
    $fake = new _FakeDriverSpecifics($driver);
    $sql = $fake->plusExpr($seconds)->getValue(DB::connection()->getQueryGrammar());
    expect($sql)->toBe($expected);
})->with('dbPlus');