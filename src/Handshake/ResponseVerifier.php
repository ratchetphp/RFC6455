<?php


namespace Ratchet\RFC6455\Handshake;


use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

class ResponseVerifier {
    public function verifyAll(Request $request, Response $response) {
        $passes = 0;
        $required = 5;

        $passes += (int)$this->verifyStatus($response->getStatusCode());
        $passes += (int)$this->verifyUpgrade($response->getHeader('Upgrade'));
        $passes += (int)$this->verifyConnection($response->getHeader('Connection'));
        $passes += (int)$this->verifySecWebSocketAccept(
            $response->getHeader('Sec-WebSocket-Accept'),
            $request->getHeader('sec-websocket-key')
            );
        $passes += (int)$this->verifySubProtocol($request, $response);

        return ($required == $passes);
    }

    public function verifySubProtocol(Request $request, Response $response) {
        $subProtocolRequest = $request->getHeader('Sec-WebSocket-Protocol');
        if (empty($subProtocolRequest)) {
            return true;
        }

        $subProtocolResponse = $response->getHeader('Sec-WebSocket-Protocol');
        if (count($subProtocolResponse) !== 1) {
            // there should be exactly one subprotocol sent back if we requested
            return false;
        }

        if (in_array($subProtocolResponse[0], $subProtocolRequest)) {
            // the response is one of the requested subprotocols
            return true;
        }

        return false;
    }

    public function verifyStatus($status) {
        return ($status == 101);
    }

    public function verifyUpgrade(array $upgrade) {
        return (in_array('websocket', array_map('strtolower', $upgrade)));
    }

    public function verifyConnection(array $connection) {
        return (in_array('upgrade', array_map('strtolower', $connection)));
    }

    public function verifySecWebSocketAccept($swa, $key) {
        return (
            1 === count($swa) &&
            1 === count($key) &&
            $swa[0] == $this->sign($key[0]));
    }

    public function sign($key) {
        return base64_encode(sha1($key . Negotiator::GUID, true));
    }
}