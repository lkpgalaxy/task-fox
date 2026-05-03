<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(): Response
    {
        $projects = Project::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => (string) $project->name,
                'workspace_path' => (string) $project->workspace_path,
                'url' => $project->url,
                'database_name' => $project->database_name,
                'database_username' => $project->database_username,
                'base_branch' => $project->base_branch,
                'has_database_password' => $project->database_password !== null && $project->database_password !== '',
                'has_credential_password' => $project->credential_password !== null && $project->credential_password !== '',
                'credential_username' => $project->credential_username,
                'has_credential_username' => $project->credential_username !== null && $project->credential_username !== '',
            ]);

        return Inertia::render('projects/Index', [
            'projects' => $projects,
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
}
