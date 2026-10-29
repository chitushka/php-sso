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

        self::assertStringContainsString("'aria-label': 'Primary navigation'", $navigation);
        self::assertStringContainsString("'aria-current':", $navigation);
        self::assertStringContainsString("global.Vue.h('nav'", $navigation);
        self::assertStringContainsString("'ArrowDown'", $navigation);
        self::assertStringContainsString("'ArrowUp'", $navigation);
        self::assertStringContainsString("'Home'", $navigation);
        self::assertStringContainsString("'End'", $navigation);
    }

    public function testFormLabelsReferenceControlsOrWrapCheckboxes(): void
    {
        $view = (string)file_get_contents(dirname(__DIR__, 2) . '/views/admin/index.php');
        preg_match_all('/<label\b([^>]*)>(.*?)<\/label>/s', $view, $labels, PREG_SET_ORDER);

        self::assertNotEmpty($labels);
        foreach ($labels as $label) {
            if (preg_match('/\bfor="([^"]+)"/', $label[1], $target) === 1) {
                self::assertStringContainsString('id="' . $target[1] . '"', $view);
                continue;
            }

            self::assertMatchesRegularExpression('/<(?:input|select|textarea)\b/', $label[2]);
        }
    }

    public function testTablesHaveAccessibleNamesAndColumnHeaders(): void
    {
        $view = (string)file_get_contents(dirname(__DIR__, 2) . '/views/admin/index.php');

        self::assertSame(substr_count($view, '<table>'), substr_count($view, '<caption'));
        self::assertDoesNotMatchRegularExpression('/<th\b(?![^>]*\bscope="col")/', $view);
    }

    public function testRequestProgressAndErrorsAreAnnounced(): void
    {
        $view = (string)file_get_contents(dirname(__DIR__, 2) . '/views/admin/index.php');
        $core = (string)file_get_contents(dirname(__DIR__, 2) . '/web/js/admin-core.js');
        $application = (string)file_get_contents(dirname(__DIR__, 2) . '/web/js/admin-app.js');

        self::assertStringContainsString(':aria-busy="isBusy', $view);
        self::assertStringContainsString('class="muted loading-status" role="status"', $view);
        self::assertStringContainsString('class="error-message" role="alert"', $view);
        self::assertStringContainsString('pendingRequests += 1', $core);
        self::assertStringContainsString('pendingRequests - 1', $core);
        self::assertStringContainsString('app.config.errorHandler', $application);
        self::assertStringContainsString(':disabled="isBusy"', $view);
    }

    public function testApplicationIsSplitIntoDomainModules(): void
    {
        $root = dirname(__DIR__, 2);
        $view = (string)file_get_contents($root . '/views/admin/index.php');

        foreach (['core', 'auth', 'oauth', 'access', 'providers', 'operations', 'app'] as $module) {
            self::assertFileExists($root . '/web/js/admin-' . $module . '.js');
            self::assertStringContainsString('/js/admin-' . $module . '.js', $view);
        }

        self::assertStringNotContainsString('Vue.createApp', $view);
        self::assertLessThan(700, substr_count($view, "\n"));
    }

    public function testSensitiveMutationsRequireConfirmation(): void
    {
        $helper = (string)file_get_contents(dirname(__DIR__, 2) . '/web/js/admin-ui.js');
        $modules = implode("\n", array_map(
            static fn(string $file): string => (string)file_get_contents($file),
            glob(dirname(__DIR__, 2) . '/web/js/admin-*.js') ?: []
        ));

        self::assertStringContainsString('global.confirm(message)', $helper);
        foreach ([
            'Disable MFA for this account?',
            'Regenerate recovery codes?',
            'Remove role ',
            'Remove permission ',
            'Unlink the ',
            'Revoke consent for ',
            'Rotate the active signing key?',
            'Delete identity provider ',
        ] as $confirmation) {
            self::assertStringContainsString("confirmAction('" . $confirmation, $modules);
        }
    }
}
