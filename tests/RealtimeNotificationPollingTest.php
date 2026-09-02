<?php

use PHPUnit\Framework\TestCase;

final class RealtimeNotificationPollingTest extends TestCase
{
    public function testNotificationPollerJavaScriptStructure(): void
    {
        $js = file_get_contents(__DIR__ . '/../assets/js/main.js');
        $this->assertIsString($js);

        // Polling interval within 15-30s
        $this->assertStringContainsString('LiveNotificationPoller', $js);
        $this->assertStringContainsString('intervalMs: 20000', $js);
        $this->assertStringContainsString('minIntervalMs: 15000', $js);
        $this->assertStringContainsString('maxIntervalMs: 60000', $js);

        // Visibility lifecycle & offline handling
        $this->assertStringContainsString('document.addEventListener("visibilitychange"', $js);
        $this->assertStringContainsString('window.addEventListener("online"', $js);
        $this->assertStringContainsString('window.addEventListener("offline"', $js);

        // In-flight locking & AbortController
        $this->assertStringContainsString('if (this.isPolling', $js);
        $this->assertStringContainsString('this.abortController =', $js);
        $this->assertStringContainsString('this.abortController.abort()', $js);

        // Duplicate prevention & seen IDs tracking
        $this->assertStringContainsString('seenNotificationIds', $js);
        $this->assertStringContainsString('ams:notificationReceived', $js);
        $this->assertStringContainsString('badge-pulse', $js);

        // Function initialization
        $this->assertStringContainsString('initLiveNotificationPoller()', $js);
        $this->assertStringContainsString('renderLiveNotifications(items, unreadCount', $js);
        $this->assertStringContainsString('setHeaderNotificationCount(unreadCount', $js);
    }

    public function testNotificationCssAnimationDefinition(): void
    {
        $css = file_get_contents(__DIR__ . '/../assets/css/main.css');
        $this->assertIsString($css);

        $this->assertStringContainsString('@keyframes badgePulse', $css);
        $this->assertStringContainsString('.notification-badge.badge-pulse', $css);
    }

    public function testNotificationApiContract(): void
    {
        $api = file_get_contents(__DIR__ . '/../api/routes/05-notifications.php');
        $this->assertIsString($api);

        $this->assertStringContainsString("if (\$route === 'notifications' && \$method === 'GET')", $api);
        $this->assertStringContainsString("'unread_count' => \$unreadCount", $api);
        $this->assertStringContainsString("'notifications' => \$savedItems", $api);
        $this->assertStringContainsString("'items' => \$savedItems", $api);
    }
}
