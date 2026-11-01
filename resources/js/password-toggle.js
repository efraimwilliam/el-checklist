const t = (text) => (window.__i18n && window.__i18n[text]) || text;
// Setiap kolom password diberi tombol mata: ketuk untuk melihat isinya, ketuk lagi untuk menyembunyikan.

const EYE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="size-5" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
const EYE_OFF = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="size-5" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 5.1A9.8 9.8 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6A17.4 17.4 0 0 0 2 12s3.6 7 10 7a9.7 9.7 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';

document.querySelectorAll('input[type="password"]').forEach((input) => {
    if (input.dataset.passwordToggle) return;
    input.dataset.passwordToggle = 'on';

    const wrap = document.createElement('div');
    wrap.className = 'relative';
    input.before(wrap);
    wrap.append(input);
    input.style.paddingRight = '2.75rem';

    const button = document.createElement('button');
    button.type = 'button';
    button.setAttribute('aria-controls', input.id);
    button.className = 'absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-xl text-slate-500 hover:text-slate-800 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-gold-500';

    const render = (visible) => {
        input.type = visible ? 'text' : 'password';
        button.innerHTML = visible ? EYE_OFF : EYE;
        button.setAttribute('aria-pressed', String(visible));
        button.setAttribute('aria-label', t(visible ? 'Sembunyikan password' : 'Tampilkan password'));
        button.title = t(visible ? 'Sembunyikan password' : 'Tampilkan password');
    };

    button.addEventListener('click', () => {
        render(input.type === 'password');
        input.focus({ preventScroll: true });
    });

    render(false);
    wrap.append(button);
});
