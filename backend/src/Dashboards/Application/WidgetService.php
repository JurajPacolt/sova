<?php

declare(strict_types=1);

namespace Sova\Dashboards\Application;

use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\AuthorizationService;
use Sova\Authorization\Application\AuthorizationSubject;
use Sova\Authorization\Domain\Permission;
use Sova\Dashboards\Domain\DashboardLayout;
use Sova\Dashboards\Domain\WidgetPlacement;
use Sova\Dashboards\Domain\WidgetRegistry\WidgetConfigurationValidator;
use Sova\Dashboards\Domain\WidgetRegistry\WidgetDefinition;
use Sova\Dashboards\Domain\WidgetRegistry\WidgetRegistry;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;

/**
 * Adding, configuring, arranging and removing the widgets of one dashboard.
 *
 * Reaching the dashboard is the gate for everything here: the dashboard service
 * answers `404` for one the caller does not own, so a widget on somebody else's
 * dashboard is unreachable before any widget rule is consulted.
 *
 * A widget renders a **saved query the caller may already reach** — their own or
 * one shared with them. Without that check a dashboard would become a way to
 * run somebody else's private query by pasting its identifier. It still conveys
 * no issues: the query is executed in the reader's own `issue.view` scope every
 * time the widget loads.
 */
final readonly class WidgetService
{
    public function __construct(
        private WidgetRepository $widgets,
        private DashboardService $dashboards,
        private WidgetRegistry $registry,
        private WidgetConfigurationValidator $configuration,
        private AuthorizationService $authorization,
    ) {}

    /**
     * @return list<DashboardWidget>
     */
    public function listForDashboard(
        string $tenantId,
        string $dashboardId,
        string $membershipId,
    ): array {
        $this->dashboards->get($tenantId, $dashboardId, $membershipId);

        return $this->widgets->listForDashboard($tenantId, $dashboardId);
    }

    /**
     * @param array<string, mixed> $rawConfiguration
     */
    public function create(
        AuthorizationSubject $subject,
        string $tenantId,
        string $dashboardId,
        string $membershipId,
        string $savedQueryId,
        string $typeKey,
        string $title,
        array $rawConfiguration,
    ): string {
        $this->requireUpdate($subject, $tenantId);
        $this->dashboards->get($tenantId, $dashboardId, $membershipId);

        $definition = $this->definition($typeKey);
        $configuration = $this->validated($definition, $rawConfiguration);
        $this->assertSourceIsUsable($tenantId, $savedQueryId, $membershipId);

        if ($this->widgets->countForDashboard($tenantId, $dashboardId) >= DashboardLayout::MAX_WIDGETS) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'DASHBOARD_LAYOUT_INVALID',
                'A dashboard holds at most 30 widgets.',
                ['widgets' => ['A dashboard holds at most 30 widgets.']],
            );
        }

        $widgetId = (string) UuidV7::generate();
        $placement = $this->firstFreeRow($tenantId, $dashboardId, $definition);

        $this->widgets->create(
            $tenantId,
            $dashboardId,
            $widgetId,
            $savedQueryId,
            $definition->type->value,
            $definition->schemaVersion,
            $title,
            $configuration,
            $placement[0],
            $placement[1],
            $definition->defaultWidth,
            $definition->defaultHeight,
        );

        return $widgetId;
    }

    /**
     * @param array<string, mixed> $rawConfiguration
     */
    public function update(
        AuthorizationSubject $subject,
        string $tenantId,
        string $dashboardId,
        string $widgetId,
        string $membershipId,
        int $expectedVersion,
        string $savedQueryId,
        string $title,
        array $rawConfiguration,
    ): void {
        $this->requireUpdate($subject, $tenantId);
        $widget = $this->get($tenantId, $dashboardId, $widgetId, $membershipId);

        // The type is fixed for the life of the instance. Changing it would
        // reinterpret a configuration written for a different schema, which is
        // exactly what the registry forbids.
        $definition = $this->definition($widget->typeKey);
        $configuration = $this->validated($definition, $rawConfiguration);
        $this->assertSourceIsUsable($tenantId, $savedQueryId, $membershipId);

        $version = $this->widgets->update(
            $tenantId,
            $dashboardId,
            $widgetId,
            $expectedVersion,
            $savedQueryId,
            $title,
            $configuration,
        );

        if ($version === null) {
            throw $this->widgetVersionConflict();
        }
    }

    public function delete(
        AuthorizationSubject $subject,
        string $tenantId,
        string $dashboardId,
        string $widgetId,
        string $membershipId,
    ): void {
        $this->requireUpdate($subject, $tenantId);
        $this->get($tenantId, $dashboardId, $widgetId, $membershipId);
        $this->widgets->delete($tenantId, $dashboardId, $widgetId);
    }

    /**
     * Applies a whole arrangement at once against the dashboard's version.
     *
     * One request rather than one per widget: moving two widgets past each
     * other is only ever legal as a pair, and a per-widget endpoint would have
     * to reject the first half of a legal move.
     *
     * @param list<WidgetPlacement> $placements
     */
    public function applyLayout(
        AuthorizationSubject $subject,
        string $tenantId,
        string $dashboardId,
        string $membershipId,
        int $expectedDashboardVersion,
        array $placements,
    ): void {
        $this->requireUpdate($subject, $tenantId);
        $this->dashboards->get($tenantId, $dashboardId, $membershipId);

        $definitions = [];
        $stored = $this->widgets->listForDashboard($tenantId, $dashboardId);

        foreach ($stored as $widget) {
            $definition = $this->registry->find($widget->typeKey);

            if ($definition !== null) {
                $definitions[$widget->id] = $definition;
            }
        }

        // The layout must name every widget of the dashboard: a partial one
        // would leave the widgets it omitted where they were, which is how two
        // of them end up overlapping.
        if (count($placements) !== count($stored)) {
            throw $this->layoutInvalid([
                'widgets' => ['The layout must place every widget of this dashboard.'],
            ]);
        }

        $errors = DashboardLayout::validate($placements, $definitions);

        if ($errors !== []) {
            throw $this->layoutInvalid($errors);
        }

        $version = $this->widgets->applyLayout(
            $tenantId,
            $dashboardId,
            $expectedDashboardVersion,
            $placements,
        );

        if ($version === null) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'DASHBOARD_VERSION_CONFLICT',
                'The dashboard was changed in the meantime. Reload and try again.',
            );
        }
    }

    /**
     * How many widgets still render this saved query. Archiving one that a
     * widget uses would leave the dashboard pointing at something retired.
     */
    public function countUsingSavedQuery(string $tenantId, string $savedQueryId): int
    {
        return $this->widgets->countUsingSavedQuery($tenantId, $savedQueryId);
    }

    public function get(
        string $tenantId,
        string $dashboardId,
        string $widgetId,
        string $membershipId,
    ): DashboardWidget {
        $this->dashboards->get($tenantId, $dashboardId, $membershipId);
        $widget = $this->widgets->find($tenantId, $dashboardId, $widgetId);

        if ($widget === null) {
            throw new DomainProblemException(
                ProblemType::ResourceNotFound,
                'WIDGET_NOT_FOUND',
                'The widget was not found.',
            );
        }

        return $widget;
    }

    /**
     * The first row where a new widget of this size fits without overlapping
     * anything. Simple and predictable beats clever: a new widget lands under
     * everything that already exists rather than squeezing into a gap the
     * person did not intend.
     *
     * @return array{int, int}
     */
    private function firstFreeRow(
        string $tenantId,
        string $dashboardId,
        WidgetDefinition $definition,
    ): array {
        $bottom = 0;

        foreach ($this->widgets->listForDashboard($tenantId, $dashboardId) as $widget) {
            $bottom = max($bottom, $widget->y + $widget->height);
        }

        return [0, $bottom];
    }

    private function definition(string $typeKey): WidgetDefinition
    {
        $definition = $this->registry->find($typeKey);

        if ($definition === null) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'WIDGET_CONFIGURATION_INVALID',
                'That widget type is not available.',
                ['type_key' => ['That widget type is not available.']],
            );
        }

        return $definition;
    }

    /**
     * @param array<string, mixed> $rawConfiguration
     *
     * @return array<string, mixed>
     */
    private function validated(WidgetDefinition $definition, array $rawConfiguration): array
    {
        $result = $this->configuration->validate($definition, $rawConfiguration);

        if (!$result->valid) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'WIDGET_CONFIGURATION_INVALID',
                'The widget configuration does not match its type.',
                $result->errors,
            );
        }

        return $result->configuration;
    }

    private function assertSourceIsUsable(
        string $tenantId,
        string $savedQueryId,
        string $membershipId,
    ): void {
        if (!$this->widgets->savedQueryIsUsable($tenantId, $savedQueryId, $membershipId)) {
            // The same answer whether the query does not exist, is archived, or
            // simply is not theirs — a widget must not become a way to find out
            // which.
            throw new DomainProblemException(
                ProblemType::ResourceNotFound,
                'WIDGET_DATA_SOURCE_NOT_FOUND',
                'The saved query is not available as a widget source.',
            );
        }
    }

    private function requireUpdate(AuthorizationSubject $subject, string $tenantId): void
    {
        $this->authorization->require(
            $subject,
            Permission::DashboardUpdateOwn,
            AuthorizationScope::tenant($tenantId),
        );
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function layoutInvalid(array $errors): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ValidationFailed,
            'DASHBOARD_LAYOUT_INVALID',
            'The arrangement is not valid.',
            $errors,
        );
    }

    private function widgetVersionConflict(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::Conflict,
            'WIDGET_VERSION_CONFLICT',
            'The widget was changed in the meantime. Reload and try again.',
        );
    }
}
