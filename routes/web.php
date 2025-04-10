<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});


use App\Http\Controllers\Doctor\ReportWebController;

Route::middleware('auth:doctor')->prefix('doctor')->group(function () {
    Route::get('/reports', [ReportWebController::class, 'index'])->name('doctor.reports.index');

    // ⬇ Place this BEFORE the /reports/{id} route
    Route::get('/reports/upload', [ReportWebController::class, 'create'])->name('doctor.reports.upload');
    Route::post('/reports/upload', [ReportWebController::class, 'store'])->name('doctor.reports.upload.submit');

    Route::get('/reports/{id}', [ReportWebController::class, 'show'])->name('doctor.reports.show');
});

use App\Http\Controllers\Doctor\AuthController;

Route::prefix('doctor')->name('doctor.')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::middleware('auth:doctor')->group(function () {
        Route::get('/reports', [ReportWebController::class, 'index'])->name('reports.index');
    });
});

Route::get('/login', function () {
    return redirect()->route('doctor.login');
})->name('login');