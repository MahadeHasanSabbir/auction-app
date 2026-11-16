<?php

namespace App\Enums;

/**
 * The five account kinds. Platform roles run the marketplace, company roles
 * sell on it, buyers bid on it. An account has exactly one role.
 */
enum UserRole: string
{
    case SystemOwner = 'system_owner';
    case PlatformStaff = 'platform_staff';
    case CompanyOwner = 'company_owner';
    case CompanyMember = 'company_member';
    case Buyer = 'buyer';

    public function label(): string
    {
        return match ($this) {
            self::SystemOwner => __('System owner'),
            self::PlatformStaff => __('Platform staff'),
            self::CompanyOwner => __('Company owner'),
            self::CompanyMember => __('Company member'),
            self::Buyer => __('Buyer'),
        };
    }

    /** Works in the admin panel. */
    public function isPlatform(): bool
    {
        return $this === self::SystemOwner || $this === self::PlatformStaff;
    }

    /** Works in the seller panel on behalf of a company. */
    public function isSeller(): bool
    {
        return $this === self::CompanyOwner || $this === self::CompanyMember;
    }

    public function isBuyer(): bool
    {
        return $this === self::Buyer;
    }

    /** @return list<self> */
    public static function platform(): array
    {
        return [self::SystemOwner, self::PlatformStaff];
    }

    /** @return list<self> */
    public static function sellers(): array
    {
        return [self::CompanyOwner, self::CompanyMember];
    }
}
