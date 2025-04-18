<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HealthMetric extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'patient_id',
        'type',
        'custom_type',
        'value',
        'unit',
        'measured_at',
        'notes'
    ];
    
    protected $casts = [
        'measured_at' => 'datetime',
    ];
    
    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }
}