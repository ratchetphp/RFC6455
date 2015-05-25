<?php
use React\Promise\Deferred;
use Ratchet\RFC6455\Messaging\Protocol\Frame;
use Ratchet\RFC6455\Messaging\Protocol\Message;

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/AbConnectionContext.php';

define('AGENT', 'RatchetRFC/0.0.0');

$testServer = "127.0.0.1";


class EmConnectionContext extends AbConnectionContext implements \Evenement\EventEmitterInterface, Ratchet\RFC6455\Messaging\Streaming\ContextInterface {
    use \Evenement\EventEmitterTrait;

    public function onMessage(\Ratchet\RFC6455\Messaging\Protocol\MessageInterface $msg) {
        $this->emit('message', [$msg]);
    }

    public function sendMessage(Frame $frame) {
        if ($this->maskPayload) {
            $frame->maskPayload();
        }
        $this->_conn->write($frame->getContents());
    }

    public function close($closeCode = Frame::CLOSE_NORMAL) {
        $closeFrame = new Frame(pack('n', $closeCode), true, Frame::OP_CLOSE);
        $closeFrame->maskPayload();
        $this->_conn->end($closeFrame->getContents());
    }
}

$loop = React\EventLoop\Factory::create();

$dnsResolverFactory = new React\Dns\Resolver\Factory();
$dnsResolver = $dnsResolverFactory->createCached('8.8.8.8', $loop);

$factory = new \React\SocketClient\Connector($loop, $dnsResolver);

function getTestCases() {
    global $factory;
    global $testServer;

    $deferred = new Deferred();

    $factory->create($testServer, 9001)->then(function (\React\Stream\Stream $stream) use ($deferred) {
        $cn = new \Ratchet\RFC6455\Handshake\ClientNegotiator("/getCaseCount");
        $cnRequest = $cn->getRequest();

        $rawResponse = "";
        $response = null;

        $ms = new \Ratchet\RFC6455\Messaging\Streaming\MessageStreamer(new \Ratchet\RFC6455\Encoding\Validator(), true);

        /** @var EmConnectionContext $context */
        $context = null;

        $stream->on('data', function ($data) use ($stream, &$rawResponse, &$response, $ms, $cn, $deferred, &$context) {
            if ($response === null) {
                $rawResponse .= $data;
                $pos = strpos($rawResponse, "\r\n\r\n");
                if ($pos) {
                    $data = substr($rawResponse, $pos + 4);
                    $rawResponse = substr($rawResponse, 0, $pos + 4);
                    $response = \GuzzleHttp\Psr7\parse_response($rawResponse);

                    if (!$cn->validateResponse($response)) {
                        $stream->end();
                        $deferred->reject();
                    } else {
                        $context = new EmConnectionContext($stream, true);

                        $context->on('message', function (Message $msg) use ($stream, $deferred, $context) {
                            $deferred->resolve($msg->getPayload());
                            $context->close();
                        });
                    }
                }
            }

            // feed the message streamer
            if ($response && $context) {
                $ms->onData($data, $context);
            }
        });

        $stream->write(\GuzzleHttp\Psr7\str($cnRequest));
    });

    return $deferred->promise();
}

function runTest($case)
{
    global $factory;
    global $testServer;

    $casePath = "/runCase?case={$case}&agent=" . AGENT;

    $deferred = new Deferred();

    $factory->create($testServer, 9001)->then(function (\React\Stream\Stream $stream) use ($deferred, $casePath, $case) {
        $cn = new \Ratchet\RFC6455\Handshake\ClientNegotiator($casePath);
        $cnRequest = $cn->getRequest();

        $rawResponse = "";
        $response = null;

        $ms = new \Ratchet\RFC6455\Messaging\Streaming\MessageStreamer(new \Ratchet\RFC6455\Encoding\Validator(), true);

        /** @var AbConnectionContext $context */
        $context = null;

        $stream->on('data', function ($data) use ($stream, &$rawResponse, &$response, $ms, $cn, $deferred, &$context) {
            if ($response === null) {
                $rawResponse .= $data;
                $pos = strpos($rawResponse, "\r\n\r\n");
                if ($pos) {
                    $data = substr($rawResponse, $pos + 4);
                    $rawResponse = substr($rawResponse, 0, $pos + 4);
                    $response = \GuzzleHttp\Psr7\parse_response($rawResponse);

                    if (!$cn->validateResponse($response)) {
                        $stream->end();
                        $deferred->reject();
                    } else {
                        $context = new AbConnectionContext($stream, true);
                    }
                }
            }

            // feed the message streamer
            if ($response && $context) {
                $ms->onData($data, $context);
            }
        });

        $stream->on('close', function () use ($deferred) {
            $deferred->resolve();
        });

        $stream->write(\GuzzleHttp\Psr7\str($cnRequest));
    });

    return $deferred->promise();
}

function createReport() {
    global $factory;
    global $testServer;

    $deferred = new Deferred();

    $factory->create($testServer, 9001)->then(function (\React\Stream\Stream $stream) use ($deferred) {
        $cn = new \Ratchet\RFC6455\Handshake\ClientNegotiator('/updateReports?agent=' . AGENT);
        $cnRequest = $cn->getRequest();

        $rawResponse = "";
        $response = null;

        $ms = new \Ratchet\RFC6455\Messaging\Streaming\MessageStreamer(new \Ratchet\RFC6455\Encoding\Validator(), true);

        /** @var EmConnectionContext $context */
        $context = null;

        $stream->on('data', function ($data) use ($stream, &$rawResponse, &$response, $ms, $cn, $deferred, &$context) {
            if ($response === null) {
                $rawResponse .= $data;
                $pos = strpos($rawResponse, "\r\n\r\n");
                if ($pos) {
                    $data = substr($rawResponse, $pos + 4);
                    $rawResponse = substr($rawResponse, 0, $pos + 4);
                    $response = \GuzzleHttp\Psr7\parse_response($rawResponse);

                    if (!$cn->validateResponse($response)) {
                        $stream->end();
                        $deferred->reject();
                    } else {
                        $context = new EmConnectionContext($stream, true);

                        $context->on('message', function (Message $msg) use ($stream, $deferred, $context) {
                            $deferred->resolve($msg->getPayload());
                            $context->close();
                        });
                    }
                }
            }

            // feed the message streamer
            if ($response && $context) {
                $ms->onData($data, $context);
            }
        });

        $stream->write(\GuzzleHttp\Psr7\str($cnRequest));
    });

    return $deferred->promise();
}


$testPromises = [];

getTestCases()->then(function ($count) use ($loop) {
    echo "Running " . $count . " test cases.\n";

    $allDeferred = new Deferred();

    $runNextCase = function () use (&$i, &$runNextCase, $count, $allDeferred) {
        $i++;
        if ($i > $count) {
            $allDeferred->resolve();
            return;
        }
        echo "Running " . $i . "\n";
        runTest($i)->then($runNextCase);
    };

    $i = 0;
    $runNextCase();

    $allDeferred->promise()->then(function () {
        echo "Generating report...\n";
        createReport();
    });
});

$loop->run();
