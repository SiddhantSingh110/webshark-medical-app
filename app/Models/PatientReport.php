<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory; 
use Illuminate\Database\Eloquent\Model;

class PatientReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_id', 
        'doctor_id', 
        'file_path', 
        'type', 
        'notes',
        'report_date',
        'report_title',
        'uploaded_by'
    ];

    protected $casts = [
        'report_date' => 'date',
    ];

    public function aiSummary()
    {
        return $this->hasOne(AISummary::class, 'report_id');
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }
}