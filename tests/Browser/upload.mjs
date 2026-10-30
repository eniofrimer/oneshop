export async function chooseUploadFile(page, scope, files) {
    // The visible picker exists after FilePond initializes; the original hidden input can be replaced during that initialization.
    const [chooser] = await Promise.all([
        page.waitForEvent('filechooser'),
        scope.locator('.filepond--label-action').click(),
    ]);
    await chooser.setFiles(files);
}
