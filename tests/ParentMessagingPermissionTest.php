<?php

use PHPUnit\Framework\TestCase;

final class ParentMessagingPermissionTest extends TestCase
{
    public function testParentChatRoutesAndDefaultRolePermissionsStayAligned(): void
    {
        $content = file_get_contents(__DIR__ . '/../functions/app-helpers.php');
        $this->assertIsString($content);

        $this->assertStringContainsString("'parent_chat.php' => 'messages.view'", $content);
        $this->assertStringContainsString("'parent_chat_action.php' => 'messages.send'", $content);

        $parentPermsStart = strpos($content, '$parentPerms =');
        $roleMapStart = strpos($content, '$roleMap =', $parentPermsStart);
        $this->assertIsInt($parentPermsStart);
        $this->assertIsInt($roleMapStart);

        $parentPermsBlock = substr($content, $parentPermsStart, $roleMapStart - $parentPermsStart);
        $this->assertStringContainsString("'messages.view'", $parentPermsBlock);
        $this->assertStringContainsString("'messages.send'", $parentPermsBlock);
    }

    public function testRbacSeedingDoesNotSkipExistingDatabasesBeforeAddingMissingDefaults(): void
    {
        $content = file_get_contents(__DIR__ . '/../functions/app-helpers.php');
        $this->assertIsString($content);

        $functionStart = strpos($content, 'function ensureRbacRolesSeeded');
        $nextFunctionStart = strpos($content, 'function loadRbacPermissions', $functionStart);
        $this->assertIsInt($functionStart);
        $this->assertIsInt($nextFunctionStart);

        $functionBlock = substr($content, $functionStart, $nextFunctionStart - $functionStart);
        $this->assertStringNotContainsString('mappingCount', $functionBlock);
        $this->assertStringContainsString('INSERT IGNORE INTO rbac_role_permissions', $functionBlock);
        $this->assertStringContainsString("'parent' => \$parentPerms", $functionBlock);
    }

    public function testParentDashboardSwitcherKeepsReferenceTextReadable(): void
    {
        $css = file_get_contents(__DIR__ . '/../assets/css/main.css');
        $this->assertIsString($css);
        $markup = file_get_contents(__DIR__ . '/../parent/Parent.php');
        $this->assertIsString($markup);

        $this->assertStringContainsString('.portal-switcher .btn-primary-custom small', $css);
        $this->assertMatchesRegularExpression('/\.portal-switcher\s+\.btn-primary-custom\s+small\s*\{[^}]*color:\s*rgba\(255,\s*255,\s*255,\s*0\.88\)/s', $css);
        $this->assertStringContainsString('class="portal-chip-reference ms-1"', $markup);
        $this->assertMatchesRegularExpression('/\.portal-switcher\s+\.portal-chip\.btn-primary-custom\s+\.portal-chip-reference\s*\{[^}]*color:\s*rgba\(255,\s*255,\s*255,\s*0\.92\)\s*!important/s', $css);
        $this->assertStringContainsString('max-width: min(360px, 28vw);', $css);
        $this->assertStringContainsString('max-width: min(380px, 30vw);', $css);
        $this->assertStringContainsString('.header-profile-meta', $css);
        $this->assertStringContainsString('min-width: 0;', $css);
    }
}
