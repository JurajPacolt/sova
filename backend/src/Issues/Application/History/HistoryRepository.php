<?php

declare(strict_types=1);

namespace Sova\Issues\Application\History;

interface HistoryRepository
{
    /**
     * @return list<HistoryEntry> newest first
     */
    public function listForIssue(string $tenantId, string $issueId, int $limit): array;
}
