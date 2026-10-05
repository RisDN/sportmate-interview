<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RepositoryFiltersRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'languages' => ['sometimes', 'array', 'list'],
            'languages.*' => ['required', 'string', 'max:255', 'distinct'],
            'without_language' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'string', Rule::in(['name', 'issues_count', 'pull_requests_count', 'last_committed_at', 'stars_count', 'forks_count'])],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $withoutLanguage = $this->input('without_language');

        if (in_array($withoutLanguage, ['true', 'false'], true)) {
            $this->merge(['without_language' => $withoutLanguage === 'true']);
        }
    }

    /** @return array{search: string, languages: list<string>, without_language: bool, sort: string, direction: 'asc'|'desc'} */
    public function filters(): array
    {
        /** @var list<string> $languages */
        $languages = $this->validated('languages', []);

        return [
            'search' => $this->string('search')->trim()->toString(),
            'languages' => $languages,
            'without_language' => $this->boolean('without_language'),
            'sort' => $this->string('sort', 'name')->toString(),
            'direction' => $this->input('direction') === 'desc' ? 'desc' : 'asc',
        ];
    }

    public function repositoryPage(string $parameter = 'page'): int
    {
        $value = $this->query($parameter);
        $page = is_string($value) && ctype_digit($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;

        return $page === false ? 1 : $page;
    }
}
