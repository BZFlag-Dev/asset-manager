function bytesToSize(bytes) {
    const kilobyte = 1024;
    const megabyte = kilobyte * 1024;
    const gigabyte = megabyte * 1024;
    const terabyte = gigabyte * 1024;

    if (bytes < kilobyte)
        return bytes + ' B';
    else if (bytes < megabyte)
        return (bytes / kilobyte).toFixed(1) + ' KiB';
    else if (bytes < gigabyte)
        return (bytes / megabyte).toFixed(1) + ' MiB';
    else if (bytes < terabyte)
        return (bytes / gigabyte).toFixed(1) + ' GiB';
    else
        return (bytes / terabyte).toFixed(1) + ' TiB';
}

function removeFile(upload_container) {
    // Subtract the file size from our total and update the totals
    total_bytes -= upload_container.querySelector('input[type=file]').files[0].size;
    total_files--;
    updateTotals();

    // Destroy the container div
    upload_container.parentNode.removeChild(upload_container);
}

const container = document.getElementById('file_list');
const template = document.getElementById('file_template');
const upload_form = document.getElementById('uploads');
const new_files = document.getElementById('new_files');
const error_container = document.getElementById('errors');

// Upload limit summary elements and counters
const total_files_span = document.getElementById('total_files');
const total_files_progress = document.getElementById('total_files_progress');
let total_files = 0;
const total_size_span = document.getElementById('total_size');
const total_size_progress = document.getElementById('total_size_progress');
let total_bytes = 0;

function updateTotals() {
    // Set the required status for the new file element in case there are no files selected
    new_files.required = (total_files === 0);

    // Update the display of the total files
    total_files_span.innerHTML = total_files;
    total_files_progress.style.width = Math.min(100, total_files / max_file_count * 100)+'%';
    if (total_files > max_file_count)
        total_files_progress.classList.add('bg-danger');
    else
        total_files_progress.classList.remove('bg-danger');

    // Update the display of the total file size
    total_size_span.innerHTML = bytesToSize(total_bytes);
    total_size_progress.style.width = Math.min(100, total_bytes / max_post_size * 100)+'%';
    if (total_bytes > max_post_size)
        total_size_progress.classList.add('bg-danger');
    else
        total_size_progress.classList.remove('bg-danger');
}

setInvalidMessage('#new_files', 'At least one file must be provided.');
setInvalidMessage('#uploader_email', 'Your email must be provided.');
setInvalidMessage('#agree_terms', 'All images must comply with the Terms of Service before they may be uploaded.');

const sol = document.getElementById('show_other_licenses');
if (sol) {
    sol.addEventListener('change', function(ev) {
        upload_form.classList.toggle('hide-other-licenses', !ev.target.checked);
    });
}

// Used for the form values to group the information together
let asset_index = 0;
new_files.addEventListener('change', function(ev) {
    if (ev.target.tagName === 'INPUT' && ev.target.type === 'file') {
        const fragment = document.createDocumentFragment();
        let errors = []
        for (const file of ev.target.files) {
            // Check if the file exceeds the maximum file size
            if (file.size > max_file_size) {
                errors.push("%FILENAME% exceeds the maximum file size.".replace('%FILENAME%', file.name));
                continue;
            }

            // Validate the filename
            if (!(new RegExp('^[a-zA-Z0-9_\-]+\\.[a-z0-9]+$', 'g')).test(file.name)) {
                errors.push("Filename %FILENAME% contains disallowed characters. Only a-z, A-Z, 0-9, _ and - are allowed, and the file extension must exist, be lowercase, and contain only a-z.");
                continue;
            }

            // TODO: Check for duplicate filename

            // Clone our template
            const clonedTemplate = template.content.cloneNode(true);

            // Move this file from our multi-selector file input to a hidden single file input
            let dt = new DataTransfer();
            dt.items.add(file);
            clonedTemplate.querySelector('input[type=file]').files = dt.files;

            // Update our file metadata
            clonedTemplate.querySelector('.file-name').innerHTML = file.name;
            clonedTemplate.querySelector('.file-size').innerHTML = bytesToSize(file.size);

            // Update totals
            total_files++;
            total_bytes += file.size;
            updateTotals();

            // Grab our preview canvas and populate it
            const preview = clonedTemplate.querySelector('.file-preview');
            if (file.type.substring(0, 6) === 'image/') {
                previewImage(preview, URL.createObjectURL(file));
            }

            // Add the asset index to the label/input/select/textarea and replace %ASSET_INDEX% with the current index
            clonedTemplate.querySelectorAll('label').forEach((el) => {
                el.htmlFor = el.htmlFor + '_' + asset_index;
            });
            clonedTemplate.querySelectorAll('input, select, textarea').forEach((el) => {
                el.id = el.id + '_' + asset_index;
                el.name = el.name.replace('%ASSET_INDEX%', asset_index);
            });
            clonedTemplate.querySelector('.asset_index').value = asset_index;
            asset_index++;

            // Attach events
            clonedTemplate.querySelector('.remove-file').addEventListener('click', (ev) => {
                removeFile(ev.target.closest('.upload'));
            });
            clonedTemplate.querySelector('.license-select').addEventListener('change', updateLicense);
            clonedTemplate.querySelector('.license-name').addEventListener('change', validateLicense);
            clonedTemplate.querySelector('.license-url').addEventListener('change', validateLicense);
            clonedTemplate.querySelector('.license-text').addEventListener('change', validateLicense);

            // Add the new file section to our fragment
            fragment.appendChild(clonedTemplate);
        }

        // Clear the file selector
        ev.target.value = '';

        // Show errors, if any
        if (errors.length > 0)
            showDialog('Error', errors);

        // Append the document fragment to the DOM
        container.appendChild(fragment);
    }
});

