<?php

use App\Models\User;
use App\Services\Security\SessionSecurityService;
use Illuminate\Support\Facades\DB;

it('deletes every stored session for the user except the current one', function () {
    config(['session.driver' => 'database']);

    $user = User::factory()->create(['remember_token' => 'old-token']);
    $otherUser = User::factory()->create();

    foreach ([
        ['id' => 'current-session', 'user_id' => $user->id],
        ['id' => 'stolen-session', 'user_id' => $user->id],
        ['id' => 'unrelated-session', 'user_id' => $otherUser->id],
    ] as $session) {
        DB::table('sessions')->insert([
            ...$session,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '',
            'last_activity' => time(),
        ]);
    }

    app(SessionSecurityService::class)->revokeOtherSessions($user, 'current-session');

    expect(DB::table('sessions')->pluck('id')->all())
        ->toContain('current-session', 'unrelated-session')
        ->not->toContain('stolen-session')
        ->and($user->refresh()->remember_token)->not->toBe('old-token');
});

it('deletes all stored sessions when credentials are reset', function () {
    config(['session.driver' => 'database']);

    $user = User::factory()->create();

    foreach (['first-session', 'second-session'] as $id) {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '',
            'last_activity' => time(),
        ]);
    }

    app(SessionSecurityService::class)->revokeAllSessions($user);

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});
