<?php

use PHPUnit\Framework\TestCase;

final class PwaAssetFreshnessTest extends TestCase
{
    public function testPortalPagesUseVersionedMainJavascriptUrl(): void
    {
        foreach (['teacher', 'student', 'parent'] as $portal) {
            $files = glob(__DIR__ . '/../' . $portal . '/*.php') ?: [];
            foreach ($files as $file) {
                $content = file_get_contents($file);
                $this->assertIsString($content);
                $this->assertStringNotContainsString('../assets/js/main.js', $content, $file);
                if (str_contains($content, "appAssetPath('js/main.js')")) {
                    $this->assertStringContainsString("appAssetPath('js/main.js')", $content);
                }
            }
        }
    }

    public function testServiceWorkerRefreshesJavascriptAndCssBeforeUsingCache(): void
    {
        $serviceWorker = file_get_contents(__DIR__ . '/../sw.js');

        $this->assertIsString($serviceWorker);
        $this->assertStringContainsString("const CACHE_NAME = 'bshs-ams-v8';", $serviceWorker);
        $this->assertStringContainsString('var needsFreshAsset = /\\.(css|js)$/i.test(url.pathname);', $serviceWorker);
        $this->assertStringContainsString('if (needsFreshAsset) {', $serviceWorker);
        $networkFirst = strpos($serviceWorker, 'if (needsFreshAsset) {');
        $cacheFirst = strpos($serviceWorker, 'if (isAsset || isStaticAsset) {');
        $this->assertIsInt($networkFirst);
        $this->assertIsInt($cacheFirst);
        $this->assertLessThan($cacheFirst, $networkFirst);
    }

    public function testPushStatusRefreshesWhenSettingsModalOpens(): void
    {
        $javascript = file_get_contents(__DIR__ . '/../assets/js/main.js');

        $this->assertIsString($javascript);
        $this->assertStringContainsString(
            'document.getElementById("settingsModal")?.addEventListener("shown.bs.modal", updatePwaPushStatus);',
            $javascript
        );
    }
}
