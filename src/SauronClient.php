<?php

declare(strict_types=1);

namespace Sauron\Bundle;

/**
 * Collects spans during the current request and flushes them to the Sauron
 * ingest endpoint on kernel.terminate.
 *
 * Thread safety: PHP is single-threaded per request, so no locking is needed.
 */
final class SauronClient
{
    /** @var array<int, array<string, mixed>> */
    private array $spans = [];

    /** Active trace context set by TraceSubscriber at kernel.controller time. */
    private string $activeTraceId   = '';
    private string $activeParentSpanId = '';

    public function __construct(
        private readonly string $endpoint,
        private readonly string $serviceName,
        private readonly int    $timeoutMs = 2000,
    ) {}

    // ── Trace context ─────────────────────────────────────────────────────────

    /**
     * Set the active trace context for the current request.
     * Called by TraceSubscriber on kernel.controller.
     */
    public function setActiveContext(string $traceId, string $parentSpanId): void
    {
        $this->activeTraceId      = $traceId;
        $this->activeParentSpanId = $parentSpanId;
    }

    /**
     * Update the active parent span (e.g. when entering a controller span).
     */
    public function setActiveParentSpan(string $spanId): void
    {
        $this->activeParentSpanId = $spanId;
    }

    public function getActiveTraceId(): string      { return $this->activeTraceId; }
    public function getActiveParentSpanId(): string { return $this->activeParentSpanId; }
    public function hasActiveTrace(): bool          { return $this->activeTraceId !== ''; }

    /**
     * Reset context (called on terminate to prepare for potential next request in FPM).
     */
    public function resetContext(): void
    {
        $this->activeTraceId      = '';
        $this->activeParentSpanId = '';
    }

    // ── Span recording ────────────────────────────────────────────────────────

    /**
     * Add a span to the current batch.
     *
     * @param array<string, string> $attributes
     */
    public function addSpan(
        string $traceId,
        string $spanId,
        string $parentSpanId,
        string $name,
        string $kind,
        \DateTimeInterface $startTime,
        float $durationMs,
        array $attributes = [],
        string $status = 'ok',
    ): void {
        $this->spans[] = [
            'trace_id'       => $traceId,
            'span_id'        => $spanId,
            'parent_span_id' => $parentSpanId,
            'name'           => $name,
            'kind'           => $kind,
            'start_time'     => $startTime->format(\DateTimeInterface::RFC3339_EXTENDED),
            'duration_ms'    => round($durationMs, 3),
            'attributes'     => (object) $attributes,  // force JSON object even if empty
            'status'         => $status,
        ];
    }

    /**
     * Convenience: record a span using the currently active trace context.
     *
     * @param array<string, string> $attributes
     */
    public function recordSpan(
        string $name,
        string $kind,
        \DateTimeInterface $startTime,
        float $durationMs,
        array $attributes = [],
        string $status = 'ok',
        ?string $parentSpanId = null,
    ): void {
        if (!$this->hasActiveTrace()) {
            return;
        }
        $this->addSpan(
            traceId:      $this->activeTraceId,
            spanId:       self::newSpanId(),
            parentSpanId: $parentSpanId ?? $this->activeParentSpanId,
            name:         $name,
            kind:         $kind,
            startTime:    $startTime,
            durationMs:   $durationMs,
            attributes:   $attributes,
            status:       $status,
        );
    }

    // ── Flush ─────────────────────────────────────────────────────────────────

    /**
     * Send buffered spans to Sauron and clear the buffer.
     * Called on kernel.terminate (after response is sent to the client).
     */
    public function flush(): void
    {
        if ($this->spans === []) {
            $this->resetContext();
            return;
        }

        $payload = json_encode(
            array_values($this->spans),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT ^ JSON_FORCE_OBJECT
        );
        $this->spans = [];
        $this->resetContext();

        if ($payload === false || $payload === '[]') {
            return;
        }

        $this->post($payload);
    }

    // ── ID generation ─────────────────────────────────────────────────────────

    /** Generate a W3C-compliant 32-char trace ID. */
    public static function newTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Generate a W3C-compliant 16-char span ID. */
    public static function newSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    // ── HTTP transport ────────────────────────────────────────────────────────

    private function post(string $body): void
    {
        $timeoutSec = $this->timeoutMs / 1000.0;

        if (\function_exists('curl_init')) {
            $this->postViaCurl($body, $timeoutSec);
        } else {
            $this->postViaStream($body, (int) ceil($timeoutSec));
        }
    }

    private function postViaCurl(string $body, float $timeout): void
    {
        $ch = curl_init($this->endpoint);
        if ($ch === false) {
            return;
        }
        curl_setopt_array($ch, [
            \CURLOPT_POST           => true,
            \CURLOPT_POSTFIELDS     => $body,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT_MS     => (int) ($timeout * 1000),
            \CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Sauron-Service: ' . $this->serviceName,
            ],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private function postViaStream(string $body, int $timeoutSec): void
    {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nX-Sauron-Service: {$this->serviceName}",
                'content'       => $body,
                'timeout'       => $timeoutSec,
                'ignore_errors' => true,
            ],
        ]);
        @file_get_contents($this->endpoint, false, $ctx);
    }
}
