<?php

namespace Soukicz\PhpLlm\Client\OpenAI;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Soukicz\PhpLlm\Cache\CacheInterface;
use Soukicz\PhpLlm\Client\LLMBaseClient;
use Soukicz\PhpLlm\Client\LLMBatchClient;
use Soukicz\PhpLlm\Http\HttpClientFactory;
use Soukicz\PhpLlm\Message\LLMMessage;
use Soukicz\PhpLlm\Message\LLMMessageImage;
use Soukicz\PhpLlm\Message\LLMMessageText;
use Soukicz\PhpLlm\Message\LLMMessageToolResult;
use Soukicz\PhpLlm\Message\LLMMessageToolUse;
use Soukicz\PhpLlm\LLMRequest;
use Soukicz\PhpLlm\LLMResponse;
use Soukicz\PhpLlm\ToolResponse;

class OpenAIClient extends LLMBaseClient implements LLMBatchClient {

    public const CODE = 'openai';

    public const GPT_4o_MINI = 'gpt-4o-mini-2024-07-18';

    private ?Client $httpClient = null;
    private ?Client $cachedHttpClient = null;

    public function __construct(private readonly string $apiKey, private readonly string $apiOrganization, private readonly ?CacheInterface $cache = null, private $customHttpMiddleware = null) {
    }

