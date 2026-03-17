<?php

declare(strict_types=1);

namespace Sauron\Bundle\EventSubscriber;

use Sauron\Bundle\SauronClient;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Creates "controller" spans and wires up the trace context for the current request.
 *
 * Lifecycle:
 *   kernel.controller  → extract W3C traceparent, start controller span timer
 *   kernel.response    → close controller span, push to SauronClient buffer
 *   kernel.terminate   → flush all buffered spans to Sauron (after response is sent)
 *
 * The SauronClient holds the active trace_id + current_parent_span_id so that
 * nested instrumentation (e.g. Doctrine middleware) can record child spans
 * without needing access to the Request object.
 */
final class TraceSubscriber implements EventSubscriberInterface
{
    private const ATTR_CTRL_SPAN  = '_sauron_ctrl_span_id';
    private const ATTR_CTRL_START = '_sauron_ctrl_start';
    private const ATTR_HAS_ERROR  = '_sauron_has_error';

    public function __construct(private readonly SauronClient $client) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onController', 0],
            KernelEvents::RESPONSE   => ['onResponse', 0],
            KernelEvents::EXCEPTION  => ['onException', 0],
            KernelEvents::TERMINATE  => ['onTerminate', -1024],
        ];
    }

    // ── Event handlers ────────────────────────────────────────────────────────

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request     = $event->getRequest();
        $traceparent = $request->headers->get('traceparent', '');
        [$traceId, $proxySpanId] = $this->parseTraceparent($traceparent);

        if ($traceId === '') {
            return;
        }

        $ctrlSpanId = SauronClient::newSpanId();

        // Set active context on the client so Doctrine middleware can read it.
        $this->client->setActiveContext($traceId, $proxySpanId);
        $this->client->setActiveParentSpan($ctrlSpanId);

        $request->attributes->set(self::ATTR_CTRL_SPAN,  $ctrlSpanId);
        $request->attributes->set(self::ATTR_CTRL_START, microtime(true));
        $request->attributes->set(self::ATTR_HAS_ERROR,  false);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request   = $event->getRequest();
        $spanId    = $request->attributes->get(self::ATTR_CTRL_SPAN);
        $startedAt = $request->attributes->get(self::ATTR_CTRL_START);

        if ($spanId === null || $startedAt === null || !$this->client->hasActiveTrace()) {
            return;
        }

        $durationMs = (microtime(true) - $startedAt) * 1000;
        $startTime  = $this->floatToDateTime($startedAt);
        $statusCode = $event->getResponse()->getStatusCode();
        $hasError   = $request->attributes->get(self::ATTR_HAS_ERROR, false);
        $status     = ($statusCode >= 400 || $hasError) ? 'error' : 'ok';

        // Restore parent span to proxy span before recording the controller span.
        $traceId   = $this->client->getActiveTraceId();
        $proxySpan = $this->getProxySpanId($request);

        $this->client->addSpan(
            traceId:      $traceId,
            spanId:       $spanId,
            parentSpanId: $proxySpan,
            name:         $this->resolveControllerName($request),
            kind:         'controller',
            startTime:    $startTime,
            durationMs:   $durationMs,
            attributes:   [
                'http.method'      => $request->getMethod(),
                'http.status_code' => (string) $statusCode,
                'http.route'       => (string) $request->attributes->get('_route', ''),
            ],
            status: $status,
        );
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $event->getRequest()->attributes->set(self::ATTR_HAS_ERROR, true);
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $this->client->flush();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Parse a W3C traceparent header: 00-{traceId(32)}-{spanId(16)}-{flags(2)}
     *
     * @return array{string, string}
     */
    private function parseTraceparent(string $header): array
    {
        if ($header === '') {
            return ['', ''];
        }
        $parts = explode('-', $header);
        if (\count($parts) !== 4) {
            return ['', ''];
        }
        [, $traceId, $parentId] = $parts;
        if (\strlen($traceId) !== 32 || \strlen($parentId) !== 16) {
            return ['', ''];
        }
        // Reject all-zero IDs (invalid per W3C spec).
        if (ltrim($traceId, '0') === '' || ltrim($parentId, '0') === '') {
            return ['', ''];
        }
        return [$traceId, $parentId];
    }

    private function getProxySpanId(\Symfony\Component\HttpFoundation\Request $request): string
    {
        $traceparent = $request->headers->get('traceparent', '');
        [, $proxySpanId] = $this->parseTraceparent($traceparent);
        return $proxySpanId;
    }

    private function resolveControllerName(\Symfony\Component\HttpFoundation\Request $request): string
    {
        $controller = $request->attributes->get('_controller', '');
        if (\is_string($controller) && $controller !== '') {
            return $controller;
        }
        if (\is_array($controller) && isset($controller[0], $controller[1])) {
            $class = \is_object($controller[0]) ? $controller[0]::class : (string) $controller[0];
            return $class . '::' . $controller[1];
        }
        return 'controller';
    }

    private function floatToDateTime(float $microtime): \DateTimeImmutable
    {
        $dt = \DateTimeImmutable::createFromFormat('U.u', number_format($microtime, 6, '.', ''));
        return $dt !== false ? $dt : new \DateTimeImmutable();
    }
}
