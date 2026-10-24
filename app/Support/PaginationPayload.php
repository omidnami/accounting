<?php

namespace App\Support;

use Illuminate\Support\Collection;

class PaginationPayload
{
    /**
     * @param  iterable<int, mixed>  $rows
     * @return array{
     *     data: array<int, mixed>, current_page: int, last_page: int,
     *     per_page: int, from: int|null, to: int|null, total: int
     * }
     */
    public static function from(
        iterable $rows,
        int $page,
        int $perPage = 50,
    ): array {
        $collection = $rows instanceof Collection ? $rows->values() : collect($rows);
        $total = $collection->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));
        $data = $collection->forPage($page, $perPage)->values()->all();

        return [
            'data' => $data,
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'from' => $total === 0 ? null : (($page - 1) * $perPage) + 1,
            'to' => $total === 0 ? null : min($total, $page * $perPage),
            'total' => $total,
        ];
    }
}
