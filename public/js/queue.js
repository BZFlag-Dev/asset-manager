document.querySelectorAll('.review-action').forEach((el) => {
    el.addEventListener('change', (ev) => {
        // Sanity check to make sure we're operating on the radio input
        if (ev.target.tagName !== 'INPUT' || ev.target.type !== 'radio')
            return;

        const review_details = ev.target.closest('.asset').querySelector('.review-details');

        // Show and require the details if the action is Request Changes or Reject
        review_details.classList.toggle('d-none', !(ev.target.value === 'request' || ev.target.value === 'reject'));
        review_details.querySelector('textarea').required = ev.target.value === 'request' || ev.target.value === 'reject';
    });
});
