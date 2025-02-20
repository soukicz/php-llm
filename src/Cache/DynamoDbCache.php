<?php declare(strict_types=1);

namespace Soukicz\Llm\Cache;

use Aws\DynamoDb\DynamoDbClient;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class DynamoDbCache extends AbstractCache {
    private array $local = [];

    public function __construct(readonly private DynamoDbClient $dynamoDbClient, readonly private string $tableName, readonly private int $TTL = 3 * 30 * 24 * 60 * 60) {
    }

    public function store(RequestInterface $request, ResponseInterface $response): void {
        $encoded = $this->responseToJson($response);

        $key = $this->getCacheKey($request);
        $this->dynamoDbClient->updateItem([
            'TableName' => $this->tableName,
            'Key' => [
                'PK' => ['S' => $key],
            ],
            'AttributeUpdates' => [
                'response' => [
                    'Value' => ['B' => gzencode($encoded, 9)],
                    'Action' => 'PUT',
                ],
                'TTL' => [
                    'Value' => ['N' => (string) (time() + $this->TTL)],
                    'Action' => 'PUT',
                ],
            ],

        ]);

        $this->local[$key] = $response;
    }

    public function fetch(RequestInterface $request): ?ResponseInterface {
        $encodedKey = $this->getCacheKey($request);
        if (array_key_exists($encodedKey, $this->local)) {
            return $this->local[$encodedKey];
        }

        $result = $this->dynamoDbClient->getItem([
            'TableName' => $this->tableName,
            'ReturnConsumedCapacity' => 'NONE',
            'Key' => ['PK' => ['S' => $encodedKey]],
        ]);
        if (isset($result['Item'])) {
            return $this->local[$encodedKey] = $this->responseFromJson(gzdecode($result['Item']['response']['B']));
        }

        return null;
    }

    public function invalidate(RequestInterface $request): void {
        $this->dynamoDbClient->deleteItem([
            'Key' => ['PK' => ['S' => $this->getCacheKey($request)]],
            'TableName' => $this->tableName,
        ]);
    }

}

