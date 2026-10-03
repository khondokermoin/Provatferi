<?php

namespace App\Support;

/**
 * Wall-clock phases of one request, for a `Server-Timing` response header.
 *
 * Added with the volunteer-application speed work so "where did the time go?"
 * is read off the real request, not guessed: boot (framework, middleware,
 * routing, throttle) up to the controller, then one lap per phase the controller
 * marks, then the total. Durations only — no request data ever goes in — and the
 * header is emitted solely when the caller opts in with `X-Pf-Timing: 1`.
 */
final class PhaseTimer
{
    /** @var array<string, float> milliseconds per phase, in the order first marked */
    private array $phases = [];

    private float $last;

    private function __construct(private readonly float $start)
    {
        $this->last = microtime(true);
    }

    /** Starts the clock at the framework's own start when it is known, so `boot` is real. */
    public static function begin(): self
    {
        $timer = new self(defined('LARAVEL_START') ? (float) LARAVEL_START : microtime(true));
        $timer->phases['boot'] = ($timer->last - $timer->start) * 1000;

        return $timer;
    }

    /** Closes the phase that has been running since the previous lap (or since `begin`). */
    public function lap(string $phase): void
    {
        $now = microtime(true);
        $this->phases[$phase] = ($this->phases[$phase] ?? 0.0) + ($now - $this->last) * 1000;
        $this->last = $now;
    }

    /** Standard `Server-Timing` syntax: `boot;dur=12.3, validate;dur=4.1, total;dur=120.9`. */
    public function header(): string
    {
        $parts = [];
        foreach ($this->phases as $name => $ms) {
            $parts[] = sprintf('%s;dur=%.1f', $name, $ms);
        }
        $parts[] = sprintf('total;dur=%.1f', (microtime(true) - $this->start) * 1000);

        return implode(', ', $parts);
    }
}
