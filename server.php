<?php

require_once "./vendor/autoload.php";
require_once "./UdpToHttpForwarder.php";

use Dotenv\Dotenv;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

if (class_exists(Dotenv::class)) {
    // Load the ".env" file, if it exists:
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// Check some mandatory env vars:
$httpForwardingServerUrl = $_ENV["HTTP_FORWARDING_URL"] ?? null;
if (!$httpForwardingServerUrl) {
    die("[FATAL] Missing mandatory env var 'HTTP_FORWARDING_URL'");
}
$udpServerAddress = $_ENV["UDP_SERVER_ADDRESS"] ?? null;
if (!$udpServerAddress) {
    die("[FATAL] Missing mandatory env var 'UDP_SERVER_ADDRESS'");
}
// Also assign some optional ones:
$httpForwardingBearerToken = $_ENV["HTTP_FORWARDING_BEARER_TOKEN"] ?? null;


// Ok. let's set this up...
$loop = React\EventLoop\Factory::create();

$logger = new Logger("udp_forwarder");
$logger->pushHandler(new StreamHandler(STDOUT));

$forwarder = new UdpToHttpForwarder($loop);
$forwarder->logger = $logger;
$httpForwardingHeaders = $httpForwardingBearerToken
    ? ["Authentication" => "Bearer ${_ENV['HTTP_FORWARDING_BEARER_TOKEN']}"]
    : [];
$forwarder->setHttpForwarding($httpForwardingServerUrl, $httpForwardingHeaders);
$forwarder->startUdpServer($udpServerAddress);

// ...And start the React PHP loop!
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
