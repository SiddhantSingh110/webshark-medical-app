<?php

namespace App\Services;

use OpenAI;
use Illuminate\Support\Facades\Log;

class AISummaryService {
    
    /**
     * Generate a medical summary with Hindi translation
     * Uses chunking strategy for large texts to avoid truncation
     * Includes patient information and color-coded status indicators with descriptions
     *
     * @param string $rawText The raw medical report text
     * @return array The processed summary with Hindi translation
     */
    public static function generateSummary($rawText)
    {
        $client = OpenAI::client(config('openai.api_key'));
        
        try {
            // Extract patient info first using a smaller chunk of text
            $patientInfo = self::extractPatientInfo($client, substr($rawText, 0, 3000));
            
            // Step 1: Process the report in chunks if it's very large
            if (strlen($rawText) > 6000) {
                $summary = self::processLargeReport($client, $rawText);
            } else {
                $summary = self::processStandardReport($client, $rawText);
            }
            
            // Validate decoded summary
            if (!is_array($summary)) {
                throw new \Exception('AI returned an invalid or non-JSON response.');
            }
            
            // Process the key findings to ensure proper status assignment and descriptions
            if (isset($summary['key_findings']) && is_array($summary['key_findings'])) {
                $summary['key_findings'] = self::processKeyFindings($summary['key_findings']);
            }
            
            // Merge patient info with medical summary
            $summary = array_merge($patientInfo, $summary);
            
            // Step 2: Translate to Hindi
            $hindiVersion = self::translateToHindi($client, $summary);
            
            // Add percentage symbol to confidence score if it doesn't have one
            if (isset($summary['confidence_score']) && is_numeric($summary['confidence_score'])) {
                $summary['confidence_score'] = $summary['confidence_score'] . '%';
            }
            
            // Step 3: Return full response
            return [
                ...$summary,
                'hindi_version' => $hindiVersion,
            ];
            
        } catch (\Exception $e) {
            Log::error("AI summary or translation failed: " . $e->getMessage());
            return [
                'patient_name' => 'N/A',
                'patient_age' => 'N/A',
                'patient_gender' => 'N/A',
                'diagnosis' => 'N/A',
                'key_findings' => ['N/A'],
                'recommendations' => ['N/A'],
                'confidence_score' => '0%',
                'hindi_version' => 'N/A',
            ];
        }
    }
    
    /**
     * Process key findings to ensure proper status assignment and descriptive text
     * 
     * @param array $findings Array of findings
     * @return array Processed findings with correct status and descriptions
     */
    private static function processKeyFindings($findings)
    {
        $processedFindings = [];
        
        foreach ($findings as $finding) {
            if (is_string($finding)) {
                // Convert string findings into structured format
                $text = strtolower($finding);
                $status = 'normal';
                $description = $finding;
                
                // Check for status based on keywords
                if (strpos($text, 'high') !== false || 
                    strpos($text, 'extremely') !== false || 
                    strpos($text, 'significantly') !== false || 
                    strpos($text, 'elevated') !== false || 
                    strpos($text, 'increased') !== false ||
                    strpos($text, 'excessive') !== false) {
                    
                    // Borderline if it contains qualifiers
                    if (strpos($text, 'slightly') !== false || 
                        strpos($text, 'mild') !== false || 
                        strpos($text, 'borderline') !== false) {
                        $status = 'borderline';
                    } else {
                        $status = 'high';
                    }
                } 
                // Check for low values
                else if (strpos($text, 'low') !== false || 
                         strpos($text, 'decreased') !== false || 
                         strpos($text, 'deficiency') !== false ||
                         strpos($text, 'deficit') !== false ||
                         strpos($text, 'lower than normal') !== false) {
                    
                    // Borderline if it contains qualifiers
                    if (strpos($text, 'slightly') !== false || 
                        strpos($text, 'mild') !== false || 
                        strpos($text, 'borderline') !== false) {
                        $status = 'borderline';
                    } else {
                        $status = 'high';
                    }
                }
                
                $processedFindings[] = [
                    'finding' => $finding,
                    'value' => '',
                    'reference' => '',
                    'status' => $status,
                    'description' => $description
                ];
            } else if (is_array($finding)) {
                // For structured findings, validate and fix status if needed
                if (!isset($finding['status']) || $finding['status'] === '') {
                    // Determine status if not set
                    $text = strtolower($finding['finding'] . ' ' . ($finding['value'] ?? ''));
                    
                    if (strpos($text, 'high') !== false || 
                        strpos($text, 'extremely') !== false || 
                        strpos($text, 'significantly') !== false || 
                        strpos($text, 'elevated') !== false || 
                        strpos($text, 'increased') !== false) {
                        
                        $finding['status'] = 'high';
                    } else if (strpos($text, 'borderline') !== false || 
                               strpos($text, 'slightly') !== false || 
                               strpos($text, 'mild') !== false) {
                        
                        $finding['status'] = 'borderline';
                    } else {
                        $finding['status'] = 'normal';
                    }
                }
                
                // Add a descriptive text if not present
                if (!isset($finding['description']) || $finding['description'] === '') {
                    $testName = $finding['finding'] ?? '';
                    $value = $finding['value'] ?? '';
                    
                    // Create description based on status and test name
                    if ($finding['status'] === 'high') {
                        if (strpos(strtolower($testName), 'low') !== false || 
                            strpos(strtolower($testName), 'deficiency') !== false) {
                            $finding['description'] = "Low $testName";
                        } else {
                            $finding['description'] = "Elevated $testName";
                        }
                    } else if ($finding['status'] === 'borderline') {
                        $finding['description'] = "Borderline $testName";
                    } else {
                        $finding['description'] = "Normal $testName";
                    }
                }
                
                $processedFindings[] = $finding;
            }
        }
        
        return $processedFindings;
    }
    
