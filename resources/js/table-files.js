// DOM APIs keep file names and URLs out of HTML interpolation.
export function fileCell(value) {
    const wrapper = document.createElement('span');
    wrapper.style.cssText = 'display:flex;align-items:center;gap:8px;height:100%;overflow:hidden';
    for (const file of value?.files ?? []) {
        if (typeof file.url !== 'string' || !file.url.trim()) continue;
        let url;
        try { url = new URL(file.url, window.location.origin); } catch { continue; }
        if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) continue;
        const link = document.createElement('a');
        link.href = url.href;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.title = file.name || 'Open attachment';
        link.addEventListener('click', (event) => event.stopPropagation());
        if (file.is_image) {
            const img = document.createElement('img');
            img.src = url.href;
            img.alt = file.name || 'Image';
            img.loading = 'lazy';
            img.referrerPolicy = 'no-referrer';
            img.style.cssText = 'display:block;height:32px;max-width:120px;object-fit:contain;border-radius:4px';
            img.addEventListener('error', () => { link.textContent = `Image unavailable: ${file.name || 'Open image'}`; });
            link.append(img);
        } else {
            link.textContent = file.name || 'Attachment';
        }
        wrapper.append(link);
    }
    return wrapper;
}
