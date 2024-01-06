document.querySelectorAll('.upload').forEach((el) => {
    el.querySelector('.license-select').addEventListener('change', updateLicense);
    el.querySelector('.license-name').addEventListener('change', validateLicense);
    el.querySelector('.license-url').addEventListener('change', validateLicense);
    el.querySelector('.license-text').addEventListener('change', validateLicense);
});

const changes_form = document.getElementById('changes');
changes_form.addEventListener('submit', async (ev) => {
    // Prevent the form from submitting normally
    ev.preventDefault();

    // Disable the submit button and add a spinner
    const submit_button = changes_form.querySelector('button[type=submit]');
    submit_button.disabled = true;
    submit_button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> <span role="status">Submitting Changes...</span>';

    // Build and send a response
    const data = new FormData(ev.target);
    const response = await fetch(ev.target.action, {
        method: 'POST',
        body: data
    });

    // If we received a successful response from the server, process it
    if (response.status === 200) {
        const data = await response.json();

        if (data.success) {
            changes_form.parentNode.removeChild(changes_form);
            showDialog('Success', 'All uploads requesting changes have been submitted for review.');
        }
        else {
            // Keep track of any assets that were successful
            let successful_files = [];

            // Check if any assets had errors
            if (data.asset_errors && Object.keys(data.asset_errors).length) {
                document.querySelectorAll('.asset').forEach((el) => {
                    const index = parseInt(el.querySelector('.asset_index').value);

                    // If a file had errors, show those for the file
                    if (data.asset_errors[index]) {
                        const asset_error_container = el.querySelector('.asset-error');
                        const asset_error_list = asset_error_container.querySelector('ul');

                        // Reset the list
                        asset_error_list.innerHTML = '';

                        // Add each error to the list
                        for (let i = 0; i < data.asset_errors[index].length; ++i) {
                            const li = document.createElement('li');
                            li.innerText = data.asset_errors[index][i];
                            asset_error_list.append(li);
                        }

                        // Show the container
                        asset_error_container.classList.toggle('d-none', false);
                    }
                    // Otherwise, this file was added to the queue, so remove it from the form.
                    else {
                        successful_files.push(el.querySelector('.file-name').innerText);
                        el.parentNode.removeChild(el);
                    }
                });
            }

            // If some files were successful, show a dialog indicating which
            if (successful_files.length)
                showDialog('Error', ['Some assets had submissions errors. However, these assets where successful:', ...successful_files]);
        }
    }
    // Otherwise show an error
    else {
        showDialog('Error', 'A server error has occurred.');
    }

    // Re-enable the upload button
    submit_button.disabled = false;
    submit_button.innerHTML = 'Submit Changes';
});
