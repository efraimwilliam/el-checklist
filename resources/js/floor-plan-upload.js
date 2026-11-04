// Unggah denah: PDF atau gambar dibaca di browser, halaman yang dipilih diubah menjadi PNG, lalu hanya gambar itu yang dikirim ke server.
// pdf.js dimuat malas (hanya saat PDF dipilih) supaya halaman lain tidak berat.

const TARGET_EDGE = 2400;
const MAX_BYTES = 7 * 1024 * 1024;

const form = document.querySelector('[data-plan-upload]');

if (form) {
    const fileInput = form.querySelector('[data-plan-file]');
    const pagesWrap = form.querySelector('[data-plan-pages-wrap]');
    const pages = form.querySelector('[data-plan-pages]');
    const previewWrap = form.querySelector('[data-plan-preview-wrap]');
    const preview = form.querySelector('[data-plan-preview]');
    const nameInput = form.querySelector('[data-plan-name]');
    const floorInput = form.querySelector('[data-plan-floor]');
    const submit = form.querySelector('[data-plan-submit]');
    const errorBox = form.querySelector('[data-plan-error]');
    const statusBox = form.querySelector('[data-plan-status]');
    const pageLabel = form.dataset.pdfLabel || 'Halaman';

    let source = null; // { render(canvas, edge) => Promise, count, pdf }
    let selectedPage = 1;

    const showError = (message) => {
        errorBox.textContent = message || '';
        errorBox.classList.toggle('hidden', !message);
    };
    const showStatus = (message) => {
        statusBox.textContent = message || '';
        statusBox.classList.toggle('hidden', !message);
    };

    const drawToCanvas = (canvas, width, height, draw) => {
        canvas.width = Math.round(width);
        canvas.height = Math.round(height);
        const context = canvas.getContext('2d');
        context.fillStyle = '#fff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        return draw(context);
    };

    const loadImage = (file) => new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const image = new Image();
        image.onload = () => resolve({ image, url });
        image.onerror = () => { URL.revokeObjectURL(url); reject(new Error('image')); };
        image.src = url;
    });

    const imageSource = async (file) => {
        const { image, url } = await loadImage(file);
        return {
            count: 1,
            render: async (canvas, edge) => {
                const scale = edge / Math.max(image.width, image.height);
                const ratio = Math.min(1, scale);
                drawToCanvas(canvas, image.width * ratio, image.height * ratio, (context) => context.drawImage(image, 0, 0, canvas.width, canvas.height));
            },
            release: () => URL.revokeObjectURL(url),
        };
    };

    const pdfSource = async (file) => {
        const pdfjs = await import('pdfjs-dist');
        const worker = await import('pdfjs-dist/build/pdf.worker.min.mjs?url');
        pdfjs.GlobalWorkerOptions.workerSrc = worker.default;
        const pdf = await pdfjs.getDocument({ data: await file.arrayBuffer() }).promise;

        return {
            count: pdf.numPages,
            render: async (canvas, edge, pageNumber = selectedPage) => {
                const page = await pdf.getPage(pageNumber);
                const base = page.getViewport({ scale: 1 });
                const viewport = page.getViewport({ scale: edge / Math.max(base.width, base.height) });
                canvas.width = Math.round(viewport.width);
                canvas.height = Math.round(viewport.height);
                const context = canvas.getContext('2d');
                context.fillStyle = '#fff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                await page.render({ canvasContext: context, viewport }).promise;
            },
            release: () => pdf.destroy(),
        };
    };

    const renderPreview = async () => {
        previewWrap.classList.remove('hidden');
        await source.render(preview, 900, selectedPage);
    };

    const buildPagePicker = async () => {
        pages.replaceChildren();
        pagesWrap.classList.toggle('hidden', source.count <= 1);
        if (source.count <= 1) return;
        for (let number = 1; number <= Math.min(source.count, 40); number++) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:border-amber-500 aria-[pressed=true]:border-amber-600 aria-[pressed=true]:bg-amber-50 aria-[pressed=true]:text-amber-900';
            button.textContent = `${pageLabel} ${number}`;
            button.setAttribute('aria-pressed', String(number === selectedPage));
            button.addEventListener('click', async () => {
                selectedPage = number;
                pages.querySelectorAll('button').forEach((other, index) => other.setAttribute('aria-pressed', String(index + 1 === number)));
                await renderPreview();
            });
            pages.append(button);
        }
    };

    fileInput.addEventListener('change', async () => {
        showError('');
        submit.disabled = true;
        source?.release?.();
        source = null;
        selectedPage = 1;
        const file = fileInput.files[0];
        if (!file) return;
        showStatus('…');
        try {
            const isPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
            source = isPdf ? await pdfSource(file) : await imageSource(file);
            if (!nameInput.value) nameInput.value = file.name.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').slice(0, 120);
            await buildPagePicker();
            await renderPreview();
            submit.disabled = false;
            showStatus('');
        } catch (error) {
            showStatus('');
            showError(form.dataset.errorRead || 'Berkas tidak bisa dibaca. Pastikan PDF atau gambar yang valid.');
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!source) return;
        showError('');
        submit.disabled = true;
        showStatus(form.dataset.statusUpload || 'Mengubah dan mengunggah…');
        try {
            const canvas = document.createElement('canvas');
            await source.render(canvas, TARGET_EDGE, selectedPage);
            let blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
            let name = 'denah.png';
            if (blob && blob.size > MAX_BYTES) {
                blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));
                name = 'denah.jpg';
            }
            if (!blob) throw new Error('blob');

            const data = new FormData(form);
            data.set('image', blob, name);
            data.set('name', nameInput.value.trim());
            data.set('floor', floorInput.value.trim());
            const response = await fetch(form.action, { method: 'POST', body: data, headers: { Accept: 'application/json' } });
            const json = await response.json().catch(() => ({}));
            if (!response.ok) {
                const first = json.errors ? Object.values(json.errors)[0][0] : json.message;
                throw new Error(first || 'upload');
            }
            window.location.href = json.redirect;
        } catch (error) {
            showStatus('');
            showError(error.message && error.message !== 'upload' && error.message !== 'blob' ? error.message : 'Denah gagal diunggah. Coba lagi.');
            submit.disabled = false;
        }
    });
}
