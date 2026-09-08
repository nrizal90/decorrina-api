<?php

namespace Tests\Unit\Models;

use App\Models\Item;
use PHPUnit\Framework\TestCase;

/**
 * Kontrak tipe atribut Item — sumber kebenaran harga & kapasitas untuk booking
 * (Fase 3). Postgres mengembalikan numerik sebagai string, jadi cast wajib.
 */
class ItemTest extends TestCase
{
    public function test_numeric_attributes_are_cast_to_integer(): void
    {
        $item = (new Item)->forceFill([
            'cap_min' => '2',
            'cap_max' => '10',
            'price_weekday' => '1500000',
            'price_weekend' => '2000000',
            'dp_minimum' => '500000',
        ]);

        $this->assertSame(2, $item->cap_min);
        $this->assertSame(10, $item->cap_max);
        $this->assertSame(1500000, $item->price_weekday);
        $this->assertSame(2000000, $item->price_weekend);
        $this->assertSame(500000, $item->dp_minimum);
    }

    public function test_requires_survey_is_cast_to_boolean(): void
    {
        $this->assertTrue((new Item)->forceFill(['requires_survey' => 1])->requires_survey);
        $this->assertFalse((new Item)->forceFill(['requires_survey' => 0])->requires_survey);
    }

    public function test_nullable_numerics_stay_null(): void
    {
        $item = (new Item)->forceFill(['dp_minimum' => null, 'cap_max' => null]);

        $this->assertNull($item->dp_minimum);
        $this->assertNull($item->cap_max);
    }

    public function test_payment_modes_match_the_frontend_radio_values(): void
    {
        $this->assertSame([
            'Full Payment',
            'DP + Pelunasan',
            'Keduanya — customer memilih',
        ], Item::PAYMENT_MODES);
    }

    public function test_tenant_id_is_not_mass_assignable(): void
    {
        $item = new Item(['name' => 'Kamar Deluxe', 'tenant_id' => 99]);

        $this->assertSame('Kamar Deluxe', $item->name);
        $this->assertNull($item->tenant_id);
    }
}
