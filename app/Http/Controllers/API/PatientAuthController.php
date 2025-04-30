<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class PatientAuthController extends Controller
{
    /**
     * Register a new patient
     * 
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'phone'    => 'required|string|unique:patients',
            'email'    => 'nullable|email|unique:patients,email',
            'password' => 'required|string|min:6',
            'gender'   => 'nullable|in:male,female,other',
            'dob'      => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $patient = Patient::create([
            'name'     => $request->name,
            'phone'    => $request->phone,
            'email'    => $request->email ?? null,
            'password' => Hash::make($request->password),
            'gender'   => $request->gender ?? null,
            'dob'      => $request->dob ?? null,
        ]);

        $token = $patient->createToken('patient_token')->plainTextToken;

        return response()->json([
            'message' => 'Patient registered successfully',
            'token' => $token,
            'user' => $patient
        ], 201);
    }

    /**
     * Login a patient
     * 
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone'    => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

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

    /**
     * Logout a patient
     * 
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Get the authenticated patient profile
     * 
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function profile(Request $request)
    {
        $patient = $request->user();
        
        return response()->json([
            'user' => $patient
        ]);
    }

    /**
     * Update patient profile
     * 
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateProfile(Request $request)
    {
        // Log the incoming request data
        Log::info('Update Profile Request:', $request->all());
        
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|nullable|email|unique:patients,email,' . $request->user()->id,
            'gender' => 'sometimes|nullable|string|in:Male,Female,Other,male,female,other',
            'dob' => 'sometimes|nullable|date',
            'height' => 'sometimes|nullable|numeric',
            'weight' => 'sometimes|nullable|numeric',
            'blood_group' => 'sometimes|nullable|string|max:10',
            'profile_photo' => 'sometimes|nullable|image|mimes:jpeg,png,jpg|max:2048',
        ]);
    
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
    
        $patient = $request->user();
    
        // Update only if the field is present in the request
        if ($request->filled('name')) {
            $patient->name = $request->name;
        }
        if ($request->filled('email')) {
            $patient->email = $request->email;
        }
        if ($request->filled('gender')) {
            // Convert to lowercase for consistency in database
            $patient->gender = strtolower($request->gender);
        }
        if ($request->filled('dob')) {
            $patient->dob = $request->dob;
        }
        if ($request->filled('height')) {
            $patient->height = $request->height;
        }
        if ($request->filled('weight')) {
            $patient->weight = $request->weight;
        }
        if ($request->filled('blood_group')) {
            $patient->blood_group = $request->blood_group;
        }
    
        if ($request->hasFile('profile_photo')) {
            $file = $request->file('profile_photo');
            $filename = uniqid('profile_') . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('profile_photos', $filename, 'public');
    
            // Optionally delete old photo
            if ($patient->profile_photo && Storage::disk('public')->exists($patient->profile_photo)) {
                Storage::disk('public')->delete($patient->profile_photo);
            }
    
            $patient->profile_photo = $path;
        }
    
        $patient->save();
    
        // Log the updated patient data
        Log::info('Updated Patient Data:', $patient->toArray());
    
        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $patient
        ]);
    }
    
    /**
     * Change password
     * 
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:6|different:current_password',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $patient = $request->user();

        if (!Hash::check($request->current_password, $patient->password)) {
            return response()->json([
                'message' => 'Current password is incorrect'
            ], 422);
        }

        $patient->password = Hash::make($request->new_password);
        $patient->save();

        return response()->json([
            'message' => 'Password changed successfully'
        ]);
    }
}