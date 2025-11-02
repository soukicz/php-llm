# State Management

Save and resume AI agent conversations using JSON serialization. Conversation state management is essential for building chatbots, customer support systems, and any application where context needs to persist across multiple requests.

## Key Concepts

**Serialization**: Conversations can be serialized to JSON and stored in files, databases, Redis, or sessions. The library uses `JsonSerializable` for automatic encoding.

**Immutability**: Remember that `LLMConversation` is immutable - use `withMessage()` to add messages, which returns a new instance.

## Saving Conversations

```php
<?php
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\Message\LLMMessage;

$conversation = new LLMConversation([
    LLMMessage::createFromUserString('Hello'),
    LLMMessage::createFromAssistantString('Hi! How can I help?'),
    LLMMessage::createFromUserString('Tell me about PHP'),
]);

// Serialize to JSON
$json = json_encode($conversation);

// Save to file
file_put_contents('conversation.json', $json);
```

## Loading Conversations

```php
<?php
// Load from file
$json = file_get_contents('conversation.json');

// Deserialize (fromJson expects an array, not JSON string)
$conversation = LLMConversation::fromJson(json_decode($json, true));

// Continue the conversation (remember: immutable objects!)
$conversation = $conversation->withMessage(
    LLMMessage::createFromUserString('What are traits?')
);

$response = $chainClient->run(
    client: $client,
    request: new LLMRequest(
        model: $model,
        conversation: $conversation,
    )
);
```

## Database Storage

Store conversations in a relational database for persistent multi-user applications. This approach provides ACID guarantees and supports complex queries.

**Database Schema Example:**

```sql
CREATE TABLE conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(255) NOT NULL,
    data JSON NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    active BOOLEAN DEFAULT 1,
    INDEX idx_user_active (user_id, active, updated_at)
);
```

### Save to Database

```php
<?php
use PDO;

function saveConversation(PDO $pdo, string $userId, LLMConversation $conversation): int {
    $stmt = $pdo->prepare('
        INSERT INTO conversations (user_id, data, created_at)
        VALUES (:user_id, :data, NOW())
    ');

    $stmt->execute([
        'user_id' => $userId,
        'data' => json_encode($conversation),
    ]);

    return $pdo->lastInsertId();
}
```

### Load from Database

```php
<?php
function loadConversation(PDO $pdo, int $conversationId): ?LLMConversation {
    $stmt = $pdo->prepare('
        SELECT data FROM conversations WHERE id = :id
    ');

    $stmt->execute(['id' => $conversationId]);
    $data = $stmt->fetchColumn();

    return $data ? LLMConversation::fromJson(json_decode($data, true)) : null;
}
```

## Redis Storage

Use Redis for fast, temporary conversation storage. Perfect for high-traffic applications where you need quick access but don't require permanent storage. The TTL (Time To Live) automatically expires old conversations.

```php
<?php
use Redis;

class ConversationStore {
    public function __construct(private Redis $redis) {}

    public function save(string $key, LLMConversation $conversation): void {
        $this->redis->setex(
            $key,
            3600, // 1 hour TTL
            json_encode($conversation)
        );
    }

    public function load(string $key): ?LLMConversation {
        $json = $this->redis->get($key);
        return $json ? LLMConversation::fromJson(json_decode($json, true)) : null;
    }

    public function delete(string $key): void {
        $this->redis->del($key);
    }
}
```

```php
<?php
$store = new ConversationStore($redis);

// Save
$store->save("user:{$userId}:conversation", $conversation);

// Load
$conversation = $store->load("user:{$userId}:conversation");

// Delete
$store->delete("user:{$userId}:conversation");
```

## Session Storage

Store conversations in PHP sessions for simple, single-server applications. This is the easiest approach for getting started but doesn't work well with load balancers or distributed systems.

```php
<?php
session_start();

// Save to session
$_SESSION['conversation'] = json_encode($conversation);

// Load from session
$conversation = isset($_SESSION['conversation'])
    ? LLMConversation::fromJson(json_decode($_SESSION['conversation'], true))
    : new LLMConversation();
```

