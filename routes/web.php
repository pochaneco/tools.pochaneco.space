<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

require __DIR__ . '/settings.php';
require __DIR__ . '/auth.php';

// Team invitation registration (no auth required)
Route::get('teams/invitations/{token}/register', [\App\Http\Controllers\TeamInvitationController::class, 'showRegister'])
    ->name('teams.invitations.register');
Route::post('teams/invitations/{token}/register', [\App\Http\Controllers\TeamInvitationController::class, 'storeRegister'])
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
    Route::resource('teams', \App\Http\Controllers\TeamController::class);
    Route::post('teams/{team}/members', [\App\Http\Controllers\TeamMemberController::class, 'store'])
        ->name('teams.members.store');
    Route::patch('teams/{team}/members/{user}', [\App\Http\Controllers\TeamMemberController::class, 'update'])
        ->name('teams.members.update');
    Route::delete('teams/{team}/members/{user}', [\App\Http\Controllers\TeamMemberController::class, 'destroy'])
        ->name('teams.members.destroy');

    // Team invitations (for existing users)
    Route::get('teams/invitations/{token}', [\App\Http\Controllers\TeamInvitationController::class, 'show'])
        ->name('teams.invitations.show');
    Route::post('teams/invitations/{token}/accept', [\App\Http\Controllers\TeamInvitationController::class, 'accept'])
        ->name('teams.invitations.accept');
    Route::post('teams/invitations/{token}/decline', [\App\Http\Controllers\TeamInvitationController::class, 'decline'])
        ->name('teams.invitations.decline');
});
