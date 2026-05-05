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
     *     project_id: int|null,
     *     priority: string|null,
     *     deadline: string|null,
     *     questions: array<int, string>,
     * }>
     */
    public function extract(InputSource $inputSource, array $projectSummaries = []): array;
}
