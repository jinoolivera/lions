<?php

use Illuminate\Support\Facades\Route;

Route::get('/tickets/{sale}/pdf', [\App\Http\Controllers\Api\V1\SalesController::class, 'downloadTicketPdf'])
    ->name('tickets.pdf')->middleware(['signed:relative', 'throttle:30,1']);

Route::view('/{path?}', 'app')->where('path', '.*');
