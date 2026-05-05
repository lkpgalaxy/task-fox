<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $projects = Project::query()
            ->with('defaultReviewer:id,name,github_username')
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project): array => $this->serializeProject($project));

        $selectedProject = null;
        if ($request->integer('project')) {
            $selectedProject = $projects->firstWhere('id', $request->integer('project'));
        }

        return Inertia::render('projects/Index', [
            'projects' => $projects,
            'reviewerOptions' => User::query()
                ->orderBy('name')
                ->get(['id', 'name', 'github_username']),
            'selectedProject' => $selectedProject,
        ]);
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $project = $request->validated();

        Project::create($project);

        return redirect()
            ->route('projects.index')
            ->with('status', 'Project created.');
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $data = $request->validated();
        $credentialUsernameInput = $request->input('credential_username');
        $credentialPasswordInput = $request->input('credential_password');

        if (($data['database_password'] ?? '') === '') {
            $data['database_password'] = $project->database_password;
        }

        if (($data['credential_password'] ?? '') === '') {
            $data['credential_password'] = $project->credential_password;
        }

        if (($data['credential_username'] ?? '') === '') {
            $data['credential_username'] = $project->credential_username;
        }

        if (
            ($credentialUsernameInput === null || $credentialUsernameInput === '')
            && ($credentialPasswordInput === null || $credentialPasswordInput === '')
        ) {
            $data['credential_username'] = $project->credential_username;
            $data['credential_password'] = $project->credential_password;
        }

        $project->update($data);

        return redirect()
            ->route('projects.index')
            ->with('status', 'Project updated.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        $project->delete();

        return redirect()
            ->route('projects.index')
            ->with('status', 'Project deleted.');
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     workspace_path: string,
     *     url: string|null,
     *     database_name: string|null,
     *     database_username: string|null,
     *     base_branch: string|null,
     *     default_reviewer_user_id: int|null,
     *     default_reviewer: array{id: int, name: string, github_username: string|null}|null,
     *     has_database_password: bool,
     *     has_credential_password: bool,
     *     credential_username: string|null,
     *     has_credential_username: bool
     * }
     */
    private function serializeProject(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => (string) $project->name,
            'workspace_path' => (string) $project->workspace_path,
            'url' => $project->url,
            'database_name' => $project->database_name,
            'database_username' => $project->database_username,
            'base_branch' => $project->base_branch,
            'default_reviewer_user_id' => $project->default_reviewer_user_id,
            'default_reviewer' => $project->defaultReviewer ? [
                'id' => $project->defaultReviewer->id,
                'name' => $project->defaultReviewer->name,
                'github_username' => $project->defaultReviewer->github_username,
            ] : null,
            'has_database_password' => $project->database_password !== null && $project->database_password !== '',
            'has_credential_password' => $project->credential_password !== null && $project->credential_password !== '',
            'credential_username' => $project->credential_username,
            'has_credential_username' => $project->credential_username !== null && $project->credential_username !== '',
        ];
    }
}
