<?php

use App\Http\Middleware\DynamicRBACMiddleware;
use App\Http\Middleware\PublicTenantMiddleware;
use App\Http\Middleware\SetTenantMiddleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Lapis 0 & 2 RBAC De'Corrinna (docs/06-rbac-decorina.md).
        $middleware->alias([
            'tenant' => SetTenantMiddleware::class,
            'tenant.public' => PublicTenantMiddleware::class,
            'rbac' => DynamicRBACMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Semua error di jalur API dikembalikan sebagai JSON dengan bentuk
        // standar: { "success": false, "message": ..., "errors"?: ... }
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null; // biarkan handler default (web) yang menangani
            }

            [$status, $message, $errors] = match (true) {
                $e instanceof ValidationException => [
                    422,
                    'Data yang dikirim tidak valid.',
                    $e->errors(),
                ],
                $e instanceof AuthenticationException => [
                    401,
                    'Belum terautentikasi.',
                    null,
                ],
                $e instanceof AuthorizationException => [
                    403,
                    'Tidak punya akses untuk tindakan ini.',
                    null,
                ],
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => [
                    404,
                    'Data tidak ditemukan.',
                    null,
                ],
                $e instanceof HttpExceptionInterface => [
                    $e->getStatusCode(),
                    $e->getMessage() ?: 'Terjadi kesalahan.',
                    null,
                ],
                default => [
                    500,
                    config('app.debug') ? $e->getMessage() : 'Terjadi kesalahan pada server.',
                    null,
                ],
            };

            $payload = [
                'success' => false,
                'message' => $message,
            ];

            if ($errors !== null) {
                $payload['errors'] = $errors;
            }

            return response()->json($payload, $status);
        });
    })->create();
