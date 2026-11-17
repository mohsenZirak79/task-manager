<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** SQL-only predicates shared by the existing list queries. */
final class ListFilters
{
    public static function has(array $filters, string $key): bool
    {
        return array_key_exists($key, $filters) && $filters[$key] !== null && $filters[$key] !== '';
    }

    public static function contains(Builder $query, string $column, string $value, string $boolean = 'and'): Builder
    {
        return self::containsExpression($query, $query->getQuery()->getGrammar()->wrap($column), $value, $boolean);
    }

    public static function containsExpression(Builder $query, string $expression, string $value, string $boolean = 'and'): Builder
    {
        $value = str_replace(['ي', 'ك'], ['ی', 'ک'], trim($value));
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';

        return $query->whereRaw("REPLACE(REPLACE(LOWER($expression), ?, ?), ?, ?) LIKE LOWER(?) ESCAPE '!'", ['ي', 'ی', 'ك', 'ک', $pattern], $boolean);
    }

    public static function fullName(Builder $query, string $value, string $boolean = 'and'): Builder
    {
        $grammar = $query->getQuery()->getGrammar();
        $first = $grammar->wrap($query->getModel()->qualifyColumn('first_name'));
        $last = $grammar->wrap($query->getModel()->qualifyColumn('last_name'));
        $expression = $query->getConnection()->getDriverName() === 'mysql'
            ? "CONCAT(COALESCE($first, ''), ' ', COALESCE($last, ''))"
            : "COALESCE($first, '') || ' ' || COALESCE($last, '')";

        return self::containsExpression($query, $expression, $value, $boolean);
    }

    public static function textColumns(Builder $query, array $filters, array $columns): void
    {
        foreach ($columns as $parameter => $column) {
            if (self::has($filters, $parameter)) {
                self::contains($query, $column, $filters[$parameter]);
            }
        }
    }

    public static function numberRange(Builder $query, array $filters, string $column, string $min, string $max): void
    {
        if (self::has($filters, $min)) {
            $query->where($column, '>=', $filters[$min]);
        }
        if (self::has($filters, $max)) {
            $query->where($column, '<=', $filters[$max]);
        }
    }

    public static function dateRange(Builder $query, array $filters, string $column, string $from, string $to, bool $timestamp = false): void
    {
        if (self::has($filters, $from)) {
            $start = $timestamp
                ? CarbonImmutable::createFromFormat('!Y-m-d', $filters[$from], 'Asia/Tehran')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s')
                : $filters[$from];
            $query->where($column, '>=', $start);
        }
        if (self::has($filters, $to)) {
            $end = $timestamp
                ? CarbonImmutable::createFromFormat('!Y-m-d', $filters[$to], 'Asia/Tehran')->addDay()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s')
                : CarbonImmutable::createFromFormat('!Y-m-d', $filters[$to], 'Asia/Tehran')->addDay()->toDateString();
            $query->where($column, '<', $end);
        }
    }
}
