<?php

declare(strict_types=1);

namespace Sova\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Sova\Dashboards\Domain\DashboardLayout;
use Sova\Dashboards\Domain\Template\StarterTemplate;
use Sova\Dashboards\Domain\Template\TemplateQuery;
use Sova\Dashboards\Domain\Template\TemplateWidget;
use Sova\Dashboards\Domain\WidgetPlacement;
use Sova\Dashboards\Domain\WidgetRegistry\WidgetConfigurationValidator;
use Sova\Dashboards\Domain\WidgetRegistry\WidgetDefinition;
use Sova\Dashboards\Domain\WidgetRegistry\WidgetRegistry;
use Sova\Issues\Domain\QueryLanguage\FieldCatalog;
use Sova\Issues\Domain\QueryLanguage\FunctionCatalog;
use Sova\Issues\Domain\QueryLanguage\QueryLimits;
use Sova\Issues\Domain\QueryLanguage\SovaQlAnalyzer;

/**
 * The starter manifest is shipped data, so it is worth as much as it is
 * correct. Everything here is checked without a database: a manifest that
 * cannot be provisioned should fail in the suite, not on somebody's first
 * login.
 */
final class StarterTemplateTest extends TestCase
{
    public function testEveryQueryIsValidAndAlreadyCanonical(): void
    {
        $analyzer = new SovaQlAnalyzer(
            new FieldCatalog(),
            new FunctionCatalog(),
            new QueryLimits(),
        );

        foreach (StarterTemplate::queries() as $query) {
            $analyzed = $analyzer->analyze($query->query);

            self::assertTrue($analyzed->valid, $query->key . ': ' . $query->query);
            // Storing the canonical spelling the server would have produced
            // anyway keeps the shipped text and the stored text the same thing.
            self::assertSame($query->query, $analyzed->canonical, $query->key);
        }
    }

    /**
     * The manifest is one file for every tenant and every person in them, so it
     * may not name any of them (spec §7.5). Whose issues these are is asked
     * with `currentUser()`, which is resolved per reader at run time.
     */
    public function testNoQueryNamesATenantAProjectOrAPerson(): void
    {
        foreach (StarterTemplate::queries() as $query) {
            self::assertDoesNotMatchRegularExpression(
                '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
                $query->query,
                $query->key . ' must not carry an identifier.',
            );
            self::assertStringNotContainsString('@', $query->query, $query->key);
            self::assertStringNotContainsString('user(', $query->query, $query->key);
            self::assertStringNotContainsString('project =', $query->query, $query->key);
        }
    }

    public function testUnsupportedFieldsStayOutOfTheManifest(): void
    {
        // `due`, `labels`, `estimate` and `closed` are valid names whose storage
        // arrives later. A preset built on one of them would be a promise the
        // server cannot keep today.
        foreach (StarterTemplate::queries() as $query) {
            foreach (['due', 'labels', 'estimate', 'closed'] as $field) {
                self::assertStringNotContainsString($field, $query->query, $query->key);
            }
        }
    }

    public function testEveryWidgetNamesAQueryTheManifestDefines(): void
    {
        $keys = array_map(
            static fn(TemplateQuery $query): string => $query->key,
            StarterTemplate::queries(),
        );

        foreach (StarterTemplate::widgets() as $widget) {
            self::assertContains($widget->queryKey, $keys);
        }

        // And nothing is defined that nothing uses: an unused query would land
        // in somebody's list without ever being shown to them.
        $used = array_map(
            static fn(TemplateWidget $widget): string => $widget->queryKey,
            StarterTemplate::widgets(),
        );

        foreach ($keys as $key) {
            self::assertContains($key, $used, $key . ' is defined but never rendered.');
        }
    }

    /**
     * The arrangement goes through the very validator a client-sent layout
     * does, so a manifest that overlaps itself or oversteps a type's limits is
     * caught here rather than at provisioning time.
     */
    public function testTheLayoutIsOneAValidatorWouldAccept(): void
    {
        $registry = new WidgetRegistry();
        $placements = [];
        $definitions = [];

        foreach (StarterTemplate::widgets() as $index => $widget) {
            $widgetId = sprintf('widget-%d', $index);
            $definition = $registry->find($widget->type->value);
            self::assertInstanceOf(WidgetDefinition::class, $definition);

            $definitions[$widgetId] = $definition;
            $placements[] = new WidgetPlacement(
                $widgetId,
                $widget->x,
                $widget->y,
                $widget->width,
                $widget->height,
            );
        }

        self::assertSame([], DashboardLayout::validate($placements, $definitions));
    }

    /**
     * A preset gets no shortcut past the schema of its own type: it is stored
     * through the same whitelist as anything a client sends, and it is written
     * out in full so that no value silently depends on today's default.
     */
    public function testEveryPresetIsAcceptedExactlyAsWritten(): void
    {
        $registry = new WidgetRegistry();
        $validator = new WidgetConfigurationValidator();

        foreach (StarterTemplate::widgets() as $widget) {
            $definition = $registry->find($widget->type->value);
            self::assertInstanceOf(WidgetDefinition::class, $definition);

            $result = $validator->validate($definition, $widget->configuration);

            self::assertSame([], $result->errors, $widget->title);
            self::assertSame($widget->configuration, $result->configuration, $widget->title);
        }
    }
}
