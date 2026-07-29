<?php

declare(strict_types=1);

namespace Sova\Dashboards\Application;

use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Application\AuthorizationSubject;
use Sova\Authorization\Domain\Permission;
use Sova\Dashboards\Domain\DashboardName;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;

/**
 * Creating, renaming, ordering, duplicating and removing personal dashboards.
 *
 * A dashboard is personal. It belongs to one membership and nobody else can
 * reach it, so every read and write is keyed on the caller's own membership
 * rather than filtered afterwards — somebody else's dashboard answers `404`,
 * exactly as a dashboard that does not exist.
 *
 * Two invariants run through all of it. A member always has **at least one**
 * dashboard, so the last one cannot be deleted; and **exactly one** of them is
 * the default, which the database enforces with a partial unique index rather
 * than trusting whoever remembers to clear the previous one.
 */
final readonly class DashboardService
{
    /**
     * Thirty widgets per dashboard is the recommended ceiling (spec §7.4); the
     * same number bounds how many dashboards one membership may keep, so a
     * runaway client cannot fill the table.
     */
    private const int MAX_DASHBOARDS = 30;

    /** The width of `dashboards.name`. */
    private const int MAX_NAME_LENGTH = 160;

    public function __construct(
        private DashboardRepository $dashboards,
        private AuthorizationService $authorization,
    ) {}

    /**
     * @return list<Dashboard>
     */
    public function listOwned(string $tenantId, string $membershipId): array
    {
        return $this->dashboards->listOwned($tenantId, $membershipId);
    }

    public function get(string $tenantId, string $dashboardId, string $membershipId): Dashboard
    {
        $dashboard = $this->dashboards->find($tenantId, $dashboardId, $membershipId);

        if ($dashboard === null) {
            throw $this->notFound();
        }

        return $dashboard;
    }

    public function activeDashboardId(string $tenantId, string $membershipId): ?string
    {
        return $this->dashboards->activeDashboardId($tenantId, $membershipId);
    }

    /**
     * The dashboard to open when none was named: the last one this member
     * looked at, or their default when the preference is missing or points at
     * something that has since been deleted.
     */
    public function resolveActive(string $tenantId, string $membershipId): ?Dashboard
    {
        $activeId = $this->dashboards->activeDashboardId($tenantId, $membershipId);

        if ($activeId !== null) {
            $active = $this->dashboards->find($tenantId, $activeId, $membershipId);

            if ($active !== null) {
                return $active;
            }
        }

        foreach ($this->dashboards->listOwned($tenantId, $membershipId) as $dashboard) {
            if ($dashboard->isDefault) {
                return $dashboard;
            }
        }

        return null;
    }

    public function create(
        AuthorizationSubject $subject,
        string $tenantId,
        string $membershipId,
        string $name,
    ): string {
        $this->authorization->require(
            $subject,
            Permission::DashboardCreate,
            AuthorizationScope::tenant($tenantId),
        );

        $owned = $this->dashboards->listOwned($tenantId, $membershipId);

        if (count($owned) >= self::MAX_DASHBOARDS) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'DASHBOARD_LIMIT_REACHED',
                'You already have as many dashboards as one member may keep.',
            );
        }

        $this->assertNameIsFree($tenantId, $membershipId, $name, null);
        $dashboardId = (string) UuidV7::generate();

        $this->dashboards->create(
            $tenantId,
            $dashboardId,
            $membershipId,
            trim($name),
            $this->nextPosition($owned),
            // A member must always have exactly one default, so the first
            // dashboard they own becomes it.
            $owned === [],
        );

        return $dashboardId;
    }

    /**
     * A name near the one asked for that this member does not already use.
     *
     * The starter template *proposes* a name. Somebody restoring it a second
     * time would collide with their own earlier copy, and `409` is the wrong
     * answer to "give me a fresh dashboard from the template" — the template
     * exists precisely so that nobody has to invent a name first.
     */
    public function availableName(
        string $tenantId,
        string $membershipId,
        string $preferred,
    ): string {
        $base = trim($preferred);

        // One more candidate than a member may own dashboards, so at least one
        // of them is always free.
        for ($suffix = 1; $suffix <= self::MAX_DASHBOARDS + 1; ++$suffix) {
            $candidate = $suffix === 1 ? $base : $this->suffixed($base, $suffix);

            if ($this->dashboards->nameIsFree(
                $tenantId,
                $membershipId,
                DashboardName::normalize($candidate),
                null,
            )) {
                return $candidate;
            }
        }

        throw new DomainProblemException(
            ProblemType::Conflict,
            'DASHBOARD_NAME_TAKEN',
            'A dashboard of that name already exists.',
        );
    }

    public function rename(
        AuthorizationSubject $subject,
        string $tenantId,
        string $dashboardId,
        string $membershipId,
        int $expectedVersion,
        string $name,
        ?int $position,
    ): void {
        $this->requireOwnUpdate($subject, $tenantId);
        $this->get($tenantId, $dashboardId, $membershipId);
        $this->assertNameIsFree($tenantId, $membershipId, $name, $dashboardId);

        $version = $this->dashboards->rename(
            $tenantId,
            $dashboardId,
            $membershipId,
            $expectedVersion,
            trim($name),
            $position,
        );

        if ($version === null) {
            throw $this->versionConflict();
        }
    }

    public function makeDefault(
        AuthorizationSubject $subject,
        string $tenantId,
        string $dashboardId,
        string $membershipId,
    ): void {
        $this->requireOwnUpdate($subject, $tenantId);
        $this->get($tenantId, $dashboardId, $membershipId);
        $this->dashboards->makeDefault($tenantId, $dashboardId, $membershipId);
    }

    /**
     * Duplicates the dashboard with its widgets. The copy points at the **same**
     * saved queries: duplicating those as well would silently double the
     * member's query list every time they copied a dashboard.
     */
    public function copy(
        AuthorizationSubject $subject,
        string $tenantId,
        string $dashboardId,
        string $membershipId,
        string $name,
    ): string {
        $this->authorization->require(
            $subject,
            Permission::DashboardCreate,
            AuthorizationScope::tenant($tenantId),
        );

        $this->get($tenantId, $dashboardId, $membershipId);
        $owned = $this->dashboards->listOwned($tenantId, $membershipId);

        if (count($owned) >= self::MAX_DASHBOARDS) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'DASHBOARD_LIMIT_REACHED',
                'You already have as many dashboards as one member may keep.',
            );
        }

        $this->assertNameIsFree($tenantId, $membershipId, $name, null);
        $copyId = (string) UuidV7::generate();

        $this->dashboards->copy(
            $tenantId,
            $dashboardId,
            $copyId,
            $membershipId,
            trim($name),
            $this->nextPosition($owned),
        );

        return $copyId;
    }

    /**
     * Removes a dashboard. The last one stays: a member must always have one,
     * and emptying it is the way to start over (spec §7.2).
     */
    public function delete(
        AuthorizationSubject $subject,
        string $tenantId,
        string $dashboardId,
        string $membershipId,
    ): void {
        $this->authorization->require(
            $subject,
            Permission::DashboardDeleteOwn,
            AuthorizationScope::tenant($tenantId),
        );

        $dashboard = $this->get($tenantId, $dashboardId, $membershipId);

        if ($this->dashboards->countOwned($tenantId, $membershipId) <= 1) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'LAST_DASHBOARD_REQUIRED',
                'Your last dashboard cannot be deleted. Empty it instead.',
            );
        }

        $this->dashboards->delete($tenantId, $dashboardId, $membershipId);

        // Removing the default would leave the member with none, so the next
        // dashboard in their order takes over.
        if ($dashboard->isDefault) {
            $remaining = $this->dashboards->listOwned($tenantId, $membershipId);

            if ($remaining !== []) {
                $this->dashboards->makeDefault($tenantId, $remaining[0]->id, $membershipId);
            }
        }
    }

    public function setActive(
        string $tenantId,
        string $dashboardId,
        string $membershipId,
    ): void {
        // Remembering which dashboard somebody looked at needs nothing beyond
        // being able to open it; it is a preference, not a change.
        $this->get($tenantId, $dashboardId, $membershipId);
        $this->dashboards->setActiveDashboard($tenantId, $membershipId, $dashboardId);
    }

    /**
     * @param list<Dashboard> $owned
     */
    private function nextPosition(array $owned): int
    {
        $highest = -1;

        foreach ($owned as $dashboard) {
            $highest = max($highest, $dashboard->position);
        }

        return $highest + 1;
    }

    /**
     * Appends the counter without letting the result outgrow the column, so a
     * long name loses its tail rather than the whole write failing.
     */
    private function suffixed(string $base, int $suffix): string
    {
        $tail = ' ' . $suffix;

        return mb_substr($base, 0, self::MAX_NAME_LENGTH - mb_strlen($tail)) . $tail;
    }

    private function requireOwnUpdate(AuthorizationSubject $subject, string $tenantId): void
    {
        $this->authorization->require(
            $subject,
            Permission::DashboardUpdateOwn,
            AuthorizationScope::tenant($tenantId),
        );
    }

    private function assertNameIsFree(
        string $tenantId,
        string $membershipId,
        string $name,
        ?string $exceptDashboardId,
    ): void {
        $normalized = DashboardName::normalize($name);

        if ($normalized === '') {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'DASHBOARD_NAME_INVALID',
                'Give the dashboard a name.',
                ['name' => ['Give the dashboard a name.']],
            );
        }

        if (!$this->dashboards->nameIsFree(
            $tenantId,
            $membershipId,
            $normalized,
            $exceptDashboardId,
        )) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'DASHBOARD_NAME_TAKEN',
                'A dashboard of that name already exists.',
            );
        }
    }

    private function notFound(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ResourceNotFound,
            'DASHBOARD_NOT_FOUND',
            'The dashboard was not found.',
        );
    }

    private function versionConflict(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::Conflict,
            'DASHBOARD_VERSION_CONFLICT',
            'The dashboard was changed in the meantime. Reload and try again.',
        );
    }
}
