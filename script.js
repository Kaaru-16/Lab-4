document.querySelectorAll('.sort-filters select').forEach(select => {
    select.addEventListener('change', () => select.form.requestSubmit());
});

const categoryDialog = document.getElementById('category-dialog');
document.getElementById('open-category').addEventListener('click', () => categoryDialog.showModal());
document.getElementById('cancel-category').addEventListener('click', () => categoryDialog.close());

const deleteDialog = document.getElementById('delete-dialog');
let pendingDeleteForm;

document.querySelectorAll('.delete-task button').forEach(button => {
    button.addEventListener('click', () => {
        pendingDeleteForm = button.form;
        deleteDialog.showModal();
    });
});

document.getElementById('confirm-delete').addEventListener('click', () => {
    deleteDialog.close();
    pendingDeleteForm.requestSubmit();
});
