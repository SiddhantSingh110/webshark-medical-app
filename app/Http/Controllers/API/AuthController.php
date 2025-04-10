<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\Patient; // use Doctor or Hospital when needed
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // Patient Registration
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'phone'    => 'required|string|unique:patients',
            'password' => 'required|string|min:6'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $patient = Patient::create([
            'name'     => $request->name,
            'phone'    => $request->phone,
            'email'    => $request->email ?? null,
            'password' => Hash::make($request->password),
        ]);

        $token = $patient->createToken('patient_token')->plainTextToken;

        return response()->json([
            'message' => 'Patient registered successfully',
            'token' => $token,
            'user' => $patient
        ]);
    }

    // Patient Login
    public function login(Request $request)
    {
        $patient = Patient::where('phone', $request->phone)->first();

        if (!$patient || !Hash::check($request->password, $patient->password)) {
            throw ValidationException::withMessages([
                'phone' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $patient->createToken('patient_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'user' => $patient
        ]);
    }
}
