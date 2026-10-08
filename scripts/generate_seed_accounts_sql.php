<?php

require_once __DIR__ . '/../vendor/autoload.php';

$adminPassword = trim((string)appEnvValue('FIRST_RUN_ADMIN_PASSWORD', ''));
if ($adminPassword === '') {
    fwrite(STDERR, "FIRST_RUN_ADMIN_PASSWORD must be set before generating seed SQL.\n");
    exit(1);
}

$adminError = null;
if (!validateStrongPassword($adminPassword, $adminError)) {
    fwrite(STDERR, 'FIRST_RUN_ADMIN_PASSWORD is not strong enough: ' . $adminError . "\n");
    exit(1);
}

try {
    $principalPassword = getFirstRunPrincipalPassword();
    appAssertDistinctBootstrapPasswords($adminPassword, $principalPassword);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$templatePath = __DIR__ . '/../database/seed_admin.sql';
$template = file_get_contents($templatePath);
if (!is_string($template)) {
    fwrite(STDERR, "Unable to read database/seed_admin.sql.\n");
    exit(1);
}

$generated = str_replace(
    ['{{ADMIN_PASSWORD_HASH}}', '{{PRINCIPAL_PASSWORD_HASH}}'],
    [password_hash($adminPassword, PASSWORD_BCRYPT), password_hash($principalPassword, PASSWORD_BCRYPT)],
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
