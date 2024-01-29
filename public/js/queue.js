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

const queue_form = document.getElementById('queue');
if (queue_form) {
    queue_form.addEventListener('submit', async (ev) => {
        // Prevent the form from submitting normally
        ev.preventDefault();

        // Disable the submit button and add a spinner
        const submit_button = queue_form.querySelector('button[type=submit]');
        submit_button.disabled = true;
        submit_button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> <span role="status">Submitting Reviews...</span>';

        // Build and send a response
        const data = new FormData(ev.target);
        const response = await fetch(ev.target.action, {
            method: 'POST',
            body: data
        });

        // If we received a successful response from the server, process it
        if (response.status === 200) {
            const data = await response.json();

            let filenames_successful = [];
            let filenames_with_errors = [];

            // Go through the successful file IDs and add their filename to a list and remove it from the form
            for (const id of data.successful_files) {
                const file_container = document.getElementById('file_' + id);
                if (file_container) {
                    filenames_successful.push(file_container.querySelector('.file-name').innerText);
                    file_container.parentNode.removeChild(file_container);
                }
            }

            // Clear any file level errors
            for (const el of document.querySelectorAll('.asset-error')) {
                el.classList.toggle('d-none', true);
                el.querySelector('ul').innerHTML = '';
            }

            //
            for (const [id, errors] of Object.entries(data.file_errors)) {
                const file_container = document.getElementById('file_' + id);
                const div = file_container.querySelector('.asset-error');
                const ul = div.querySelector('ul');
                errors.forEach((error) => {
                    const li = document.createElement('li');
                    li.innerText = error;
                    ul.append(li);
                });
                div.classList.toggle('d-none', false);
                filenames_with_errors.push(file_container.querySelector('.file-name').innerText);
            }

            // Show a dialog of results
            let messages = [];
            if (filenames_successful.length > 0) {
                messages.push(`The following files were successfully processed: ${fancyJoin(filenames_successful)}`)
            }
            if (filenames_with_errors.length > 0) {
                messages.push(`The following files have errors: ${fancyJoin(filenames_with_errors)}`)
            }
            if (messages.length === 0) {
                messages.push("No actions were processed");
            }
            showDialog('Results', messages);
        }
        // Otherwise show an error
        else {
            // TODO: Show better error messages
            showDialog('Error', 'A server error has occurred.');
        }

        // Re-enable the upload button
        submit_button.disabled = false;
        submit_button.innerHTML = 'Submit Changes';
    });
}
