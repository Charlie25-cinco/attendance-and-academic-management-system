<?php

use PHPUnit\Framework\TestCase;

final class RbacScriptCoverageTest extends TestCase
{
    /**
     * Every portal page script must resolve to a non-empty RBAC permission.
     * Unmapped scripts silently skip permission enforcement in
     * enforceScriptPermission() (functions/app-helpers.php).
     */
    public function testEveryPortalPageHasAnRbacPermissionMapping(): void
    {
        $portals = ['admin', 'teacher', 'student', 'parent'];
        $includeOnlyHelpers = [
            'teacher_reports_helper.php',
            'teacher_enrollment_helper.php',
        ];
        $unmapped = [];

        foreach ($portals as $portal) {
            foreach (glob(APP_ROOT . '/' . $portal . '/*.php') ?: [] as $path) {
                $script = strtolower(basename($path));
                if (in_array($script, $includeOnlyHelpers, true)) {
                    continue;
                }
                if (permissionForScript($script) === '') {
                    $unmapped[] = $portal . '/' . basename($path);
                }
            }
        }

        $this->assertSame(
            [],
            $unmapped,
            'Portal scripts without an RBAC permission mapping bypass permission enforcement. '
            . 'Add them to permissionForScript() or, for include-only helper libraries, '
            . 'to this test\'s allowlist.'
        );
    }

    public function testRbacMapHasNoStaleEntries(): void
    {
        $mapReflection = new ReflectionFunction('permissionForScript');
        $startLine = $mapReflection->getStartLine();
        $endLine = $mapReflection->getEndLine();
        $source = file(APP_ROOT . '/functions/app-helpers.php');
        $body = implode('', array_slice($source, $startLine - 1, $endLine - $startLine));

        preg_match_all("/'([a-z0-9_.]+\.php)'\s*=>/", $body, $matches);
        $this->assertNotEmpty($matches[1], 'permissionForScript() map entries not found.');

        $stale = [];
        foreach ($matches[1] as $script) {
            $found = false;
            foreach (['admin', 'teacher', 'student', 'parent'] as $portal) {
                $candidates = glob(APP_ROOT . '/' . $portal . '/*.php') ?: [];
                foreach ($candidates as $path) {
                    if (strtolower(basename($path)) === $script) {
                        $found = true;
                        break 2;
                    }
                }
            }
            if (!$found) {
                $stale[] = $script;
            }
        }

        $this->assertSame(
            [],
            $stale,
            'permissionForScript() contains keys pointing to files that do not exist.'
        );
    }
}