    /**
     * Generate detailed information for a specific abnormal finding
     * This includes cases, symptoms, remedies, future consequences, and next steps
     * 
     * @param array $finding The abnormal finding to get details for
     * @param string $context Additional context from the report (optional)
     * @return array Detailed information
     */
    public static function generateFindingDetails($finding, $context = '')
    {
        $client = OpenAI::client(config('openai.api_key'));
        
        try {
            // Extract finding information
            $findingName = is_array($finding) ? $finding['finding'] : $finding;
            $findingValue = is_array($finding) ? $finding['value'] : '';
            $findingReference = is_array($finding) ? $finding['reference'] : '';
            $findingStatus = is_array($finding) ? $finding['status'] : 'high';
            $findingDescription = is_array($finding) ? $finding['description'] : $finding;
            
            $prompt = "Provide detailed medical information about the following abnormal test result:
            
            Test: {$findingName}
            Value: {$findingValue}
            Reference Range: {$findingReference}
            Status: {$findingStatus} (" . ($findingStatus === 'borderline' ? 'slightly abnormal' : 'significantly abnormal') . ")
            Description: {$findingDescription}
            
            Please provide detailed, evidence-based medical information in the following categories:
            
            1. Cases: Common reasons why this abnormality might occur (with percentages if known)
            2. Symptoms: Physical symptoms that might be associated with this abnormality
            3. Remedies: Treatment options and lifestyle modifications that may help address this abnormality
            4. Future consequences: Potential health impacts if this abnormality persists untreated
            5. Next steps: Recommended follow-up tests, specialist consultations, and when retesting should occur
            
            Structure the information in JSON format with these exact keys: 'cases', 'symptoms', 'remedies', 'consequences', 'next_steps'.
            
            For each category, provide the information as an array of objects, where each object has:
            - 'title': Brief title or heading (1-5 words)
            - 'description': Detailed paragraph (2-3 sentences)
            - 'icon': A relevant emoji that represents this item (optional)
            - 'priority': A number from 1-5 indicating importance/relevance (1 being highest priority)
            
            For the 'next_steps' category, please include a specific timeframe for retesting as one of the steps.
            
            Additional context: {$context}";
            
            $response = $client->chat()->create([
                'model' => 'gpt-4',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a medical expert AI assistant specializing in lab result interpretation.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);
            
            $details = json_decode($response->choices[0]->message->content, true);
            
            // Validate the structure of returned data
            if (!is_array($details) || 
                !isset($details['cases']) || 
                !isset($details['symptoms']) || 
                !isset($details['remedies']) || 
                !isset($details['consequences']) || 
                !isset($details['next_steps'])) {
                
                throw new \Exception('AI returned an invalid or incomplete JSON response.');
            }
            
            return $details;
            
        } catch (\Exception $e) {
            Log::error("AI finding details generation failed: " . $e->getMessage());
            
            // Return a structured error response
            return [
                'cases' => [['title' => 'Error', 'description' => 'Could not generate information', 'icon' => '❓', 'priority' => 1]],
                'symptoms' => [['title' => 'Error', 'description' => 'Could not generate information', 'icon' => '❓', 'priority' => 1]],
                'remedies' => [['title' => 'Error', 'description' => 'Could not generate information', 'icon' => '❓', 'priority' => 1]],
                'consequences' => [['title' => 'Error', 'description' => 'Could not generate information', 'icon' => '❓', 'priority' => 1]],
                'next_steps' => [['title' => 'Error', 'description' => 'Could not generate information', 'icon' => '❓', 'priority' => 1]],
            ];
        }
    }
    
    /**
     * Extract patient information from the beginning of the report
     *
     * @param $client OpenAI client
     * @param string $textSample The first portion of the report
     * @return array Patient information
     */
    private static function extractPatientInfo($client, $textSample)
    {
        $prompt = "Extract ONLY the following patient information from this medical report:
        - patient_name (full name of the patient)
        - patient_age (age with units if available)
        - patient_gender (male/female/other)
        
        Return ONLY these fields in JSON format without any explanations.
        
        Text: {$textSample}";
        
        $response = $client->chat()->create([
            'model' => 'gpt-4',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a medical data extraction specialist.'],
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);
        
        $patientInfo = json_decode($response->choices[0]->message->content, true);
        
        if (!is_array($patientInfo)) {
            return [
                'patient_name' => 'N/A',
                'patient_age' => 'N/A',
                'patient_gender' => 'N/A',
            ];
        }
        
        return $patientInfo;
    }
    
    /**
     * Process a standard-sized report (under 6000 characters)
     *
     * @param $client OpenAI client
     * @param string $rawText The raw report text
     * @return array Parsed summary
     */
    private static function processStandardReport($client, $rawText)
    {
        $prompt = "Analyze the following health report and return a JSON object with:
        1. diagnosis (short title),
        2. key_findings (array of objects, each with 'finding', 'value', 'reference', 'status', and 'description' fields),
           - finding: name of the test (e.g., 'RBC Count')
           - value: the measured value with units (e.g., '5.66 million/µL')
           - reference: reference range (e.g., '4.50 - 5.50 million/µL')
           - status: one of 'normal', 'borderline', or 'high' based on medical significance
           - description: a brief interpretation (e.g., 'Elevated Red Blood Cell (RBC) Count')
        3. recommendations (array of strings with bullet points),
        4. confidence_score (numerical value 0-100, without % symbol)
        
        For example, key_findings should look like:
        [
          {
            \"finding\": \"RBC Count\",
            \"value\": \"5.66 million/µL\",
            \"reference\": \"4.50 - 5.50 million/µL\",
            \"status\": \"high\",
            \"description\": \"Elevated Red Blood Cell (RBC) Count\"
          },
          {
            \"finding\": \"Vitamin D\",
            \"value\": \"10.50 ng/mL\", 
            \"reference\": \">=30 ng/mL (Sufficiency)\",
            \"status\": \"high\",
            \"description\": \"Vitamin D Deficiency\"
          }
        ]
        
        IMPORTANT: 
        - Values marked with H or L in the report should have 'high' status
        - 'high' status means clinically significant (either too high OR too low)
        - Use 'description' field to provide a clear interpretation of each finding
        
        DO NOT include patient demographic information or the full raw text.
        
        Text: {$rawText}";
        
        $response = $client->chat()->create([
            'model' => 'gpt-4',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a medical expert AI assistant.'],
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);
        
        return json_decode($response->choices[0]->message->content, true);
    }
    
    /**
     * Process a large report by breaking it into meaningful chunks
     *
     * @param $client OpenAI client
     * @param string $rawText The raw report text
     * @return array Consolidated summary
     */
    private static function processLargeReport($client, $rawText)
    {
        // Step 1: Extract key sections from the report
        $sectionExtractionPrompt = "This is a large medical report. First, identify the most important sections that contain:
        - Lab test results with abnormal values (marked as H or L)
        - Major diagnoses or medical conditions
        - Vital signs
        
        Just list the section names you found in the report as a JSON array. Don't include the content yet.";
        
        $sectionsResponse = $client->chat()->create([
            'model' => 'gpt-4',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a medical report analyzer.'],
                ['role' => 'user', 'content' => $sectionExtractionPrompt . "\n\nReport: " . substr($rawText, 0, 2000)],
            ],
        ]);
        
        $sections = json_decode($sectionsResponse->choices[0]->message->content, true);
        
        if (!is_array($sections)) {
            $sections = ['Abnormal Labs', 'Key Values'];
        }
        
        // Step 2: Extract content for each important section
        $extractedData = [];
        $chunkSize = 4000; // Safe size for GPT-4 processing
        
        for ($i = 0; $i < strlen($rawText); $i += $chunkSize) {
            $chunk = substr($rawText, $i, $chunkSize);
            
            $extractionPrompt = "Extract the abnormal lab values (marked with H or L) and their reference ranges from this chunk of a medical report. 
            Format as a JSON array of objects with these fields:
            - 'test_name': name of the test
            - 'result': the test result with units
            - 'reference_range': normal range for the test
            - 'significance': must be one of these three values exactly: 'normal', 'borderline', or 'high'
            - 'description': interpretive comment (e.g., 'Elevated Red Blood Cell Count')
            
            IMPORTANT: All values marked with H or L should be marked as 'high' significance.";
            
            $chunkResponse = $client->chat()->create([
                'model' => 'gpt-4',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a medical data extraction specialist.'],
                    ['role' => 'user', 'content' => $extractionPrompt . "\n\nChunk: " . $chunk],
                ],
            ]);
            
            $chunkData = json_decode($chunkResponse->choices[0]->message->content, true);
            
            if (is_array($chunkData)) {
                $extractedData = array_merge($extractedData, $chunkData);
            }
        }
        
        // Step 3: Generate final summary from extracted data
        $summaryPrompt = "Based on these extracted lab values and findings, generate a comprehensive health summary with:
        1. diagnosis (short title),
        2. key_findings (array of objects, each with 'finding', 'value', 'reference', 'status', and 'description' fields),
           - finding: name of the test (e.g., 'RBC Count')
           - value: the measured value with units (e.g., '5.66 million/µL')
           - reference: reference range (e.g., '4.50 - 5.50 million/µL')
           - status: one of 'normal', 'borderline', or 'high' based on medical significance
           - description: a brief interpretation (e.g., 'Elevated Red Blood Cell (RBC) Count')
        3. recommendations (array of strings with bullet points),
        4. confidence_score (numerical value 0-100, without % symbol)
        
        IMPORTANT: 
        - Values marked with H or L in the report should have 'high' status
        - 'high' status means clinically significant (either too high OR too low)
        - Use 'description' field to provide a clear interpretation of each finding
        
        DO NOT include patient demographic information.";
        
        $finalResponse = $client->chat()->create([
            'model' => 'gpt-4',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a medical expert AI assistant.'],
                ['role' => 'user', 'content' => $summaryPrompt . "\n\nExtracted Data: " . json_encode($extractedData, JSON_PRETTY_PRINT)],
            ],
        ]);
        
        return json_decode($finalResponse->choices[0]->message->content, true);
    }
    
