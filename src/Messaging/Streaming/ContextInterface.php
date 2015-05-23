<?php
namespace Ratchet\RFC6455\Messaging\Streaming;
use Ratchet\RFC6455\Messaging\Protocol\MessageInterface;
use Ratchet\RFC6455\Messaging\Protocol\FrameInterface;

interface ContextInterface {
    public function setFrame(FrameInterface $frame = null);

    /**
     * @return \Ratchet\RFC6455\Messaging\Protocol\FrameInterface
     */
    public function getFrame();

    public function setMessage(MessageInterface $message = null);

    /**
     * @return \Ratchet\RFC6455\Messaging\Protocol\MessageInterface
     */
    public function getMessage();

    public function onMessage(MessageInterface $msg);
    public function onPing(FrameInterface $frame);
    public function onPong(FrameInterface $frame);

    /**
     * @param $code int
     */
    public function onClose($code);
}