<?php

declare(strict_types=1);

namespace App\Livewire\Automation;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Spatie\Permission\Models\Role;

final class UserManagement extends Component
{
    public string $name = '';

    public string $username = '';

    public string $password = '';

    public string $role = 'Staff';

    /** @var array<int, string> */
    public array $userRoles = [];

    public ?string $message = null;

    public function mount(): void
    {
        Gate::authorize('users.manage');

        User::query()->with('roles')->get()->each(function (User $user): void {
            $this->userRoles[$user->id] = $user->roles->first()?->name ?? 'Staff';
        });
    }

    public function createUser(): void
    {
        Gate::authorize('users.manage');

        $allowedRoles = ['Admin', 'Staff'];
        $data = validator([
            'name' => trim($this->name),
            'username' => trim($this->username),
            'password' => $this->password,
            'role' => $this->role,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9._-]+$/', Rule::unique('users', 'username')],
            'password' => ['required', 'string', 'min:6', 'max:255'],
            'role' => ['required', Rule::in($allowedRoles)],
        ])->validate();

        $user = User::query()->create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => null,
            'password' => $data['password'],
            'is_active' => true,
        ]);
        $user->syncRoles([$data['role']]);

        $this->reset('name', 'username', 'password');
        $this->role = 'Staff';
        $this->message = 'User '.$user->name.' berhasil ditambahkan.';
    }

    public function updateRole(int $userId): void
    {
        Gate::authorize('users.manage');

        $user = User::query()->findOrFail($userId);
        $selectedRole = $this->userRoles[$userId] ?? '';
        validator(['role' => $selectedRole], [
            'role' => ['required', Rule::in(['Admin', 'Staff'])],
        ])->validate();

        if ($user->is(auth()->user()) && $selectedRole !== 'Admin') {
            $this->addError('userRoles.'.$userId, 'Role Admin milik akun aktif tidak dapat diturunkan.');

            return;
        }

        $user->syncRoles([$selectedRole]);
        $this->message = 'Role user '.$user->name.' berhasil diubah menjadi '.$selectedRole.'.';
    }

    public function render()
    {
        Gate::authorize('users.manage');

        return view('livewire.automation.user-management', [
            'users' => User::query()->with('roles')->latest()->get(),
            'roles' => Role::query()->whereIn('name', ['Admin', 'Staff'])->orderBy('name')->pluck('name')->all(),
        ]);
    }
}
