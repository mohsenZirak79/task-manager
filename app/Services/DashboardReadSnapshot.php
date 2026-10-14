<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/** A read snapshot only for dashboard requests, never a global isolation change. */
class DashboardReadSnapshot
{
    public function run(Closure $read): mixed
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if ($connection->transactionLevel() > 0) {
            if ($driver !== 'sqlite') {
                // Isolation cannot safely be strengthened after an outer transaction has read.
                throw new LogicException('Dashboard requires its own read transaction on this database.');
            }

            return $read();
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // Applies to the NEXT transaction only, even when the session default is READ COMMITTED.
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        }

        return $connection->transaction(function () use ($read, $connection, $driver) {
            if ($driver === 'pgsql') {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }

            return $read();
        });
    }
}
