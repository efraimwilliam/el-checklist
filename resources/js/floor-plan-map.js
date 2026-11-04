// Peta kamar berbasis denah (hanya zoom): kotak kamar sudah digambar server di atas gambar sebagai tautan.
const STEPS = [1, 1.5, 2, 3, 4];

document.querySelectorAll('[data-plan-map]').forEach((map) => {
    const stage = map.querySelector('[data-stage]');
    let zoom = 1;
    map.querySelectorAll('[data-zoom]').forEach((button) => button.addEventListener('click', () => {
        const index = STEPS.indexOf(zoom);
        if (button.dataset.zoom === 'fit') zoom = 1;
        if (button.dataset.zoom === 'in') zoom = STEPS[Math.min(STEPS.length - 1, index + 1)];
        if (button.dataset.zoom === 'out') zoom = STEPS[Math.max(0, index - 1)];
        stage.style.width = `${zoom * 100}%`;
    }));
});
