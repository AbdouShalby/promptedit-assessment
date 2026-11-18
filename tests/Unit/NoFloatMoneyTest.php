<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Guard rail: the money code must stay integer-only. A float anywhere here is a bug waiting to happen. */
class NoFloatMoneyTest extends TestCase
{
    public function test_money_code_contains_no_float_operations(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [
            ...glob($root.'/app/Billing/*.php'),
            ...glob($root.'/app/Support/*.php'),
            $root.'/app/Generations/ApplyProviderSnapshot.php',
        ];

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $code = file_get_contents($file);

            foreach (['(float)', 'floatval(', 'round(', 'ceil(', 'floor(', 'number_format(', ': float', 'float $'] as $forbidden) {
                // Comments may mention the words; only real code matters.
                $withoutComments = preg_replace('#//.*$|/\*.*?\*/#ms', '', $code);

                $this->assertStringNotContainsString($forbidden, $withoutComments, basename($file)." uses {$forbidden}");
            }
        }
    }
}
