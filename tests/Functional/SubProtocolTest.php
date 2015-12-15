<?php

namespace Ratchet\RFC6455\Test\Functional;

use Ratchet\RFC6455\Encoding\Validator;
use Ratchet\RFC6455\Handshake\ClientNegotiator;
use Ratchet\RFC6455\Handshake\Negotiator;

class SubProtocolTest extends TestCase
{
    public function testNoSubProtocol() {
        $cn = new ClientNegotiator();
        $sn = new Negotiator(new Validator());

        $request = $cn->getRequest();
        $response = $sn->handshake($request);

        $this->assertTrue($cn->validateResponse($response));
    }

    public function testSubProtocolClientOneServerZero() {
        $cn = new ClientNegotiator("ws://127.0.0.1:9001/", ["test.subprotocol"]);
        $sn = new Negotiator(new Validator());

        $this->assertFalse($cn->validateResponse(
            $sn->handshake($cn->getRequest())
        ));
    }

    public function testSubProtocolClientOneServerOne() {
        $cn = new ClientNegotiator("ws://127.0.0.1:9001/", ["test.subprotocol"]);
        $sn = new Negotiator(new Validator());
        $sn->setSupportedSubProtocols(["test.subprotocol"]);

        $this->assertTrue($cn->validateResponse(
            $sn->handshake($cn->getRequest())
        ));
    }

    public function testSubProtocolClientOneServerOneNoMatch() {
        $cn = new ClientNegotiator("ws://127.0.0.1:9001/", ["test.subprotocol"]);
        $sn = new Negotiator(new Validator());
        $sn->setSupportedSubProtocols(["xxxxxxx"]);

        $this->assertFalse($cn->validateResponse(
            $sn->handshake($cn->getRequest())
        ));
    }

    public function testSubProtocolClientTwoServerOne() {
        $cn = new ClientNegotiator("ws://127.0.0.1:9001/", ["test.subprotocol.2", "test.subprotocol"]);
        $sn = new Negotiator(new Validator());
        $sn->setSupportedSubProtocols(["test.subprotocol"]);

        $this->assertTrue($cn->validateResponse(
            $sn->handshake($cn->getRequest())
        ));
    }

    public function testSubProtocolClientTwoServerTwo() {
        $cn = new ClientNegotiator("ws://127.0.0.1:9001/", ["test.subprotocol.2", "test.subprotocol"]);
        $sn = new Negotiator(new Validator());
        $sn->setSupportedSubProtocols(["test.subprotocol", "test.subprotocol.2"]);

        $this->assertTrue($cn->validateResponse(
            $sn->handshake($cn->getRequest())
        ));
    }

    public function testSubProtocolClientZeroServerOne() {
        $cn = new ClientNegotiator("ws://127.0.0.1:9001/");
        $sn = new Negotiator(new Validator());
        $sn->setSupportedSubProtocols(["test.subprotocol"]);

        $this->assertFalse($cn->validateResponse(
            $sn->handshake($cn->getRequest())
        ));
    }
}