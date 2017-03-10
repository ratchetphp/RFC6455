<?php

namespace Ratchet\RFC6455\Handshake;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class PermessageDeflateOptions
{
    private $deflate = false; // disable by default

    private $server_no_context_takeover;
    private $client_no_context_takeover;
    private $server_max_window_bits;
    private $client_max_window_bits;

    private function __construct() { }

    /**
     * https://tools.ietf.org/html/rfc6455#section-9.1
     * https://tools.ietf.org/html/rfc7692#section-7
     *
     * @param MessageInterface $requestOrResponse
     * @return PermessageDeflateOptions[]
     * @throws \Exception
     */
    public static function fromRequestOrResponse(MessageInterface $requestOrResponse) {
        $optionSets = [];

        $extHeader = preg_replace('/\s+/', '', join(', ', $requestOrResponse->getHeader('Sec-Websocket-Extensions')));

        $configurationRequests = explode(',', $extHeader);
        foreach ($configurationRequests as $configurationRequest) {
            $parts = explode(';', $configurationRequest);
            if (count($parts) == 0) {
                continue;
            }

            if ($parts[0] !== 'permessage-deflate') {
                continue;
            }

            array_shift($parts);
            $options = new static();
            $options->deflate = true;
            foreach ($parts as $part) {
                $kv = explode('=', $part);
                $key = $kv[0];
                $value = count($kv) > 1 ? $kv[1] : null;

                $validBits = ['8', '9', '10', '11', '12', '13', '14', '15'];
                switch ($key) {
                    case "server_no_context_takeover":
                    case "client_no_context_takeover":
                        if ($value !== null) {
                            throw new \Exception($key . ' must not have a value.');
                        }
                        $value = true;
                        break;
                    case "server_max_window_bits":
                        if (!in_array($value, $validBits)) {
                            throw new \Exception($key . ' must have a value between 8 and 15.');
                        }
                        break;
                    case "client_max_window_bits":
                        if ($value === null) {
                            $value = '15';
                        }
                        if (!in_array($value, $validBits)) {
                            throw new \Exception($key . ' must have no value or a value between 8 and 15.');
                        }
                        break;
                    default:
                        throw new \Exception('Option "' . $key . '"is not valid for this extension');
                }

                if ($options->$key !== null) {
                    throw new \Exception('Key specified more than once. Connection must be declined.');
                }

                $options->$key = $value;
            }

            if ($options->getClientMaxWindowBits() === null) {
                $options->client_max_window_bits = 15;
            }

            if ($options->getServerMaxWindowBits() === null) {
                $options->server_max_window_bits = 15;
            }

            $optionSets[] = $options;
        }

        // always put a disabled on the end
        $optionSets[] = new static();

        return $optionSets;
    }

    public static function createDisabled() {
        return new static();
    }

    public static function validateResponseToRequest(ResponseInterface $response, RequestInterface $request) {
        $requestOptions = static::fromRequestOrResponse($request);
        $responseOptions = static::fromRequestOrResponse($response);
    }

    /**
     * @return mixed
     */
    public function getServerNoContextTakeover()
    {
        return $this->server_no_context_takeover;
    }

    /**
     * @return mixed
     */
    public function getClientNoContextTakeover()
    {
        return $this->client_no_context_takeover;
    }

    /**
     * @return mixed
     */
    public function getServerMaxWindowBits()
    {
        return $this->server_max_window_bits;
    }

    /**
     * @return mixed
     */
    public function getClientMaxWindowBits()
    {
        return $this->client_max_window_bits;
    }

    /**
     * @return bool
     */
    public function getDeflate()
    {
        return $this->deflate;
    }

    /**
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    public function addHeaderToResponse(ResponseInterface $response)
    {
        if (!$this->deflate) {
            return $response;
        }

        $header = 'permessage-deflate';
        if ($this->client_max_window_bits != 15) {
            $header .= '; client_max_window_bits='. $this->client_max_window_bits;
        }
        if ($this->client_no_context_takeover) {
            $header .= '; client_no_context_takeover';
        }
        if ($this->server_max_window_bits != 15) {
            $header .= '; server_max_window_bits=' . $this->server_max_window_bits;
        }
        if ($this->server_no_context_takeover) {
            $header .= '; server_no_context_takeover';
        }

        return $response->withAddedHeader('Sec-Websocket-Extensions', $header);
    }
}