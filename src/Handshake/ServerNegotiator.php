<?php
namespace Ratchet\RFC6455\Handshake;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;

/**
 * The latest version of the WebSocket protocol
 * @todo Unicode: return mb_convert_encoding(pack("N",$u), mb_internal_encoding(), 'UCS-4BE');
 */
class ServerNegotiator implements NegotiatorInterface {
    /**
     * @var \Ratchet\RFC6455\Handshake\RequestVerifier
     */
    private $verifier;

    /**
     * @var ResponseFactoryInterface
     */
    private $responseFactory;

    private $_supportedSubProtocols = [];

    private $_strictSubProtocols = false;

    public function __construct(RequestVerifier $requestVerifier, ResponseFactoryInterface $responseFactory) {
        $this->verifier = $requestVerifier;
        $this->responseFactory = $responseFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function isProtocol(RequestInterface $request) {
        return $this->verifier->verifyVersion($request->getHeader('Sec-WebSocket-Version'));
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
    public function handshake(RequestInterface $request) {
        $response = $this->responseFactory->createResponse();
        if (true !== $this->verifier->verifyMethod($request->getMethod())) {
            return $response->withHeader('Allow', 'GET')->withStatus(405);
        }

        if (true !== $this->verifier->verifyHTTPVersion($request->getProtocolVersion())) {
            return $response->withStatus(505);
        }

        if (true !== $this->verifier->verifyRequestURI($request->getUri()->getPath())) {
            return $response->withStatus(400);
        }

        if (true !== $this->verifier->verifyHost($request->getHeader('Host'))) {
            return $response->withStatus(400);
        }

        $upgradeResponse = $response
            ->withHeader('Connection'           , 'Upgrade')
            ->withHeader('Upgrade'              , 'websocket')
            ->withHeader('Sec-WebSocket-Version', $this->getVersionNumber());

        if (count($this->_supportedSubProtocols) > 0) {
            $upgradeResponse = $upgradeResponse->withHeader(
                'Sec-WebSocket-Protocol', implode(', ', array_keys($this->_supportedSubProtocols))
            );
        }
        if (true !== $this->verifier->verifyUpgradeRequest($request->getHeader('Upgrade'))) {
            return $upgradeResponse->withStatus(426, 'Upgrade header MUST be provided');
        }

        if (true !== $this->verifier->verifyConnection($request->getHeader('Connection'))) {
            return $response->withStatus(400, 'Connection Upgrade MUST be requested');
        }

        if (true !== $this->verifier->verifyKey($request->getHeader('Sec-WebSocket-Key'))) {
            return $response->withStatus(400, 'Invalid Sec-WebSocket-Key');
        }

        if (true !== $this->verifier->verifyVersion($request->getHeader('Sec-WebSocket-Version'))) {
            return $upgradeResponse->withStatus(426);
        }

        $subProtocols = $request->getHeader('Sec-WebSocket-Protocol');
        if (count($subProtocols) > 0 || (count($this->_supportedSubProtocols) > 0 && $this->_strictSubProtocols)) {
            $subProtocols = array_map('trim', explode(',', implode(',', $subProtocols)));

            $match = array_reduce($subProtocols, function($accumulator, $protocol) {
                return $accumulator ?: (isset($this->_supportedSubProtocols[$protocol]) ? $protocol : null);
            }, null);

            if ($this->_strictSubProtocols && null === $match) {
                return $upgradeResponse->withStatus(426, 'No Sec-WebSocket-Protocols requested supported');
            }

            if (null !== $match) {
                $response = $response->withHeader('Sec-WebSocket-Protocol', $match);
            }
        }
        return $response
            ->withStatus(101)
            ->withHeader('Upgrade'             , 'websocket')
            ->withHeader('Connection'          , 'Upgrade')
            ->withHeader('Sec-WebSocket-Accept', $this->sign((string)$request->getHeader('Sec-WebSocket-Key')[0]))
            ->withHeader('X-Powered-By'        , 'Ratchet');
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

    /**
     * @param array $protocols
     */
    function setSupportedSubProtocols(array $protocols) {
        $this->_supportedSubProtocols = array_flip($protocols);
    }

    /**
     * If enabled and support for a subprotocol has been added handshake
     *  will not upgrade if a match between request and supported subprotocols
     * @param boolean $enable
     * @todo Consider extending this interface and moving this there.
     *       The spec does says the server can fail for this reason, but
     * it is not a requirement. This is an implementation detail.
     */
    function setStrictSubProtocolCheck($enable) {
        $this->_strictSubProtocols = (boolean)$enable;
    }
}
