<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Cache\CacheInterface;
use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude35Haiku;
use Soukicz\Llm\Client\Gemini\GeminiClient;
use Soukicz\Llm\Client\Gemini\Model\Gemini20Flash;
use Soukicz\Llm\Client\LLMClient;
use Soukicz\Llm\Client\ModelInterface;
use Soukicz\Llm\Client\OpenAI\Model\GPT4oMini;
use Soukicz\Llm\Client\OpenAI\OpenAIClient;

/**
 * @group integration
 */
abstract class IntegrationTestBase extends TestCase {
    protected ?CacheInterface $cache = null;
    protected float $totalCost = 0.0;
    protected float $maxCost;
    protected bool $verbose;
    protected static bool $envLoaded = false;

    protected function setUp(): void {
        parent::setUp();

        // Load environment variables
        self::loadEnvironmentStatic();

        // Check if integration tests should run
        $this->checkEnvironmentVariables();

        // Setup cache
        $cacheDir = sys_get_temp_dir() . '/llm-integration-tests';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }
        $this->cache = new FileCache($cacheDir);

        // Setup cost tracking
        $this->maxCost = (float) ($_ENV['INTEGRATION_TEST_MAX_COST'] ?? 1.0);
        $this->verbose = ($_ENV['INTEGRATION_TEST_VERBOSE'] ?? 'false') === 'true';
    }

    protected function tearDown(): void {
        parent::tearDown();

        if ($this->verbose && $this->totalCost > 0) {
            echo sprintf("\nTest cost: $%.4f\n", $this->totalCost);
        }
    }

    protected static function loadEnvironmentStatic(): void {
        if (self::$envLoaded) {
            return;
        }

        $envFile = dirname(__DIR__, 2) . '/.env';
        if (!file_exists($envFile)) {
            self::$envLoaded = true;
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '#') === 0) {
                continue;
            }

            if (strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
        
        self::$envLoaded = true;
    }

    protected function checkEnvironmentVariables(): void {
        $requiredVars = $this->getRequiredEnvironmentVariables();
        $missing = [];

        foreach ($requiredVars as $var) {
            if (empty($_ENV[$var])) {
                $missing[] = $var;
            }
        }

        if (!empty($missing)) {
            $this->markTestSkipped(
                'Integration tests require the following environment variables: ' .
                implode(', ', $missing) . '. ' .
                'Copy .env.example to .env and fill in your API keys.'
            );
        }
    }

    /**
     * Get required environment variables for the specific test
     * @return array<string>
     */
    protected function getRequiredEnvironmentVariables(): array {
        return ['ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'GEMINI_API_KEY'];
    }

    /**
     * Get all available LLM clients with their models for testing
     * @return array<array{client: LLMClient, model: ModelInterface, name: string}>
     */
    protected function getAllClients(): array {
        // Ensure environment is loaded (in case called from data provider)
        self::loadEnvironmentStatic();
        
        // Initialize cache if not already done
        if ($this->cache === null) {
            $cacheDir = sys_get_temp_dir() . '/llm-integration-tests';
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0777, true);
            }
            $this->cache = new FileCache($cacheDir);
        }
        
        $clients = [];

        if (!empty($_ENV['ANTHROPIC_API_KEY'])) {
            $clients[] = [
                'client' => new AnthropicClient($_ENV['ANTHROPIC_API_KEY'], $this->cache),
                'model' => new AnthropicClaude35Haiku(AnthropicClaude35Haiku::VERSION_20241022),
                'name' => 'Anthropic Claude 3.5 Haiku',
            ];
        }

        if (!empty($_ENV['OPENAI_API_KEY'])) {
            $clients[] = [
                'client' => new OpenAIClient($_ENV['OPENAI_API_KEY'], '', $this->cache),
                'model' => new GPT4oMini(GPT4oMini::VERSION_2024_07_18),
                'name' => 'OpenAI GPT-4o Mini',
            ];
        }

        if (!empty($_ENV['GEMINI_API_KEY'])) {
            $clients[] = [
                'client' => new GeminiClient($_ENV['GEMINI_API_KEY'], $this->cache),
                'model' => new Gemini20Flash(),
                'name' => 'Google Gemini 2.0 Flash',
            ];
        }

        return $clients;
    }

    /**
     * Track cost from a response
     */
    protected function trackCost(float $cost): void {
        $this->totalCost += $cost;

        if ($this->totalCost > $this->maxCost) {
            $this->fail(sprintf(
                'Test exceeded maximum cost limit. Used: $%.4f, Limit: $%.4f',
                $this->totalCost,
                $this->maxCost
            ));
        }
    }

    /**
     * Assert that a string contains text (case-insensitive)
     */
    protected function assertContainsIgnoreCase(string $needle, string $haystack, string $message = ''): void {
        $this->assertStringContainsStringIgnoringCase($needle, $haystack, $message);
    }

    /**
     * Assert that the response contains any of the given strings
     */
    protected function assertContainsAny(array $needles, string $haystack, string $message = ''): void {
        foreach ($needles as $needle) {
            if (stripos($haystack, $needle) !== false) {
                // Found a match, assertion passes
                return;
            }
        }

        $this->fail(
            $message ?: sprintf(
                'Failed asserting that "%s" contains any of: %s',
                substr($haystack, 0, 100) . '...',
                implode(', ', array_map(fn($n) => '"' . $n . '"', $needles))
            )
        );
    }
}
