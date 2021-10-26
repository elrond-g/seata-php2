<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace Hyperf\Seata\Core\Rpc\Runtime\Swow;

use Hyperf\Engine\Channel;
use Hyperf\Seata\Core\Protocol\MessageType;
use Hyperf\Seata\Core\Protocol\RpcMessage;
use Hyperf\Seata\Core\Rpc\Address;
use Hyperf\Seata\Core\Rpc\Runtime\SocketChannelInterface;
use Hyperf\Seata\Core\Rpc\Runtime\V1\ProtocolV1Decoder;
use Hyperf\Seata\Core\Rpc\Runtime\V1\ProtocolV1Encoder;
use Hyperf\Seata\Utils\Buffer\ByteBuffer;
use Hyperf\Utils\ApplicationContext;
use Hyperf\Utils\Coroutine;
use Swow\Socket;

class SocketChannel implements SocketChannelInterface
{
    protected ProtocolV1Encoder $protocolEncoder;

    protected ProtocolV1Decoder $protocolDecoder;

    protected int $messageId;

    protected Socket $socket;

    protected Address $address;

    protected array $responses = [];

    protected Channel $sendChannel;

    public function __construct(Socket $socket, Address $address)
    {
        $this->socket = $socket;
        $this->address = $address;
        $container = ApplicationContext::getContainer();
        $this->protocolEncoder = $container->get(ProtocolV1Encoder::class);
        $this->protocolDecoder = $container->get(ProtocolV1Decoder::class);
        $this->sendChannel = new Channel();
        $this->createRecvLoop();
        //$this->createSendLoop();
    }

    public function sendSyncWithResponse(RpcMessage $rpcMessage, int $timeoutMillis)
    {
        $channel = new Channel();
        $this->responses[$rpcMessage->getId()] = $channel;
        $this->sendSyncWithNoResponse($rpcMessage, $timeoutMillis);
        return $channel->pop();
    }

    public function sendSyncWithNoResponse(RpcMessage $rpcMessage, int $timeoutMillis)
    {
        $data = $this->protocolEncoder->encode($rpcMessage);
        $this->socket->sendString($data);
    }

    public function getAddress(): Address
    {
        return $this->address;
    }

    protected function createRecvLoop()
    {
        Coroutine::create(function () {
            while (true) {
                try {
                    $data = $this->socket->recvString();
                    if (! $data) {
                        continue;
                    }
                    $byteBuffer = ByteBuffer::wrapBin($data);
                    $rpcMessage = $this->protocolDecoder->decode($byteBuffer);
                    if (isset($this->responses[$rpcMessage->getId()])) {
                        $responseChannel = $this->responses[$rpcMessage->getId()];
                        $responseChannel->push($rpcMessage);
                    } elseif ($rpcMessage->getMessageType() === MessageType::TYPE_HEARTBEAT_MSG) {
                        var_dump('heartbeat', $rpcMessage);
                    } else {
                        var_dump('else', $rpcMessage);
                    }
                } catch (\InvalidArgumentException $exception) {
                    var_dump($exception->getMessage());
                }
            }
        });
    }
}
