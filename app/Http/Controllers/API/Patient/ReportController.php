<?php

namespace App\Http\Controllers\API\Patient;

use App\Http\Controllers\Controller;
use App\Models\PatientReport;
use App\Models\AISummary;
use App\Models\HealthMetric;
use App\Services\AISummaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\PdfToText\Pdf as PdfToText;
use Barryvdh\DomPDF\Facade\Pdf as DomPdf;

class ReportController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,jpeg,png,jpg',
            'notes' => 'nullable|string',
            'report_date' => 'nullable|date',
            'report_title' => 'nullable|string|max:255',
        ]);

        $patientId = auth()->id();
        $file = $request->file('file');
        $ext = $file->getClientOriginalExtension();
        $filename = Str::uuid() . '.' . $ext;
        $path = $file->storeAs('patient_reports', $filename, 'public');

        $report = PatientReport::create([
            'patient_id' => $patientId,
            'doctor_id' => null,
            'file_path' => $path,
            'type' => $ext === 'pdf' ? 'pdf' : 'image',
            'notes' => $request->notes,
            'report_date' => $request->report_date ?? now(),
            'report_title' => $request->report_title ?? 'Medical Report',
            'uploaded_by' => 'patient',
        ]);

        $text = '';
        if ($ext === 'pdf') {
            try {
                $text = PdfToText::getText($file->getPathname());
                $text = $this->normalizePdfText($text);
                $metrics = $this->extractHealthMetrics($text);

                foreach ($metrics as $metric) {
                    HealthMetric::create([
                        'patient_id' => $patientId,
                        'type' => Str::slug($metric['parameter'], '_'),
                        'custom_type' => null,
                        'value' => $metric['value'],
                        'unit' => $metric['unit'],
                        'measured_at' => now(),
                        'notes' => 'Auto-extracted from report'
                    ]);
                }

            } catch (\Exception $e) {
                return response()->json([
                    'error' => 'Unable to extract text from PDF. Please upload a valid or unlocked PDF.'
                ], 422);
            }
        }

        $aiSummaryJson = AISummaryService::generateSummary($text);
        AISummary::create([
            'report_id' => $report->id,
            'raw_text' => $text,
            'summary_json' => $aiSummaryJson ?? [],
            'confidence_score' => isset($aiSummaryJson['confidence_score']) 
                ? (int) filter_var($aiSummaryJson['confidence_score'], FILTER_SANITIZE_NUMBER_INT) 
                : 0,
            'ai_model_used' => 'gpt-4',
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Report uploaded successfully.',
                'report_id' => $report->id,
                'raw_text_preview' => Str::limit($text, 300),
                'summary' => $aiSummaryJson,
                'confidence_score' => $aiSummaryJson['confidence_score'] ?? null,
            ], 201);
        } else {
            return redirect()
                ->route('patient.dashboard')
                ->with('success', 'Report uploaded successfully!');
        }
    }

    private function normalizePdfText($text)
    {
        $text = preg_replace("/\n([A-Za-z])/m", " $1", $text);
        $text = preg_replace("/\s{2,}/", " ", $text);
        return trim($text);
    }

    private function extractHealthMetrics($text)
    {
        $matches = [];
        preg_match_all('/([A-Z \(\)\/]+)\s+([\d\.]+)\s+([a-zA-Z\/\%µ]+)\s+([\d\.\- ]+)/', $text, $matches, PREG_SET_ORDER);

        $data = [];
        foreach ($matches as $match) {
            $data[] = [
                'parameter' => trim($match[1]),
                'value' => $match[2],
                'unit' => $match[3],
                'ref_range' => $match[4]
            ];
        }
        return $data;
    }

    public function index()
    {
        $patientId = auth()->id();

        $reports = PatientReport::with(['aiSummary'])
            ->where('patient_id', $patientId)
            ->latest()
            ->get()
            ->map(function ($report) {
                return [
                    'id' => $report->id,
                    'title' => $report->report_title,
                    'uploaded_at' => $report->created_at->toDateTimeString(),
                    'report_date' => $report->report_date,
                    'file_type' => $report->type,
                    'summary_diagnosis' => $report->aiSummary->summary_json['diagnosis'] ?? null,
                    'file_url' => Storage::disk('public')->url($report->file_path),
                    'uploaded_by' => $report->uploaded_by,
                    'doctor_name' => $report->doctor ? $report->doctor->name : null,
                ];
            });

        return response()->json([
            'reports' => $reports
        ]);
    }

    public function show($id)
    {
        $patientId = auth()->id();
        $report = PatientReport::with(['aiSummary'])
            ->where('patient_id', $patientId)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'id' => $report->id,
            'title' => $report->report_title,
            'report_date' => $report->report_date,
            'notes' => $report->notes,
            'uploaded_at' => $report->created_at->toDateTimeString(),
            'file_url' => Storage::disk('public')->url($report->file_path),
            'file_type' => $report->type,
            'uploaded_by' => $report->uploaded_by,
            'doctor_name' => $report->doctor ? $report->doctor->name : null,
            'ai_summary' => $report->aiSummary->summary_json ?? null,
            'confidence_score' => $report->aiSummary->confidence_score ?? null,
            'ai_model_used' => $report->aiSummary->ai_model_used ?? null,
            'raw_text' => Str::limit($report->aiSummary->raw_text ?? '', 1500),
        ]);
    }

    public function destroy($id)
    {
        $patientId = auth()->id();
        $report = PatientReport::where('patient_id', $patientId)
            ->where('id', $id)
            ->firstOrFail();

        Storage::disk('public')->delete($report->file_path);
        $report->delete();

        return response()->json([
            'message' => 'Report deleted successfully'
        ]);
    }
    /**
     * Get detailed information for a specific finding
     *
     * @param Request $request
     * @param int $reportId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getFindingDetails(Request $request, $id)
    {
        try {
            // Log the incoming request data
            Log::info('Finding details request', [
                'report_id' => $id,
                'request_data' => $request->all()
            ]);
            
            // Less restrictive validation to handle various formats
            $validated = $request->validate([
                'finding' => 'required',
            ]);
            
            // Get the finding data
            $finding = $request->input('finding');
            
            // Get the report for context
            $report = PatientReport::where('id', $id)
                ->where('patient_id', auth()->id())
                ->first();
                
            if (!$report) {
                Log::error('Report not found', ['report_id' => $id]);
                return response()->json([
                    'error' => 'Report not found',
                ], 404);
            }
            
            // Get some context from the report file if available
            $context = '';
            if ($report->file_path && Storage::exists($report->file_path)) {
                try {
                    // Get first 1000 characters for context
                    $fileContents = Storage::get($report->file_path);
                    $context = substr($fileContents, 0, 1000);
                } catch (\Exception $e) {
                    Log::warning('Could not read report file', [
                        'error' => $e->getMessage(),
                        'report_id' => $id
                    ]);
                    // Continue without context
                }
            }
            
            // Generate detailed information for the finding
            $details = AISummaryService::generateFindingDetails($finding, $context);
            
            return response()->json([
                'success' => true,
                'details' => $details
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error generating finding details', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'report_id' => $id
            ]);
            
            return response()->json([
                'error' => 'Failed to generate finding details',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    public function downloadSummaryPdf($id)
    {
        $report = PatientReport::with('aiSummary')
            ->where('id', $id)
            ->where('patient_id', auth()->id())
            ->firstOrFail();

        $summary = $report->aiSummary->summary_json ?? [];
        $confidence = $report->aiSummary->confidence_score ?? null;

        $pdf = DomPdf::loadView('pdfs.ai_summary', [
            'report' => $report,
            'summary' => $summary,
            'confidence' => $confidence,
        ]);

        return $pdf->download('AI_Summary_Report.pdf');
    }
}
