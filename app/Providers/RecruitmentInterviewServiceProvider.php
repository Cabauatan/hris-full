<?php

namespace App\Providers;

use App\Models\Applicant;
use App\Models\RecruitmentInterview;
use App\Models\User;
use App\Services\Recruitment\RecruitmentInterviewService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class RecruitmentInterviewServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        TrimStrings::except(['payload.sdp', 'payload.candidate']);

        Broadcast::channel('Recruitment.Interview.{uuid}', function (User|Applicant $actor, string $uuid): bool {
            $interview = RecruitmentInterview::query()->with('application')->where('uuid', $uuid)->first();
            $actor = $actor::query()->find($actor->id);

            return $interview !== null && $actor !== null
                && app(RecruitmentInterviewService::class)->canJoin($interview, $actor);
        });

        foreach (['interview-join' => 20, 'interview-signal' => 240, 'interview-broadcast-auth' => 60, 'interview-messages-read' => 120, 'interview-messages-send' => 30] as $name => $maximum) {
            RateLimiter::for($name, function (Request $request) use ($name, $maximum): Limit {
                $actor = $request->user();
                $kind = $actor instanceof Applicant ? 'applicant' : 'user';
                $identity = $actor === null ? 'ip:'.$request->ip() : $kind.':'.$actor->getAuthIdentifier();
                $room = $request->route('recruitmentInterview') ?? $request->input('channel_name', '');
                $room = $room instanceof RecruitmentInterview ? $room->id : $room;
                $room = is_scalar($room) ? (string) $room : '';

                return Limit::perMinute($maximum)->by($name.':'.$identity.':'.hash('sha256', $room));
            });
        }
    }
}