    /**
     * Translate the medical summary to Hindi
     *
     * @param $client OpenAI client
     * @param array $summary The English summary
     * @return string Hindi version of the summary
     */
    private static function translateToHindi($client, $summary)
    {
        // Create a copy of the summary for translation that doesn't include the detailed key_findings
        $translationSummary = $summary;
        
        // Simplify key_findings to just the findings text for translation
        if (isset($translationSummary['key_findings']) && is_array($translationSummary['key_findings'])) {
            $simplifiedFindings = [];
            foreach ($translationSummary['key_findings'] as $finding) {
                if (is_array($finding)) {
                    // Use description field if available, otherwise create a text representation
                    if (isset($finding['description']) && !empty($finding['description'])) {
                        $simplifiedFindings[] = $finding['description'];
                    } else if (isset($finding['finding']) && isset($finding['value'])) {
                        $simplifiedFindings[] = $finding['finding'] . ': ' . $finding['value'];
                    } else {
                        $simplifiedFindings[] = $finding['finding'] ?? 'Finding not specified';
                    }
                } else {
                    $simplifiedFindings[] = $finding;
                }
            }
            $translationSummary['key_findings'] = $simplifiedFindings;
        }
        
        $translatePrompt = " give me answer in humanize language in hindi,  Translate the following medical summary to Easy Hindi Format Mostly like Mumbai Style:\n\n" . json_encode($translationSummary, JSON_PRETTY_PRINT);
        
        $translation = $client->chat()->create([
            'model' => 'gpt-4',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a professional Hindi translator. give me answer in humanize language in hindi'],
                ['role' => 'user', 'content' => $translatePrompt],
            ],
        ]);
        
        return $translation->choices[0]->message->content;
    }
}