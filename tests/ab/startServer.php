<?php
use Ratchet\RFC6455\Messaging\MessageInterface;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\Frame;

require_once __DIR__ . "/../bootstrap.php";

$loop   = \React\EventLoop\Factory::create();

$socket = new \React\Socket\Server('127.0.0.1:9001', $loop);

$closeFrameChecker = new \Ratchet\RFC6455\Messaging\CloseFrameChecker;
$negotiator = new \Ratchet\RFC6455\Handshake\ServerNegotiator(new \Ratchet\RFC6455\Handshake\RequestVerifier);

$uException = new \UnderflowException;

$socket->on('connection', function (React\Socket\ConnectionInterface $connection) use ($negotiator, $closeFrameChecker, $uException) {
    $headerComplete = false;
    $buffer = '';
    $parser = null;
    $connection->on('data', function ($data) use ($connection, &$parser, &$headerComplete, &$buffer, $negotiator, $closeFrameChecker, $uException) {
        if ($headerComplete) {
            $parser->onData($data);
            return;
        }

        $buffer .= $data;
        $parts = explode("\r\n\r\n", $buffer);
        if (count($parts) < 2) {
            return;
        }
        $headerComplete = true;
        $psrRequest = \GuzzleHttp\Psr7\parse_request($parts[0] . "\r\n\r\n");
        $negotiatorResponse = $negotiator->handshake($psrRequest);

        $negotiatorResponse = $negotiatorResponse->withAddedHeader("Content-Length", "0");

        $connection->write(\GuzzleHttp\Psr7\str($negotiatorResponse));

        if ($negotiatorResponse->getStatusCode() !== 101) {
            $connection->end();
            return;
        }

        $parser = new \Ratchet\RFC6455\Messaging\MessageBuffer($closeFrameChecker,
            function (MessageInterface $message) use ($connection) {
                $connection->write($message->getContents());
            }, function (FrameInterface $frame) use ($connection, &$parser) {
                switch ($frame->getOpCode()) {
                    case Frame::OP_CLOSE:
                        $connection->end($frame->getContents());
                        break;
                    case Frame::OP_PING:
                        $connection->write($parser->newFrame($frame->getPayload(), true, Frame::OP_PONG)->getContents());
                        break;
                }
            }, true, function () use ($uException) {
                return $uException;
            });

        array_shift($parts);
        $parser->onData(implode("\r\n\r\n", $parts));
    });
});

$loop->run();