## Conversation History Management

Long conversations can exceed model context windows and become expensive. Implement strategies to manage conversation size.

**Why trim conversations?**
- Models have token limits (e.g., 200K for Claude, 128K for GPT-4)
- Longer context = higher costs
- Very long contexts can degrade performance

### Limit History Length

```php
<?php
function trimConversation(LLMConversation $conversation, int $maxMessages): LLMConversation {
    $messages = $conversation->getMessages();

    if (count($messages) <= $maxMessages) {
        return $conversation;
    }

    // Keep most recent messages
    $trimmedMessages = array_slice($messages, -$maxMessages);

    return new LLMConversation($trimmedMessages);
}

$conversation = trimConversation($conversation, 20); // Keep last 20 messages
```

### Conversation Metadata

```php
<?php
class ConversationMetadata {
    public function __construct(
        public readonly string $id,
        public readonly string $userId,
        public readonly LLMConversation $conversation,
        public readonly DateTime $createdAt,
        public readonly DateTime $updatedAt,
    ) {}

    public function toArray(): array {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'conversation' => json_encode($this->conversation),
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    public static function fromArray(array $data): self {
        return new self(
            id: $data['id'],
            userId: $data['user_id'],
            conversation: LLMConversation::fromJson(json_decode($data['conversation'], true)),
            createdAt: new DateTime($data['created_at']),
            updatedAt: new DateTime($data['updated_at']),
        );
    }
}
```

## Multi-User Chat Application

```php
<?php
class ChatService {
    public function __construct(
        private PDO $pdo,
        private LLMChainClient $chainClient,
        private $client,
        private $model
    ) {}

    public function getOrCreateConversation(string $userId): LLMConversation {
        $stmt = $this->pdo->prepare('
            SELECT data FROM conversations
            WHERE user_id = :user_id AND active = 1
            ORDER BY updated_at DESC LIMIT 1
        ');

        $stmt->execute(['user_id' => $userId]);
        $data = $stmt->fetchColumn();

        if ($data) {
            return LLMConversation::fromJson(json_decode($data, true));
        }

        // Create new conversation
        $conversation = new LLMConversation();
        $this->saveConversation($userId, $conversation);

        return $conversation;
    }

    public function sendMessage(string $userId, string $message): string {
        $conversation = $this->getOrCreateConversation($userId);

        // Add user message (immutable - returns new instance)
        $conversation = $conversation->withMessage(
            LLMMessage::createFromUserString($message)
        );

        $response = $this->chainClient->run(
            client: $this->client,
            request: new LLMRequest(
                model: $this->model,
                conversation: $conversation,
            )
        );

        // Add AI response (immutable - returns new instance)
        $conversation = $conversation->withMessage($response->getLastMessage());
        $this->saveConversation($userId, $conversation);

        return $response->getLastText();
    }

    private function saveConversation(string $userId, LLMConversation $conversation): void {
        $stmt = $this->pdo->prepare('
            INSERT INTO conversations (user_id, data, updated_at)
            VALUES (:user_id, :data, NOW())
            ON DUPLICATE KEY UPDATE data = :data, updated_at = NOW()
        ');

        $stmt->execute([
            'user_id' => $userId,
            'data' => json_encode($conversation),
        ]);
    }
}
```

## Conversation Export/Import

### Export

```php
<?php
function exportConversation(LLMConversation $conversation, string $filename): void {
    $data = [
        'version' => '1.0',
        'exported_at' => date('c'),
        'conversation' => json_decode(json_encode($conversation), true),
    ];

    file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT));
}
```

### Import

```php
<?php
function importConversation(string $filename): LLMConversation {
    $data = json_decode(file_get_contents($filename), true);

    if ($data['version'] !== '1.0') {
        throw new Exception('Unsupported conversation format');
    }

    // $data['conversation'] is already an array from json_decode
    return LLMConversation::fromJson($data['conversation']);
}
```

## See Also

- [Quick Start](quick-start.md) - Basic usage examples
- [Configuration Guide](../guides/configuration.md) - Configure conversations
