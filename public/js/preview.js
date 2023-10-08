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
