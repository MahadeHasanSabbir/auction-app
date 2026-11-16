<?php

namespace App\Enums;

enum KycDocumentType: string
{
    case NationalId = 'national_id';
    case Passport = 'passport';
    case DrivingLicence = 'driving_licence';
    case TradeLicence = 'trade_licence';
    case TaxCertificate = 'tax_certificate';
    case BankStatement = 'bank_statement';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NationalId => __('National ID'),
            self::Passport => __('Passport'),
            self::DrivingLicence => __('Driving licence'),
            self::TradeLicence => __('Trade licence'),
            self::TaxCertificate => __('Tax certificate'),
            self::BankStatement => __('Bank statement'),
            self::Other => __('Other document'),
        };
    }
}
