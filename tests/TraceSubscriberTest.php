<?php

declare(strict_types=1);

namespace Sauron\Bundle\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Sauron\Bundle\EventSubscriber\TraceSubscriber;
use Sauron\Bundle\SauronClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class TraceSubscriberTest extends TestCase
{
    /** Valid W3C traceparent: version-traceId(32)-spanId(16)-flags */
    private const TRACEPARENT = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
    private const TRACE_ID    = '4bf92f3577b34da6a3ce929d0e0e4736';
    private const PROXY_SPAN  = '00f067aa0ba902b7';

    private function makeClient(): SauronClient
    {
        return new SauronClient('http://0.0.0.0:1/ingest/spans', 'test-service', 1);
    }

    /** @return array<int, array<string, mixed>> */
    private function getSpans(SauronClient $client): array
    {
        $prop = new ReflectionProperty(SauronClient::class, 'spans');
        return $prop->getValue($client);
    }

    private function makeKernel(): HttpKernelInterface
    {
        return $this->createMock(HttpKernelInterface::class);
    }

    private function dispatchController(
        TraceSubscriber $sub,
        HttpKernelInterface $kernel,
        Request $request,
        int $type = HttpKernelInterface::MAIN_REQUEST,
    ): void {
        $sub->onController(new ControllerEvent($kernel, fn () => '', $request, $type));
    }

    private function dispatchResponse(
        TraceSubscriber $sub,
        HttpKernelInterface $kernel,
        Request $request,
        int $statusCode = 200,
        int $type = HttpKernelInterface::MAIN_REQUEST,
    ): void {
        $sub->onResponse(new ResponseEvent($kernel, $request, $type, new Response('', $statusCode)));
    }

    // TC-18: Request without traceparent header → no span is created.
    public function testNoTraceparentProducesNoSpan(): void
    {
        $client = $this->makeClient();
        $sub    = new TraceSubscriber($client);
        $kernel = $this->makeKernel();
        $request = new Request(); // no traceparent header

        $this->dispatchController($sub, $kernel, $request);
        $this->dispatchResponse($sub, $kernel, $request);

        $this->assertCount(0, $this->getSpans($client));
        $this->assertFalse($client->hasActiveTrace());
    }

    // TC-19: Request with valid traceparent → controller span with correct parent.
    public function testValidTraceparentCreatesControllerSpan(): void
    {
        $client  = $this->makeClient();
        $sub     = new TraceSubscriber($client);
        $kernel  = $this->makeKernel();
        $request = new Request();
        $request->headers->set('traceparent', self::TRACEPARENT);
        $request->attributes->set('_controller', 'App\\Controller\\UserController::index');

        $this->dispatchController($sub, $kernel, $request);
        $this->dispatchResponse($sub, $kernel, $request, 200);

        $spans = $this->getSpans($client);
        $this->assertCount(1, $spans);
        $this->assertEquals('controller', $spans[0]['kind']);
        $this->assertEquals(self::TRACE_ID,   $spans[0]['trace_id']);
        $this->assertEquals(self::PROXY_SPAN, $spans[0]['parent_span_id']);
        $this->assertEquals('ok', $spans[0]['status']);
    }

    // TC-20: Response with 5xx status → span status=error.
    public function testResponse5xxProducesErrorSpan(): void
    {
        $client  = $this->makeClient();
        $sub     = new TraceSubscriber($client);
        $kernel  = $this->makeKernel();
        $request = new Request();
        $request->headers->set('traceparent', self::TRACEPARENT);

        $this->dispatchController($sub, $kernel, $request);
        $this->dispatchResponse($sub, $kernel, $request, 500);

        $spans = $this->getSpans($client);
        $this->assertCount(1, $spans);
        $this->assertEquals('error', $spans[0]['status']);
    }

    // TC-21: onException sets the error flag, causing the span to have status=error
    //        even when the final HTTP response code is 200.
    public function testOnExceptionMarksSpanAsError(): void
    {
        $client  = $this->makeClient();
        $sub     = new TraceSubscriber($client);
        $kernel  = $this->makeKernel();
        $request = new Request();
        $request->headers->set('traceparent', self::TRACEPARENT);

        $this->dispatchController($sub, $kernel, $request);

        $sub->onException(
            new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('boom'))
        );

        // Response is 200, but the error flag was set by onException.
        $this->dispatchResponse($sub, $kernel, $request, 200);

        $spans = $this->getSpans($client);
        $this->assertCount(1, $spans);
        $this->assertEquals('error', $spans[0]['status']);
    }

    // TC-22: Sub-requests (isMainRequest=false) are ignored entirely.
    public function testSubRequestsAreIgnored(): void
    {
        $client  = $this->makeClient();
        $sub     = new TraceSubscriber($client);
        $kernel  = $this->makeKernel();
        $request = new Request();
        $request->headers->set('traceparent', self::TRACEPARENT);

        $this->dispatchController($sub, $kernel, $request, HttpKernelInterface::SUB_REQUEST);
        $this->dispatchResponse($sub, $kernel, $request, 200, HttpKernelInterface::SUB_REQUEST);

        $this->assertFalse($client->hasActiveTrace());
        $this->assertCount(0, $this->getSpans($client));
    }
}
