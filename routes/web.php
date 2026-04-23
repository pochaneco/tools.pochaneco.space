<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\TeamKnowledgeController;
use App\Http\Controllers\TeamMemberController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

// Team invitation registration (no auth required)
Route::get('teams/invitations/{token}/register', [TeamInvitationController::class, 'showRegister'])
    ->name('teams.invitations.register');
Route::post('teams/invitations/{token}/register', [TeamInvitationController::class, 'storeRegister'])
    ->name('teams.invitations.register.store');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::post('/chat/message', [ChatController::class, 'message'])->name('chat.message');
    Route::post('/chat/regenerate', [ChatController::class, 'regenerate'])->name('chat.regenerate');
    Route::get('/chat/conversations', [ChatController::class, 'conversations'])
        ->name('chat.conversations.index');
    Route::get('/chat/conversations/{conversation}', [ChatController::class, 'conversation'])
        ->name('chat.conversations.show');
    Route::patch('/chat/conversations/{conversation}', [ChatController::class, 'renameConversation'])
        ->name('chat.conversations.update');
    Route::delete('/chat/conversations/{conversation}', [ChatController::class, 'destroyConversation'])
        ->name('chat.conversations.destroy');

    // Team management
    Route::resource('teams', TeamController::class);
    Route::post('teams/{team}/members', [TeamMemberController::class, 'store'])
        ->name('teams.members.store');
    Route::patch('teams/{team}/members/{user}', [TeamMemberController::class, 'update'])
        ->name('teams.members.update');
    Route::delete('teams/{team}/members/{user}', [TeamMemberController::class, 'destroy'])
        ->name('teams.members.destroy');

    // Team invitations (for existing users)
    Route::get('teams/invitations/{token}', [TeamInvitationController::class, 'show'])
        ->name('teams.invitations.show');
    Route::post('teams/invitations/{token}/accept', [TeamInvitationController::class, 'accept'])
        ->name('teams.invitations.accept');
    Route::post('teams/invitations/{token}/decline', [TeamInvitationController::class, 'decline'])
        ->name('teams.invitations.decline');

    // Team knowledge (shallow nesting: list/create are team-scoped, individual
    // knowledge routes are not, since a knowledge entry is globally addressable
    // by its own id and the policy enforces team membership).
    Route::get('teams/{team}/knowledges', [TeamKnowledgeController::class, 'index'])
        ->name('team-knowledges.index');
    Route::get('teams/{team}/knowledges/create', [TeamKnowledgeController::class, 'create'])
        ->name('team-knowledges.create');
    Route::post('teams/{team}/knowledges', [TeamKnowledgeController::class, 'store'])
        ->name('team-knowledges.store');
    Route::get('knowledges/{knowledge}', [TeamKnowledgeController::class, 'show'])
        ->name('team-knowledges.show');
    Route::get('knowledges/{knowledge}/edit', [TeamKnowledgeController::class, 'edit'])
        ->name('team-knowledges.edit');
    Route::patch('knowledges/{knowledge}', [TeamKnowledgeController::class, 'update'])
        ->name('team-knowledges.update');
    Route::delete('knowledges/{knowledge}', [TeamKnowledgeController::class, 'destroy'])
        ->name('team-knowledges.destroy');
    Route::post('knowledges/{knowledge}/publish', [TeamKnowledgeController::class, 'publish'])
        ->name('team-knowledges.publish');
    Route::post('knowledges/{knowledge}/unpublish', [TeamKnowledgeController::class, 'unpublish'])
        ->name('team-knowledges.unpublish');
    Route::post('knowledges/{knowledge}/archive', [TeamKnowledgeController::class, 'archive'])
        ->name('team-knowledges.archive');
});
