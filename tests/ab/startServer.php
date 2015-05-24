<?php
use Ratchet\RFC6455\Messaging\Protocol\Frame;

require_once __DIR__ . "/../bootstrap.php";

class ConnectionContext implements Ratchet\RFC6455\Messaging\Streaming\ContextInterface {
    private $_frame;
    private $_message;

    /**
     * @var \React\Http\Response
     */
    private $_conn;

    public function __construct(\React\Http\Response $connectionContext) {
        $this->_conn = $connectionContext;
    }

    public function setFrame(\Ratchet\RFC6455\Messaging\Protocol\FrameInterface $frame = null) {
        $this->_frame = $frame;
    }

    public function getFrame() {
        return $this->_frame;
    }

    public function setMessage(\Ratchet\RFC6455\Messaging\Protocol\MessageInterface $message = null) {
        $this->_message = $message;
    }

    public function getMessage() {
        return $this->_message;
    }

    public function onMessage(\Ratchet\RFC6455\Messaging\Protocol\MessageInterface $msg) {
        foreach ($msg as $frame) {
            $frame->unMaskPayload();
        }

        $this->_conn->write($msg->getContents());
    }

    public function onPing(\Ratchet\RFC6455\Messaging\Protocol\FrameInterface $frame) {
        $pong = new Frame($frame->getPayload(), true, Frame::OP_PONG);
        $this->_conn->write($pong->getContents());
    }

    public function onPong(\Ratchet\RFC6455\Messaging\Protocol\FrameInterface $msg) {
        // TODO: Implement onPong() method.
    }

    public function onClose($code = 1000) {
        $frame = new Frame(
            pack('n', $code),
            true,
            Frame::OP_CLOSE
        );

        $this->_conn->end($frame->getContents());
    }
}

$loop   = \React\EventLoop\Factory::create();
$socket = new \React\Socket\Server($loop);
$server = new \React\Http\Server($socket);

$server->on('request', function (\React\Http\Request $request, \React\Http\Response $response) {
    $conn = new ConnectionContext($response);

    $encodingValidator = new \Ratchet\RFC6455\Encoding\Validator;

    // make the React Request a Psr7 request (not perfect)
    $psrRequest = new \GuzzleHttp\Psr7\Request($request->getMethod(), $request->getPath(), $request->getHeaders());

    $negotiator = new \Ratchet\RFC6455\Handshake\Negotiator($encodingValidator);

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

    $ms = new \Ratchet\RFC6455\Messaging\Streaming\MessageStreamer($encodingValidator);

    $request->on('data', function ($data) use ($ms, $conn) {
        $ms->onData($data, $conn);
    });
});
$socket->listen(9001, '0.0.0.0');
$loop->run();
