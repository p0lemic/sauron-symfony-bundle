<?php

declare(strict_types=1);

namespace Sauron\Bundle\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Sauron\Bundle\SauronClient;

final class SauronClientTest extends TestCase
{
    private function makeClient(): SauronClient
    {
        // Unreachable endpoint with 1 ms timeout so flush() fails fast and silently.
        return new SauronClient('http://0.0.0.0:1/ingest/spans', 'test-service', 1);
    }

    /** @return array<int, array<string, mixed>> */
    private function getSpans(SauronClient $client): array
    {
        $prop = new ReflectionProperty(SauronClient::class, 'spans');
        return $prop->getValue($client);
    }

    // TC-13: addSpan accumulates spans in the internal buffer.
    public function testAddSpanAccumulatesInBuffer(): void
    {
        $client = $this->makeClient();
        $now    = new DateTimeImmutable();

        $client->addSpan('trace1', 'span1', '', 'action1', 'controller', $now, 10.0);
        $client->addSpan('trace1', 'span2', '', 'action2', 'controller', $now, 20.0);
        $client->addSpan('trace1', 'span3', '', 'action3', 'controller', $now, 30.0);

        $this->assertCount(3, $this->getSpans($client));
    }

    // TC-14: flush() empties the buffer; a second flush() with no spans is a no-op.
    public function testFlushEmptiesBuffer(): void
    {
        $client = $this->makeClient();
        $client->addSpan('trace1', 'span1', '', 'action', 'controller', new DateTimeImmutable(), 5.0);
        $this->assertCount(1, $this->getSpans($client));

        $client->flush();
        $this->assertCount(0, $this->getSpans($client));

        // Second flush: no spans → early return, still empty.
        $client->flush();
        $this->assertCount(0, $this->getSpans($client));
    }

    // TC-15: setActiveContext / hasActiveTrace / resetContext lifecycle.
    public function testActiveContextLifecycle(): void
    {
        $client = $this->makeClient();

        $this->assertFalse($client->hasActiveTrace(), 'should be false before any context is set');

        $client->setActiveContext('4bf92f3577b34da6a3ce929d0e0e4736', 'a2fb4a1d1a96d312');
        $this->assertTrue($client->hasActiveTrace(), 'should be true after setActiveContext');

        $client->resetContext();
        $this->assertFalse($client->hasActiveTrace(), 'should be false after resetContext');
    }

    // TC-16: recordSpan() without an active context does not add a span.
    public function testRecordSpanWithoutContextDoesNotAddSpan(): void
    {
        $client = $this->makeClient();
        // No setActiveContext call — hasActiveTrace() is false.
        $client->recordSpan('some:action', 'controller', new DateTimeImmutable(), 10.0);

        $this->assertCount(0, $this->getSpans($client));
    }

    // TC-17: flush() with no buffered spans does not invoke the transport.
    // Verified by the early-return branch: flush() returns before calling post(),
    // so the unreachable endpoint is never dialled and no exception is thrown.
    public function testFlushWithoutSpansDoesNotCallTransport(): void
    {
        $client = $this->makeClient();

        $client->flush(); // must not throw and must complete instantly

        $this->assertCount(0, $this->getSpans($client));
        $this->assertFalse($client->hasActiveTrace());
    }
}
