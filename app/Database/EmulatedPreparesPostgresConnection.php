<?php

namespace App\Database;

use DateTimeInterface;
use Illuminate\Database\PostgresConnection;

/**
 * Fixes a second-order bug introduced by turning on
 * PDO::ATTR_EMULATE_PREPARES for the pgsql connection (see config/database.php
 * for why that's on — Supabase's Supavisor pooler, transaction mode, port
 * 6543).
 *
 * Laravel's base Connection::prepareBindings() converts every PHP bool
 * binding to a plain integer (1/0) before it reaches PDO. With REAL
 * server-side prepared statements this is harmless — Postgres infers the
 * bound parameter's type from the target column during PREPARE and coerces
 * it. With EMULATED prepares, PDO instead substitutes that integer directly
 * into the literal SQL text (no column-type context at that point), so a
 * query like `where('is_active', true)` becomes the literal
 * `"is_active" = 1` — and Postgres's boolean type has no implicit cast from
 * a bare integer literal, producing exactly:
 *   SQLSTATE[42883]: Undefined function: operator does not exist:
 *   boolean = integer
 */
class EmulatedPreparesPostgresConnection extends PostgresConnection
{
    public function prepareBindings(array $bindings)
    {
        $grammar = $this->getQueryGrammar();

        foreach ($bindings as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $bindings[$key] = $value->format($grammar->getDateFormat());
            } elseif (is_bool($value)) {
                $bindings[$key] = $value ? 'true' : 'false';
            }
        }

        return $bindings;
    }
}
