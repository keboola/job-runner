<?php

declare(strict_types=1);

namespace App;

use Keboola\ErrorControl\Monolog\LogInfoInterface;

class LogInfo implements LogInfoInterface
{
    private string $projectId;
    private string $runId;
    private string $componentId;

    public function __construct(
        string $runId,
        string $componentId,
        string $projectId
    ) {
        $this->runId = $runId;
        $this->componentId = $componentId;
        $this->projectId = $projectId;
    }

    public function getProjectId(): string
    {
        return $this->projectId;
    }

    public function getRunId(): string
    {
        return $this->runId;
    }

    public function getComponentId(): string
    {
        return $this->componentId;
    }

    public function toArray(): array
    {
        return [
            'component' => $this->getComponentId(),
            'runId' => $this->getRunId(),
            'owner' => [
                'id' => $this->getProjectId(),
            ],
        ];
    }
}
