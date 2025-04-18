<?php

namespace App\Http\Controllers\API\Patient;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\HealthMetric; // Assuming you have a HealthMetric model

class HealthMetricsController extends Controller
{
    /**
     * Store a new health metric
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|string|in:blood_pressure,heart_rate,weight,blood_sugar,temperature,oxygen_level,custom',
            'value' => 'required|string',
            'unit' => 'required|string',
            'measured_at' => 'nullable|date',
            'notes' => 'nullable|string',
            'custom_type' => 'required_if:type,custom|nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $patientId = auth()->id();
        
        $metric = HealthMetric::create([
            'patient_id' => $patientId,
            'type' => $request->type,
            'custom_type' => $request->custom_type,
            'value' => $request->value,
            'unit' => $request->unit,
            'measured_at' => $request->measured_at ?? now(),
            'notes' => $request->notes,
        ]);
        
        return response()->json([
            'message' => 'Health metric recorded successfully',
            'metric' => $metric
        ], 201);
    }
    
    /**
     * Get all metrics for the authenticated patient
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $patientId = auth()->id();
        
        $query = HealthMetric::where('patient_id', $patientId);
        
        // Apply filters
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        
        if ($request->has('from_date')) {
            $query->where('measured_at', '>=', $request->from_date);
        }
        
        if ($request->has('to_date')) {
            $query->where('measured_at', '<=', $request->to_date);
        }
        
        $metrics = $query->orderBy('measured_at', 'desc')->get();
        
        return response()->json([
            'metrics' => $metrics
        ]);
    }
    
    /**
     * Get stats and trends for a specific metric type
     *
     * @param Request $request
     * @param string $type
     * @return \Illuminate\Http\JsonResponse
     */
    public function trends(Request $request, $type)
    {
        $patientId = auth()->id();
        
        // Validate type
        if (!in_array($type, ['blood_pressure', 'heart_rate', 'weight', 'blood_sugar', 'temperature', 'oxygen_level', 'custom'])) {
            return response()->json(['error' => 'Invalid metric type'], 400);
        }
        
        $timeframe = $request->input('timeframe', 'month'); // day, week, month, year
        
        // Get appropriate date based on timeframe
        $fromDate = now();
        switch ($timeframe) {
            case 'day':
                $fromDate = $fromDate->subDay();
                break;
            case 'week':
                $fromDate = $fromDate->subWeek();
                break;
            case 'month':
                $fromDate = $fromDate->subMonth();
                break;
            case 'year':
                $fromDate = $fromDate->subYear();
                break;
        }
        
        $metrics = HealthMetric::where('patient_id', $patientId)
            ->where('type', $type)
            ->where('measured_at', '>=', $fromDate)
            ->orderBy('measured_at', 'asc')
            ->get();
            
        // Calculate statistics
        $stats = [
            'count' => $metrics->count(),
            'latest' => $metrics->last(),
            'average' => $metrics->avg('value'),
            'min' => $metrics->min('value'),
            'max' => $metrics->max('value'),
            'trend' => [] // Data points for charting
        ];
        
        // Format data for trend chart
        foreach ($metrics as $metric) {
            $stats['trend'][] = [
                'date' => $metric->measured_at->format('Y-m-d H:i'),
                'value' => $metric->value,
            ];
        }
        
        return response()->json($stats);
    }
    
    /**
     * Delete a health metric
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $patientId = auth()->id();
        
        $metric = HealthMetric::where('patient_id', $patientId)
            ->where('id', $id)
            ->firstOrFail();
            
        $metric->delete();
        
        return response()->json([
            'message' => 'Health metric deleted successfully'
        ]);
    }
}