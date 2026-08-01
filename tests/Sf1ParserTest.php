<?php

use PHPUnit\Framework\TestCase;

final class Sf1ParserTest extends TestCase
{
    public function testParsesExcelSerialBirthdate(): void
    {
        $this->assertSame('2008-01-15', Sf1Parser::parseBirthdate('39462'));
    }

    public function testParsesSlashBirthdate(): void
    {
        $this->assertSame('2008-01-15', Sf1Parser::parseBirthdate('01/15/2008'));
    }
}
