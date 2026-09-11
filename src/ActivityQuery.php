<?php

declare(strict_types=1);

namespace SimoneBianco\ActivityMatrix;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use SimoneBianco\ActivityMatrix\Data\ActivityItemData;
use SimoneBianco\ActivityMatrix\Models\ActivityEntry;

final class ActivityQuery
{
    public function feed(string $scope, array $filters = []): array
    {
        return $this->feedQuery(ActivityEntry::query()->where('scope', $scope), $filters);
    }

    public function feedScopePrefix(string $scopePrefix, array $filters = []): array
    {
        return $this->feedQuery($this->scopePrefixQuery($scopePrefix), $filters);
    }

    public function feedLatestCorrelatedScopePrefix(string $scopePrefix, array $filters = []): array
    {
        return $this->feedQuery($this->latestCorrelatedScopePrefixQuery($scopePrefix), $filters);
    }

    public function detail(string $scope, string $id): ActivityEntry
    {
        return ActivityEntry::query()->where('scope', $scope)->findOrFail($id);
    }

    public function detailScopePrefix(string $scopePrefix, string $id): ActivityEntry
    {
        return $this->scopePrefixQuery($scopePrefix)->findOrFail($id);
    }

    /** @param Builder<ActivityEntry> $query */
    private function feedQuery(Builder $query, array $filters): array
    {
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 240)));
        if (isset($filters['before'], $filters['after'])) {
            throw new InvalidArgumentException('Use only one cursor direction.');
        }
        foreach (['agent' => 'actor_id', 'operation' => 'operation'] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }
        foreach (['from' => '>=', 'to' => '<='] as $filter => $operator) {
            if (isset($filters[$filter])) {
                $query->where('occurred_at', $operator, $filters[$filter]);
            }
        }
        $pendingCount = isset($filters['since']) ? (clone $query)->where('id', '>', $filters['since'])->count() : 0;
        $newer = isset($filters['after']);
        if (isset($filters['before']) || $newer) {
            $query->where('id', $newer ? '>' : '<', $filters[$newer ? 'after' : 'before']);
        }
        // Read the nearest newer page ascending before presenting descending, so bursts never skip rows.
        $rows = $query->orderBy('id', $newer ? 'asc' : 'desc')->limit($limit + 1)
            ->get(['id', 'actor_id', 'actor_label', 'operation', 'category', 'status', 'summary', 'occurred_at', 'correlation_id']);
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);
        if ($newer) {
            $rows = $rows->reverse()->values();
        }

        return [
            'items' => $rows->map(fn (ActivityEntry $row): array => ActivityItemData::fromEntry($row)->jsonSerialize())->values()->all(),
            'older_cursor' => $rows->last() ? (string) $rows->last()->id : null,
            'newer_cursor' => $rows->first() ? (string) $rows->first()->id : null,
            'has_more' => $hasMore,
            'pending_count' => $pendingCount,
        ];
    }

    /** @return Builder<ActivityEntry> */
    private function latestCorrelatedScopePrefixQuery(string $scopePrefix): Builder
    {
        $query = $this->scopePrefixQuery($scopePrefix);
        $table = (new ActivityEntry)->getTable();

        return $query->where(function (Builder $query) use ($scopePrefix, $table): void {
            $query->whereNull('correlation_id')->orWhereIn('id', function ($latest) use ($scopePrefix, $table): void {
                $latest->from($table)
                    ->selectRaw('MAX(id)')
                    ->where('scope', 'like', $scopePrefix.'%')
                    ->whereNotNull('correlation_id')
                    ->groupBy('scope', 'category', 'operation', 'correlation_id');
            });
        });
    }

    /** @return Builder<ActivityEntry> */
    private function scopePrefixQuery(string $scopePrefix): Builder
    {
        if ($scopePrefix === '' || strlen($scopePrefix) > 100 || strpbrk($scopePrefix, '%_\\') !== false) {
            throw new InvalidArgumentException('Scope prefix must be a literal non-empty prefix without SQL wildcard characters.');
        }

        return ActivityEntry::query()->where('scope', 'like', $scopePrefix.'%');
    }
}
