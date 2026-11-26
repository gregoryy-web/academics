document.addEventListener('DOMContentLoaded', () => {
    const busy = document.getElementById('busy');
    const busyText = document.getElementById('busyText');

    document.querySelectorAll('form[data-confirm], form[data-busy]').forEach(form => {
        form.addEventListener('submit', ev => {
            if (form.dataset.confirm && !confirm(form.dataset.confirm)) { ev.preventDefault(); return; }
            if (form.dataset.busy) { busyText.textContent = form.dataset.busy; busy.hidden = false; }
        });
    });

    document.querySelectorAll('.msg.ok').forEach(m => setTimeout(() => m.remove(), 12000));

    const form = document.getElementById('entForm');
    if (!form) return;
    const fields = [...form.querySelectorAll('input[name], select[name]')].filter(i => i.type !== 'hidden');
    const rowId = document.getElementById('rowId');
    const err = document.getElementById('formErr');
    const saveBtn = document.getElementById('saveBtn');
    const cancel = document.getElementById('cancelEdit');
    const addLabel = saveBtn.textContent;
    const title = document.getElementById('formTitle');
    const addTitle = title.textContent;

    const borrow = fields.find(i => i.name === 'borrow_date');
    const due = fields.find(i => i.name === 'due_date');
    const iso = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    const plus7 = v => { const [y, m, d] = v.split('-').map(Number); return iso(new Date(y, m - 1, d + 7)); };
    const syncMin = () => { if (borrow && due && borrow.value) due.min = borrow.value; };
    const setDefaults = () => {
        if (!borrow || !due) return;
        borrow.value = iso(new Date());
        due.value = plus7(borrow.value);
        syncMin();
    };
    setDefaults();
    if (borrow && due) borrow.addEventListener('change', () => {
        if (borrow.value) due.value = plus7(borrow.value);
        syncMin();
    });

    const setInsertOnly = disabled => form.querySelectorAll('label.ins').forEach(l => {
        l.classList.toggle('off', disabled);
        l.querySelector('input, select').disabled = disabled;
    });

    form.addEventListener('submit', ev => {
        const problems = [];
        fields.forEach(i => {
            i.classList.remove('bad');
            if (i.disabled) return;
            const v = i.value.trim();
            const label = i.closest('label').firstChild.textContent.trim();
            if (v === '') { problems.push(label + ' is required.'); i.classList.add('bad'); }
            else if (i.type === 'number' && !/^\d+$/.test(v)) { problems.push(label + ' must be a whole number, 0 or higher.'); i.classList.add('bad'); }
        });
        const d = fields.filter(i => i.type === 'date' && !i.disabled);
        const due = fields.find(i => i.name === 'due_date'), borrow = fields.find(i => i.name === 'borrow_date');
        if (due && borrow && !borrow.disabled && due.value && borrow.value && due.value < borrow.value) {
            problems.push('Due date cannot be before the borrow date.'); due.classList.add('bad');
        }
        err.hidden = !problems.length;
        err.textContent = problems.join(' ');
        if (problems.length) ev.preventDefault();
    });

    document.querySelectorAll('.edit').forEach(btn => btn.addEventListener('click', () => {
        const row = JSON.parse(btn.dataset.row);
        rowId.value = btn.dataset.id;
        fields.forEach(i => { if (row[i.name] !== undefined && row[i.name] !== null) i.value = row[i.name]; });
        syncMin();
        setInsertOnly(true);
        saveBtn.textContent = 'Save changes';
        title.textContent = addTitle.replace('Add ', 'Edit ') + ' #' + btn.dataset.id;
        cancel.hidden = false;
        err.hidden = true;
        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }));

    cancel.addEventListener('click', () => {
        form.reset();
        setDefaults();
        rowId.value = '';
        setInsertOnly(false);
        saveBtn.textContent = addLabel;
        title.textContent = addTitle;
        cancel.hidden = true;
        err.hidden = true;
        fields.forEach(i => i.classList.remove('bad'));
    });
});