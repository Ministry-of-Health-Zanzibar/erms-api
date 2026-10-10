<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken; // IMPORTANT
use Laravel\Sanctum\Sanctum;            // IMPORTANT
use Carbon\Carbon;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([\App\Models\FollowUp::class, \App\Models\HospitalLetter::class] as $model) {
            $prefix = $model === \App\Models\FollowUp::class ? 'Follow-up' : 'Follow-up letter';
            if ($model === \App\Models\FollowUp::class) {
                $model::created(static fn ($record) => app(\App\Services\CaseJourneyRecorder::class)->record($record, 'Follow-up recorded'));
            }
            $model::updated(static fn ($record) => app(\App\Services\CaseJourneyRecorder::class)->record($record, $prefix.' updated'));
            $model::deleted(static fn ($record) => app(\App\Services\CaseJourneyRecorder::class)->record($record, $prefix.' archived'));
            $model::restored(static fn ($record) => app(\App\Services\CaseJourneyRecorder::class)->record($record, $prefix.' restored'));
        }
        Sanctum::authenticateAccessTokensUsing(
            static function (PersonalAccessToken $accessToken, bool $isValid) {
                // 1. Check if token is already invalid (e.g. past the 24h hard limit)
                if (!$isValid) return false;

                // 2. Define your idle timeout (in minutes)
                $idleTimeout = 60;

                // 3. Compare current time with last usage
                // If never used before, use the creation time
                $lastActivity = $accessToken->last_used_at ?? $accessToken->created_at;

                return $lastActivity->gt(now()->subMinutes($idleTimeout));
            }
        );
    }
}
