<?php

use Ratchet\RFC6455\Messaging\Protocol\Frame;
use Ratchet\RFC6455\Messaging\Protocol\Message;

require_once __DIR__ . "/../bootstrap.php";

$loop = \React\EventLoop\Factory::create();
$socket = new \React\Socket\Server($loop);

$server = new \React\Http\Server($socket);

$server->on('request', function (\React\Http\Request $request, \React\Http\Response $response) {
    // saving this for later
    $conn = $response;

    // make the React Request a Psr7 request (not perfect)
    $psrRequest = new \GuzzleHttp\Psr7\Request($request->getMethod(), $request->getPath(), $request->getHeaders());

    $negotiator = new \Ratchet\RFC6455\Handshake\Negotiator(new \Ratchet\RFC6455\Encoding\NullValidator());

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

    $ms = new \Ratchet\RFC6455\Messaging\Streaming\MessageStreamer();

    $ms->on('message', function (Message $msg) use ($conn) {
        $opcode = $msg->isBinary() ? Frame::OP_BINARY : Frame::OP_TEXT;
        $frame = new Frame($msg->getPayload(), true, $opcode);
        $conn->write($frame->getContents());
    });

    $ms->on('ping', function (Frame $frame) use ($conn) {
        $pong = new Frame($frame->getPayload(), true, Frame::OP_PONG);
        $conn->write($pong->getContents());
    });

    $ms->on('pong', function (Frame $frame) {
        echo "got PONG...\n";
    });

    $ms->on('close', function ($code) use ($conn) {
        if ($code === null) {
            $conn->close();
            return;
        }
        $frame = new Frame(
            pack('n', $code),
            true,
            Frame::OP_CLOSE
        );
        $conn->end($frame->getContents());
    });

    $request->on('data', function ($data) use ($ms) {
        $ms->onData($data);
    });
});
$socket->listen(9001);
$loop->run();