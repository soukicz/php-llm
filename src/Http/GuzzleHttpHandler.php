<?php

namespace Soukicz\PhpLlm\Http;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class GuzzleHttpHandler {
    public function __construct(private readonly ClientInterface $client) {
    }

    public function __invoke(RequestInterface $request, array $options = []): ResponseInterface {
        return $this->client->send($request, $options);
    }

    public function async(RequestInterface $request, array $options = []): PromiseInterface {
        return $this->client->sendAsync($request, $options);
    }
}
