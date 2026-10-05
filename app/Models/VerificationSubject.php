<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationSubject extends Model
{
    protected $fillable = [
        'consumer_id', 'type', 'external_id', 'first_name', 'middle_names',
        'surname', 'date_of_birth', 'country', 'national_id_number',
        'passport_number', 'driver_license_number', 'gender', 'extra',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'extra' => 'array',
        ];
    }

    public function consumer(): BelongsTo
    {
        return $this->belongsTo(Consumer::class);
    }

    public function expectedIdentity(): array
    {
        return array_filter([
            'first_name' => $this->first_name,
            'middle_names' => $this->middle_names,
            'surname' => $this->surname,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'national_id_number' => $this->national_id_number,
            'passport_number' => $this->passport_number,
            'driver_license_number' => $this->driver_license_number,
            'gender' => $this->gender,
            'country' => $this->country,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
