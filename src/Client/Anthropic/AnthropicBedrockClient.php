<?php

namespace Soukicz\PhpLlm\Client\Anthropic;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Credentials\Credentials;
use Aws\Result;
use GuzzleHttp\Promise\PromiseInterface;
use Soukicz\PhpLlm\Cache\CacheInterface;
use Soukicz\PhpLlm\Http\GuzzleHttpHandler;
use Soukicz\PhpLlm\Http\HttpClientFactory;

class AnthropicBedrockClient extends AnthropicBaseClient {

    private ?BedrockRuntimeClient $bedrockClient = null;

    public function __construct(private readonly string $region, private readonly Credentials $awsCredentials, private $customHttpMiddleware = null, private readonly ?CacheInterface $cache = null) {
    }

    private function getClient(): BedrockRuntimeClient {
        if ($this->bedrockClient === null) {
            $this->bedrockClient = new BedrockRuntimeClient([
                'credentials' => $this->awsCredentials,
                'region' => $this->region,
                'version' => 'latest',
                'http_handler' => new GuzzleHttpHandler(HttpClientFactory::createClient($this->customHttpMiddleware, $this->cache)),
            ]);
        }

        return $this->bedrockClient;
    }

    protected function invokeModel(array $data): PromiseInterface {
        unset($data['model']);
        $data['anthropic_version'] = 'bedrock-2023-05-31';

        $modelId = 'anthropic.claude-3-5-sonnet-20240620-v1:0';

        return $this->getClient()->invokeModelAsync([
            'modelId' => $modelId,
            'body' => json_encode($data, JSON_THROW_ON_ERROR),
        ])->then(function (Result $result) {
            return json_decode($result->toArray()['body'], true, 512, JSON_THROW_ON_ERROR);
        })
            ->then(function (array $response) {
                return $response;
            });
    }

}
