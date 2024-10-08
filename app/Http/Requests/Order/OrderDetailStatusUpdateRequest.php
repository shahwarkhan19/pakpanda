<?php

namespace App\Http\Requests\Order;

use App\Http\Requests\BaseRequest;
use App\Models\OrderDetail;
use Illuminate\Validation\Rule;

class OrderDetailStatusUpdateRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'status' => [
                'string',
                'required',
                Rule::in(OrderDetail::STATUSES)
            ],
        ];
    }
}
