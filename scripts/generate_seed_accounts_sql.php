<?php

require_once __DIR__ . '/../vendor/autoload.php';

$defaultPassword = getDefaultNewUserPassword();
$passwordError = null;
if (!validateStrongPassword($defaultPassword, $passwordError)) {
    fwrite(STDERR, 'DEFAULT_NEW_USER_PASSWORD is not strong enough: ' . $passwordError . "\n");
    exit(1);
}

$templatePath = __DIR__ . '/../database/seed_admin.sql';
$template = file_get_contents($templatePath);
if (!is_string($template)) {
    fwrite(STDERR, "Unable to read database/seed_admin.sql.\n");
    exit(1);
}

$generated = str_replace(
    '{{DEFAULT_PASSWORD_HASH}}',
    password_hash($defaultPassword, PASSWORD_BCRYPT),
    $template
);
if (str_contains($generated, '{{')) {
    fwrite(STDERR, "Seed SQL generation failed because a template placeholder remains.\n");
    exit(1);
}

$outputDirectory = __DIR__ . '/../storage/generated';
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0700, true) && !is_dir($outputDirectory)) {
    fwrite(STDERR, "Unable to create storage/generated.\n");
    exit(1);
}

$outputPath = $outputDirectory . '/seed_system_accounts.sql';
if (file_put_contents($outputPath, $generated, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write generated seed SQL.\n");
    exit(1);
}
@chmod($outputPath, 0600);
echo "Generated private seed SQL: storage/generated/seed_system_accounts.sql\n";
