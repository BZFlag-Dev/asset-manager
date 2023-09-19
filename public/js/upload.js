const preview_width = 128;
const preview_height = 128;

function clampImageSize(x, y, maxX, maxY) {
    // Set initial width/height
    let size = {
        width: maxX,
        height: maxY
    };

    // Calculate the image ratios and
    const sourceRatio = x / y;
    const targetRatio = maxX / maxY;

    // Adjust the target dimensions to match the source ratio
    if (sourceRatio > targetRatio) {
        size.height = Math.floor(y / (x / maxX));
    } else if (sourceRatio < targetRatio) {
        size.width = Math.floor(x / (y / maxY));
    }

    return size;
}

function previewImage(canvas, img_src) {
    // Get a 2D context that we can draw onto
    const ctx = canvas.getContext('2d');

    // Create an image element that we can load the blob into to retrieve the dimensions
    const img = new Image;
    // Set the source and add a listener for the load event
    img.src = img_src;
    img.addEventListener('load', (event) => {
        // Now calculate a size that fits the image into our canvas without
        // altering the aspect ratio.
        const size = clampImageSize(img.width, img.height, canvas.width, canvas.height);
        // Draw the image in the top left
        ctx.drawImage(img, 0, 0, size.width, size.height);
        canvas.classList.remove('d-none');
    })
}

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

function updateLicense(ev) {
    // Sanity check to make sure we're operating on the select element
    if (ev.target.tagName !== 'SELECT')
        return;

    // Loop through all the custom license elements in for this file
    ev.target.closest('.upload').querySelectorAll('.custom-license').forEach((el) => {
        // If it's 255, that's a custom license, so show this
        if (ev.target.value === '255')
            el.classList.remove('d-none');
        else
            el.classList.add('d-none');
    });

    // Also validate the license
    validateLicense(ev);
}

function validateLicense(ev) {
    const upload_container = ev.target.closest('.upload');
    const license_selector = upload_container.querySelector('.license-select');

    // If the license is set to "Other OSI-Approved License", verify that at least name, and then URL or text is provided
    if (license_selector.value === '255') {
        if (upload_container.querySelector('.license-name').value.length === 0) {
            license_selector.setCustomValidity('When using another OSI-approved license, the license name must be provided.');
            return;
        }
        if (upload_container.querySelector('.license-url').value.length === 0 && upload_container.querySelector('.license-text').value.length === 0) {
            license_selector.setCustomValidity('When using another OSI-approved license, then license URL or text must be provided.');
            return;
        }
    }

    // If we got this far, it's valid
    license_selector.setCustomValidity('');
}

function removeFile(ev) {
    const upload_container = ev.target.closest('.upload')

    // Subtract the file size from our total and update the totals
    total_bytes -= upload_container.querySelector('input[type=file]').files[0].size;
    total_files--;
    updateTotals();

    // Destroy the container div
    upload_container.parentNode.removeChild(upload_container);
}

const container = document.getElementById('file_list');
const template = document.getElementById('file_template');
const new_files = document.getElementById('new_files');

// Upload limit summary elements and counters
const total_files_span = document.getElementById('total_files');
const total_files_progress = document.getElementById('total_files_progress');
let total_files = 0;
const total_size_span = document.getElementById('total_size');
const total_size_progress = document.getElementById('total_size_progress');
let total_bytes = 0;

function updateTotals() {
    new_files.required = (total_files === 0);
    total_files_span.innerHTML = total_files;
    total_files_progress.style.width = Math.min(100, total_files / max_file_count * 100)+'%';
    total_size_span.innerHTML = bytesToSize(total_bytes);
    total_size_progress.style.width = Math.min(100, total_bytes / max_post_size * 100)+'%';
}

// Custom form validation tooltips
function setInvalidMessage(selector, message) {
    document.querySelectorAll(selector).forEach((el) => {
        el.addEventListener('invalid', function () {
            this.setCustomValidity(message)
        });
        el.addEventListener('input', function () {
            this.setCustomValidity('')
        });
    });
}
setInvalidMessage('#first_name', 'Your first name must be provided.');
setInvalidMessage('#last_name', 'Your last name must be provided.');
setInvalidMessage('#new_files', 'At least one file must be provided.');
setInvalidMessage('#agree_terms', 'All images must comply with the Terms of Service before they may be uploaded.');

