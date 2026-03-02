<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('renders chat page for authenticated user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('chat.index'));

    $response->assertOk();
    $response->assertInertia(fn(Assert $page) => $page->component('Chat/Index'));
});

it('chat stream responds with SSE headers', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('chat.stream', ['duration' => 1, 'interval' => 100, 'events' => 2]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))
        ->toContain('text/event-stream');
    expect($response->headers->get('Cache-Control'))
        ->toContain('no-cache');
});
