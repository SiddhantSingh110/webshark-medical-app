<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\DoctorAuthController;
use App\Http\Controllers\API\HospitalAuthController;
use App\Http\Controllers\API\Doctor\PatientReportController;


// use App\Http\Controllers\API\PatientAuthController;
Route::post('/patient/register', [AuthController::class, 'register']);
Route::post('/patient/login', [AuthController::class, 'login']);

// Doctor routes
Route::post('/doctor/register', [DoctorAuthController::class, 'register']);
Route::post('/doctor/login', [DoctorAuthController::class, 'login']);

// Hospital routes
Route::post('/hospital/register', [HospitalAuthController::class, 'register']);
Route::post('/hospital/login', [HospitalAuthController::class, 'login']);

//This route returns the currently authenticated user (patient/doctor/hospital) based on their token.
Route::middleware('auth:sanctum')->get('/me', function (Request $request) {
    return response()->json([
        'user' => $request->user()
    ]);
});

// This route is for uploading patient reports by doctors.
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/doctor/report/upload', [PatientReportController::class, 'upload']);
});

// 📁 Doctor Portal: Authenticated routes to view reports
// - GET /doctor/reports: List all reports uploaded by the doctor
// - GET /doctor/reports/{id}: View detailed report with AI summary & patient info
Route::middleware(['auth:sanctum'])->prefix('doctor')->group(function () {
    Route::get('/reports', [PatientReportController::class, 'index']);
    Route::get('/reports/{id}', [PatientReportController::class, 'show']);
});

// Returns a list of reports uploaded by the logged-in doctor.
// Includes patient name, upload timestamp, file URL, notes, and AI diagnosis (if available).
Route::middleware('auth:sanctum')->get('/doctor/reports', [PatientReportController::class, 'listReports']);
