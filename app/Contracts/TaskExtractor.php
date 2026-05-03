<?php

namespace App\Contracts;

use App\Models\InputSource;

interface TaskExtractor
{
    /**
     * @return array<int, array{
     *     title: string,
     *     description: string,
     *     assignee_github_username: string|null,
     *     priority: string|null,
     *     deadline: string|null,
     *     acceptance_criteria: array<int, array{body: string, checked: bool}>,
     *     questions: array<int, string>,
     * }>
     */
    public function extract(InputSource $inputSource): array;
}
