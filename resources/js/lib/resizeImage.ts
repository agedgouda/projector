export async function resizeImage(file: File, maxSize = 800): Promise<File> {
    return new Promise((resolve, reject) => {
        const img = new Image();
        const objectUrl = URL.createObjectURL(file);

        img.onload = () => {
            URL.revokeObjectURL(objectUrl);

            const { width, height } = img;

            if (width <= maxSize && height <= maxSize) {
                resolve(file);
                return;
            }

            const ratio = Math.min(maxSize / width, maxSize / height);
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(width * ratio);
            canvas.height = Math.round(height * ratio);

            const ctx = canvas.getContext('2d')!;
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

            canvas.toBlob(
                (blob) => {
                    if (!blob) { reject(new Error('Canvas toBlob failed')); return; }
                    resolve(new File([blob], file.name, { type: file.type }));
                },
                file.type,
            );
        };

        img.onerror = () => { URL.revokeObjectURL(objectUrl); reject(new Error('Image load failed')); };
        img.src = objectUrl;
    });
}
