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
        $this->assertStringContainsString('window._pwaInstalled = false;', $constants);
        $this->assertStringContainsString('window.isPwaStandalone = function ()', $constants);
        $this->assertStringContainsString('window.isPwaInstalled = function ()', $constants);
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

        // Run node runtime evaluation to test full PWA installation lifecycle, storage persistence, and standalone mode
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

        class MockLocalStorage {
            constructor() {
                this.store = {};
            }
            getItem(key) {
                return this.store[key] || null;
            }
            setItem(key, value) {
                this.store[key] = String(value);
            }
            removeItem(key) {
                delete this.store[key];
            }
            clear() {
                this.store = {};
            }
        }

        async function run() {
            const storage = new MockLocalStorage();

            // Scenario 1: Initial load in standard browser mode
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

            let standaloneMode = false;
            const mockWindow = {
                matchMedia: (query) => ({
                    matches: query.includes('standalone') ? standaloneMode : false
                }),
                navigator: { standalone: false, userAgent: 'Chrome/Desktop' },
                localStorage: storage,
                addEventListener: (event, fn) => doc.addEventListener(event, fn),
                document: doc,
                bootstrap: mockBootstrap
            };

            const fn = new Function('window', 'document', 'navigator', 'bootstrap', pwaScript);
            fn(mockWindow, doc, mockWindow.navigator, mockBootstrap);

            // Phase 1: DOMContentLoaded in browser mode -> button is 'Install'
            await doc.dispatchEvent('DOMContentLoaded');
            const btn = doc.elements['settingsPwaInstallBtn'];
            if (btn.dataset.bound !== '1' || !btn.innerHTML.includes('Install') || btn.innerHTML.includes('Installed')) {
                process.exit(10);
            }

            // Phase 2: Click without prompt -> modal shown with install instructions
            modalShown = false;
            await btn.dispatchEvent('click');
            if (!modalShown || doc.elements['pwaInstallModalTitle'].textContent !== 'Install BSHS AMS') {
                process.exit(11);
            }

            // Phase 3: Receive beforeinstallprompt and user accepts -> button becomes 'Installed' and persisted in localStorage
            let promptCalled = false;
            await doc.dispatchEvent('beforeinstallprompt', {
                preventDefault: () => {},
                prompt: async () => { promptCalled = true; },
                userChoice: Promise.resolve({ outcome: 'accepted' })
            });

            modalShown = false;
            await btn.dispatchEvent('click');
            if (!promptCalled) {
                process.exit(12);
            }
            if (mockWindow._pwaInstallPrompt !== null) {
                process.exit(13);
            }
            if (!btn.innerHTML.includes('Installed') || storage.getItem('bshs_pwa_installed') !== '1') {
                process.exit(14);
            }

            // Phase 4: Settings modal reopen -> bindPwaInstallButton() preserves 'Installed' state
            mockWindow.bindPwaInstallButton();
            if (!btn.innerHTML.includes('Installed')) {
                process.exit(15);
            }

            // Phase 5: Click when installed -> does NOT call prompt, opens 'Application Already Installed' guidance
            promptCalled = false;
            modalShown = false;
            await btn.dispatchEvent('click');
            if (promptCalled || !modalShown || doc.elements['pwaInstallModalTitle'].textContent !== 'Application Already Installed') {
                process.exit(16);
            }

            // Scenario 2: appinstalled event test
            storage.clear();
            const doc2 = new MockDocument();
            doc2.elements['settingsPwaInstallSection'] = new MockElement('settingsPwaInstallSection');
            doc2.elements['settingsPwaInstallBtn'] = new MockElement('settingsPwaInstallBtn', 'button');
            doc2.elements['pwaInstallModal'] = new MockElement('pwaInstallModal');
            doc2.elements['pwaInstallModalTitle'] = new MockElement('pwaInstallModalTitle');
            doc2.elements['pwaInstallModalSubtitle'] = new MockElement('pwaInstallModalSubtitle');

            const mockWindow2 = {
                matchMedia: (query) => ({ matches: false }),
                navigator: { standalone: false, userAgent: 'Chrome/Desktop' },
                localStorage: storage,
                addEventListener: (event, fn) => doc2.addEventListener(event, fn),
                document: doc2,
                bootstrap: mockBootstrap
            };

            const fn2 = new Function('window', 'document', 'navigator', 'bootstrap', pwaScript);
            fn2(mockWindow2, doc2, mockWindow2.navigator, mockBootstrap);
            await doc2.dispatchEvent('DOMContentLoaded');

            const btn2 = doc2.elements['settingsPwaInstallBtn'];
            if (!btn2.innerHTML.includes('Install') || btn2.innerHTML.includes('Installed')) {
                process.exit(20);
            }

            // Dispatch appinstalled event
            await doc2.dispatchEvent('appinstalled');
            if (!btn2.innerHTML.includes('Installed') || storage.getItem('bshs_pwa_installed') !== '1' || !mockWindow2.isPwaInstalled()) {
                process.exit(21);
            }

            // Reopen Settings modal -> still Installed
            mockWindow2.bindPwaInstallButton();
            if (!btn2.innerHTML.includes('Installed')) {
                process.exit(22);
            }

            // Scenario 3: Fresh standalone page load
            const doc3 = new MockDocument();
            doc3.elements['settingsPwaInstallSection'] = new MockElement('settingsPwaInstallSection');
            doc3.elements['settingsPwaInstallBtn'] = new MockElement('settingsPwaInstallBtn', 'button');
            doc3.elements['pwaInstallModal'] = new MockElement('pwaInstallModal');
            doc3.elements['pwaInstallModalTitle'] = new MockElement('pwaInstallModalTitle');
            doc3.elements['pwaInstallModalSubtitle'] = new MockElement('pwaInstallModalSubtitle');

            const mockWindow3 = {
                matchMedia: (query) => ({ matches: query.includes('standalone') }),
                navigator: { standalone: false, userAgent: 'Chrome/Desktop' },
                localStorage: new MockLocalStorage(),
                addEventListener: (event, fn) => doc3.addEventListener(event, fn),
                document: doc3,
                bootstrap: mockBootstrap
            };

            const fn3 = new Function('window', 'document', 'navigator', 'bootstrap', pwaScript);
            fn3(mockWindow3, doc3, mockWindow3.navigator, mockBootstrap);
            await doc3.dispatchEvent('DOMContentLoaded');

            const btn3 = doc3.elements['settingsPwaInstallBtn'];
            if (!btn3.innerHTML.includes('Installed') || !mockWindow3.isPwaInstalled()) {
                process.exit(30);
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
