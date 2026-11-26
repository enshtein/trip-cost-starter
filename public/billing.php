<?php
require __DIR__ . '/../lib/bootstrap.php';

$months = db()->query("SELECT DISTINCT substr(departure_date, 1, 7) FROM trips ORDER BY 1 DESC")->fetchAll(PDO::FETCH_COLUMN);
$period = isset($_GET['period']) ? $_GET['period'] : '';
if (!in_array($period, $months)) {
    $period = $months ? $months[0] : '';
}

page_header('Счета за месяц');
?>
<h1>Счета за месяц</h1>
<p>Проверьте изменения по новому правилу расчёта перед применением.</p>

<form id="billing-form" class="filter">
    <label for="billing-period">Месяц:</label>
    <select id="billing-period" name="period">
        <?php foreach ($months as $month): ?>
            <option value="<?= h($month) ?>"<?= $month === $period ? ' selected' : '' ?>><?= h($month) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Показать</button>
</form>

<div id="billing-message" class="msg" hidden></div>

<div id="billing-results" hidden>
    <table id="billing-preview" class="grid">
        <thead>
        <tr>
            <th>Клиент</th>
            <th class="r">Сумма сейчас, ₽</th>
            <th class="r">По новому правилу, ₽</th>
            <th class="r">Разница, ₽</th>
        </tr>
        </thead>
        <tbody></tbody>
        <tfoot>
        <tr class="total">
            <td>Итого</td>
            <td class="r" data-role="total-before">—</td>
            <td class="r" data-role="total-after">—</td>
            <td class="r" data-role="total-diff">—</td>
        </tr>
        </tfoot>
    </table>
    <p class="actions"><button id="billing-apply" type="button">Применить</button></p>
</div>

<script src="js/billing.js"></script>
<?php page_footer();
