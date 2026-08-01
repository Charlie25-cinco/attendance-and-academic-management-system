<?php

use PHPUnit\Framework\TestCase;

final class Sf1ParserTest extends TestCase
{
    public function testParseBirthdateSupportsExcelSerialDate(): void
    {
        self::assertSame('2007-05-15', Sf1Parser::parseBirthdate('39217'));
    }

    public function testParseBirthdateSupportsIsoDate(): void
    {
        self::assertSame('2007-05-15', Sf1Parser::parseBirthdate('2007-05-15'));
    }

    public function testParseBirthdateRejectsEmptyValue(): void
    {
        self::assertNull(Sf1Parser::parseBirthdate(''));
    }
}

