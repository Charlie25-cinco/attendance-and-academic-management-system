<?php

namespace BshsAms\User;

final class SystemAccountPolicy
{
    public const PRINCIPAL_REFERENCE_CODE = 'PR341227-1';
    public const PRINCIPAL_EMAIL = 'PR341227-1@balingasag.edu.ph';

    public static function isPrincipalRole(?string $role): bool
    {
        return strtolower(trim((string)$role)) === 'principal';
    }

    public static function adminMayMutateRole(?string $role): bool
    {
        return !self::isPrincipalRole($role);
    }

    public static function protectedMessage(): string
    {
        return 'The Principal account is protected and can only be changed by the Principal or a deployment operator.';
    }
}
