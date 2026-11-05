const menuButton = document.querySelector('.menu-button');
menuButton.form.addEventListener('submit', async event => {
    event.preventDefault();
    menuButton.disabled = true;
    const data = new FormData(menuButton.form);
    data.set('action', 'menu');
    try {
        const response = await fetch(menuButton.form.action, {
            method: 'POST', body: data, headers: {'Accept': 'application/json'}
        });
        if (!response.ok) throw new Error('Menu update failed');
        const state = await response.json();
        document.body.classList.toggle('sidebar-collapsed', state.collapsed);
        menuButton.setAttribute('aria-expanded', String(!state.collapsed));
        menuButton.removeAttribute('title');
    } catch {
        menuButton.title = 'Unable to update the menu. Please try again.';
    } finally {
        menuButton.disabled = false;
    }
});

const dueInput = document.getElementById('task-due');
if (dueInput) {
    function refreshDueMinimum() {
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Asia/Taipei', year: 'numeric', month: '2-digit',
            day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23'
        }).formatToParts(new Date());
        const time = Object.fromEntries(parts.map(part => [part.type, part.value]));
        dueInput.min = `${time.year}-${time.month}-${time.day}T${time.hour}:${time.minute}`;
    }
    dueInput.addEventListener('focus', refreshDueMinimum);
    dueInput.addEventListener('input', refreshDueMinimum);
    dueInput.addEventListener('change', () => {
        refreshDueMinimum();
        if (dueInput.value && dueInput.validity.valid && document.activeElement === dueInput) {
            document.querySelector('.priority-choices input:checked').focus({preventScroll: true});
        }
    });
    dueInput.form.addEventListener('reset', () => setTimeout(refreshDueMinimum, 0));
    refreshDueMinimum();
}

document.querySelectorAll('.sort-filters select, .completion-form input[type="checkbox"]').forEach(input => {
    input.addEventListener('change', () => input.form.requestSubmit());
});

const deleteOverlay = document.getElementById('delete-modal-overlay');
let activeDeleteTaskId = null;
document.querySelectorAll('[data-delete-task]').forEach(button => {
    button.addEventListener('click', () => {
        activeDeleteTaskId = button.dataset.deleteTask;
        deleteOverlay.hidden = false;
    });
});
document.getElementById('cancel-delete-btn').addEventListener('click', () => {
    activeDeleteTaskId = null;
    deleteOverlay.hidden = true;
});
document.getElementById('confirm-delete-btn').addEventListener('click', () => {
    if (activeDeleteTaskId !== null) {
        document.getElementById('delete-form-' + activeDeleteTaskId).requestSubmit();
    }
});