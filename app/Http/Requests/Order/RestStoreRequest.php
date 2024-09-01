<?php

namespace App\Http\Requests\Order;

use App\Http\Requests\BaseRequest;

class RestStoreRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
		$rules = (new StoreRequest)->rules();

		unset($rules['delivery_type']);

        return $rules;
    }
}
