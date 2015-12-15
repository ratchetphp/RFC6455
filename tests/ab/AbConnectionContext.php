<?php


class AbConnectionContext implements Ratchet\RFC6455\Messaging\Streaming\ContextInterface {
    private $_frame;
    private $_message;
    protected $maskPayload;

    /**
     * @var \React\Stream\Stream
     */
    protected $_conn;

    public function __construct(\React\Stream\Stream $connectionContext, $maskPayload = false) {
        $this->_conn = $connectionContext;
        $this->maskPayload = $maskPayload;
    }

    public function setFrame(\Ratchet\RFC6455\Messaging\Protocol\FrameInterface $frame = null) {
        $this->_frame = $frame;
        return $frame;
    }

    public function getFrame() {
        return $this->_frame;
    }

    public function setMessage(\Ratchet\RFC6455\Messaging\Protocol\MessageInterface $message = null) {
        $this->_message = $message;
        return $message;
    }

    public function getMessage() {
        return $this->_message;
    }

    public function onMessage(\Ratchet\RFC6455\Messaging\Protocol\MessageInterface $msg) {
        $frame = new \Ratchet\RFC6455\Messaging\Protocol\Frame($msg->getPayload(), true, $msg[0]->getOpcode());
        if ($this->maskPayload) {
            $frame->maskPayload();
        }
        $this->_conn->write($frame->getContents());
    }

    public function onPing(\Ratchet\RFC6455\Messaging\Protocol\FrameInterface $frame) {
        $pong = new \Ratchet\RFC6455\Messaging\Protocol\Frame($frame->getPayload(), true, \Ratchet\RFC6455\Messaging\Protocol\Frame::OP_PONG);
        if ($this->maskPayload) {
            $pong->maskPayload();
        }
        $this->_conn->write($pong->getContents());
    }

    public function onPong(\Ratchet\RFC6455\Messaging\Protocol\FrameInterface $msg) {
        // TODO: Implement onPong() method.
    }

    public function onClose($code = 1000) {
        $frame = new \Ratchet\RFC6455\Messaging\Protocol\Frame(
            pack('n', $code),
            true,
            \Ratchet\RFC6455\Messaging\Protocol\Frame::OP_CLOSE
        );
        if ($this->maskPayload) {
            $frame->maskPayload();
        }

        $this->_conn->end($frame->getContents());
    }
}