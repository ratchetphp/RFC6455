<?php
namespace Ratchet\RFC6455\Handshake;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use GuzzleHttp\Psr7\Request;

class ClientNegotiator {
    /**
     * @var ResponseVerifier
     */
    private $verifier;

    /**
     * @var \Psr\Http\Message\RequestInterface
     */
    private $defaultHeader;

    function __construct($enablePerMessageDeflate = false) {
        $this->verifier = new ResponseVerifier;

        $this->defaultHeader = new Request('GET', '', [
            'Connection'            => 'Upgrade'
          , 'Upgrade'               => 'websocket'
          , 'Sec-WebSocket-Version' => $this->getVersion()
          , 'User-Agent'            => "Ratchet"
        ]);

        if ($enablePerMessageDeflate && (version_compare(PHP_VERSION, '7.0.15', '<') || version_compare(PHP_VERSION, '7.1.0', '='))) {
            $enablePerMessageDeflate = false;
        }
        if ($enablePerMessageDeflate && !function_exists('deflate_add')) {
            $enablePerMessageDeflate = false;
        }

        if ($enablePerMessageDeflate) {
            $this->defaultHeader = $this->defaultHeader->withAddedHeader(
                'Sec-WebSocket-Extensions',
                'permessage-deflate');
        }
    }

    public function generateRequest(UriInterface $uri) {
        return $this->defaultHeader->withUri($uri)
            ->withHeader("Sec-WebSocket-Key", $this->generateKey());
    }

    public function validateResponse(RequestInterface $request, ResponseInterface $response) {
        return $this->verifier->verifyAll($request, $response);
    }

    public function generateKey() {
        $chars     = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwzyz1234567890+/=';
        $charRange = strlen($chars) - 1;
        $key       = '';
        for ($i = 0; $i < 16; $i++) {
            $key .= $chars[mt_rand(0, $charRange)];
        }

        return base64_encode($key);
    }

    public function getVersion() {
        return 13;
    }
}
