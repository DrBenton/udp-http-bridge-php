<?php


namespace App\Datadog;


class Config
{
    public const DATADOG_LOG_INPUT_URL = "https://http-intake.logs.datadoghq.eu/v1/input";

    public string $apiToken;
    public string $hostname;
    public string $service = "udp_to_http_forwarder";

    public function __construct(string $apiToken)
    {
        $this->apiToken = $apiToken;
        $this->hostname = gethostname();
    }
}
