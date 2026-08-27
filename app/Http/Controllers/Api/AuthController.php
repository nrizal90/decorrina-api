<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OA;

/**
 * Auth (A11). Endpoint ini TIDAK diberi ->name() dan TIDAK lewat middleware
 * `rbac` (bukan route bisnis terproteksi permission) — lihat dok 06 §5, §11.
 */
class AuthController extends Controller
{
    /**
     * Registrasi publik → membuat akun Customer (global, tenant_id NULL).
     */
    #[OA\Post(
        path: '/api/auth/register',
        tags: ['Auth'],
        summary: 'Registrasi akun customer',
        description: 'Registrasi publik. Akun yang dibuat SELALU berrole `customer` dengan `tenant_id` NULL. Akun staff/admin dibuat lewat manajemen user (B13), bukan endpoint ini. Token Sanctum langsung diterbitkan sehingga FE tidak perlu login ulang.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'password', 'password_confirmation'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Budi Santoso'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 255, example: 'budi@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', description: 'Mengikuti Password::defaults() Laravel (minimal 8 karakter).', example: 'rahasia123'),
                    new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', description: 'Wajib sama dengan `password` (rule `confirmed`).', example: 'rahasia123'),
                    new OA\Property(property: 'device_name', type: 'string', maxLength: 255, description: 'Label token, muncul di daftar perangkat. Default `spa`.', example: 'spa'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Registrasi berhasil',
                content: new OA\JsonContent(
                    allOf: [new OA\Schema(ref: '#/components/schemas/SuccessResponse')],
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Registrasi berhasil'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'token', type: 'string', example: '3|kR7pQx2LmN...'),
                                new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Validasi gagal (mis. email sudah terpakai, konfirmasi password tidak cocok)',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')
            ),
        ]
    )]
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'tenant_id' => null,
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => Hash::make($request->validated('password')),
        ]);

        // Registrasi publik selalu jadi Customer (role global).
        $user->assignRole('customer');

        $token = $user->createToken($this->deviceName($request))->plainTextToken;

        return $this->created([
            'token' => $token,
            'user' => new UserResource($user),
        ], 'Registrasi berhasil');
    }

    /**
     * Login → verifikasi kredensial, terbitkan token Sanctum.
     */
    #[OA\Post(
        path: '/api/auth/login',
        tags: ['Auth'],
        summary: 'Login & terbitkan token',
        description: 'Memverifikasi kredensial lalu menerbitkan token Sanctum baru. Setiap login membuat token baru (login multi-perangkat diperbolehkan) — bedakan lewat `device_name`. Pesan error sengaja generik agar tidak membocorkan email mana yang terdaftar.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'budi@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'rahasia123'),
                    new OA\Property(property: 'device_name', type: 'string', maxLength: 255, description: 'Label token. Default `spa`.', example: 'spa'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Login berhasil',
                content: new OA\JsonContent(
                    allOf: [new OA\Schema(ref: '#/components/schemas/SuccessResponse')],
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Login berhasil'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'token', type: 'string', description: 'Kirim di header `Authorization: Bearer {token}`.', example: '4|aB9zXy1QwE...'),
                                new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Kredensial salah',
                content: new OA\JsonContent(
                    allOf: [new OA\Schema(ref: '#/components/schemas/ErrorResponse')],
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Email atau password salah.'),
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Validasi gagal (email/password kosong atau format email salah)',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')
            ),
        ]
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            return $this->error('Email atau password salah.', 401);
        }

        $token = $user->createToken($this->deviceName($request))->plainTextToken;

        return $this->ok([
            'token' => $token,
            'user' => new UserResource($user),
        ], 'Login berhasil');
    }

    /**
     * Logout → cabut token yang sedang dipakai.
     */
    #[OA\Post(
        path: '/api/auth/logout',
        tags: ['Auth'],
        summary: 'Logout (cabut token aktif)',
        description: 'Menghapus HANYA token yang dipakai request ini. Token perangkat lain tetap berlaku.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Logout berhasil',
                content: new OA\JsonContent(
                    allOf: [new OA\Schema(ref: '#/components/schemas/SuccessResponse')],
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Logout berhasil'),
                        new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Token tidak ada / tidak valid',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')
            ),
        ]
    )]
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->ok(null, 'Logout berhasil');
    }

    /**
     * Profil user terautentikasi + roles + permissions + tenant_id.
     */
    #[OA\Get(
        path: '/api/auth/me',
        tags: ['Auth'],
        summary: 'Profil user terautentikasi',
        description: 'Mengembalikan profil user beserta `roles` dan `permissions`. Dipakai FE saat boot untuk memulihkan sesi dari token tersimpan dan menyusun guard menu berdasar permission.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Profil user',
                content: new OA\JsonContent(
                    allOf: [new OA\Schema(ref: '#/components/schemas/SuccessResponse')],
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'OK'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/User'),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Token tidak ada / tidak valid',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')
            ),
        ]
    )]
    public function me(Request $request): JsonResponse
    {
        return $this->ok(new UserResource($request->user()), 'OK');
    }

    private function deviceName(Request $request): string
    {
        return $request->input('device_name', 'spa');
    }
}