new_files.addEventListener('change', function(ev) {
    if (ev.target.tagName === 'INPUT' && ev.target.type === 'file') {
        const fragment = document.createDocumentFragment();
        for (const file of ev.target.files) {
            // Check if the file exceeds the maximum file size
            if (file.size > max_file_size) {
                alert("%FILENAME% exceeds the maximum file size.".replace('%FILENAME%', file.name));
                continue;
            }

            // Clone our template
            const clonedTemplate = template.content.cloneNode(true);

            // TODO: Add 'for' attribute to labels and 'id' attributes to input/textarea

            // Move this file from our multi-selector file input to a hidden single file input
            let dt = new DataTransfer();
            dt.items.add(file);
            clonedTemplate.querySelector('input[type=file]').files = dt.files;

            // Update our file metadata
            clonedTemplate.querySelector('.file_name').innerHTML = file.name;
            clonedTemplate.querySelector('.file_size').innerHTML = bytesToSize(file.size);

            // Update totals
            total_files++;
            total_bytes += file.size;
            updateTotals();

            // Grab our preview canvas and populate it
            const preview = clonedTemplate.querySelector('.file_preview');
            if (file.type.substring(0, 6) === 'image/') {
                previewImage(preview, URL.createObjectURL(file));
                preview.addEventListener('click', () => {
                    if (preview.classList.contains('expanded')) {
                        preview.classList.remove('expanded');
                        // Delay removing the front class because it visually looks better
                        setTimeout(() => preview.classList.remove('front'), 500);
                    } else {
                        preview.classList.add('expanded', 'front');
                    }
                });
            }

            // Attach events
            clonedTemplate.querySelector('.remove-file').addEventListener('click', removeFile);
            clonedTemplate.querySelector('.license-select').addEventListener('change', updateLicense);
            clonedTemplate.querySelector('.license-name').addEventListener('change', validateLicense);
            clonedTemplate.querySelector('.license-url').addEventListener('change', validateLicense);
            clonedTemplate.querySelector('.license-text').addEventListener('change', validateLicense);

            // Add the new file section to our fragment
            fragment.appendChild(clonedTemplate);
        }
        ev.target.value = '';

        // Append the document fragment to the DOM
        container.appendChild(fragment);
    }
});

document.getElementById('uploads').addEventListener('submit', async (ev) => {
    // Prevent the form from submitting normally
    ev.preventDefault();

    // There should already be at least one due to other validation, but just double check
    if (total_files < 1) {
        // TODO: Better error notification
        alert("You must have provide at least one file.");
        return;
    }
    // Verify we don't have too many files
    else if (total_files > max_file_count) {
        // TODO: Better error notification
        alert("Too many files provided.");
        return;
    }

    // Check if we've exceeded the maximum POST size
    if (total_bytes > max_post_size) {
        // TODO: Better error notification
        alert("The provided files exceed maximum total size.")
        return;
    }

    const upload_button = document.getElementById('btn_upload_assets');
    upload_button.disabled = true;
    upload_button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span role="status">Uploading...</span>';

    // Build and send a response
    const data = new FormData(ev.target);
    const response = await fetch(ev.target.action, {
        method: 'POST',
        data
    });

    // If we received a successful response from the server, process it
    if (response.status === 200) {
        const data = await response.json();
        console.log(data);

        if (data.success) {
            // Reset the form
            ev.target.classList.remove('was-validated');
            document.getElementById('agree_terms').checked = false;
            container.innerHTML = '';
            total_files = 0;
            total_bytes = 0;
            updateTotals();
            upload_button.disabled = false;
            upload_button.innerHTML = 'Upload Assets';
            // TODO: Better status notification
            alert('The submission was accepted');
        }
        else {
            upload_button.disabled = false;
            upload_button.innerHTML = 'Upload Assets';
            // TODO: Better status notification
            alert('The submission was rejected');
        }
    }
    // Otherwise show an error
    else {
        // TODO: Better error notification
        alert('Failed to send the request');
    }
});
