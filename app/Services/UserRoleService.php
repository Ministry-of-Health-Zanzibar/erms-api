<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserRoleChange;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

final class UserRoleService
{
    public function assign(User $user, Role $role): void
    {
        DB::transaction(function () use ($user, $role): void {
            $user = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $previousRoles = $user->roles()->with('permissions')->get();
            $before = $previousRoles->map(fn ($role) => ['id' => $role->id, 'name' => $role->name])->values()->all();
            $after = [['id' => $role->id, 'name' => $role->name]];
            if (count($before) === 1 && (int) $before[0]['id'] === (int) $role->id) {
                return;
            }

            $actor = auth()->user();
            $actorRoles = $actor?->roles()->get(['roles.id', 'roles.name'])
                ->map(fn ($role) => ['id' => $role->id, 'name' => $role->name])->values()->all() ?? [];
            // Older registration copied role permissions directly onto the user.
            // Remove those stale copies while preserving independent direct grants.
            $inheritedIds = $previousRoles->flatMap(fn ($role) => $role->permissions->modelKeys())->unique();
            $directPermissions = $user->permissions()->get()->reject(fn ($permission) => $inheritedIds->contains($permission->id));
            $user->syncRoles([$role]);
            $user->syncPermissions($directPermissions);

            $change = UserRoleChange::create([
                'user_id' => $user->getKey(),
                'user_name' => $user->full_name,
                'previous_roles' => $before,
                'new_roles' => $after,
                'changed_by' => $actor?->getKey(),
                'changed_by_name' => $actor?->full_name,
                'changed_by_roles' => $actorRoles,
                'ip_address' => request()->ip(),
            ]);

            AuditService::record('role_changed', 'users', $user,
                ['roles' => $before], ['roles' => $after], 'User role changed',
                ['role_change_id' => $change->getKey(), 'user_id' => $user->getKey(),
                    'actor_snapshot' => $actor ? ['id' => $actor->getKey(), 'name' => $actor->full_name, 'roles' => $actorRoles] : null]);
        });
    }
}
