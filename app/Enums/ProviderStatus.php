<?php

namespace App\Enums;

enum ProviderStatus: string
{
    case Unverified = 'unverified';
    case Connected = 'connected';
    case Invalid = 'invalid';
    case Error = 'error';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Not tested',
            self::Connected => 'Connected',
            self::Invalid => 'Invalid key',
            self::Error => 'Unreachable',
            self::Revoked => 'Revoked',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Connected => 'success',
            self::Invalid, self::Revoked => 'error',
            self::Error => 'warning',
            self::Unverified => 'muted',
        };
    }
}
