const { test, expect } = require('@playwright/test');

test.describe.serial('API счетов за месяц', () => {
    test('предпросмотр не изменяет данные и показывает расчётные суммы', async ({ request }) => {
        const firstResponse = await request.get('/api/billing_preview.php?period=2026-09');
        const secondResponse = await request.get('/api/billing_preview.php?period=2026-09');

        expect(firstResponse.status()).toBe(200);
        expect(secondResponse.status()).toBe(200);
        const preview = await firstResponse.json();
        expect(await secondResponse.json()).toEqual(preview);
        expect(preview.period).toBe('2026-09');
        expect(preview.clients).toHaveLength(6);
        expect(preview.total_before).toBe('89571.51');
        expect(preview.total_after).toBe('89571.50');
        expect(preview.clients.find((client) => client.client_id === 1)).toMatchObject({
            before: '2124.10',
            after: '29857.71',
            diff: '27733.61',
        });
    });

    test('применение обновляет счета один раз и затем становится идемпотентным', async ({ request }) => {
        const firstResponse = await request.post('/api/billing_apply.php', {
            form: { period: '2026-09' },
        });
        const secondResponse = await request.post('/api/billing_apply.php', {
            form: { period: '2026-09' },
        });

        expect(firstResponse.status()).toBe(200);
        expect(await firstResponse.json()).toMatchObject({
            period: '2026-09',
            created: 0,
            updated: 6,
            unchanged: 0,
        });
        expect(secondResponse.status()).toBe(200);
        expect(await secondResponse.json()).toMatchObject({
            period: '2026-09',
            created: 0,
            updated: 0,
            unchanged: 6,
        });
    });
});
