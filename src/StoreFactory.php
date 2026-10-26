<?php
declare(strict_types=1);

namespace App;

use App\Repository\DataStore;
use App\Repository\JsonStore;
use App\Repository\OracleStore;

final class StoreFactory
{
    /** Oracle se ORACLE_* estiverem definidas; caso contrário, JSON (demo). */
    public static function create(string $dataDir): DataStore
    {
        if (Config::oracleConfigured()) {
            return new OracleStore(new Database(Config::oracle()));
        }
        return new JsonStore($dataDir);
    }
}
