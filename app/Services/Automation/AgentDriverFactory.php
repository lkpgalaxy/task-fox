<?php

namespace App\Services\Automation;

use App\Contracts\Agent;
use App\Contracts\CodingAgent;
use App\Services\CodingAgents\OpenCodeCodingAgent;
use InvalidArgumentException;

class AgentDriverFactory
{
    /**
     * @return array<string, string>
     */
    public function agentDrivers(): array
    {
        $drivers = config('automation.supported_agent_drivers', []);

        return is_array($drivers) ? $drivers : [];
    }

    /**
     * @return array<string, string>
     */
    public function codingAgentDrivers(): array
    {
        $drivers = config('automation.supported_coding_agent_drivers', []);

        return is_array($drivers) ? $drivers : [];
    }

    public function defaultAgentDriver(): string
    {
        return (string) config('automation.agent.driver', config('automation.coding_agent.driver', 'codex'));
    }

    public function defaultCodingAgentDriver(): string
    {
        return (string) config('automation.coding_agent.driver', 'codex');
    }

    public function supportsAgentDriver(?string $driver): bool
    {
        return $driver !== null && array_key_exists($driver, $this->agentDrivers());
    }

    public function supportsCodingAgentDriver(?string $driver): bool
    {
        return $driver !== null && array_key_exists($driver, $this->codingAgentDrivers());
    }

    public function makeAgent(?string $driver): Agent
    {
        $driver ??= $this->defaultAgentDriver();

        return match ($driver) {
            'codex' => app(Agent::class),
            'opencode' => app(OpenCodeCodingAgent::class),
            default => throw new InvalidArgumentException("Unsupported agent driver [{$driver}]."),
        };
    }

    public function makeCodingAgent(?string $driver): CodingAgent
    {
        $driver ??= $this->defaultCodingAgentDriver();

        return match ($driver) {
            'codex' => app(CodingAgent::class),
            'opencode' => app(OpenCodeCodingAgent::class),
            default => throw new InvalidArgumentException("Unsupported coding agent driver [{$driver}]."),
        };
    }
}
