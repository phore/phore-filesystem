<?php

namespace Test;

use PHPUnit\Framework\TestCase;

class ExamplesTest extends TestCase
{
    public function testAllExamplesAreExecutable(): void
    {
        $examples = glob(__DIR__ . '/../examples/*.php');
        sort($examples);

        $this->assertNotEmpty($examples, 'No examples found.');

        foreach ($examples as $example) {
            $output = [];
            $exitCode = 0;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($example) . ' 2>&1', $output, $exitCode);

            $this->assertSame(
                0,
                $exitCode,
                "Example failed: {$example}\n" . implode("\n", $output)
            );
        }
    }
}
