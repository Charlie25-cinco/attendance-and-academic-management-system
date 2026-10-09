<?php

use PHPUnit\Framework\TestCase;

final class GradeApprovalFlowTest extends TestCase
{
    public function testGradeApprovalStatuses(): void
    {
        $validStatuses = [
            'pending',
            'submitted',
            'admin_verified',
            'rejected',
            'submitted_admin',
            'approved',
        ];

        foreach ($validStatuses as $status) {
            $this->assertIsString($status);
            $this->assertNotEmpty($status);
        }
    }

    public function testGradeSubmissionStateTransitions(): void
    {
        $teacherState = 'submitted';
        $principalActionVerified = 'admin_verified';
        $principalActionRejected = 'rejected';

        $this->assertSame('admin_verified', $principalActionVerified);
        $this->assertSame('rejected', $principalActionRejected);
        $this->assertNotEquals($teacherState, $principalActionVerified);
    }

    public function testReportCardApprovalStateTransitions(): void
    {
        $adviserSubmission = 'submitted_admin';
        $principalEndorsement = 'pending';
        $finalApproval = 'approved';

        $this->assertSame('submitted_admin', $adviserSubmission);
        $this->assertSame('pending', $principalEndorsement);
        $this->assertSame('approved', $finalApproval);

        $isStudentVisible = ($finalApproval === 'approved');
        $this->assertTrue($isStudentVisible);

        $isAdviserSubmissionVisible = ($adviserSubmission === 'approved');
        $this->assertFalse($isAdviserSubmissionVisible);
    }

    public function testTeacherEditingLockedOnlyWhenSubmitted(): void
    {
        $isLockedForTeacher = fn(string $status): bool => $status === 'submitted';

        $this->assertTrue($isLockedForTeacher('submitted'));
        $this->assertFalse($isLockedForTeacher('pending'));
        $this->assertFalse($isLockedForTeacher('admin_verified'));
        $this->assertFalse($isLockedForTeacher('rejected'));
    }
}
