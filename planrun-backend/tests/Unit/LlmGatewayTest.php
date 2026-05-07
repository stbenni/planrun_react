<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../services/LlmGateway.php';

class LlmGatewayTest extends TestCase
{
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->keys() as $key) {
            $this->originalEnv[$key] = [
                'env' => $_ENV[$key] ?? null,
                'getenv' => getenv($key),
                'isset' => array_key_exists($key, $_ENV) || getenv($key) !== false,
            ];
            $this->setEnv($key, '');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if (!$value['isset']) {
                unset($_ENV[$key]);
                putenv($key);
                continue;
            }
            $_ENV[$key] = $value['env'] ?? '';
            putenv($key . '=' . (string) ($value['getenv'] !== false ? $value['getenv'] : ($value['env'] ?? '')));
        }
        parent::tearDown();
    }

    public function test_api_keys_accept_pool_and_deduplicate_values(): void
    {
        $this->setEnv('PLAN_LLM_API_KEYS', "plan_a, plan_b\nplan_a plan_c");

        $this->assertSame(['plan_a', 'plan_b', 'plan_c'], \LlmGateway::apiKeys('plan'));
    }

    public function test_headers_use_purpose_specific_pool(): void
    {
        $this->setEnv('LLM_CHAT_API_KEYS', 'chat_key_one');

        $headers = \LlmGateway::headers('https://api.deepseek.com', null, 'chat');

        $this->assertContains('Authorization: Bearer chat_key_one', $headers);
    }

    public function test_api_key_fingerprint_is_stable_without_exposing_secret(): void
    {
        $fingerprint = \LlmGateway::apiKeyFingerprint('sk-super-secret');

        $this->assertSame($fingerprint, \LlmGateway::apiKeyFingerprint('sk-super-secret'));
        $this->assertSame(12, strlen((string) $fingerprint));
        $this->assertStringNotContainsString('sk-super-secret', (string) $fingerprint);
    }

    public function test_concurrency_limiter_rejects_when_global_pool_is_full(): void
    {
        $db = getDBConnection();
        $this->setEnv('LLM_GATEWAY_GLOBAL_MAX_CONCURRENT', '1');

        $firstLease = null;
        $thirdLease = null;
        try {
            $firstLease = \LlmGateway::acquireConcurrencyLease([
                'db' => $db,
                'purpose' => 'unit_test',
                'feature' => 'Limiter unit test',
                'timeout' => 1,
                'max_attempts' => 1,
                'limit_wait_seconds' => 0,
                'limit_ttl_seconds' => 30,
            ]);

            $this->assertIsArray($firstLease);
            $this->assertSame('global', $firstLease['limit_pools'][0]['pool'] ?? null);

            try {
                \LlmGateway::acquireConcurrencyLease([
                    'db' => $db,
                    'purpose' => 'unit_test',
                    'feature' => 'Limiter unit test',
                    'timeout' => 1,
                    'max_attempts' => 1,
                    'limit_wait_seconds' => 0,
                    'limit_ttl_seconds' => 30,
                ]);
                $this->fail('Expected busy limiter exception');
            } catch (\LlmGatewayRequestException $e) {
                $this->assertSame(429, $e->getHttpStatus());
                $this->assertTrue($e->isRetryable());
            }

            \LlmGateway::releaseConcurrencyLease($firstLease);
            $firstLease = null;

            $thirdLease = \LlmGateway::acquireConcurrencyLease([
                'db' => $db,
                'purpose' => 'unit_test',
                'feature' => 'Limiter unit test',
                'timeout' => 1,
                'max_attempts' => 1,
                'limit_wait_seconds' => 0,
                'limit_ttl_seconds' => 30,
            ]);
            $this->assertIsArray($thirdLease);
        } finally {
            \LlmGateway::releaseConcurrencyLease($firstLease);
            \LlmGateway::releaseConcurrencyLease($thirdLease);
            $db->query("DELETE FROM llm_gateway_locks WHERE feature = 'Limiter unit test'");
        }
    }

    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }

    private function keys(): array
    {
        return [
            'PLAN_LLM_API_KEYS',
            'PLAN_LLM_API_KEY',
            'LLM_CHAT_API_KEYS',
            'LLM_CHAT_API_KEY',
            'LLM_PLAN_API_KEYS',
            'LLM_PLAN_API_KEY',
            'DEEPSEEK_API_KEYS',
            'DEEPSEEK_API_KEY',
            'LLM_GATEWAY_GLOBAL_MAX_CONCURRENT',
            'LLM_GATEWAY_PLAN_MAX_CONCURRENT',
            'LLM_GATEWAY_CHAT_MAX_CONCURRENT',
            'LLM_GATEWAY_LIMIT_WAIT_SECONDS',
            'LLM_GATEWAY_LIMIT_TTL_SECONDS',
            'PLAN_LLM_MAX_CONCURRENT',
            'PLAN_LLM_LIMIT_WAIT_SECONDS',
            'LLM_CHAT_MAX_CONCURRENT',
            'LLM_CHAT_LIMIT_WAIT_SECONDS',
        ];
    }
}
