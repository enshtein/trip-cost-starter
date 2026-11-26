/**
 * Экранирует значение перед вставкой в HTML через jQuery.
 * @param {*} s
 * @returns {string}
 */
function esc(s) {
    return $('<div>').text(s === null || s === undefined ? '' : String(s)).html();
}

/** @param {*} x @returns {string} */
function money(x) {
    return Number(x).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** @param {*} x @returns {string} */
function num(x) {
    return Number(x).toLocaleString('ru-RU', { maximumFractionDigits: 1 });
}