    private function getHeaders(): array {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'OpenAI-Organization' => $this->apiOrganization,
        ];
    }

    private function getHttpClient(): Client {
        if (!$this->httpClient) {
            $this->httpClient = HttpClientFactory::createClient($this->customHttpMiddleware, null, $this->getHeaders());
        }

        return $this->httpClient;
    }

    private function getCachedHttpClient(): Client {
        if (!$this->cache) {
            return $this->getHttpClient();
        }
        if (!$this->cachedHttpClient) {
            $this->cachedHttpClient = HttpClientFactory::createClient($this->customHttpMiddleware, $this->cache, $this->getHeaders());
        }

        return $this->cachedHttpClient;
    }

    private function encodeRequest(LLMRequest $request): array {
        $encodedMessages = [];
        foreach ($request->getMessages() as $message) {
            $role = $message->isUser() ? 'user' : 'assistant';
            $contents = [];
            foreach ($message->getContents() as $messageContent) {
                if ($messageContent instanceof LLMMessageText) {
                    $contents[] = [
                        'type' => 'text',
                        'text' => $messageContent->getText(),
                    ];
                } elseif ($messageContent instanceof LLMMessageImage) {
                    $contents[] = [
                        'type' => 'image',
                        'source' => [
                            'type' => $messageContent->getEncoding(),
                            'media_type' => $messageContent->getMediaType(),
                            'data' => $messageContent->getData(),
                        ],
                    ];
                } elseif ($messageContent instanceof LLMMessageToolUse) {
                    $encodedMessages[] = [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => $messageContent->getId(),
                                'type' => 'function',
                                'function' => [
                                    'name' => $messageContent->getName(),
                                    'arguments' => json_encode($messageContent->getInput(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                ],
                            ],
                        ],
                    ];
                    continue 2;
                } elseif ($messageContent instanceof LLMMessageToolResult) {
                    $encodedMessages[] = [
                        'role' => 'tool',
                        'content' => is_string($messageContent->getContent())
                            ? $messageContent->getContent()
                            : json_encode($messageContent->getContent(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'tool_call_id' => $messageContent->getId(),
                    ];
                    continue 2;
                } else {
                    throw new \InvalidArgumentException('Unsupported message type');
                }
            }
            $encodedMessages[] = [
                'role' => $role,
                'content' => $contents,
            ];
        }

        $requestData = [
            'model' => $request->getModel(),
            'messages' => $encodedMessages,
            'max_tokens' => $request->getMaxTokens(),
            'temperature' => $request->getTemperature(),
        ];

        if (!empty($request->getStopSequences())) {
            $requestData['stop'] = $request->getStopSequences();
        }

        if (!empty($request->getTools())) {
            $requestData['tools'] = [];
            foreach ($request->getTools() as $tool) {
                $requestData['tools'][] = [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool->getName(),
                        'description' => $tool->getDescription(),
                        'parameters' => $tool->getInputSchema(),
                    ],
                ];
            }
        }

        return $requestData;
    }

    public function sendPrompt(LLMRequest $request): LLMResponse {
        return $this->sendPromptAsync($request)->wait();
    }

    private function sendCachedRequestAsync(RequestInterface $httpRequest): PromiseInterface {
        return $this->getCachedHttpClient()->sendAsync($httpRequest)->then(function (ResponseInterface $response) {
            return $response;
        });
    }

    public function sendPromptAsync(LLMRequest $request): PromiseInterface {
        return $this->sendCachedRequestAsync($this->getChatRequest($request))->then(function (ResponseInterface $httpResponse) use ($request) {
            $response = json_decode($httpResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);

            if ($request->getModel() === 'gpt-4o-2024-08-06') {
                $inputPrice = $response['usage']['prompt_tokens'] * (2.5 / 1_000_000);
                $outputPrice = $response['usage']['completion_tokens'] * (10 / 1_000_000);
            } elseif ($request->getModel() === 'gpt-4o-mini-2024-07-18') {
                $inputPrice = $response['usage']['prompt_tokens'] * (0.150 / 1_000_000);
                $outputPrice = $response['usage']['completion_tokens'] * (0.6 / 1_000_000);
            } else {
                $inputPrice = null;
                $outputPrice = null;
            }

            if ($inputPrice) {
                $request = $request->withCost($response['usage']['prompt_tokens'], $response['usage']['completion_tokens'], $inputPrice, $outputPrice);
            }
            $request = $request->withTime((int) $httpResponse->getHeaderLine('x-request-duration'));

            $assistantMessage = $response['choices'][0]['message'];
            $responseContents = [];

            if (isset($assistantMessage['content'])) {
                $responseContents[] = new LLMMessageText($assistantMessage['content']);
            }

            if (!empty($assistantMessage['tool_calls'])) {
                foreach ($assistantMessage['tool_calls'] as $toolCall) {
                    if ($toolCall['type'] === 'function') {
                        $responseContents[] = new LLMMessageToolUse(
                            $toolCall['id'],
                            $toolCall['function']['name'],
                            json_decode($toolCall['function']['arguments'], true, 512, JSON_THROW_ON_ERROR)
                        );
                    }
                }
            }

            $request = $request->withMessage(LLMMessage::createFromAssistant($responseContents));

            if ($response['choices'][0]['finish_reason'] === 'tool_calls') {
                $toolResponseContents = [];

                foreach ($assistantMessage['tool_calls'] as $toolCall) {
                    if ($toolCall['type'] === 'function') {
                        foreach ($request->getTools() as $tool) {
                            if ($tool->getName() === $toolCall['function']['name']) {
                                $toolResponseContents[] = $tool->handle(
                                    $toolCall['id'],
                                    json_decode($toolCall['function']['arguments'], true, 512, JSON_THROW_ON_ERROR)
                                )->then(static function (ToolResponse $response) {
                                    return new LLMMessageToolResult($response->getId(), $response->getData());
                                });
                            }
                        }
                    }
                }

                $request = $request->withMessage(LLMMessage::createFromUser(Utils::unwrap($toolResponseContents)));

                return $this->sendPromptAsync($request);
            }

            return $this->postProcessResponse($request, new LLMResponse(
                $request->getMessages(),
                'end_turn',
                $request->getPreviousInputTokens(),
                $request->getPreviousOutputTokens(),
                $request->getPreviousMaximumOutputTokens(),
                $request->getPreviousInputCostUSD(),
                $request->getPreviousOutputCostUSD(),
                $request->getPreviousTimeMs()
            ));
        });
    }

    private function getChatRequest(LLMRequest $request): RequestInterface {
        return new Request('POST', 'https://api.openai.com/v1/chat/completions', [
            'Content-Type' => 'application/json',
            'accept-encoding' => 'gzip',
            'Authorization' => 'Bearer ' . $this->apiKey,
            'OpenAI-Organization' => $this->apiOrganization,
        ], json_encode($this->encodeRequest($request), JSON_THROW_ON_ERROR));
    }

    public function getBatchEmbeddings(array $texts, string $model = 'text-embedding-3-small', int $dimensions = 512): array {
        $results = [];
        $totalTokens = 0;
        foreach (array_chunk($texts, 100, true) as $chunk) {
            $keys = array_keys($chunk);
            $response = json_decode($this->getHttpClient()->post('https://api.openai.com/v1/embeddings', [
                'json' => [
                    'model' => $model,
                    'dimensions' => $dimensions,
                    'input' => array_values($chunk),
                ],
            ])->getBody(), true, 512, JSON_THROW_ON_ERROR);
            foreach ($response['data'] as $embedding) {
                $results[$keys[$embedding['index']]] = $embedding['embedding'];
            }
            $totalTokens += $response['usage']['total_tokens'];
        }

        return $results;
    }

    /**
     * @param LLMRequest[] $requests
     * @return string batch id
     */
    public function createBatch(array $requests): string {
        $body = '';
        foreach ($requests as $customId => $request) {
            $body .= json_encode([
                    'custom_id' => $customId,
                    'method' => 'POST',
                    'url' => '/v1/chat/completions',
                    'body' => $this->encodeRequest($request),
                ], JSON_THROW_ON_ERROR) . "\n";
        }

        $fileResponse = $this->getHttpClient()->post('https://api.openai.com/v1/files', [
            'multipart' => [
                ['name' => 'purpose', 'contents' => 'batch'],
                ['name' => 'file', 'contents' => $body, 'filename' => 'batch.jsonl'],
            ],
        ]);

        $response = $this->getHttpClient()->post('https://api.openai.com/v1/batches', [
            'json' => [
                'input_file_id' => json_decode((string) $fileResponse->getBody(), true, 512, JSON_THROW_ON_ERROR)['id'],
                'endpoint' => '/v1/chat/completions',
                'completion_window' => '24h',
            ],
        ]);

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR)['id'];
    }

    public function retrieveBatch(string $batchId): ?array {
        $response = json_decode($this->getHttpClient()->get('https://api.openai.com/v1/batches/' . $batchId)->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if ($response['status'] !== 'completed') {
            return null;
        }

        if ($response['output_file_id'] === null && $response['error_file_id']) {
            if ($response['completed_at'] < time() - 3 * 24 * 60 * 60) {
                return [];
            }
            $file = (string) $this->getHttpClient()->get('https://api.openai.com/v1/files/' . $response['error_file_id'] . '/content')->getBody();
            throw new \RuntimeException('Batch failed: ' . substr($file, 0, 1000));
        }

        $file = (string) $this->getHttpClient()->get('https://api.openai.com/v1/files/' . $response['output_file_id'] . '/content')->getBody();
        $results = explode("\n", trim($file));
        $responses = [];
        foreach ($results as $row) {
            $result = json_decode($row, true, 512, JSON_THROW_ON_ERROR);
            $content = '';
            foreach ($result['response']['body']['choices'] as $contentPart) {
                $content = $contentPart['message']['content'];
                if (is_string($content)) {
                    $content .= $content;
                } elseif ($content['type'] === 'text') {
                    $content .= $content['text'];
                }
            }
            $responses[$result['custom_id']] = $content;
        }

        return $responses;
    }

    public function getCode(): string {
        return self::CODE;
    }
}
