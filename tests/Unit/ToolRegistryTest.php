<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/ToolRegistry.php';

use PHPUnit\Framework\TestCase;

class ToolRegistryTest extends TestCase
{
    public function testKnownPagesHaveTitlesAndGroups(): void
    {
        $this->assertSame('Audit', ToolRegistry::title('hub-audit'));
        $this->assertSame('Duplicate Shipping Addresses', ToolRegistry::title('addrdupes'));
        $this->assertSame('search', ToolRegistry::groupOf('globalsearch'));
        $this->assertSame('manage', ToolRegistry::groupOf('runlog'));
        $this->assertContains('inventoryoversell', ToolRegistry::allowedPages());
        $this->assertContains('shipmentaging', ToolRegistry::allowedPages());
        $this->assertSame('Fraud & Compliance', array_key_last(ToolRegistry::hubSections('audit')));
    }

    public function testNormalizePageFallsBackForUnknownPages(): void
    {
        $this->assertSame('hub-audit', ToolRegistry::normalizePage('does-not-exist'));
        $this->assertSame('run', ToolRegistry::normalizePage('run'));
    }

    public function testHubSectionsIncludeAuditAndSearchTools(): void
    {
        $audit = ToolRegistry::hubSections('audit');
        $search = ToolRegistry::hubSections('search');

        $this->assertArrayHasKey('Core Audit', $audit);
        $this->assertArrayHasKey('Orders', $search);
        $this->assertSame('run', $audit['Core Audit'][1]['page']);
        $this->assertSame('spotcheck', $search['Orders'][0]['page']);
    }
}
