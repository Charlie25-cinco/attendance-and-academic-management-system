<?php

namespace BshsAms\Security;

final class ApiAccessPolicy
{
    /** null means no authorized route/method; [] means authenticated self-service. */
    public static function permissions(string $route, string $method, string $role): ?array
    {
        $routes = [
            'authenticate' => ['GET' => []],
            'profile' => ['GET' => [], 'POST' => []],
            'settings' => ['GET' => [], 'POST' => []],
            'notifications' => ['GET' => []],
            'notification-action' => ['POST' => []],
            'web-push-status' => ['GET' => []],
            'register-push-token' => ['POST' => []],
            'unregister-push-token' => ['POST' => []],
            'web-push-subscription' => ['POST' => [], 'DELETE' => []],
            'admin-users' => ['GET' => ['users.view'], 'POST' => ['users.create']],
            'admin-user-status' => ['POST' => ['users.edit']],
            'admin-enroll' => ['POST' => ['users.edit']],
            'student-attendance' => ['GET' => ['attendance.view']],
            'student-classes' => ['GET' => ['classes.view']],
            'student-qr' => ['GET' => ['attendance.view']],
            'student-grades' => ['GET' => ['grades.view']],
            'student-report-card' => ['GET' => ['grades.view']],
            'parent-progress' => ['GET' => ['grades.view']],
            'parent-children' => ['GET' => ['reports.view']],
            'teacher-attendance' => ['GET' => ['attendance.view'], 'POST' => ['attendance.manage']],
            'teacher-grades' => ['GET' => ['grades.view'], 'POST' => ['grades.enter']],
            'teacher-classes' => ['GET' => ['classes.view']],
            'teacher-class-students' => ['GET' => ['classes.view']],
            'teacher-advisory' => ['GET' => ['grades.view']],
            'teacher-announcements' => ['GET' => ['announcements.view']],
            'ecr-template' => ['GET' => ['grades.view']],
            'ecr-export-preview' => ['GET' => ['grades.view', 'reports.export']],
            'ecr-export' => ['GET' => ['grades.view', 'reports.export']],
            'ecr-upload' => ['POST' => ['grades.enter']],
            'ecr-import' => ['POST' => ['grades.enter']],
        ];
        if ($route === 'bootstrap' && $method === 'GET') {
            return match ($role) {
                'admin' => ['users.view', 'classes.view', 'attendance.view', 'announcements.view'],
                'teacher' => ['grades.view', 'announcements.view'],
                'student' => ['classes.view', 'announcements.view'],
                'parent' => ['reports.view', 'announcements.view'],
                default => null,
            };
        }
        return $routes[$route][$method] ?? null;
    }

    public static function permits(?array $required, array $granted): bool
    {
        return $required !== null && array_diff($required, $granted) === [];
    }

    public static function validCsrf(string $sessionToken, array $candidates): bool
    {
        if ($sessionToken === '') {
            return false;
        }
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && hash_equals($sessionToken, trim($candidate))) {
                return true;
            }
        }
        return false;
    }
}
