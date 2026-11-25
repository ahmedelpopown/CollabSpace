<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Projects\Models\Project;
use Modules\Projects\Policies\ProjectPolicy;
use Modules\Tasks\Models\Task;
use Modules\Tasks\Policies\TaskPolicy;
use Modules\Teams\Models\Team;
use Modules\Teams\Policies\TeamPolicy;
use Modules\Files\Models\File;
use Modules\Files\Policies\FilePolicy;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(Team::class, TeamPolicy::class);
        Gate::policy(File::class, FilePolicy::class);

        RateLimiter::for('auth-login', function (Request $request): array {
            $email = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('auth-login-ip:'.$request->ip()),
                Limit::perMinute(5)->by(
                    'auth-login-email:'.hash('sha256', $email ?: 'missing-email')
                ),
            ];
        });

        RateLimiter::for('auth-register', function (Request $request): Limit {
            return Limit::perMinute(3)->by('auth-register:'.$request->ip());
        });

        RateLimiter::for('auth-google', function (Request $request): Limit {
            return Limit::perMinute(5)->by('auth-google:'.$request->ip());
        });
    }
}
