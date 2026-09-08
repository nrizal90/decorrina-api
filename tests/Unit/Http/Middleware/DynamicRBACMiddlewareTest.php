<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\DynamicRBACMiddleware;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Lapis 2 fail-closed: route tanpa nama ditolak, permission dicocokkan 1:1
 * dengan nama route (dok 06 §5). Tanpa DB — user dan route ditiru.
 */
class DynamicRBACMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** Request dengan nama route tertentu (null = route tanpa nama). */
    private function request(?string $routeName, ?User $user = null): Request
    {
        $route = Mockery::mock(Route::class);
        $route->shouldReceive('getName')->andReturn($routeName);

        $request = Request::create('/api/admin/items', 'GET');
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function user(array $granted): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => 1, 'tenant_id' => 3]);
        $user->shouldReceive('can')->andReturnUsing(fn ($p) => in_array($p, $granted, true));
        $user->shouldReceive('getRoleNames')->andReturn(collect(['admin']));

        return $user;
    }

    private function handle(Request $request): mixed
    {
        return (new DynamicRBACMiddleware)->handle($request, fn () => new Response('lolos'));
    }

    public function test_unnamed_route_is_rejected_with_403(): void
    {
        try {
            $this->handle($this->request(null, $this->user([])));
            $this->fail('Route tanpa nama seharusnya ditolak.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_unnamed_route_is_rejected_before_checking_the_user(): void
    {
        $this->expectException(HttpException::class);

        $this->handle($this->request(null));
    }

    public function test_guest_on_a_named_route_gets_401(): void
    {
        try {
            $this->handle($this->request('items:read'));
            $this->fail('Guest seharusnya ditolak.');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function test_user_with_matching_permission_passes_through(): void
    {
        $result = $this->handle($this->request('items:read', $this->user(['items:read'])));

        $this->assertSame('lolos', $result->getContent());
    }

    public function test_permission_name_must_match_the_route_name_exactly(): void
    {
        try {
            $this->handle($this->request('items:delete', $this->user(['items:read', 'items:create'])));
            $this->fail('Permission lain tidak boleh meloloskan route ini.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_denied_access_is_logged_with_audit_context(): void
    {
        $logged = [];
        Log::shouldReceive('warning')->once()->andReturnUsing(function ($message, $context) use (&$logged) {
            $logged = ['message' => $message] + $context;
        });

        try {
            $this->handle($this->request('items:delete', $this->user([])));
            $this->fail('Akses seharusnya ditolak.');
        } catch (HttpException) {
            // ditolak sesuai harapan; yang diuji di sini adalah jejak auditnya
        }

        $this->assertStringContainsString('akses ditolak', $logged['message']);
        $this->assertSame('items:delete', $logged['route']);
        $this->assertSame(['items:delete'], $logged['required_permission']);
        $this->assertSame(1, $logged['user_id']);
        $this->assertSame(3, $logged['tenant_id']);
    }
}
