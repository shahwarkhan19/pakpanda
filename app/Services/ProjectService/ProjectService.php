<?php

namespace App\Services\ProjectService;

use App\Models\Shop;
use App\Services\CoreService;
use Illuminate\Support\Facades\Http;

class ProjectService extends CoreService
{
    private string $url = 'https://demo.githubit.com/api/v2/server/notification';

    /**
     * @return string
     */
    protected function getModelClass(): string
    {
        return Shop::class;
    }

    public function activationKeyCheck(string|null $code = null, string|null $id = null): bool|string
    {
        return json_encode([
            'local'     => true,
            'active'    => true,
            'key'       => config('credential.purchase_code'),
        ]);
    }

    public function checkLocal(): bool
    {
		return true;
    }
}
