<?php

use PHPUnit\Framework\TestCase;

final class ProfileNameCapitalizationTest extends TestCase
{
    public function testProfileModalInputsHaveAutoCapitalizeWordsAttribute(): void
    {
        $modals = file_get_contents(__DIR__ . '/../includes/modals.php');
        $this->assertIsString($modals);

        $this->assertStringContainsString('id="profileFirstName"', $modals);
        $this->assertStringContainsString('id="profileMiddleName"', $modals);
        $this->assertStringContainsString('id="profileLastName"', $modals);

        // Verify autocapitalize="words" attribute
        $this->assertMatchesRegularExpression('/id="profileFirstName"[\s\S]*?autocapitalize="words"/', $modals);
        $this->assertMatchesRegularExpression('/id="profileMiddleName"[\s\S]*?autocapitalize="words"/', $modals);
        $this->assertMatchesRegularExpression('/id="profileLastName"[\s\S]*?autocapitalize="words"/', $modals);
    }

    public function testProfileModalScriptIncludesTitleCaseFormatter(): void
    {
        $modals = file_get_contents(__DIR__ . '/../includes/modals.php');
        $this->assertIsString($modals);

        $this->assertStringContainsString('function toTitleCase(str)', $modals);
        $this->assertStringContainsString('input.addEventListener(\'blur\'', $modals);
        $this->assertStringContainsString('input.addEventListener(\'input\'', $modals);
        $this->assertStringContainsString('payload.first_name = toTitleCase', $modals);
        $this->assertStringContainsString('payload.last_name = toTitleCase', $modals);
    }

    public function testProfileApiRouteAppliesTitleCaseNormalization(): void
    {
        $route = file_get_contents(__DIR__ . '/../api/routes/03-profile.php');
        $this->assertIsString($route);

        $this->assertStringContainsString('MB_CASE_TITLE', $route);
        $this->assertStringContainsString('mb_convert_case($value, MB_CASE_TITLE, \'UTF-8\')', $route);
    }

    public function testNameTitleCaseTransformationLogic(): void
    {
        $rawFirstName = 'juan';
        $rawMiddleName = 'delos santos';
        $rawLastName = 'dela cruz';

        $titleFirstName = mb_convert_case($rawFirstName, MB_CASE_TITLE, 'UTF-8');
        $titleMiddleName = mb_convert_case($rawMiddleName, MB_CASE_TITLE, 'UTF-8');
        $titleLastName = mb_convert_case($rawLastName, MB_CASE_TITLE, 'UTF-8');

        $this->assertSame('Juan', $titleFirstName);
        $this->assertSame('Delos Santos', $titleMiddleName);
        $this->assertSame('Dela Cruz', $titleLastName);
    }
}
