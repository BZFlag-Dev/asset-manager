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

        // Show the canvas
        canvas.classList.remove('d-none');

        // Add class to the parent
        canvas.parentNode.classList.add('has-preview');

        // Add any preview events
        addPreviewEvents(canvas);
    });
}

function addPreviewEvents(el) {
    el.addEventListener('click', () => {
        if (el.classList.contains('expanded')) {
            el.classList.remove('expanded');
            // Delay removing the front class because it visually looks better
            setTimeout(() => el.classList.remove('front'), 500);
        } else {
            el.classList.add('expanded', 'front');
        }
    });
}

function updateLicense(ev) {
    // Sanity check to make sure we're operating on the select element
    if (ev.target.tagName !== 'SELECT')
        return;

    // Loop through all the custom license elements in for this file
    ev.target.closest('.upload').querySelectorAll('.custom-license').forEach((el) => {
        el.classList.toggle('d-none', ev.target.value !== 'Other');
    });

    // Also validate the license
    validateLicense(ev);
}

function validateLicense(ev) {
    const upload_container = ev.target.closest('.upload');
    const license_selector = upload_container.querySelector('.license-select');

    // If the license is set to "Other OSI-Approved License", verify that at least name, and then URL or text is provided
    if (license_selector.value === 'Other') {
        if (upload_container.querySelector('.license-name').value.length === 0) {
            license_selector.setCustomValidity('When using another approved license, the license name must be provided.');
            return;
        }
        if (upload_container.querySelector('.license-url').value.length === 0 && upload_container.querySelector('.license-text').value.length === 0) {
            license_selector.setCustomValidity('When using another approved license, the license URL or text must be provided.');
            return;
        }
    }

    // If we got this far, it's valid
    license_selector.setCustomValidity('');
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

function showDialog(title, message) {
    const dialog = new bootstrap.Modal(document.getElementById('dialog'), {})
    const dialog_title = document.getElementById('dialog_title');
    const dialog_message = document.getElementById('dialog_message');

    dialog_title.innerText = title;
    dialog_message.innerHTML = '';
    if (!Array.isArray(message)) {
        message = [message]
    }
    for (let i = 0; i < message.length; ++i) {
        const p = document.createElement('p');
        p.innerText = message[i];
        dialog_message.append(p);
    }
    dialog.show();
}

function fancyJoin(values) {
    if (!Array.isArray(values)) {
        console.error('fancyJoin: Value provided is not an array');
        return '';
    }

    if (values.length === 0)
        return '';
    else if (values.length === 1)
        return values[0];
    else if (values.length === 2)
        return values[0] + " and " + values[1];
    else {
        let return_value = '';
        const last = values.length - 1;
        for (let index = 0; index < values.length; index++) {
            if (index === last)
                return_value += ` and ${values[index]}`;
            else
                return_value += `, ${values[index]}`;
        }
    }

}
