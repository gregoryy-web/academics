const dlg = document.getElementById('dlg');
const cols = JSON.parse(dlg.dataset.cols);
const pk = dlg.dataset.pk;

function localDate(offsetDays = 0) {
    const d = new Date();
    d.setDate(d.getDate() + offsetDays);
    const p = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}

function openForm(row) {
    document.getElementById('ttl').textContent = row ? 'Edit record' : 'Add record';
    document.getElementById('action').value = row ? 'update' : 'create';
    document.getElementById('id').value = row ? row[pk] : '';
    cols.forEach(c => document.getElementById('f_' + c).value = row && row[c] !== null ? row[c] : '');

    if (!row) {
        const bd = document.getElementById('f_borrow_date');
        const dd = document.getElementById('f_due_date');
        if (bd) bd.value = localDate(0);
        if (dd) dd.value = localDate(7);
    }
    dlg.showModal();
}

function normDate(s) {
    s = s.trim();
    let y, m, d, x;
    if ((x = s.match(/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/))) {
        [y, m, d] = [+x[1], +x[2], +x[3]];
    } else if ((x = s.match(/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/))) {
        [m, d, y] = +x[1] > 12 ? [+x[2], +x[1], +x[3]] : [+x[1], +x[2], +x[3]];
    } else {
        const t = new Date(s + ' 12:00');
        if (isNaN(t)) return s;
        [y, m, d] = [t.getFullYear(), t.getMonth() + 1, t.getDate()];
    }
    const chk = new Date(y, m - 1, d);
    if (chk.getFullYear() !== y || chk.getMonth() !== m - 1 || chk.getDate() !== d) return s;
    const p = n => String(n).padStart(2, '0');
    return `${y}-${p(m)}-${p(d)}`;
}

function pickDate(c) {
    const txt = document.getElementById('f_' + c), pick = document.getElementById('p_' + c);
    pick.value = /^\d{4}-\d{2}-\d{2}$/.test(txt.value) ? txt.value : '';
    pick.showPicker ? pick.showPicker() : pick.click();
}

document.querySelectorAll('.datewrap input[type=text]').forEach(inp => {
    inp.addEventListener('paste', e => {
        e.preventDefault();
        inp.value = normDate((e.clipboardData || window.clipboardData).getData('text'));
    });
    inp.addEventListener('blur', () => { if (inp.value) inp.value = normDate(inp.value); });
});