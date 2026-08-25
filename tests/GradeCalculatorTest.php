<?php

use PHPUnit\Framework\TestCase;

final class GradeCalculatorTest extends TestCase
{
    public function testDefaultWeightsBySubjectCategory(): void
    {
        $core = SshsGradeCalculator::defaultWeights('core');
        $this->assertSame(['ww' => 25, 'pt' => 50, 'assessment' => 25], $core);

        $academicElective = SshsGradeCalculator::defaultWeights('academic_elective');
        $this->assertSame(['ww' => 25, 'pt' => 45, 'assessment' => 30], $academicElective);

        $research = SshsGradeCalculator::defaultWeights('research');
        $this->assertSame(['ww' => 40, 'pt' => 60, 'assessment' => 0], $research);

        $workImmersion = SshsGradeCalculator::defaultWeights('work_immersion');
        $this->assertSame(['ww' => 20, 'pt' => 60, 'assessment' => 20], $workImmersion);

        $techproElective = SshsGradeCalculator::defaultWeights('techpro_elective');
        $this->assertSame(['ww' => 20, 'pt' => 60, 'assessment' => 20], $techproElective);

        $tvlImmersion = SshsGradeCalculator::defaultWeights('tvl_immersion');
        $this->assertSame(['ww' => 20, 'pt' => 80, 'assessment' => 0], $tvlImmersion);

        $fieldExp = SshsGradeCalculator::defaultWeights('field_experience_elective');
        $this->assertSame(['ww' => 15, 'pt' => 65, 'assessment' => 20], $fieldExp);

        $otherElective = SshsGradeCalculator::defaultWeights('other_elective');
        $this->assertSame(['ww' => 20, 'pt' => 50, 'assessment' => 30], $otherElective);

        $defaultFallback = SshsGradeCalculator::defaultWeights('unknown_category');
        $this->assertSame(['ww' => 25, 'pt' => 50, 'assessment' => 25], $defaultFallback);
    }

    public function testTransmute(): void
    {
        $this->assertNull(SshsGradeCalculator::transmute(null));
        $this->assertNull(SshsGradeCalculator::transmute(-5.0));

        $this->assertEquals(60.0, SshsGradeCalculator::transmute(0.0));
        $this->assertEquals(60.0, SshsGradeCalculator::transmute(3.9));
        $this->assertEquals(65.0, SshsGradeCalculator::transmute(20.0));
        $this->assertEquals(75.0, SshsGradeCalculator::transmute(60.0));
        $this->assertEquals(90.0, SshsGradeCalculator::transmute(84.0));
        $this->assertEquals(100.0, SshsGradeCalculator::transmute(100.0));
    }

    public function testProficiencyLevelAndSf9Level(): void
    {
        $this->assertSame('N/A', SshsGradeCalculator::proficiencyLevel(null));
        $this->assertSame('Outstanding', SshsGradeCalculator::proficiencyLevel(95.0));
        $this->assertSame('Very Satisfactory', SshsGradeCalculator::proficiencyLevel(87.0));
        $this->assertSame('Satisfactory', SshsGradeCalculator::proficiencyLevel(82.0));
        $this->assertSame('Fairly Satisfactory', SshsGradeCalculator::proficiencyLevel(76.0));
        $this->assertSame('Did Not Meet Expectations', SshsGradeCalculator::proficiencyLevel(72.0));

        $this->assertSame('', SshsGradeCalculator::sf9Level(null));
        $this->assertSame('O', SshsGradeCalculator::sf9Level(92.0));
        $this->assertSame('VS', SshsGradeCalculator::sf9Level(86.0));
        $this->assertSame('S', SshsGradeCalculator::sf9Level(81.0));
        $this->assertSame('FS', SshsGradeCalculator::sf9Level(75.0));
        $this->assertSame('DNME', SshsGradeCalculator::sf9Level(70.0));
    }

    public function testGradingSystemAndValidTerms(): void
    {
        $this->assertSame('3_term', SshsGradeCalculator::gradingSystem('2026-2027'));
        $this->assertSame('3_term', SshsGradeCalculator::gradingSystem('2027-2028'));
        $this->assertSame('4_quarter', SshsGradeCalculator::gradingSystem('2025-2026'));
        $this->assertSame('3_term', SshsGradeCalculator::gradingSystem(null));

        $this->assertSame(['Term1', 'Term2', 'Term3'], SshsGradeCalculator::validTerms('3_term'));
        $this->assertSame(['Q1', 'Q2', 'Q3', 'Q4'], SshsGradeCalculator::validTerms('4_quarter'));
    }

    public function testSubjectTermCount(): void
    {
        $this->assertSame(3, SshsGradeCalculator::subjectTermCount('core', '3_term'));
        $this->assertSame(1, SshsGradeCalculator::subjectTermCount('academic_elective', '3_term'));
        $this->assertSame(4, SshsGradeCalculator::subjectTermCount('core', '4_quarter'));
        $this->assertSame(2, SshsGradeCalculator::subjectTermCount('academic_elective', '4_quarter'));
    }

    public function testFinalGradeCalculation(): void
    {
        $this->assertNull(SshsGradeCalculator::finalGrade([]));
        $this->assertNull(SshsGradeCalculator::finalGrade([null, null]));

        $this->assertEquals(88.0, SshsGradeCalculator::finalGrade([85.0, 90.0, 88.0]));
        $this->assertEquals(88.0, SshsGradeCalculator::finalGrade([85.0, 90.0, null]));
    }

    public function testCombinedSubjectHelpers(): void
    {
        $this->assertTrue(SshsGradeCalculator::isCombinedSubject('Effective Communication in Business'));
        $this->assertTrue(SshsGradeCalculator::isCombinedSubject('Mabisang Komunikasyon sa Akademikong Filipino'));
        $this->assertFalse(SshsGradeCalculator::isCombinedSubject('General Mathematics'));

        $this->assertSame('ec', SshsGradeCalculator::combinedSubjectKey('Effective Communication'));
        $this->assertSame('mk', SshsGradeCalculator::combinedSubjectKey('Mabisang Komunikasyon'));

        $this->assertNull(SshsGradeCalculator::combineGrades(null, null));
        $this->assertEquals(85.0, SshsGradeCalculator::combineGrades(85.0, null));
        $this->assertEquals(90.0, SshsGradeCalculator::combineGrades(null, 90.0));
        $this->assertEquals(88.0, SshsGradeCalculator::combineGrades(85.0, 90.0));
    }
}
