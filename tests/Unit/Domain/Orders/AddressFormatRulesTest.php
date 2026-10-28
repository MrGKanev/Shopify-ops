<?php

namespace Tests\Unit\Domain\Orders;

use App\Domain\Orders\AddressFormatRules;
use PHPUnit\Framework\TestCase;

class AddressFormatRulesTest extends TestCase
{
    public function test_country_requirements_come_from_the_address_metadata(): void
    {
        $rules = new AddressFormatRules;

        $this->assertTrue($rules->requiresPostalCode('US'));
        $this->assertTrue($rules->requiresAdministrativeArea('US'));
        $this->assertTrue($rules->requiresPostalCode(' gb '));
        $this->assertFalse($rules->requiresAdministrativeArea('GB'));
        $this->assertFalse($rules->requiresPostalCode('AE'));
    }

    public function test_postal_codes_must_match_the_whole_country_format(): void
    {
        $rules = new AddressFormatRules;

        $this->assertTrue($rules->isValidPostalCode('US', '78701'));
        $this->assertTrue($rules->isValidPostalCode('US', ' 78701-1234 '));
        $this->assertFalse($rules->isValidPostalCode('US', '7870'));
        $this->assertFalse($rules->isValidPostalCode('US', '78701 extra'));
        $this->assertTrue($rules->isValidPostalCode('GB', 'sw1a 1aa'));
        $this->assertFalse($rules->isValidPostalCode('GB', '12345'));
        $this->assertTrue($rules->isValidPostalCode('BG', '1000'));
    }

    public function test_countries_without_a_known_postal_format_accept_any_value(): void
    {
        $this->assertTrue((new AddressFormatRules)->isValidPostalCode('AE', 'anything'));
    }
}
