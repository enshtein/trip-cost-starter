const { test, expect } = require('@playwright/test');

test('экран счетов за месяц показывает предпросмотр и применяет его после подтверждения', async ({ page }) => {
    await page.goto('/billing.php');

    await expect(page.locator('.top a[href="billing.php"]')).toHaveClass(/active/);
    await expect(page.locator('#billing-results')).toBeHidden();
    await page.locator('#billing-period').selectOption('2026-09');
    await page.getByRole('button', { name: 'Показать' }).click();

    const table = page.locator('#billing-preview');
    await expect(table.locator('tbody tr')).toHaveCount(6);
    await expect(table).toContainText('ООО «Хрустим»');
    await expect(table.locator('[data-role="total-after"]')).toHaveText('89 571,50');

    page.once('dialog', async (dialog) => {
        expect(dialog.message()).toBe('Применить расчёт счетов за 2026-09?');
        await dialog.accept();
    });
    await page.getByRole('button', { name: 'Применить' }).click();

    await expect(page.locator('#billing-message')).toBeVisible();
    await expect(page.locator('#billing-message')).toHaveText(/Счета за 2026-09: создано \d+, обновлено \d+, без изменений \d+\./);
    await expect(table.locator('[data-role="total-before"]')).toHaveText('89 571,50');
    await expect(table.locator('[data-role="total-after"]')).toHaveText('89 571,50');
});

test('экран исключает рейс без положительного объёма и пересчитывает остальные рейсы октября', async ({ page }) => {
    await page.goto('/billing.php');
    await page.locator('#billing-period').selectOption('2026-10');
    await page.getByRole('button', { name: 'Показать' }).click();

    await expect(page.locator('#billing-exclusions')).toBeVisible();
    await expect(page.locator('#billing-exclusions')).toHaveText('Не пересчитаны рейсы без положительного объёма: НН-1108.');
    await expect(page.locator('#billing-preview tbody tr')).toHaveCount(6);

    page.once('dialog', async (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Применить' }).click();

    await expect(page.locator('#billing-message')).toHaveText('Счета за 2026-10: создано 0, обновлено 6, без изменений 0.');
    await expect(page.locator('#billing-preview [data-role="total-before"]')).toHaveText('454 610,80');
    await expect(page.locator('#billing-preview [data-role="total-after"]')).toHaveText('454 610,80');
});
