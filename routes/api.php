<?php

use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::get('/search', SearchController::class)
    ->middleware('throttle:60,1')
    ->name('api.search');
