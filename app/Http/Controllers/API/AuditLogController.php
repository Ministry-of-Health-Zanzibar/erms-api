<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Support\Pagination;
use App\Support\SuperAdminAccess;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class AuditLogController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request)
    {
        $user = auth()->user();
        if (! SuperAdminAccess::allowed($user, 'View Audit Logs')) {
            return response()->json([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'entity_id' => ['sometimes', 'integer', 'min:1'],
            'patient_history_id' => ['sometimes', 'integer', 'min:1'],
            'patient_id' => ['sometimes', 'integer', 'min:1'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'search' => ['sometimes', 'string', 'max:100'],
            'action' => ['sometimes', 'string', 'max:100'],
            'module' => ['sometimes', 'string', 'max:100'],
        ]);

        $query = Activity::query()
            ->with(['causer:id,first_name,middle_name,last_name,email'])
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = mb_strtolower(trim((string) $request->input('search')));
                $query->where(function ($query) use ($search): void {
                    $query->whereRaw('LOWER(description) LIKE ?', ['%'.$search.'%'])
                        ->orWhereRaw('LOWER(subject_type) LIKE ?', ['%'.$search.'%'])
                        ->orWhereHas('causer', function ($causerQuery) use ($search): void {
                            $causerQuery->whereRaw('LOWER(email) LIKE ?', ['%'.$search.'%'])
                                ->orWhereRaw('LOWER(first_name) LIKE ?', ['%'.$search.'%'])
                                ->orWhereRaw('LOWER(last_name) LIKE ?', ['%'.$search.'%']);
                        });
                });
            })
            ->when($request->filled('user_id'), function ($query) use ($request): void {
                $query->where('causer_id', $request->input('user_id'));
            })
            ->when($request->filled('action'), function ($query) use ($request): void {
                $action = mb_strtolower(trim((string) $request->input('action')));
                $query->where(function ($query) use ($action): void {
                    $query->whereRaw('LOWER(description) LIKE ?', ['%'.$action.'%'])
                        ->orWhereRaw("LOWER(properties->>'action') LIKE ?", ['%'.$action.'%']);
                });
            })
            ->when($request->filled('module'), function ($query) use ($request): void {
                $module = mb_strtolower(trim((string) $request->input('module')));
                $query->where(function ($query) use ($module): void {
                    $query->whereRaw('LOWER(subject_type) LIKE ?', ['%'.$module.'%'])
                        ->orWhereRaw("LOWER(properties->>'module') LIKE ?", ['%'.$module.'%']);
                });
            })
            ->when($request->filled('entity_id'), function ($query) use ($request): void {
                $query->where('subject_id', $request->input('entity_id'));
            })
            ->when($request->filled('patient_history_id'), function ($query) use ($request): void {
                $query->where(function ($query) use ($request): void {
                    $query->where('subject_id', $request->input('patient_history_id'))
                        ->orWhereRaw("properties->>'patient_history_id' = ?", [(string) $request->input('patient_history_id')]);
                });
            })
            ->when($request->filled('patient_id'), function ($query) use ($request): void {
                $query->whereRaw("properties->>'patient_id' = ?", [(string) $request->input('patient_id')]);
            })
            ->when($request->filled('date_from'), function ($query) use ($request): void {
                $query->whereDate('created_at', '>=', $request->input('date_from'));
            })
            ->when($request->filled('date_to'), function ($query) use ($request): void {
                $query->whereDate('created_at', '<=', $request->input('date_to'));
            })
            ->latest('id');

        $logs = $query->paginate(Pagination::perPage($request, 25));
        $items = collect($logs->items())->map(fn (Activity $activity) => $this->transform($activity))->values();

        return response()->json([
            'activity' => $items,
            'data' => $items,
            'meta' => Pagination::meta($logs),
            'statusCode' => 200,
        ]);
    }

    public function show(int $id)
    {
        $user = auth()->user();
        if (! SuperAdminAccess::allowed($user, 'View Audit Logs')) {
            return response()->json([
                'message' => 'Forbidden',
                'statusCode' => 403,
            ], 403);
        }

        $activity = Activity::with('causer:id,first_name,middle_name,last_name,email')->find($id);
        if (! $activity) {
            return response()->json([
                'message' => 'Audit entry not found',
                'statusCode' => 404,
            ], 404);
        }

        return response()->json([
            'data' => $this->transform($activity),
            'statusCode' => 200,
        ]);
    }

    private function transform(Activity $activity): array
    {
        $causer = $activity->causer;
        $properties = $activity->properties;
        $properties = is_object($properties) && method_exists($properties, 'toArray')
            ? $properties->toArray()
            : (array) $properties;

        return [
            'id' => $activity->id,
            'log_name' => $activity->log_name,
            'description' => $activity->description,
            'subject_type' => $activity->subject_type,
            'subject_id' => $activity->subject_id,
            'actor_snapshot' => $properties['actor_snapshot'] ?? null,
            'causer' => $causer ? [
                'id' => $causer->id,
                'name' => $causer->full_name ?: $causer->email,
                'email' => $causer->email,
            ] : null,
            'properties' => \App\Support\AuditService::scrub($properties),
            'created_at' => $activity->created_at,
        ];
    }
}
