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
}
