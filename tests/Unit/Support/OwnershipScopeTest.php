<?php

namespace Tests\Unit\Support;

use App\Models\User;
use App\Support\OwnershipScope;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Lapis 3 ownership: hanya role `customer` yang dipersempit ke datanya sendiri
 * (dok 06 §6). Diuji dengan builder tiruan supaya tak butuh DB.
 */
class OwnershipScopeTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function user(bool $isCustomer, int $id = 7): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasRole')->with('customer')->andReturn($isCustomer);
        $user->forceFill(['id' => $id]);

        return $user;
    }

    public function test_customer_query_is_narrowed_to_own_rows(): void
    {
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('where')->once()->with('user_id', 7)->andReturnSelf();

        $this->assertSame($query, OwnershipScope::forCustomer($query, $this->user(true)));
    }

    public function test_non_customer_query_is_left_untouched(): void
    {
        $query = Mockery::mock(Builder::class);
        $query->shouldNotReceive('where');

        $this->assertSame($query, OwnershipScope::forCustomer($query, $this->user(false)));
    }

    public function test_ownership_column_is_configurable(): void
    {
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('where')->once()->with('created_by', 42)->andReturnSelf();

        $this->assertSame($query, OwnershipScope::forCustomer($query, $this->user(true, 42), 'created_by'));
    }
}
