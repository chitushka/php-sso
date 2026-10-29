<?php

namespace tests\Unit;

use PHPUnit\Framework\TestCase;

class AdminUiAccessibilityTest extends TestCase
{
    public function testAdminShellExposesKeyboardAndScreenReaderLandmarks(): void
    {
        $view = (string)file_get_contents(dirname(__DIR__, 2) . '/views/admin/index.php');

        self::assertStringContainsString('class="skip-link"', $view);
        self::assertStringContainsString('id="admin-content"', $view);
        self::assertStringContainsString('<admin-navigation', $view);
        self::assertStringContainsString('role="status"', $view);
        self::assertStringContainsString('aria-live="polite"', $view);
    }

    public function testNavigationModuleSupportsCurrentPageAndArrowKeys(): void
    {
        $navigation = (string)file_get_contents(dirname(__DIR__, 2) . '/web/js/admin-navigation.js');

        self::assertStringContainsString('aria-label="Primary navigation"', $navigation);
        self::assertStringContainsString(':aria-current=', $navigation);
        self::assertStringContainsString("'ArrowDown'", $navigation);
        self::assertStringContainsString("'ArrowUp'", $navigation);
        self::assertStringContainsString("'Home'", $navigation);
        self::assertStringContainsString("'End'", $navigation);
    }
}
