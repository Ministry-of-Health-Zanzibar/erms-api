<?php

namespace App\Models;

use Spatie\Activitylog\Models\Activity;

class AuditActivity extends Activity
{
    protected static function booted(): void
    {
        static::creating(function (self $activity): void {
            $properties = collect($activity->properties);
            if ($properties->has('actor_snapshot')) {
                return;
            }
            $actor = $activity->causer;
            if (! $actor instanceof User) {
                return;
            }

            // Persist the role used for this action; never derive it from today's role.
            $activity->properties = $properties->put('actor_snapshot', [
                'id' => $actor->getKey(),
                'name' => $actor->full_name,
                'roles' => $actor->roles()->get(['roles.id', 'roles.name'])
                    ->map(fn ($role) => ['id' => $role->id, 'name' => $role->name])->values()->all(),
            ]);
        });
    }
}
