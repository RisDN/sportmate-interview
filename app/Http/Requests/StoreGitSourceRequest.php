<?php

namespace App\Http\Requests;

use App\Models\GitSource;
use App\Services\GitProviderRegistry;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGitSourceRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(GitProviderRegistry $providers): array
    {
        return [
            'provider' => ['bail', 'required', 'string', Rule::in($providers->keys())],
            'account' => [
                'bail',
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail) use ($providers): void {
                    $key = $this->input('provider');
                    $provider = is_string($key) ? $providers->find($key) : null;

                    if ($provider === null || ! is_string($value)) {
                        return;
                    }

                    if (! $provider->isValidAccountName($value)) {
                        $fail('create.invalid');

                        return;
                    }

                    if (GitSource::query()->where('provider', $key)
                        ->where('normalized_account', mb_strtolower($value))->exists()) {
                        $fail('create.duplicate');
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'provider.required' => 'create.providerInvalid',
            'provider.string' => 'create.providerInvalid',
            'provider.in' => 'create.providerInvalid',
            'account.required' => 'create.required',
            'account.string' => 'create.invalid',
        ];
    }
}
