<?php

declare(strict_types=1);

namespace Sova\Dashboards\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Application\AuthorizationSubject;
use Sova\Authorization\Domain\Permission;
use Sova\Dashboards\Domain\Template\StarterTemplate;
use Sova\Dashboards\Domain\Template\TemplateQuery;
use Sova\Dashboards\Domain\WidgetPlacement;
use Sova\SavedQueries\Application\SavedQueryService;

/**
 * Turns the starter manifest into a dashboard somebody owns (spec §7.5).
 *
 * Everything lands in **one transaction**: the private queries, the dashboard,
 * the widgets that point at those queries and the layout. Half of it would be
 * worse than none — widgets whose data source was never created render nothing
 * but an error, and a dashboard nobody asked for is harder to explain than an
 * empty list.
 *
 * The manifest is copied through the **same application services and the same
 * permission checks** a hand-built dashboard goes through. A template is a
 * convenience, not a way around a rule: nobody ends up owning a query they were
 * not allowed to create.
 *
 * Two callers, two different promises:
 *
 * - `ensure()` runs on first open and is idempotent. A member who already owns
 *   a dashboard is left alone, and a race between two first opens is settled by
 *   the unique index rather than by a lock — the loser simply finds the
 *   dashboard the winner made.
 * - `restore()` is explicit and always creates something new. It never
 *   overwrites and never re-points: existing dashboards, their widgets and
 *   their queries are untouched, and even the default flag stays where the
 *   member put it.
 */
final readonly class StarterDashboardProvisioner
{
    public function __construct(
        private DashboardService $dashboards,
        private WidgetService $widgets,
        private SavedQueryService $savedQueries,
        private AuthorizationService $authorization,
        private Connection $connection,
    ) {}

    /**
     * The starter dashboard for a member who has none, or null when there is
     * nothing to do — they already have one, or they may not create dashboards
     * at all, in which case an empty list is the truthful answer.
     */
    public function ensure(
        AuthorizationSubject $subject,
        string $tenantId,
        string $membershipId,
    ): ?string {
        if (!$this->mayCreateDashboards($subject, $tenantId)) {
            return null;
        }

        if ($this->dashboards->listOwned($tenantId, $membershipId) !== []) {
            return null;
        }

        try {
            return $this->connection->transactional(
                fn(): string => $this->provision($subject, $tenantId, $membershipId, null),
            );
        } catch (UniqueConstraintViolationException) {
            // Two first opens at once: the other one got there first, and its
            // dashboard is exactly the one this call would have made.
            return null;
        }
    }

    /**
     * A fresh copy of the template, alongside whatever the member already has.
     *
     * Restoring is explicit, so a missing permission is an error rather than a
     * quietly smaller dashboard: somebody who asked for the template and
     * received an empty page would have no way of knowing why.
     */
    public function restore(
        AuthorizationSubject $subject,
        string $tenantId,
        string $membershipId,
        ?string $name,
    ): string {
        foreach (
            [
                Permission::DashboardCreate,
                Permission::DashboardUpdateOwn,
                Permission::SavedQueryCreate,
            ] as $permission
        ) {
            $this->authorization->require(
                $subject,
                $permission,
                AuthorizationScope::tenant($tenantId),
            );
        }

        return $this->connection->transactional(
            fn(): string => $this->provision($subject, $tenantId, $membershipId, $name),
        );
    }

    private function provision(
        AuthorizationSubject $subject,
        string $tenantId,
        string $membershipId,
        ?string $name,
    ): string {
        $dashboardId = $this->dashboards->create(
            $subject,
            $tenantId,
            $membershipId,
            $this->dashboards->availableName(
                $tenantId,
                $membershipId,
                $this->requestedName($name),
            ),
        );

        // A member allowed to own a dashboard but not to fill it still gets the
        // dashboard: the invariant is that they have one, not that it is
        // furnished. Without the queries there is nothing for a widget to show,
        // so the two go together.
        if (!$this->mayFillDashboards($subject, $tenantId)) {
            return $dashboardId;
        }

        $queryIds = $this->createQueries($subject, $tenantId, $membershipId);
        $placements = [];

        foreach (StarterTemplate::widgets() as $widget) {
            $widgetId = $this->widgets->create(
                $subject,
                $tenantId,
                $dashboardId,
                $membershipId,
                $queryIds[$widget->queryKey],
                $widget->type->value,
                $widget->title,
                $widget->configuration,
            );

            $placements[] = new WidgetPlacement(
                $widgetId,
                $widget->x,
                $widget->y,
                $widget->width,
                $widget->height,
            );
        }

        if ($placements !== []) {
            // Widgets are created one by one and stack up; the manifest's
            // arrangement is applied as a whole afterwards, through the same
            // validation any client-sent layout gets. A manifest that overlaps
            // itself therefore fails here instead of reaching storage.
            $this->widgets->applyLayout(
                $subject,
                $tenantId,
                $dashboardId,
                $membershipId,
                $this->dashboards->get($tenantId, $dashboardId, $membershipId)->version,
                $placements,
            );
        }

        return $dashboardId;
    }

    /**
     * @return array<string, string> saved query id per manifest key
     */
    private function createQueries(
        AuthorizationSubject $subject,
        string $tenantId,
        string $membershipId,
    ): array {
        $ids = [];

        foreach (StarterTemplate::queries() as $query) {
            $ids[$query->key] = $this->savedQueries->create(
                $subject,
                $tenantId,
                $membershipId,
                $this->queryName($tenantId, $membershipId, $query),
                $query->description,
                $query->query,
                $query->defaultColumns,
            );
        }

        return $ids;
    }

    private function queryName(
        string $tenantId,
        string $membershipId,
        TemplateQuery $query,
    ): string {
        return $this->savedQueries->availableName($tenantId, $membershipId, $query->name);
    }

    private function requestedName(?string $name): string
    {
        $requested = trim($name ?? '');

        return $requested === '' ? StarterTemplate::DASHBOARD_NAME : $requested;
    }

    private function mayCreateDashboards(
        AuthorizationSubject $subject,
        string $tenantId,
    ): bool {
        return $this->authorization->isGranted(
            $subject,
            Permission::DashboardCreate,
            AuthorizationScope::tenant($tenantId),
        );
    }

    private function mayFillDashboards(
        AuthorizationSubject $subject,
        string $tenantId,
    ): bool {
        $scope = AuthorizationScope::tenant($tenantId);

        return $this->authorization->isGranted($subject, Permission::DashboardUpdateOwn, $scope)
            && $this->authorization->isGranted($subject, Permission::SavedQueryCreate, $scope);
    }
}
