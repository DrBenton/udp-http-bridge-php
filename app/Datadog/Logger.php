<?php


namespace App\Datadog;


use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use React\Http\Browser;
use React\Promise\PromiseInterface;

class Logger
{
    public LoggerInterface $logger;

    private Config $config;
    private Browser $httpClient;

    public function __construct(Config $config, Browser $httpClient)
    {
        $this->config = $config;
        $this->httpClient = $httpClient;
        $this->logger = new NullLogger();
    }

    public function logToDatadog(string $message, array $context = [], ?Exception $error = null): PromiseInterface
    {
        // @link https://docs.datadoghq.com/api/v1/logs/#send-logs
        // @link https://docs.datadoghq.com/logs/log_collection/?tab=http#reserved-attributes

        $this->logger->debug("Logging to Datadog...", $context);

        $headers = [
            "Content-Type" => "application/json",
            "DD-API-KEY" => $this->config->apiToken,
        ];
        $logLevel = $error ? LogLevel::ERROR : LogLevel::INFO;
        $logData = [
            "ddsource" => "php_logger",
            "hostname" => $this->config->hostname,
            "service" => $this->config->service,
            "message" => $message,
            "level" => strtoupper($logLevel),
            "context" => $context,
        ];

        // Ok. let's send this!
        $responsePromise = $this->httpClient->post(
            $this->config::DATADOG_LOG_INPUT_URL,
            $headers,
            json_encode($logData),
        );

        return $responsePromise->then(
            function (ResponseInterface $response) use ($context) {
                // Successful logging to Datadog? Nothing more to do :-)
                $this->logger->debug(
                    "Logged to Datadog",
                    array_merge($context, ["status_code" => $response->getStatusCode()]),
                );
            },
            function (Exception $datadogError) use ($context, $logLevel, $message, $error) {
                // Ouch! :-/
                $logData = array_merge($context, [
                    "datadog_error" => $datadogError->getMessage(),
                    "log_level" => $logLevel,
                    "log_message" => $message,
                ]);
                if ($error) {
                    $logData["log_original_error"] = $error->getMessage();
                }
                $this->logger->error(
                    "Logging to Datadog failed",
                    $logData,
                );
            }
        );
    }
}
