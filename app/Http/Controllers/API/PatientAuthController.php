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
     * Update patient profile with support for profile photo uploads
     * 
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateProfile(Request $request)
    {
        Log::info('Update Profile Request');
        
        // Get raw input and try to parse manually if needed
        $rawInput = file_get_contents('php://input');
        Log::info('Raw Input', [$rawInput]);
        
        // Check if regular parsing worked
        $parsedData = $request->all();
        Log::info('Parsed Fields', [$parsedData]);
        
        // Check for uploaded files
        $files = $request->allFiles();
        Log::info('Files in request', ['count' => count($files), 'keys' => array_keys($files)]);
        
        // Extract form data if needed
        if (empty($parsedData) || (count($parsedData) === 1 && isset($parsedData['profile_photo']) && empty($parsedData['profile_photo']))) {
            Log::info('Regular parsing failed or only contained empty profile_photo, attempting manual extraction');
            $parsedData = $this->extractFormDataFromRawInput($rawInput);
            Log::info('Manually extracted data', [$parsedData]);
        }
        
        // Validation - DON'T validate the profile_photo here as it might be handled separately
        $validator = Validator::make($parsedData, [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|nullable|email|unique:patients,email,' . $request->user()->id,
            'gender' => 'sometimes|nullable|in:male,female,other',
            'dob' => 'sometimes|nullable|date_format:Y-m-d',  // Strict date format
            'height' => 'sometimes|nullable|numeric',
            'weight' => 'sometimes|nullable|numeric',
            'blood_group' => 'sometimes|nullable|string|max:10',
            'phone' => 'sometimes|nullable|string',
        ]);
    
        if ($validator->fails()) {
            Log::error('Validation failed', ['errors' => $validator->errors()->toArray()]);
            return response()->json(['errors' => $validator->errors()], 422);
        }
    
        $patient = $request->user();
        
        // Update fields from parsed data
        if (!empty($parsedData['name'])) {
            $patient->name = $parsedData['name'];
        }
        
        if (isset($parsedData['email'])) {
            $patient->email = $parsedData['email'];
        }
        
        if (!empty($parsedData['gender'])) {
            // Explicitly check for valid values
            $gender = strtolower($parsedData['gender']);
            if (in_array($gender, ['male', 'female', 'other'])) {
                $patient->gender = $gender;
            }
        }
        
        if (!empty($parsedData['dob'])) {
            // Ensure proper date format
            try {
                $date = new \DateTime($parsedData['dob']);
                $patient->dob = $date->format('Y-m-d');
            } catch (\Exception $e) {
                Log::error('Invalid date format', ['dob' => $parsedData['dob']]);
            }
        }
        
        if (isset($parsedData['height']) && is_numeric($parsedData['height'])) {
            $patient->height = $parsedData['height'];
        }
        
        if (isset($parsedData['weight']) && is_numeric($parsedData['weight'])) {
            $patient->weight = $parsedData['weight'];
        }
        
        if (!empty($parsedData['blood_group'])) {
            $patient->blood_group = $parsedData['blood_group'];
        }
        
        // Process profile photo - check in request
        $profilePhoto = $request->file('profile_photo');
    
        // In the profile photo handling section
        if ($profilePhoto && $profilePhoto->isValid()) {
            try {
                // Create a unique filename
                $filename = 'profile_' . uniqid() . '.jpg';
                
                // Resize the image using Intervention Image library
                // First, install it: composer require intervention/image
                $img = \Intervention\Image\Facades\Image::make($profilePhoto);
                
                // Resize to reasonable dimensions while maintaining aspect ratio
                $img->resize(500, null, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
                
                // Save the resized image to storage
                $path = 'profile_photos/' . $filename;
                Storage::disk('public')->put($path, (string) $img->encode('jpg', 70));
                
                // Delete old profile photo if exists
                if ($patient->profile_photo && Storage::disk('public')->exists($patient->profile_photo)) {
                    Storage::disk('public')->delete($patient->profile_photo);
                }
                
                // Update patient record
                $patient->profile_photo = $path;
                Log::info('Profile photo resized and saved', [
                    'original_size' => $profilePhoto->getSize(),
                    'path' => $path,
                    'dimensions' => $img->width() . 'x' . $img->height()
                ]);
            } catch (\Exception $e) {
                Log::error('Error processing profile photo', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        } else {
            // Log that we didn't find a file
            Log::info('No profile photo found in request');
        }
        
        // Save patient data
        try {
            $patient->save();
            Log::info('Patient saved successfully', $patient->toArray());
            
            return response()->json([
                'message' => 'Profile updated successfully',
                'user' => $patient,
                'status' => 'success'
            ]);
        } catch (\Exception $e) {
            Log::error('Error saving patient data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'message' => 'Error updating profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper function to translate upload error codes to messages
     *
     * @param int $code Error code from PHP file upload
     * @return string Human-readable error message
     */
    protected function uploadErrorCodeToMessage($code)
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
                return 'The uploaded file exceeds the upload_max_filesize directive in php.ini';
            case UPLOAD_ERR_FORM_SIZE:
                return 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form';
            case UPLOAD_ERR_PARTIAL:
                return 'The uploaded file was only partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing a temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'A PHP extension stopped the file upload';
            default:
                return 'Unknown upload error';
        }
    }

    /**
     * Helper function to manually extract form data from raw input
     *
     * @param string $rawInput Raw HTTP request body
     * @return array Extracted form data as associative array
     */
    private function extractFormDataFromRawInput($rawInput)
    {
        $data = [];
        
        // Extract form fields using regex pattern
        preg_match_all('/content-disposition: form-data; name=\"([^\"]+)\"\s+\s+([\s\S]+?)(?=--|\Z)/i', $rawInput, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $fieldName = $match[1];
            $fieldValue = trim($match[2]);
            $data[$fieldName] = $fieldValue;
        }
        
        return $data;
    }
    
    /**
     * Change password for authenticated patient
     * 
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:6|different:current_password',
            'profile_photo' => 'sometimes|image|mimes:jpeg,png,jpg|max:10048', // 10MB max
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