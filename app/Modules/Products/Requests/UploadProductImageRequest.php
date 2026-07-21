<?php

namespace App\Modules\Products\Requests;

use App\Modules\Products\Services\ImageProcessor;
use Illuminate\Foundation\Http\FormRequest;

class UploadProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:'.implode(',', ImageProcessor::ALLOWED_MIMES),
                'max:'.(ImageProcessor::MAX_INPUT_SIZE / 1024),
                'dimensions:max_width='.ImageProcessor::MAX_INPUT_DIMENSION.',max_height='.ImageProcessor::MAX_INPUT_DIMENSION,
            ],
            'alt' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.required' => 'Debes seleccionar un archivo de imagen.',
            'image.mimes' => 'La imagen debe ser JPG, PNG o WebP.',
            'image.mimetypes' => 'La imagen debe ser JPG, PNG o WebP.',
            'image.max' => 'La imagen no puede pesar mas de 5 MB.',
            'image.dimensions' => 'La imagen no puede superar 4096x4096 px.',
        ];
    }
}
