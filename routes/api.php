<?php

use App\Http\Controllers\CampaignDispatchController;
use Illuminate\Support\Facades\Route;

Route::post('/campaigns/{campaign}/deliveries', [CampaignDispatchController::class, 'store']);
