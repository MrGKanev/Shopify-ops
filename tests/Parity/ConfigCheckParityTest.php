<?php

declare(strict_types=1);

use App\Application\Health\CheckConfiguration;
use PHPUnit\Framework\TestCase;

final class ConfigCheckParityTest extends TestCase
{
    public function test_shared_order_type_and_tag_policy_validation_match_legacy(): void
    {
        $checker = new CheckConfiguration;
        $orderTypes = new ReflectionMethod($checker, 'orderTypes');
        $tagPolicy = new ReflectionMethod($checker, 'tagPolicy');

        foreach ([
            ['rules' => [['name' => 'Z1', 'match' => 'sku_starts_with', 'value' => 'Z1']]],
            ['rules' => [['name' => '', 'match' => 'unsupported']]],
        ] as $config) {
            $this->assertSame($this->legacyValid('order_types.json', $config), $orderTypes->invoke($checker, $config)['ok']);
        }
        foreach ([
            ['required' => [['when' => ['vip'], 'must_have' => ['approved']]], 'forbidden' => [['tags' => ['a', 'b']]]],
            ['required' => [['when' => [], 'must_have' => []]], 'forbidden' => [['tags' => ['one']]]],
        ] as $config) {
            $this->assertSame($this->legacyValid('tag_policy.json', $config), $tagPolicy->invoke($checker, $config)['ok']);
        }
    }

    private function legacyValid(string $name, array $config): bool
    {
        $path = sys_get_temp_dir().'/'.uniqid('parity-', true).'-'.$name;
        file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));
        try {
            $result = $name === 'order_types.json' ? ConfigValidator::validateOrderTypes($path) : ConfigValidator::validateTagPolicy($path);

            return $result['ok'];
        } finally {
            unlink($path);
        }
    }
}
