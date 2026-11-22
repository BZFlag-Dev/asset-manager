document.querySelectorAll('.expand').forEach((el) => {
    el.addEventListener('click', (ev) => {
        document.querySelector('tr[data-index="' + ev.target.dataset['index'] + '"]').classList.toggle('d-none');
    });
});
