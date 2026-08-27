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

/**
 * Auth (A11). Endpoint ini TIDAK diberi ->name() dan TIDAK lewat middleware
 * `rbac` (bukan route bisnis terproteksi permission) — lihat dok 06 §5, §11.
 */
class AuthController extends Controller
{
    /**
     * Registrasi publik → membuat akun Customer (global, tenant_id NULL).
     */
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
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->ok(null, 'Logout berhasil');
    }

    /**
     * Profil user terautentikasi + roles + permissions + tenant_id.
     */
    public function me(Request $request): JsonResponse
    {
        return $this->ok(new UserResource($request->user()), 'OK');
    }

    private function deviceName(Request $request): string
    {
        return $request->input('device_name', 'spa');
    }
}
