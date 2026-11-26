const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
    testDir: './tests/browser',
    timeout: 30_000,
    workers: 1,
    use: {
        baseURL: 'http://127.0.0.1:18080',
        trace: 'retain-on-failure',
    },
    webServer: {
        command: 'APP_DB_FILE="$PWD/data/browser-test.sqlite" php bin/reset-db.php && APP_DB_FILE="$PWD/data/browser-test.sqlite" php -S 127.0.0.1:18080 -t public',
        url: 'http://127.0.0.1:18080',
        reuseExistingServer: false,
        timeout: 30_000,
    },
});
