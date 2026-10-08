<?php
// Seed protected system accounts.
// Every role uses the password configured by DEFAULT_NEW_USER_PASSWORD.
// Run this after database/schema.sql is imported.
// Usage: composer run seed:admin

require_once __DIR__ . '/../functions/bootstrap.php';

use BshsAms\User\SystemAccountPolicy;

try {
    $defaultPassword = getDefaultNewUserPassword();
    $passwordError = null;
    if (!validateStrongPassword($defaultPassword, $passwordError)) {
        throw new RuntimeException('DEFAULT_NEW_USER_PASSWORD is not strong enough: ' . $passwordError);
    }
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$db = (new Database())->getConnection();
if (!$db) {
    fwrite(STDERR, "Database connection failed.\n");
    exit(1);
}

$accounts = [
    [
        'reference_code' => 'A341227-1',
        'email' => 'A341227-1@balingasag.edu.ph',
        'first_name' => 'System',
        'last_name' => 'Administrator',
        'role' => 'admin',
    ],
    [
        'reference_code' => SystemAccountPolicy::PRINCIPAL_REFERENCE_CODE,
        'email' => SystemAccountPolicy::PRINCIPAL_EMAIL,
        'first_name' => 'School',
        'last_name' => 'Principal',
        'role' => 'principal',
    ],
];

$defaultPasswordHash = password_hash($defaultPassword, PASSWORD_BCRYPT);

$db->beginTransaction();
try {
    $messages = [];
    $find = $db->prepare('SELECT id FROM users WHERE reference_code = ? LIMIT 1');
    $insert = $db->prepare(
        'INSERT INTO users (reference_code, email, password, first_name, last_name, role, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, \'active\', NOW(), NOW())'
    );
    $update = $db->prepare(
        'UPDATE users SET email = ?, password = ?, first_name = ?, last_name = ?, role = ?, status = \'active\', updated_at = NOW()
         WHERE reference_code = ?'
    );

    foreach ($accounts as $account) {
        $find->execute([$account['reference_code']]);
        if ($find->fetchColumn()) {
            $update->execute([
                $account['email'],
                $defaultPasswordHash,
                $account['first_name'],
                $account['last_name'],
                $account['role'],
                $account['reference_code'],
            ]);
            $messages[] = ucfirst($account['role']) . " account updated (ref: {$account['reference_code']}).";
            continue;
        }
        $insert->execute([
            $account['reference_code'],
            $account['email'],
            $defaultPasswordHash,
            $account['first_name'],
            $account['last_name'],
            $account['role'],
        ]);
        $messages[] = ucfirst($account['role']) . " account created (ref: {$account['reference_code']}).";
    }
    $db->commit();
    echo implode("\n", $messages) . "\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "System account seeding failed.\n");
    exit(1);
}
