<?php

use PHPUnit\Framework\TestCase;

final class WasmerDeploymentWorkflowTest extends TestCase
{
    public function testDeployRetriesTransientRegistryFailures(): void
    {
        $workflow = file_get_contents(__DIR__ . '/../.github/workflows/wasmer-deploy.yml');

        $this->assertIsString($workflow);
        $this->assertStringContainsString('timeout-minutes: 40', $workflow);
        $this->assertStringContainsString('wasmer login "${WASMER_TOKEN}"', $workflow);
        $this->assertStringContainsString('max_attempts=5', $workflow);
        $this->assertStringContainsString('for attempt in $(seq 1 "$max_attempts")', $workflow);
        $this->assertStringContainsString('if wasmer deploy --non-interactive --token="${WASMER_TOKEN}"; then', $workflow);
        $this->assertStringContainsString('delay=$((attempt * 30))', $workflow);
        $this->assertStringContainsString('failed after ${max_attempts} attempts', $workflow);
    }
}
