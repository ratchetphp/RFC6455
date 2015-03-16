<?php


namespace Ratchet\RFC6455\Handshake;


use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

class ResponseVerifier {
    public function verifyAll(Request $request, Response $response) {
        $passes = 0;

        $passes += (int)$this->verifyStatus($response->getStatusCode());
        $passes += (int)$this->verifyUpgrade($response->getHeader('Upgrade'));
        $passes += (int)$this->verifyConnection($response->getHeader('Connection'));
        $passes += (int)$this->verifySecWebSocketAccept(
            $response->getHeader('Sec-WebSocket-Accept'),
            $request->getHeader('sec-websocket-key')
            );

        return (4 == $passes);
    }

    public function verifyStatus($status) {
        return ($status == 101);
    }

    public function verifyUpgrade($upgrade) {
        return (strtolower($upgrade) == "websocket");
    }

    public function verifyConnection($connection) {
        return (strtolower($connection) == "upgrade");
    }

    public function verifySecWebSocketAccept($swa, $key) {
        return ($swa == $this->sign($key));
    }

    public function sign($key) {
        return base64_encode(sha1($key . Negotiator::GUID, true));
    }
}