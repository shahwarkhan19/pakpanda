<?php

namespace App\Http\Requests\Order;

use App\Http\Requests\BaseRequest;

class UploadPhotoRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'img' => 'required|string|max:255'
        ];
    }
}
