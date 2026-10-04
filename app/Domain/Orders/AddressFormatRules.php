<?php

namespace App\Domain\Orders;

use CommerceGuys\Addressing\AddressFormat\AddressField;
use CommerceGuys\Addressing\AddressFormat\AddressFormat;
use CommerceGuys\Addressing\AddressFormat\AddressFormatHelper;
use CommerceGuys\Addressing\AddressFormat\AddressFormatRepository;
use CommerceGuys\Addressing\AddressFormat\FieldOverrides;

/**
 * Country-specific address requirements (required fields and postal code formats) from the
 * Google address metadata bundled with commerceguys/addressing.
 */
class AddressFormatRules
{
    public function __construct(private readonly AddressFormatRepository $formats = new AddressFormatRepository) {}

    public function requiresPostalCode(string $countryCode): bool
    {
        return in_array(AddressField::POSTAL_CODE, $this->requiredFields($countryCode), true);
    }

    public function requiresAdministrativeArea(string $countryCode): bool
    {
        return in_array(AddressField::ADMINISTRATIVE_AREA, $this->requiredFields($countryCode), true);
    }

    /**
     * Whether the postal code fully matches the country's format. Countries without a known
     * format accept any value.
     */
    public function isValidPostalCode(string $countryCode, string $postalCode): bool
    {
        $pattern = $this->format($countryCode)->getPostalCodePattern();
        if ($pattern === null || $pattern === '') {
            return true;
        }

        $postalCode = trim($postalCode);

        return preg_match('/'.$pattern.'/i', $postalCode, $matches) === 1 && $matches[0] === $postalCode;
    }

    /** @return string[] */
    private function requiredFields(string $countryCode): array
    {
        return AddressFormatHelper::getRequiredFields($this->format($countryCode), new FieldOverrides([]));
    }

    private function format(string $countryCode): AddressFormat
    {
        return $this->formats->get(strtoupper(trim($countryCode)));
    }
}
