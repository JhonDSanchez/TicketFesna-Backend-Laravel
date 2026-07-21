<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UsuarioController extends Controller
{
    public function index(Request $request)
    {
        $users = Usuario::query()
            ->with('area')
            ->orderBy('ID_usuario')
            ->get();

        return response()->json($users->map(fn (Usuario $user) => [
            'id' => 'USR-' . str_pad((string) $user->ID_usuario, 3, '0', STR_PAD_LEFT),
            'cedula' => (string) $user->cedula,
            'name' => $user->nombre_completo,
            'email' => $user->correo,
            'role' => $user->rol,
            'department' => $user->area?->nombre_area ?? 'Sin área',
            'status' => strtolower((string) $user->estado_cuenta) === 'activo' ? 'active' : 'inactive',
            'joined' => '2024-01-01',
            'mustChangePassword' => (bool) $user->debe_cambiar_password,
        ]));
    }

    public function update(Request $request, string $id)
    {
        $userId = (int) preg_replace('/\D+/', '', $id);

        $data = $request->validate([
            'cedula' => ['required', 'string', 'max:20', 'unique:usuarios,cedula,' . $userId . ',ID_usuario'],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:usuarios,correo,' . $userId . ',ID_usuario'],
            'role' => ['required', 'string'],
            'department' => ['required', 'string'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $user = Usuario::query()->findOrFail($userId);

        $area = Area::query()
            ->where('nombre_area', $data['department'])
            ->first();

        $user->cedula = (string) $data['cedula'];
        $user->nombre_completo = $data['name'];
        $user->correo = $data['email'];
        $user->rol = $this->mapRole($data['role']);
        $user->estado_cuenta = $data['status'] === 'active' ? 'Activo' : 'Inactivo';
        $user->ID_area = $area?->ID_area;
        $user->save();

        $user->load('area');

        return response()->json([
            'id' => 'USR-' . str_pad((string) $user->ID_usuario, 3, '0', STR_PAD_LEFT),
            'cedula' => (string) $user->cedula,
            'name' => $user->nombre_completo,
            'email' => $user->correo,
            'role' => $user->rol,
            'department' => $user->area?->nombre_area ?? 'Sin área',
            'status' => strtolower((string) $user->estado_cuenta) === 'activo' ? 'active' : 'inactive',
            'joined' => '2024-01-01',
            'mustChangePassword' => (bool) $user->debe_cambiar_password,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'cedula' => ['required', 'string', 'max:20', 'unique:usuarios,cedula'],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:usuarios,correo'],
            'role' => ['required', 'string'],
            'department' => ['required', 'string'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $area = Area::query()
            ->where('nombre_area', $data['department'])
            ->first();

        $temporaryPassword = (string) $data['cedula'];

        $user = Usuario::query()->create([
            'cedula' => (string) $data['cedula'],
            'nombre_completo' => (string) $data['name'],
            'correo' => (string) $data['email'],
            'contraseña' => Hash::make($temporaryPassword),
            'rol' => $this->mapRole((string) $data['role']),
            'estado_cuenta' => $data['status'] === 'active' ? 'Activo' : 'Inactivo',
            'ID_area' => $area?->ID_area,
            'debe_cambiar_password' => true,
        ]);

        $user->load('area');

        return response()->json([
            'id' => 'USR-' . str_pad((string) $user->ID_usuario, 3, '0', STR_PAD_LEFT),
            'cedula' => (string) $user->cedula,
            'name' => $user->nombre_completo,
            'email' => $user->correo,
            'role' => $user->rol,
            'department' => $user->area?->nombre_area ?? 'Sin área',
            'status' => strtolower((string) $user->estado_cuenta) === 'activo' ? 'active' : 'inactive',
            'joined' => '2024-01-01',
            'mustChangePassword' => (bool) $user->debe_cambiar_password,
        ], 201);
    }

    public function resetPassword(Request $request, string $id)
    {
        $data = $request->validate([
            'actorRole' => ['required', 'string'],
        ]);

        if (strtolower(trim($data['actorRole'])) !== 'administrador') {
            return response()->json(['message' => 'Solo los administradores pueden restablecer contraseña.'], 403);
        }

        $userId = (int) preg_replace('/\D+/', '', $id);
        $user = Usuario::query()->findOrFail($userId);

        $temporaryPassword = (string) $user->cedula;
        $user->contraseña = Hash::make($temporaryPassword);
        $user->debe_cambiar_password = true;
        $user->save();

        return response()->json([
            'message' => 'Contraseña restablecida correctamente. El usuario debe cambiarla al iniciar sesión.',
            'userId' => 'USR-' . str_pad((string) $user->ID_usuario, 3, '0', STR_PAD_LEFT),
        ]);
    }

    private function mapRole(string $role): string
    {
        return match ($role) {
            'Personal Universitario' => 'Funcionario',
            default => $role,
        };
    }
}
