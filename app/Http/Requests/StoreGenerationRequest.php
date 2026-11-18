<?php

namespace App\Http\Requests;

use App\Models\Generation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    /**
     * Pixelforge's own limits, checked here so a bad request never reaches it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in(Generation::SIZES)],
            'quality' => ['required', Rule::in(Generation::QUALITIES)],
            'reference_images' => ['list', 'max:4'],
            'reference_images.*' => ['required', 'url:http,https', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reference_images.*.url' => 'Each reference image must be an http(s) URL.',
        ];
    }
}
