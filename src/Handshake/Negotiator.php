<?php
namespace Ratchet\RFC6455\Handshake;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;
use Ratchet\RFC6455\Encoding\ValidatorInterface;

/**
 * The latest version of the WebSocket protocol
 * @todo Unicode: return mb_convert_encoding(pack("N",$u), mb_internal_encoding(), 'UCS-4BE');
 */
class Negotiator implements NegotiatorInterface {
    /**
     * @var \Ratchet\RFC6455\Handshake\RequestVerifier
     */
    private $verifier;

    /**
     * @var \Ratchet\RFC6455\Encoding\ValidatorInterface
     */
    private $validator;

    /**
     * A lookup of the valid close codes that can be sent in a frame
     * @var array
     * @deprecated
     */
    private $closeCodes = [];

    public function __construct(ValidatorInterface $validator) {
        $this->verifier = new RequestVerifier;

        $this->setCloseCodes();

        $this->validator = $validator;
    }

    /**
     * {@inheritdoc}
     */
    public function isProtocol(ServerRequestInterface $request) {
        $version = (int)(string)$request->getHeader('Sec-WebSocket-Version');

        return ($this->getVersionNumber() === $version);
    }

    /**
     * {@inheritdoc}
     */
    public function getVersionNumber() {
        return RequestVerifier::VERSION;
    }

    /**
     * {@inheritdoc}
     */
    public function handshake(ServerRequestInterface $request) {
        if (true !== $this->verifier->verifyAll($request)) {
            return new Response(400);
        }

        return new Response(101, [
            'Upgrade'              => 'websocket'
          , 'Connection'           => 'Upgrade'
          , 'Sec-WebSocket-Accept' => $this->sign((string)$request->getHeader('Sec-WebSocket-Key'))
        ]);
    }

    /**
     * @deprecated - The logic belons somewhere else
     * @param \Ratchet\WebSocket\Version\RFC6455\Connection $from
     * @param string                                        $data
     */
    public function onMessage(ConnectionInterface $from, $data) {

    }

    /**
     * Used when doing the handshake to encode the key, verifying client/server are speaking the same language
     * @param  string $key
     * @return string
     * @internal
     */
    public function sign($key) {
        return base64_encode(sha1($key . static::GUID, true));
    }
}
