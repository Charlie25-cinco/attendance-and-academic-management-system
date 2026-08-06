<?php

use PHPUnit\Framework\TestCase;

final class WebPushNotificationTest extends TestCase
{
    private ?PDO $db = null;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            first_name TEXT,
            last_name TEXT,
            role TEXT,
            grade_level INTEGER,
            section TEXT,
            status TEXT
        )");

        $this->db->exec("CREATE TABLE parent_students (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            parent_id INTEGER,
            student_id INTEGER
        )");

        $this->db->exec("CREATE TABLE push_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            endpoint TEXT NOT NULL UNIQUE,
            p256dh TEXT NOT NULL,
            auth TEXT NOT NULL,
            user_agent TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE TABLE user_settings (
            user_id INTEGER PRIMARY KEY,
            push_notifications INTEGER DEFAULT 1
        )");

        $this->db->exec("CREATE TABLE classes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_name TEXT,
            grade_level INTEGER,
            section TEXT
        )");

        $this->db->exec("CREATE TABLE enrollments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER,
            student_id INTEGER,
            status TEXT
        )");
    }

    public function testBase64UrlHelpers(): void
    {
        $data = "WebPushTest\x00Data";
        $encoded = pushBase64UrlEncode($data);
        $decoded = pushBase64UrlDecode($encoded);

        $this->assertSame($data, $decoded);
        $this->assertFalse(str_contains($encoded, '+'));
        $this->assertFalse(str_contains($encoded, '/'));
        $this->assertFalse(str_contains($encoded, '='));
    }

    public function testPushSubscriptionSaveFetchDelete(): void
    {
        $userId = 101;
        $subscription = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint-token-123',
            'keys' => [
                'p256dh' => 'BDH3sFk_test_p256dh_key',
                'auth' => 'test_auth_secret_123'
            ]
        ];

        $saved = pushSaveSubscription($this->db, $userId, $subscription, 'Mozilla/5.0 Test Browser');
        $this->assertTrue($saved);

        $subs = pushFetchSubscriptions($this->db, [$userId]);
        $this->assertCount(1, $subs);
        $this->assertSame((int)$userId, (int)$subs[0]['user_id']);
        $this->assertSame($subscription['endpoint'], $subs[0]['endpoint']);

        $deleted = pushDeleteSubscription($this->db, $userId, $subscription['endpoint']);
        $this->assertTrue($deleted);

        $subsAfter = pushFetchSubscriptions($this->db, [$userId]);
        $this->assertCount(0, $subsAfter);
    }

    public function testPushNotifyAttendanceEvent(): void
    {
        $this->db->exec("INSERT INTO users (id, first_name, last_name, role) VALUES (1, 'Juan', 'Dela Cruz', 'student')");
        $this->db->exec("INSERT INTO users (id, first_name, last_name, role) VALUES (2, 'Maria', 'Dela Cruz', 'parent')");
        $this->db->exec("INSERT INTO parent_students (parent_id, student_id) VALUES (2, 1)");

        $result = pushNotifyAttendanceEvent($this->db, 1, 'present', '2026-08-06');
        $this->assertIsBool($result);
    }

    public function testPushNotifyGradePublication(): void
    {
        $this->db->exec("INSERT INTO classes (id, class_name, grade_level, section) VALUES (1, 'STEM A', 12, 'A')");
        $this->db->exec("INSERT INTO enrollments (class_id, student_id, status) VALUES (1, 1, 'enrolled')");

        $result = pushNotifyGradePublication($this->db, 1, 'Final', '2025-2026');
        $this->assertIsBool($result);
    }
}
