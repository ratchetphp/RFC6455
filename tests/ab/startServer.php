<?php
use Ratchet\RFC6455\Messaging\Protocol\MessageInterface;
use Ratchet\RFC6455\Messaging\Protocol\FrameInterface;
use Ratchet\RFC6455\Messaging\Protocol\Frame;

require_once __DIR__ . "/../bootstrap.php";

$loop   = \React\EventLoop\Factory::create();
$socket = new \React\Socket\Server($loop);
$server = new \React\Http\Server($socket);

$encodingValidator = new \Ratchet\RFC6455\Encoding\Validator;
$negotiator = new \Ratchet\RFC6455\Handshake\Negotiator($encodingValidator);
$ms = new \Ratchet\RFC6455\Messaging\Streaming\MessageStreamer($encodingValidator);

$server->on('request', function (\React\Http\Request $request, \React\Http\Response $response) use ($negotiator, $ms) {
    $psrRequest = new \GuzzleHttp\Psr7\Request($request->getMethod(), $request->getPath(), $request->getHeaders());

    $negotiatorResponse = $negotiator->handshake($psrRequest);

    $response->writeHead(
        $negotiatorResponse->getStatusCode(),
        array_merge(
            $negotiatorResponse->getHeaders(),
            ["Content-Length" => "0"]
        )
    );

    if ($negotiatorResponse->getStatusCode() !== 101) {
        $response->end();
        return;
    }

    $msg = null;
    $request->on('data', function($data) use ($ms, $response, &$msg) {
        $msg = $ms->onData($data, $msg, function(MessageInterface $msg, \React\Http\Response $conn) {
            $conn->write($msg->getContents());
        }, function(FrameInterface $frame, \React\Http\Response $conn) use ($ms) {
            switch ($frame->getOpCode()) {
                case Frame::OP_CLOSE:
                    $conn->end($frame->getContents());
                break;
                case Frame::OP_PING:
                    $conn->write($ms->newFrame($frame->getPayload(), true, Frame::OP_PONG)->getContents());
                break;
            }
        }, $response);
    });
});
$socket->listen(9001, '0.0.0.0');
$loop->run();
