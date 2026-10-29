<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Many-row writes in a fixed number of statements instead of one (or two)
 * per row.
 *
 * Postgres-only (UPDATE … FROM (VALUES …)). Neither method needs a unique
 * constraint on the key columns — unlike Builder::upsert(), whose
 * ON CONFLICT requires one — so they are safe on tables whose constraints
 * have changed over time.
 */
class BulkWrite
{
    private const CHUNK = 500;

    /** @var array<string, array<string, string>> table => column => SQL type */
    private static array $types = [];

    /**
     * updateOrInsert() for many rows: 1 read + 1 INSERT + 1 UPDATE (per 500
     * rows) instead of 2 queries per row. Each row holds the key columns and
     * the value columns. Matching rows get the value columns (every matching
     * row, as updateOrInsert() does); missing keys are inserted with all
     * columns. A key repeated in $rows: the last one wins, as in a loop.
     * A null key value matches NULL (updateOrInsert's whereNull).
     *
     * @return array{inserted:int, updated:int}
     */
    public static function updateOrInsertMany(string $table, array $keyCols, array $rows): array
    {
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[self::keyOf($row, $keyCols)] = $row;
        }
        if (!$byKey) {
            return ['inserted' => 0, 'updated' => 0];
        }

        $inserted = 0;
        $updated = 0;
        foreach (array_chunk($byKey, self::CHUNK, true) as $chunk) {
            $existing = DB::table($table)
                ->where(function ($w) use ($chunk, $keyCols) {
                    foreach ($chunk as $row) {
                        $w->orWhere(function ($k) use ($row, $keyCols) {
                            foreach ($keyCols as $c) {
                                $row[$c] === null ? $k->whereNull($c) : $k->where($c, $row[$c]);
                            }
                        });
                    }
                })
                ->get($keyCols)
                ->mapWithKeys(fn ($r) => [self::keyOf((array) $r, $keyCols) => true]);

            $new = [];
            $old = [];
            foreach ($chunk as $key => $row) {
                isset($existing[$key]) ? $old[] = $row : $new[] = $row;
            }

            if ($new) {
                // insert() needs one column set for every row.
                $cols = array_keys(array_merge(...array_map(fn ($r) => array_fill_keys(array_keys($r), null), $new)));
                DB::table($table)->insert(array_map(
                    fn ($r) => array_merge(array_fill_keys($cols, null), $r),
                    $new
                ));
                $inserted += count($new);
            }
            if ($old) {
                $updated += self::updateFromValues($table, $keyCols, $old, true);
            }
        }

        return ['inserted' => $inserted, 'updated' => $updated];
    }

    /**
     * One UPDATE per 500 rows: each row holds the key columns and the columns
     * to set, matched with IS NOT DISTINCT FROM. Returns rows affected.
     */
    public static function updateMany(string $table, array $keyCols, array $rows): int
    {
        $n = 0;
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $n += self::updateFromValues($table, $keyCols, $chunk, false);
        }
        return $n;
    }

    private static function updateFromValues(string $table, array $keyCols, array $rows, bool $sameColumns): int
    {
        $cols = array_keys($rows[0]);
        if (!$sameColumns) {
            foreach ($rows as $r) {
                if (array_keys($r) !== $cols) {
                    throw new \InvalidArgumentException('BulkWrite::updateMany: every row needs the same columns.');
                }
            }
        }
        $setCols = array_values(array_diff($cols, $keyCols));
        if (!$setCols) {
            return 0;
        }

        $types = self::columnTypes($table);
        $q = fn (string $c) => '"' . str_replace('"', '""', $c) . '"';
        $cast = fn (string $c) => 'CAST(? AS ' . ($types[$c] ?? 'text') . ')';

        $tuple = '(' . implode(', ', array_map($cast, $cols)) . ')';
        $bindings = [];
        foreach ($rows as $r) {
            foreach ($cols as $c) {
                $v = $r[$c] ?? null;
                $bindings[] = is_array($v) ? json_encode($v) : $v;
            }
        }

        $sql = sprintf(
            'UPDATE %s AS t SET %s FROM (VALUES %s) AS v (%s) WHERE %s',
            $q($table),
            implode(', ', array_map(fn ($c) => $q($c) . ' = v.' . $q($c), $setCols)),
            implode(', ', array_fill(0, count($rows), $tuple)),
            implode(', ', array_map($q, $cols)),
            implode(' AND ', array_map(fn ($c) => 't.' . $q($c) . ' IS NOT DISTINCT FROM v.' . $q($c), $keyCols))
        );

        return DB::update($sql, $bindings);
    }

    /** Column => SQL type (e.g. "character varying(20)"), looked up once per table. */
    private static function columnTypes(string $table): array
    {
        return self::$types[$table] ??= collect(DB::select(
            'SELECT a.attname, format_type(a.atttypid, a.atttypmod) AS type
               FROM pg_attribute a
              WHERE a.attrelid = to_regclass(?) AND a.attnum > 0 AND NOT a.attisdropped',
            [$table]
        ))->pluck('type', 'attname')->all();
    }

    private static function keyOf(array $row, array $keyCols): string
    {
        return json_encode(array_map(fn ($c) => isset($row[$c]) ? (string) $row[$c] : null, $keyCols));
    }
}
