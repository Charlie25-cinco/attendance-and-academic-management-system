<?php

use PHPUnit\Framework\TestCase;

final class MiddleNameValidationTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../functions/app-helpers.php';
    }

    public function testEmptyMiddleNameIsValid(): void
    {
        $this->assertTrue(isValidMiddleName(''));
        $this->assertTrue(isValidMiddleName('   '));
    }

    public function testSingleLetterMiddleNameIsValid(): void
    {
        $this->assertTrue(isValidMiddleName('D'));
        $this->assertTrue(isValidMiddleName('M'));
        $this->assertTrue(isValidMiddleName('D.'));
        $this->assertTrue(isValidMiddleName('M.'));
    }

    public function testFullMiddleNameIsValid(): void
    {
        $this->assertTrue(isValidMiddleName('Santos'));
        $this->assertTrue(isValidMiddleName('Dela Cruz'));
        $this->assertTrue(isValidMiddleName('Del Rosario'));
    }

    public function testInvalidMiddleNamesAreRejected(): void
    {
        $this->assertFalse(isValidMiddleName('123'));
        $this->assertFalse(isValidMiddleName('D123'));
        $this->assertFalse(isValidMiddleName('Santos@'));
        $this->assertFalse(isValidMiddleName('...'));
    }
}