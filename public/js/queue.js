document.querySelectorAll('.file_preview').forEach((el) => {
    el.addEventListener('click', () => {
        if (el.classList.contains('expanded')) {
            el.classList.remove('expanded');
            // Delay removing the front class because it visually looks better
            setTimeout(() => el.classList.remove('front'), 500);
        } else {
            el.classList.add('expanded', 'front');
        }
    });
});
