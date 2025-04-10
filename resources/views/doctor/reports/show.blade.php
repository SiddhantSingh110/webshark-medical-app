@extends('doctor.layout')

@section('content')
    <h2 class="text-2xl font-bold mb-4">Report Details</h2>

    <div class="bg-white shadow p-4 rounded">
        <p><strong>Patient:</strong> {{ $report->patient->name }}</p>
        <p><strong>Uploaded At:</strong> {{ $report->created_at->format('d M Y, h:i A') }}</p>
        <p><strong>Notes:</strong> {{ $report->notes ?? 'N/A' }}</p>
        <p><strong>AI Model:</strong> {{ $summary->ai_model_used ?? 'N/A' }}</p>
        <p><strong>Confidence Score:</strong> {{ $summary->confidence_score ?? 'N/A' }}%</p>

        <hr class="my-4">

        <h3 class="text-lg font-semibold mb-2">Diagnosis</h3>
        <p>{{ $summary->summary_json['diagnosis'] ?? 'N/A' }}</p>

        <h3 class="text-lg font-semibold mt-4 mb-2">Key Findings</h3>
        <ul class="list-disc pl-6">
            @foreach($summary->summary_json['key_findings'] ?? [] as $finding)
                <li>{{ $finding }}</li>
            @endforeach
        </ul>

        <h3 class="text-lg font-semibold mt-4 mb-2">Recommendations</h3>
        <ul class="list-disc pl-6">
            @foreach($summary->summary_json['recommendations'] ?? [] as $rec)
                <li>{{ $rec }}</li>
            @endforeach
        </ul>

        <h3 class="text-lg font-semibold mt-4 mb-2">Raw Text</h3>
       <!-- <pre class="whitespace-pre-wrap text-sm bg-gray-100 p-3 rounded border">{{ $summary->raw_text ?? 'N/A' }}</pre>-->

        <a href="{{ asset('storage/' . $report->file_path) }}" target="_blank" class="text-blue-500 underline mt-4 inline-block">View Original File</a>
    </div>
@endsection
