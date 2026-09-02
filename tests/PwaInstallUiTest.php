<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class PwaInstallUiTest extends TestCase
{
    public function testHeaderDoesNotRenderPwaInstallButtonsOrDropdownItems(): void
    {
        $header = file_get_contents(__DIR__ . '/../includes/header.php');
        $this->assertIsString($header);

        // Header quick action button is removed
        $this->assertStringNotContainsString('id="pwaInstallBtn"', $header);

        // Header profile dropdown item is removed
        $this->assertStringNotContainsString('id="headerPwaInstallDropdownItem"', $header);
        $this->assertStringNotContainsString('id="headerPwaInstallDropdownBtn"', $header);
    }

    public function testSettingsModalRendersPwaApplicationInstallSectionAndGuidanceModal(): void
    {
        $modals = file_get_contents(__DIR__ . '/../includes/modals.php');
        $this->assertIsString($modals);

        // Settings modal install section is rendered without display:none
        $this->assertStringContainsString('id="settingsPwaInstallSection"', $modals);
        $this->assertStringContainsString('id="settingsPwaInstallBtn"', $modals);
        $this->assertStringContainsString('class="settings-option"', $modals);
        $this->assertStringContainsString('Install App', $modals);
        $this->assertStringContainsString('Application', $modals);
        $this->assertStringNotContainsString('<div id="settingsPwaInstallSection" class="mt-4" style="display:none;">', $modals);

        // PWA install guidance modal exists with fallback steps
        $this->assertStringContainsString('id="pwaInstallModal"', $modals);
        $this->assertStringContainsString('id="pwaInstallModalTitle"', $modals);
        $this->assertStringContainsString('id="pwaInstallModalSubtitle"', $modals);
        $this->assertStringContainsString('id="pwaGuideStep1Title"', $modals);
        $this->assertStringContainsString('id="pwaGuideStep2Title"', $modals);
        $this->assertStringContainsString('id="pwaGuideStep3Title"', $modals);
    }

    public function testConstantsRegistersPwaInstallPromptAndManagesSettingsModalWithFallback(): void
    {
        $constants = file_get_contents(__DIR__ . '/../config/constants.php');
        $this->assertIsString($constants);

        $this->assertStringContainsString('window._pwaInstallPrompt = null;', $constants);
        $this->assertStringContainsString('window.isPwaStandalone = function ()', $constants);
        $this->assertStringContainsString('window.showPwaInstallModal = function ()', $constants);
        $this->assertStringContainsString('window.bindPwaInstallButton = function ()', $constants);
        $this->assertStringContainsString("document.getElementById('settingsPwaInstallSection')", $constants);
        $this->assertStringContainsString("document.getElementById('settingsPwaInstallBtn')", $constants);
        $this->assertStringNotContainsString("document.getElementById('pwaInstallBtn')", $constants);
        $this->assertStringNotContainsString("document.getElementById('headerPwaInstallDropdownItem')", $constants);
        $this->assertStringNotContainsString("document.getElementById('headerPwaInstallDropdownBtn')", $constants);
        $this->assertStringNotContainsString("window.triggerPwaInstall", $constants);
        $this->assertStringNotContainsString("window._pwaInstallInProgress", $constants);
        $this->assertStringContainsString("window.addEventListener('beforeinstallprompt'", $constants);
        $this->assertStringContainsString("window.addEventListener('appinstalled'", $constants);
        $this->assertStringContainsString("window._pwaInstallPrompt.prompt()", $constants);
        $this->assertStringContainsString("window.showPwaInstallModal()", $constants);
    }

    public function testDirectSettingsPwaInstallButtonBinding(): void
    {
        $constants = file_get_contents(__DIR__ . '/../config/constants.php');
        $this->assertIsString($constants);

        // Assert direct button event listener and bound attribute guard
        $this->assertStringContainsString("if (settingsBtn.dataset.bound !== '1') {", $constants);
        $this->assertStringContainsString("settingsBtn.dataset.bound = '1';", $constants);
        $this->assertStringContainsString("settingsBtn.addEventListener('click', async function (e) {", $constants);

        // Assert native prompt handling with userChoice
        $this->assertStringContainsString('await window._pwaInstallPrompt.prompt();', $constants);
        $this->assertStringContainsString('var choice = await window._pwaInstallPrompt.userChoice;', $constants);
        $this->assertStringContainsString("choice.outcome === 'accepted'", $constants);

        // Assert fallback modal invocation
        $this->assertStringContainsString('window.showPwaInstallModal();', $constants);

        // Assert absence of document-level click delegators for Settings button
        $this->assertStringNotContainsString("e.target.closest('#settingsPwaInstallBtn')", $constants);
    }

    public function testServiceWorkerRegistrationIsNotPreemptedOnFreshLoadWithoutController(): void
    {
        $constants = file_get_contents(__DIR__ . '/../config/constants.php');
        $this->assertIsString($constants);

        // Ensure navigator.serviceWorker.register() is called and controllerchange listener is properly bound
        $this->assertStringContainsString("navigator.serviceWorker.addEventListener('controllerchange', function () {", $constants);
        $this->assertStringContainsString("navigator.serviceWorker.register(desiredScript, { scope: desiredScope, updateViaCache: 'none' })", $constants);

        // Assert that register occurs in the same finally block without an unconditional/unwrapped return before it
        $finallyPos = strpos($constants, '.finally(function () {');
        $this->assertNotFalse($finallyPos);
        $registerPos = strpos($constants, 'navigator.serviceWorker.register(', $finallyPos);
        $this->assertNotFalse($registerPos);
        $controllerListenerPos = strpos($constants, "navigator.serviceWorker.addEventListener('controllerchange'", $finallyPos);
        $this->assertNotFalse($controllerListenerPos);
        $this->assertLessThan($registerPos, $controllerListenerPos, 'controllerchange listener should be registered before or alongside register call');
    }

    public function testMainJsRefreshesPwaInstallStateWhenSettingsModalOpens(): void
    {
        $js = file_get_contents(__DIR__ . '/../assets/js/main.js');
        $this->assertIsString($js);

        $this->assertStringContainsString('settingsModal', $js);
        $this->assertStringContainsString('shown.bs.modal', $js);
        $this->assertStringContainsString('bindPwaInstallButton', $js);
    }

    public function testAllPortalsIncludeSharedHeader(): void
    {
        $portals = [
            __DIR__ . '/../admin/admin.php',
            __DIR__ . '/../teacher/teacher.php',
            __DIR__ . '/../student/Student.php',
            __DIR__ . '/../parent/Parent.php',
        ];

        foreach ($portals as $portalFile) {
            $this->assertFileExists($portalFile);
            $content = file_get_contents($portalFile);
            $this->assertIsString($content);
            $this->assertTrue(
                str_contains($content, "includes/header.php"),
                "File $portalFile should include shared header.php"
            );
        }
    }

    public function testPwaHeadScriptExecutesWithoutSyntaxErrorsAndHandlesInstallClick(): void
    {
        require_once __DIR__ . '/../config/constants.php';
        $html = pwaHeadHtml();
        $this->assertIsString($html);

        $startTag = '<script>';
        $endTag = '</script>';
        $startIndex = strrpos($html, $startTag);
        $this->assertNotFalse($startIndex);
        $startIndex += strlen($startTag);
        $endIndex = strrpos($html, $endTag);
        $this->assertNotFalse($endIndex);

        $pwaScript = substr($html, $startIndex, $endIndex - $startIndex);
        $this->assertNotEmpty($pwaScript);

        // Run node runtime evaluation to ensure zero syntax errors and verify actual DOM click execution
        $nodeScript = "
        const pwaScript = " . json_encode($pwaScript) . ";
        class MockElement {
            constructor(id, tagName = 'div') {
                this.id = id;
                this.tagName = tagName;
                this.style = {};
                this.dataset = {};
                this.classList = {
                    classes: new Set(),
                    add: (c) => this.classList.classes.add(c),
                    remove: (c) => this.classList.classes.delete(c),
                    contains: (c) => this.classList.classes.has(c)
                };
                this.listeners = {};
                this.innerHTML = '';
                this.textContent = '';
            }
            addEventListener(event, fn) {
                if (!this.listeners[event]) this.listeners[event] = [];
                this.listeners[event].push(fn);
            }
            removeEventListener(event, fn) {
                if (this.listeners[event]) {
                    this.listeners[event] = this.listeners[event].filter(f => f !== fn);
                }
            }
            async dispatchEvent(event, data) {
                if (this.listeners[event]) {
                    for (const fn of this.listeners[event]) {
                        await fn(data || { preventDefault: () => {} });
                    }
                }
            }
        }

        class MockDocument {
            constructor() {
                this.elements = {};
                this.listeners = {};
            }
            getElementById(id) {
                return this.elements[id] || null;
            }
            addEventListener(event, fn) {
                if (!this.listeners[event]) this.listeners[event] = [];
                this.listeners[event].push(fn);
            }
            async dispatchEvent(event, data) {
                if (this.listeners[event]) {
                    for (const fn of this.listeners[event]) {
                        await fn(data || { preventDefault: () => {} });
                    }
                }
            }
        }

        async function run() {
            const doc = new MockDocument();
            doc.elements['settingsPwaInstallSection'] = new MockElement('settingsPwaInstallSection');
            doc.elements['settingsPwaInstallBtn'] = new MockElement('settingsPwaInstallBtn', 'button');
            doc.elements['pwaInstallModal'] = new MockElement('pwaInstallModal');
            doc.elements['pwaInstallModalTitle'] = new MockElement('pwaInstallModalTitle');
            doc.elements['pwaInstallModalSubtitle'] = new MockElement('pwaInstallModalSubtitle');

            let modalShown = false;
            const mockBootstrap = {
                Modal: {
                    getInstance: (el) => ({ show: () => { modalShown = true; } })
                }
            };

            const mockWindow = {
                matchMedia: () => ({ matches: false }),
                navigator: { standalone: false, userAgent: 'Chrome/Desktop' },
                addEventListener: (event, fn) => doc.addEventListener(event, fn),
                document: doc,
                bootstrap: mockBootstrap
            };

            const fn = new Function('window', 'document', 'navigator', 'bootstrap', pwaScript);
            fn(mockWindow, doc, mockWindow.navigator, mockBootstrap);

            // Trigger DOMContentLoaded
            await doc.dispatchEvent('DOMContentLoaded');
            if (doc.elements['settingsPwaInstallBtn'].dataset.bound !== '1') {
                process.exit(10);
            }

            // Click without prompt -> modal shown
            modalShown = false;
            await doc.elements['settingsPwaInstallBtn'].dispatchEvent('click');
            if (!modalShown) {
                process.exit(11);
            }

            // Click with prompt -> prompt called
            let promptCalled = false;
            await doc.dispatchEvent('beforeinstallprompt', {
                preventDefault: () => {},
                prompt: async () => { promptCalled = true; },
                userChoice: Promise.resolve({ outcome: 'accepted' })
            });

            modalShown = false;
            await doc.elements['settingsPwaInstallBtn'].dispatchEvent('click');
            if (!promptCalled) {
                process.exit(12);
            }
            if (mockWindow._pwaInstallPrompt !== null) {
                process.exit(13);
            }

            process.exit(0);
        }
        run();
        ";

        $tmpFile = tempnam(sys_get_temp_dir(), 'pwa_test_') . '.js';
        file_put_contents($tmpFile, $nodeScript);

        exec('node ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $returnCode);
        @unlink($tmpFile);

        $this->assertSame(0, $returnCode, 'PWA script execution failed with code ' . $returnCode . ': ' . implode("\n", $output));
    }
}
