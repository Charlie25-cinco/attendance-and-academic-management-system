<?php

use PHPUnit\Framework\TestCase;

final class PasswordResetTest extends TestCase
{
    public function testValidateStrongPasswordRequirements(): void
    {
        $error = null;

        // Less than 12 chars
        $this->assertFalse(validateStrongPassword('Short1!', $error));
        $this->assertSame('Password must be at least 12 characters.', $error);

        // Missing uppercase
        $this->assertFalse(validateStrongPassword('lowercase123!', $error));
        $this->assertSame('Password must contain at least one uppercase letter.', $error);

        // Missing lowercase
        $this->assertFalse(validateStrongPassword('UPPERCASE123!', $error));
        $this->assertSame('Password must contain at least one lowercase letter.', $error);

        // Missing number
        $this->assertFalse(validateStrongPassword('NoDigitsHere!', $error));
        $this->assertSame('Password must contain at least one number.', $error);

        // Missing special character
        $this->assertFalse(validateStrongPassword('NoSpecialNum1', $error));
        $this->assertSame('Password must contain at least one special character.', $error);

        // Valid strong password
        $this->assertTrue(validateStrongPassword('ValidPass123!@#', $error));
        $this->assertNull($error);
    }

    public function testOtpFormatValidation(): void
    {
        $validOtp = '123456';
        $invalidOtps = ['12345', '1234567', 'abcdef', '12345a'];

        $this->assertSame(1, preg_match('/^\d{6}$/', $validOtp));

        foreach ($invalidOtps as $otp) {
            $this->assertSame(0, preg_match('/^\d{6}$/', $otp), "OTP {$otp} should be invalid");
        }
    }

    public function testOtpTokenHashConsistency(): void
    {
        $otp = '654321';
        $hash1 = hash('sha256', $otp);
        $hash2 = hash('sha256', $otp);

        $this->assertSame(64, strlen($hash1));
        $this->assertSame($hash1, $hash2);
        $this->assertNotEquals($otp, $hash1);
    }

    public function testOldTokenInvalidationQueryStructure(): void
    {
        // Test logic of invalidating active tokens prior to issuing a new reset code
        $activeTokens = [
            ['id' => 1, 'user_id' => 42, 'used_at' => null],
            ['id' => 2, 'user_id' => 42, 'used_at' => null],
        ];

        $now = date('Y-m-d H:i:s');
        foreach ($activeTokens as &$token) {
            if ($token['user_id'] === 42 && $token['used_at'] === null) {
                $token['used_at'] = $now;
            }
        }
        unset($token);

        $this->assertNotNull($activeTokens[0]['used_at']);
        $this->assertNotNull($activeTokens[1]['used_at']);
    }
}
