<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = Usuario::query()
            ->with('area')
            ->where('correo', $data['email'])
            ->first();

        if (!$user) {
            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

        $storedPassword = (string) $user->contraseña;
        $isHash = str_starts_with($storedPassword, '$2y$') || str_starts_with($storedPassword, '$2b$');

        $validPassword = $isHash
            ? Hash::check($data['password'], $storedPassword)
            : hash_equals($storedPassword, $data['password']);

        if ($validPassword && !$isHash) {
            $user->contraseña = Hash::make($data['password']);
            $user->save();
        }

        if (!$validPassword) {
            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

        if (strtolower((string) $user->estado_cuenta) !== 'activo') {
            return response()->json(['message' => 'La cuenta está inactiva. Contacta al administrador.'], 403);
        }

        return response()->json([
            'requiresPasswordChange' => (bool) $user->debe_cambiar_password,
            'user' => [
                'rawId' => (int) $user->ID_usuario,
                'id' => 'USR-' . str_pad((string) $user->ID_usuario, 3, '0', STR_PAD_LEFT),
                'name' => $user->nombre_completo,
                'role' => $user->rol,
                'department' => $user->area?->nombre_area ?? 'Sin área',
                'initials' => mb_strtoupper(substr((string) $user->nombre_completo, 0, 1)),
                'email' => $user->correo,
                'areaId' => $user->ID_area,
                'mustChangePassword' => (bool) $user->debe_cambiar_password,
            ],
        ]);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'userId' => ['required', 'integer'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        /** @var Usuario $user */
        $user = Usuario::query()->findOrFail($data['userId']);

        if (!(bool) $user->debe_cambiar_password) {
            return response()->json([
                'message' => 'Este usuario no tiene cambio obligatorio pendiente.',
            ], 422);
        }

        $user->contraseña = Hash::make($data['password']);
        $user->debe_cambiar_password = false;
        $user->save();

        return response()->json([
            'message' => 'Contraseña actualizada correctamente.',
        ]);
    }
}
