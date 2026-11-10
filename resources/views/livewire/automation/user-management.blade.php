<div>
    <x-automation-nav />

    <main class="page-shell">
        <section class="page-heading">
            <div>
                <div class="eyebrow">User &amp; Access Control</div>
                <h1 class="page-title">Kelola User</h1>
                <p class="page-subtitle">Tambahkan user baru dan atur role akun. Role Staff hanya dapat mengakses menu Cek Status Rilis.</p>
            </div>
        </section>

        @if ($message)
            <div class="info-banner mb-6">
                <strong>Informasi</strong>
                <span>{{ \App\Support\SystemBranding::text($message) }}</span>
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-3">
            <article class="panel overflow-hidden lg:col-span-1">
                <header class="panel-header">
                    <div>
                        <h2 class="panel-title">Tambahkan User</h2>
                        <p class="panel-copy">Pilih role akses Admin atau Staff.</p>
                    </div>
                </header>

                <form wire:submit="createUser" class="space-y-4 p-5">
                    <div>
                        <label class="form-label">Nama Lengkap</label>
                        <input type="text" wire:model="name" class="form-input" placeholder="Nama user" required>
                        @error('name') <span class="text-xs text-red-500">{{ \App\Support\SystemBranding::text($message) }}</span> @enderror
                    </div>

                    <div>
                        <label class="form-label">Username</label>
                        <input type="text" wire:model="username" class="form-input" placeholder="username_tanpa_spasi" required>
                        @error('username') <span class="text-xs text-red-500">{{ \App\Support\SystemBranding::text($message) }}</span> @enderror
                    </div>

                    <div>
                        <label class="form-label">Password</label>
                        <input type="password" wire:model="password" class="form-input" placeholder="Minimal 6 karakter" required>
                        @error('password') <span class="text-xs text-red-500">{{ \App\Support\SystemBranding::text($message) }}</span> @enderror
                    </div>

                    <div>
                        <label class="form-label">Pilih Role</label>
                        <select wire:model="role" class="form-input">
                            <option value="Staff">Staff (Hanya Cek Status Rilis)</option>
                            <option value="Admin">Admin (Akses Penuh)</option>
                        </select>
                        @error('role') <span class="text-xs text-red-500">{{ \App\Support\SystemBranding::text($message) }}</span> @enderror
                    </div>

                    <div class="pt-2">
                        <button type="submit" class="primary-button w-full" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="createUser">Simpan User Baru</span>
                            <span wire:loading wire:target="createUser">Menyimpan...</span>
                        </button>
                    </div>
                </form>
            </article>

            <article class="panel overflow-hidden lg:col-span-2">
                <header class="panel-header">
                    <div>
                        <h2 class="panel-title">Daftar User &amp; Role</h2>
                        <p class="panel-copy">Pengguna aktif pada aplikasi {{ $systemName }}.</p>
                    </div>
                </header>

                <div class="table-scroll">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Nama</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Login Terakhir</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($users as $user)
                                <tr>
                                    <td><strong>{{ $user->name }}</strong></td>
                                    <td><span class="text-xs text-slate-500">{{ $user->username }}</span></td>
                                    <td>
                                        <div class="flex min-w-48 items-center gap-2">
                                            <select wire:model="userRoles.{{ $user->id }}" class="form-input !py-2">
                                                @foreach ($roles as $availableRole)
                                                    <option value="{{ $availableRole }}">{{ $availableRole }}</option>
                                                @endforeach
                                            </select>
                                            <button type="button" class="secondary-button whitespace-nowrap" wire:click="updateRole({{ $user->id }})">
                                                Simpan Role
                                            </button>
                                        </div>
                                        @error('userRoles.'.$user->id) <span class="text-xs text-red-500">{{ \App\Support\SystemBranding::text($message) }}</span> @enderror
                                    </td>
                                    <td>
                                        <span class="badge {{ $user->is_active ? 'badge--success' : 'badge--danger' }}">
                                            {{ $user->is_active ? 'Aktif' : 'Nonaktif' }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-xs text-slate-500">
                                            {{ $user->last_login_at?->diffForHumans() ?? 'Belum pernah' }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </article>
        </div>
    </main>
</div>
