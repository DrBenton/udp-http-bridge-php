<?php

require_once "./vendor/autoload.php";

use App\Config;
use App\Datadog\Config as DatadogConfig;
use App\Datadog\Logger as DatadogLogger;
use App\UdpToHttpForwarder;
use Dotenv\Dotenv;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use React\Datagram\Factory as UdpFactory;
use React\EventLoop\Factory as LoopFactory;
use React\Http\Browser;

if (class_exists(Dotenv::class)) {
    // Load the ".env" file, if it exists:
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// Check some mandatory env vars, and init a Config value object:
$udpServerAddress = $_ENV["UDP_SERVER_ADDRESS"] ?? null;
if (!$udpServerAddress) {
    die("[FATAL] Missing mandatory env var 'UDP_SERVER_ADDRESS'");
}
$httpForwardingServerUrl = $_ENV["HTTP_FORWARDING_URL"] ?? null;
if (!$httpForwardingServerUrl) {
    die("[FATAL] Missing mandatory env var 'HTTP_FORWARDING_URL'");
}
$config = new Config($udpServerAddress, $httpForwardingServerUrl);

// Also assign some optional config settings:
$config->httpForwardingBearerToken = $_ENV["HTTP_FORWARDING_BEARER_TOKEN"] ?? null;
$datadogApiToken = $_ENV["DATADOG_API_TOKEN"] ?? null;


// Ok. let's set this up...
$loop = LoopFactory::create();
$udpFactory = new UdpFactory($loop);
$httpClient = new Browser($loop);

$logHandler = new StreamHandler(STDOUT);

$forwarder = new UdpToHttpForwarder($config, $udpFactory, $httpClient);
$forwarderLogger = new Logger("udp_forwarder");
$forwarderLogger->pushHandler($logHandler);
$forwarder->logger = $forwarderLogger;

// Integrated Datadog logging?
if ($datadogApiToken) {
    $datadogConfig = new DatadogConfig($datadogApiToken);
    $datadogLogger = new DatadogLogger($datadogConfig, $httpClient);

    $datadogLoggerLogger = new Logger("datadog_logger");// a logger for the Datadog logger, so meta! ^_^
    $datadogLoggerLogger->pushHandler($logHandler);
    $datadogLogger->logger = $datadogLoggerLogger;

    $forwarder->datadogLogger = $datadogLogger;
}

// ...And start that thing!
$forwarder->startUdpServer();
$loop->run();

/*
 * To test:
 * ```bash
 * $ function slowcat(){ while read; do sleep .01; echo "$REPLY"; done; }
 * $ cat udp-input.txt  | slowcat | nc -u 127.0.0.1 6789
 * ```
 * With:
 * ```bash
 * $ cat udp-input.txt
 * line 0
 * line 1
 * line 2
 * line 3
 * line 4
 * line 5
 * line 6
 * line 7
 * line 8
 * line 9
 * ```
 *
 * @link https://stackoverflow.com/questions/13969817/send-text-file-line-by-line-with-netcat
 */
