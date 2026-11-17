<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->admin($request);

        return response()->json(['data' => User::query()->with('branches:id,code,name')->orderBy('name')->get(['id', 'name', 'email', 'role'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:254', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'role' => ['required', 'in:manager,cashier'],
            'branch_ids' => ['required', 'array', 'min:1'],
            'branch_ids.*' => ['integer', 'distinct', Rule::exists('branches', 'id')->where('is_active', true)],
        ]);
        $user = DB::transaction(function () use ($data): User {
            $user = User::query()->create(collect($data)->only(['name', 'email', 'password', 'role'])->all());
            $user->branches()->sync($data['branch_ids']);

            return $user;
        });

        return response()->json(['data' => $user->load('branches:id,code,name')], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->admin($request);
        if ($user->role === 'admin') {
            throw ValidationException::withMessages(['user' => 'La cuenta administradora se gestiona por separado.']);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:254', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'role' => ['required', 'in:manager,cashier'],
            'branch_ids' => ['required', 'array', 'min:1'],
            'branch_ids.*' => ['integer', 'distinct', Rule::exists('branches', 'id')->where('is_active', true)],
        ]);
        DB::transaction(function () use ($data, $user): void {
            $user->fill(collect($data)->only(['name', 'email', 'role'])->all());
            if (! empty($data['password'])) $user->password = $data['password'];
            $user->save();
            $user->branches()->sync($data['branch_ids']);
        });

        return response()->json(['data' => $user->fresh()->load('branches:id,code,name')]);
    }

    private function admin(Request $request): void
    {
        abort_unless($request->user()->role === 'admin', 403);
    }
}
