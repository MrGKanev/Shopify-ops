<?php

namespace App\Domain\Orders;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Validates shipping phone numbers against the numbering plan of the destination country.
 */
class PhoneNumberValidator
{
    /**
     * Whether the phone number is dialable in the given country. Numbers with an explicit
     * international prefix (+…) are checked against their own country code.
     */
    public function isValid(string $phone, string $countryCode): bool
    {
        return $this->parseValid($phone, $countryCode) !== null;
    }

    /**
     * The number in international E.164 form (e.g. +16175550100), or null when it is not valid.
     */
    public function toE164(string $phone, string $countryCode): ?string
    {
        $number = $this->parseValid($phone, $countryCode);

        return $number === null ? null : PhoneNumberUtil::getInstance()->format($number, PhoneNumberFormat::E164);
    }

    private function parseValid(string $phone, string $countryCode): ?PhoneNumber
    {
        $phone = trim($phone);
        $region = strtoupper(trim($countryCode));
        if ($phone === '' || ($region === '' && ! str_starts_with($phone, '+'))) {
            return null;
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            $number = $util->parse($phone, $region === '' ? null : $region);
        } catch (NumberParseException) {
            return null;
        }

        return $util->isValidNumber($number) ? $number : null;
    }
}
