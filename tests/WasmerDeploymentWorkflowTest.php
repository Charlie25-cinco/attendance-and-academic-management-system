<?php

use PHPUnit\Framework\TestCase;

final class WasmerDeploymentWorkflowTest extends TestCase
{
    public function testDeployRetriesTransientRegistryFailures(): void
    {
        $workflow = file_get_contents(__DIR__ . '/../.github/workflows/wasmer-deploy.yml');
        $wasmerIgnore = file_get_contents(__DIR__ . '/../.wasmerignore');

        $this->assertIsString($workflow);
        $this->assertIsString($wasmerIgnore);
        $this->assertStringContainsString('wasmer_ignore = Path(".wasmerignore")', $workflow);
        $this->assertStringContainsString('timeout-minutes: 40', $workflow);
        $this->assertStringContainsString('wasmer login "${WASMER_TOKEN}"', $workflow);
        $this->assertStringContainsString('max_attempts=5', $workflow);
        $this->assertStringContainsString('for attempt in $(seq 1 "$max_attempts")', $workflow);
        $this->assertStringContainsString('if wasmer deploy --non-interactive --token="${WASMER_TOKEN}"; then', $workflow);
        $this->assertStringContainsString('delay=$((attempt * 30))', $workflow);
        $this->assertStringContainsString('composer install --no-dev', $workflow);
        $this->assertStringContainsString('export WASMER_TOKEN="${WASMER_TOKEN}"', $workflow);
        $this->assertStringContainsString('.git/', $wasmerIgnore);
        $this->assertStringContainsString('resources/', $wasmerIgnore);
        $this->assertStringContainsString('database/', $wasmerIgnore);
        $this->assertStringContainsString('scripts/', $wasmerIgnore);
        $this->assertStringContainsString('examples/', $wasmerIgnore);
        $this->assertStringContainsString('storage/', $wasmerIgnore);
        $this->assertStringContainsString('assets/uploads/', $wasmerIgnore);
        $this->assertStringContainsString('deped/*.xlsm', $wasmerIgnore);
    }
}