upload_form.addEventListener('submit', async (ev) => {
    // Prevent the form from submitting normally
    ev.preventDefault();

    // There should already be at least one due to other validation, but just double check
    if (total_files < 1) {
        showDialog('Error', 'You must have provide at least one file.');
        return;
    }
    // Verify we don't have too many files
    else if (total_files > max_file_count) {
        showDialog('Error', 'Too many files provided.');
        return;
    }

    // Check if we've exceeded the maximum POST size
    if (total_bytes > max_post_size) {
        showDialog('Error', 'The provided files exceed maximum total size.')
        return;
    }

    const upload_button = upload_form.querySelector('button[type=submit]');
    upload_button.disabled = true;
    upload_button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> <span role="status">Uploading...</span>';

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
            // Reset the form
            document.getElementById('agree_terms').checked = false;
            container.innerHTML = '';
            error_container.classList.toggle('d-none', true);
            total_files = 0;
            total_bytes = 0;
            updateTotals();
            showDialog('Success', 'All the files have been submitted to the moderation queue.');
        }
        else {
            // Keep track of any files that were successful
            let successful_files = [];
            // Check if any files had errors
            if (data.file_errors && Object.keys(data.file_errors).length) {
                document.querySelectorAll('.upload').forEach((el) => {
                    const index = parseInt(el.querySelector('.asset_index').value);

                    // If a file had errors, show those for the file
                    if (data.file_errors[index]) {
                        const file_error_container = el.querySelector('.file-error');
                        const file_error_list = file_error_container.querySelector('ul');

                        // Reset the list
                        file_error_list.innerHTML = '';

                        // Add each error to the list
                        for (let i = 0; i < data.file_errors[index].length; ++i) {
                            const li = document.createElement('li');
                            li.innerText = data.file_errors[index][i];
                            file_error_list.append(li);
                        }

                        // Show the container
                        file_error_container.classList.toggle('d-none', false);
                    }
                    // Otherwise, this file was added to the queue, so remove it from the form.
                    else {
                        successful_files.push(el.querySelector('.file-name').innerText);
                        removeFile(el);
                    }
                });

                // Since we had at least one file error, add an error to the general list so the user knows to check
                data.errors.push('There were some files with errors.');
            }

            const error_list = error_container.querySelector('ul');

            // Reset the list
            error_list.innerHTML = '';

            // Add each error to the list
            for (let i = 0; i < data.errors.length; ++i) {
                const li = document.createElement('li');
                li.innerText = data.errors[i];
                error_list.append(li);
            }

            // Show the container
            error_container.classList.toggle('d-none', false);

            // If some files were successful, show a dialog indicating which
            if (successful_files.length)
                showDialog('Error', ['Some files had submissions errors. However, these files where successful:', ...successful_files]);
        }
    }
    // Otherwise show an error
    else {
        showDialog('Error', 'A server error has occurred.');
    }

    // Re-enable the upload button
    upload_button.disabled = false;
    upload_button.innerHTML = 'Upload Assets';
});
