<?php


namespace App;


class Config
{
    public string $udpServerAddress;
    public string $httpForwardingServerUrl;
    public ?array $httpForwardingHeaders;
    public ?string $httpForwardingBearerToken;

    public function __construct(string $udpServerAddress, string $httpForwardingServerUrl)
    {
        $this->udpServerAddress = $udpServerAddress;
        $this->httpForwardingServerUrl = $httpForwardingServerUrl;
    }
}
