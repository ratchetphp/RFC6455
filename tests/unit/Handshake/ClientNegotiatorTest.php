<?php

namespace Ratchet\RFC6455\Test\Unit\Handshake;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Uri;
use Ratchet\RFC6455\Handshake\ClientNegotiator;
use PHPUnit\Framework\TestCase;

/**
 * @covers Ratchet\RFC6455\Handshake\ClientNegotiator
 */
class ClientNegotiatorTest extends TestCase
{
    public function testGenerateRequest(): void {
        $negotiator = new ClientNegotiator(new HttpFactory());

        $request = $negotiator->generateRequest(new Uri('ws://127.0.0.1:9001/'));

        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('Upgrade', $request->getHeaderLine('Connection'));
        $this->assertEquals('websocket', $request->getHeaderLine('Upgrade'));
        $this->assertEquals('13', $request->getHeaderLine('Sec-WebSocket-Version'));
    }
}
