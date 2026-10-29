const t = (text) => (window.__i18n && window.__i18n[text]) || text;
// Foto bukti dibuka di penampil dalam halaman: ada tombol Tutup, dan bisa ditutup dengan Esc atau klik di luar foto.
const viewer = document.getElementById('photo-viewer');

if (viewer) {
    const image = viewer.querySelector('[data-photo-viewer-image]');

    document.addEventListener('click', (event) => {
        const link = event.target.closest('[data-photo-open]');
        if (!link) return;
        event.preventDefault();
        image.src = link.href;
        image.alt = link.dataset.alt || t('Foto tugas');
        viewer.showModal();
    });

    viewer.querySelector('[data-close-photo]').addEventListener('click', () => viewer.close());
    viewer.addEventListener('click', (event) => {
        if (event.target === viewer) viewer.close();
    });
    viewer.addEventListener('close', () => image.removeAttribute('src'));
}
