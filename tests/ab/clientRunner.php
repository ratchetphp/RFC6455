<?php
use React\Promise\Deferred;
use Ratchet\RFC6455\Messaging\Protocol\Frame;
use Ratchet\RFC6455\Messaging\Protocol\Message;

require __DIR__ . '/../bootstrap.php';

define('AGENT', 'RatchetRFC/0.0.0');

$loop = React\EventLoop\Factory::create();

$dnsResolverFactory = new React\Dns\Resolver\Factory();
$dnsResolver = $dnsResolverFactory->createCached('8.8.8.8', $loop);

$factory = new \React\SocketClient\Connector($loop, $dnsResolver);

function getTestCases() {
    global $factory;

    $deferred = new Deferred();

    $factory->create('127.0.0.1', 9001)->then(function (\React\Stream\Stream $stream) use ($deferred) {
        $cn = new \Ratchet\RFC6455\Handshake\ClientNegotiator("/getCaseCount");
        $cnRequest = $cn->getRequest();

        $rawResponse = "";
        $response = null;

        $ms = new \Ratchet\RFC6455\Messaging\Streaming\MessageStreamer(true);

        $ms->on('message', function (Message $msg) use ($stream, $deferred) {
            $deferred->resolve($msg->getPayload());

            $closeFrame = new Frame(pack('n', Frame::CLOSE_NORMAL), true, Frame::OP_CLOSE);
            $closeFrame->maskPayload();
            $stream->end($closeFrame->getContents());
        });

        $ms->on('close', function ($code) use ($stream) {
            if ($code === null) {
                $stream->end();
                return;
            }
            $frame = new Frame(pack('n', $code), true, Frame::OP_CLOSE);
            $frame->maskPayload();
            $stream->end($frame->getContents());
        });

        $stream->on('data', function ($data) use ($stream, &$rawResponse, &$response, $ms, $cn, $deferred) {
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
                    }
                }
            }

            // feed the message streamer
            if ($response) {
                $ms->onData($data);
            }
        });

        $stream->write(\GuzzleHttp\Psr7\str($cnRequest));
    });

    return $deferred->promise();
}

function runTest($case)
{
    global $factory;

    $casePath = "/runCase?case={$case}&agent=" . AGENT;

    $deferred = new Deferred();

    $factory->create('127.0.0.1', 9001)->then(function (\React\Stream\Stream $stream) use ($deferred, $casePath, $case) {
        $cn = new \Ratchet\RFC6455\Handshake\ClientNegotiator($casePath);
        $cnRequest = $cn->getRequest();

        $rawResponse = "";
        $response = null;

        $ms = new \Ratchet\RFC6455\Messaging\Streaming\MessageStreamer(true);

        $ms->on('message', function (Message $msg) use ($stream, $deferred, $case) {
            echo "Got message on case " . $case . "\n";
            $opcode = $msg->isBinary() ? Frame::OP_BINARY : Frame::OP_TEXT;
            $frame  = new Frame($msg->getPayload(), true, $opcode);
            $frame->maskPayload();

            $stream->write($frame->getContents());
        });

        $ms->on('ping', function (Frame $frame) use ($stream) {
            $response = new Frame($frame->getPayload(), true, Frame::OP_PONG);
            $response->maskPayload();
            $stream->write($response->getContents());
        });

        $ms->on('close', function ($code) use ($stream, $deferred) {
            if ($code === null) {
                $stream->end();
                return;
            }
            $frame = new Frame(pack('n', $code), true, Frame::OP_CLOSE);
            $frame->maskPayload();
            $stream->end($frame->getContents());
        });

        $stream->on('data', function ($data) use ($stream, &$rawResponse, &$response, $ms, $cn, $deferred) {
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
                    }
                }
            }

            // feed the message streamer
            if ($response) {
                $ms->onData($data);
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

    $deferred = new Deferred();

    $factory->create('127.0.0.1', 9001)->then(function (\React\Stream\Stream $stream) use ($deferred) {
        $cn = new \Ratchet\RFC6455\Handshake\ClientNegotiator('/updateReports?agent=' . AGENT);
        $cnRequest = $cn->getRequest();

        $rawResponse = "";
        $response = null;

        $ms = new \Ratchet\RFC6455\Messaging\Streaming\MessageStreamer(true);

        $ms->on('message', function (Message $msg) use ($stream, $deferred) {
            $deferred->resolve($msg->getPayload());

            $closeFrame = new Frame(pack('n', Frame::CLOSE_NORMAL), true, Frame::OP_CLOSE);
            $closeFrame->maskPayload();
            $stream->end($closeFrame->getContents());
        });

        $ms->on('close', function ($code) use ($stream) {
            if ($code === null) {
                $stream->end();
                return;
            }
            $frame = new Frame(pack('n', $code), true, Frame::OP_CLOSE);
            $frame->maskPayload();
            $stream->end($frame->getContents());
        });

        $stream->on('data', function ($data) use ($stream, &$rawResponse, &$response, $ms, $cn, $deferred) {
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
                    }
                }
            }

            // feed the message streamer
            if ($response) {
                $ms->onData($data);
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
