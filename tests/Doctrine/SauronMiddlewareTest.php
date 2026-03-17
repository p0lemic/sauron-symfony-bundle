<?php

declare(strict_types=1);

namespace Sauron\Bundle\Tests\Doctrine;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Sauron\Bundle\Doctrine\SauronConnection;
use Sauron\Bundle\SauronClient;

final class SauronMiddlewareTest extends TestCase
{
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

    private function makeConnection(SauronClient $client): SauronConnection
    {
        $inner      = $this->createMock(\Doctrine\DBAL\Driver\Connection::class);
        $mockResult = $this->createMock(\Doctrine\DBAL\Driver\Result::class);
        $inner->method('query')->willReturn($mockResult);
        $inner->method('exec')->willReturn(1);
        return new SauronConnection($inner, $client);
    }

    // TC-23: query() without an active trace context → no span is recorded.
    public function testQueryWithoutActiveTraceProducesNoSpan(): void
    {
        $client = $this->makeClient();
        // No setActiveContext call — hasActiveTrace() is false.
        $conn = $this->makeConnection($client);
        $conn->query('SELECT * FROM users');

        $this->assertCount(0, $this->getSpans($client));
    }

    // TC-24: query() with active trace → db span with normalized SQL.
    public function testQueryWithActiveTraceCreatesDbSpan(): void
    {
        $client = $this->makeClient();
        $client->setActiveContext('4bf92f3577b34da6a3ce929d0e0e4736', 'a2fb4a1d1a96d312');

        $conn = $this->makeConnection($client);
        $conn->query('SELECT * FROM users WHERE id = 1');

        $spans = $this->getSpans($client);
        $this->assertCount(1, $spans);
        $this->assertEquals('db', $spans[0]['kind']);
        $this->assertEquals('SELECT users', $spans[0]['name']);
        $attrs = (array) $spans[0]['attributes'];
        $this->assertArrayHasKey('db.query', $attrs);
        $this->assertStringContainsString('?', (string) $attrs['db.query']);
    }

    // TC-25: exec() that throws an exception → span with status=error is still recorded.
    public function testExecExceptionProducesErrorSpan(): void
    {
        $client = $this->makeClient();
        $client->setActiveContext('4bf92f3577b34da6a3ce929d0e0e4736', 'a2fb4a1d1a96d312');

        $inner = $this->createMock(\Doctrine\DBAL\Driver\Connection::class);
        $inner->method('exec')->willThrowException(new \RuntimeException('DB error'));
        $conn = new SauronConnection($inner, $client);

        try {
            $conn->exec('INSERT INTO logs VALUES (1)');
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException) {
            // Exception is expected — verify the span was still recorded.
        }

        $spans = $this->getSpans($client);
        $this->assertCount(1, $spans);
        $this->assertEquals('error', $spans[0]['status']);
    }

    // TC-26: normalize() replaces string literals and numeric literals with placeholders.
    public function testNormalizeReplacesLiterals(): void
    {
        $sql        = "SELECT * FROM t WHERE id = 42 AND name = 'foo'";
        $normalized = SauronConnection::normalize($sql);

        $this->assertEquals("SELECT * FROM t WHERE id = ? AND name = '?'", $normalized);
    }
}
