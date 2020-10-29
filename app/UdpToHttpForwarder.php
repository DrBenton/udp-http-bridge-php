<?php

namespace App;

use App\Datadog\Logger as DatadogLogger;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\Datagram\Factory as UdpFactory;
use React\Datagram\Socket as UdpSocket;
use React\Http\Browser;

class UdpToHttpForwarder
{
    public LoggerInterface $logger;
    public ?DatadogLogger $datadogLogger;

    private Config $config;
    private UdpFactory $udpFactory;
    private Browser $httpClient;
    private array $httpTargetServerHeaders;

    private int $messagesCounter = -1;

    public function __construct(Config $config, UdpFactory $udpFactory, Browser $httpClient)
    {
        $this->config = $config;
        $this->udpFactory = $udpFactory;
        $this->httpClient = $httpClient;
        $this->logger = new NullLogger();
    }

    public function startUdpServer(): void
    {
        $logMessage = "UDP server starting";
        $logContext = [
            "udp_address" => $this->config->udpServerAddress,
            "http_target_url" => $this->config->httpForwardingServerUrl,
            "datadog_logging" => (bool) $this->datadogLogger,
        ];
        $this->logger->debug($logMessage,$logContext,);
        if ($this->datadogLogger) {
            $this->datadogLogger->logToDatadog($logMessage,$logContext);
        }

        $this->udpFactory->createServer($this->config->udpServerAddress)
            ->then(fn(...$args) => $this->onUdpServerCreated(...$args));
    }

    private function onUdpServerCreated(UdpSocket $server): void
    {
        $logMessage ="UDP server created";
        $this->logger->debug($logMessage);
        if ($this->datadogLogger) {
            $this->datadogLogger->logToDatadog($logMessage);
        }

        $this->initHttpTargetServerHeaders();

        $server->on(
            "message",
            fn(...$args) => $this->onUdpMessage(...$args)
        );
    }

    private function initHttpTargetServerHeaders(): void
    {
        $this->httpTargetServerHeaders = array_merge(
            ["Content-Type" => "application/json"],
            $this->config->httpForwardingHeaders ?? []
        );

        if ($this->config->httpForwardingBearerToken) {
            $this->httpTargetServerHeaders["Authentication"] = "Bearer {$this->config->httpForwardingBearerToken}";
        }
    }

    private function onUdpMessage(string $message, string $address, UdpSocket $server): void
    {
        $this->logger->debug(
            "UDP message received",
            ["msg" => $message, "addr" => $address, "msg_counter" => $this->messagesCounter]
        );

        $this->messagesCounter++;
        $this->forwardMessageViaHttp($message, $this->messagesCounter);
    }

    private function forwardMessageViaHttp(string $message, int $messagesCounter): void
    {
        $this->logger->debug(
            "Forwarding UDP message to HTTP server",
            ["msg_counter" => $messagesCounter]
        );


        $httpMessage = [
            "msg" => $this->base64EncodeUrl($message),
        ];

        $responsePromise = $this->httpClient->post(
            $this->config->httpForwardingServerUrl,
            $this->httpTargetServerHeaders,
            json_encode($httpMessage)
        );

        $responsePromise->then(
            function (ResponseInterface $response) use ($message, $messagesCounter) {
                // Success!
                $logMessage = "Forwarded UDP message to HTTP server";
                $logContext = ["status_code" => $response->getStatusCode(), "msg_counter" => $messagesCounter];
                $this->logger->info(
                    $logMessage,
                    $logContext,
                );

                if ($this->datadogLogger) {
                    $this->datadogLogger->logToDatadog(
                        $logMessage,
                        array_merge($logContext, ["msg" => $message])
                    );
                }
            },
            function (Exception $error) use ($message, $messagesCounter) {
                // Error :-/
                $logMessage = "UDP message to HTTP server failed";
                $logContext = ["error" => $error->getMessage(), "msg_counter" => $messagesCounter];
                $this->logger->error(
                    $logMessage,
                    $logContext
                );

                if ($this->datadogLogger) {
                    $this->datadogLogger->logToDatadog(
                        $logMessage,
                        array_merge($logContext, ["msg" => $message]),
                        $error
                    );
                }
            }
        );
    }

    private function base64EncodeUrl(string $string): string
    {
        // @link https://www.php.net/manual/en/function.base64-encode.php#123098
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($string));
    }
}
