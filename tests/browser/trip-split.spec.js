const { test, expect } = require('@playwright/test');

test.describe('Карточка рейса: распределение стоимости', () => {
    test('загружает через API объёмные доли и точные итоги', async ({ page }) => {
        await page.goto('/trip.php?id=11');

        const table = page.locator('#trip-split');
        await expect(table.locator('tbody tr')).toHaveCount(2);
        await expect(table).toContainText('ООО «Хрустим»');
        await expect(table).toContainText('2');
        await expect(table).toContainText('120');
        await expect(table).toContainText('8');
        await expect(table).toContainText('80');
        await expect(table).toContainText('39 000,40');
        await expect(table).toContainText('ООО «Родник»');
        await expect(table).toContainText('9 750,10');
        await expect(table.locator('[data-role="clients-total"]')).toHaveText('48 750,50');
        await expect(table.locator('[data-role="trip-cost"]')).toHaveText('48 750,50');
    });

    test('показывает ошибку вместо итогов при отсутствии положительного объёма', async ({ page }) => {
        await page.goto('/trip.php?id=8');

        await expect(page.locator('#trip-split-message')).toContainText('не указан положительный объём');
        await expect(page.locator('#trip-split tbody')).toContainText('Распределение недоступно');
        await expect(page.locator('#trip-split [data-role="clients-total"]')).toHaveText('—');
        await expect(page.locator('#trip-split [data-role="trip-cost"]')).toHaveText('—');
    });
});
