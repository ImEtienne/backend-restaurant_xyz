<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', function (): JsonResponse {
    return response()->json(['message' => 'API de reservations']);
});

Route::view('/docs', 'swagger')->name('docs');

Route::get('/docs/openapi.json', function (): JsonResponse {
    return response()->json(json_decode(
        file_get_contents(resource_path('openapi.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    ));
})->name('docs.openapi');
