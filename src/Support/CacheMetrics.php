<?php

namespace Jackardios\FileStash\Support;

class CacheMetrics
{
    public int $hits = 0;
    public int $misses = 0;
    public int $evictions = 0;
    public int $retrievals = 0;
    public int $errors = 0;

    /**
     * Get the cache hit rate as a percentage.
     *
     * @return float|null Null if no requests yet.
     */
    public function hitRate(): ?float
    {
        $total = $this->hits + $this->misses;

        if ($total === 0) {
            return null;
        }

        return ($this->hits / $total) * 100;
    }

    /**
     * Get all metrics as an array.
     *
     * @return array{hits: int, misses: int, evictions: int, retrievals: int, errors: int, hit_rate: float|null}
     */
    public function toArray(): array
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'evictions' => $this->evictions,
            'retrievals' => $this->retrievals,
            'errors' => $this->errors,
            'hit_rate' => $this->hitRate(),
        ];
    }

    /**
     * Reset all counters.
     */
    public function reset(): void
    {
        $this->hits = 0;
        $this->misses = 0;
        $this->evictions = 0;
        $this->retrievals = 0;
        $this->errors = 0;
    }
}
