<?php

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\Datagram\Factory as UdpFactory;
use React\Datagram\Socket as UdpSocket;
use React\EventLoop\LoopInterface;
use React\Http\Browser;

class UdpToHttpForwarder
{
    public LoggerInterface $logger;

    private LoopInterface $reactLoop;
    private UdpFactory $udpFactory;
    private Browser $httpClient;
    private string $httpTargetServerUrl;
    private array $httpTargetServerHeaders;

    private int $messagesCounter = -1;

    public function __construct(LoopInterface $loop)
    {
        $this->reactLoop = $loop;
        $this->udpFactory = new UdpFactory($loop);
        $this->httpClient = new Browser($loop);
        $this->logger = new NullLogger();
    }

    public function setHttpForwarding(string $fullUrl, array $headers = []): void
    {
        $this->httpTargetServerUrl = $fullUrl;
        $this->httpTargetServerHeaders = $headers;
    }

    public function startUdpServer(string $address): void
    {
        if (!$this->httpTargetServerUrl) {
            throw new DomainException("setHttpForwarding() must be called before starting the UDP server.");
        }

        $this->logger->debug("UDP server starting", ["udp_address" => $address, "http_target_url" => $this->httpTargetServerUrl]);
        $this->udpFactory->createServer($address)->then(fn(...$args) => $this->onUdpServerCreated(...$args));
    }

    private function onUdpServerCreated(UdpSocket $server): void
    {
        $this->logger->debug("UDP server created");

        $server->on('message', fn(...$args) => $this->onUdpMessage(...$args));
    }

    private function onUdpMessage(string $message, string $address, UdpSocket $server): void
    {
        $this->logger->debug("UDP message received", ["msg" => $message, "addr" => $address, "msg_counter" => $this->messagesCounter]);

        $this->messagesCounter++;
        $this->forwardMessageViaHttp($message, $this->messagesCounter);
    }

    private function forwardMessageViaHttp(string $message, int $messagesCounter): void
    {
        $this->logger->debug("Forwarding UDP message to HTTP server", ["msg_counter" => $messagesCounter]);

        $headers = array_merge(["Content-Type" => "application/json"], $this->httpTargetServerHeaders);
        $httpMessage = [
            "msg" => $this->base64EncodeUrl($message),
        ];

        $responsePromise = $this->httpClient->post($this->httpTargetServerUrl, $headers, json_encode($httpMessage));

        $responsePromise->then(
            function (ResponseInterface $response) use ($messagesCounter) {
                $this->logger->info("Forwarded UDP message to HTTP server", ["status_code" => $response->getStatusCode(), "msg_counter" => $messagesCounter]);
            },
            function (Exception $error) use ($messagesCounter) {
                $this->logger->error("UDP message to HTTP server failed", ["error" => $error->getMessage(), "msg_counter" => $messagesCounter]);
            }
        );

    }

    private function base64EncodeUrl(string $string): string
    {
        // @link https://www.php.net/manual/en/function.base64-encode.php#123098
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($string));
    }

}
