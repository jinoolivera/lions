<?php

use App\Http\Controllers\Api\V1\DeliveryGuidePdfController;
use App\Http\Controllers\Api\V1\SalesController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\PilotGuideController;

Route::get('/pilot-guides/{shipment}/pdf', [PilotGuideController::class, 'signedPdf'])
    ->name('pilot-guides.pdf')->middleware(['signed:relative', 'throttle:30,1']);

Route::get('/tickets/{sale}/pdf', [SalesController::class, 'downloadTicketPdf'])
    ->name('tickets.pdf')->middleware(['signed:relative', 'throttle:30,1']);

Route::get('/guides/{guide}/pdf', [DeliveryGuidePdfController::class, 'signedPdf'])
    ->name('guides.pdf')->middleware(['signed:relative', 'throttle:30,1']);

Route::view('/{path?}', 'app')->where('path', '.*');
