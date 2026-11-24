<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\SourceCatalogController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ChatController::class, 'index'])->block(15, 10)->name('chat.index');
Route::post('/chat', [ChatController::class, 'submit'])->block(120, 10)->middleware('throttle:20,1')->name('chat.submit');
Route::post('/chat/stream', [ChatController::class, 'stream'])->block(120, 10)->middleware('throttle:20,1')->name('chat.stream');
Route::get('/chats/{conversation}', [ChatController::class, 'show'])->block(15, 10)->whereUuid('conversation')->name('chat.show');
Route::delete('/chats/{conversation}', [ChatController::class, 'destroy'])->block(15, 10)->whereUuid('conversation')
    ->middleware('throttle:30,1')->name('chat.destroy');
Route::delete('/chats', [ChatController::class, 'destroyAll'])->block(15, 10)->middleware('throttle:30,1')->name('chat.destroyAll');
Route::post('/settings', [ChatController::class, 'updateSettings'])->block(15, 10)->middleware('throttle:30,1')->name('chat.settings');
Route::get('/sources', SourceCatalogController::class)->block(15, 10)->middleware('throttle:30,1')->name('sources.index');
