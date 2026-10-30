@props(['rows' => [], 'prefix' => 'param'])
@php($labels = \App\Checklists\ParameterRules::labels())
<div data-param-editor data-rows="{{ json_encode(array_values($rows)) }}" data-templates="{{ json_encode(\App\Tasks\TaskParameters::templates()->map(fn ($t) => $t->only(['name', 'type', 'unit', 'min_value', 'max_value', 'options', 'normal_option']))->values()) }}" id="{{ $prefix }}-editor">
    <div class="mb-3 flex flex-wrap items-end gap-2 rounded-xl bg-slate-50 p-3">
        <div class="min-w-0 flex-1">
            <label for="{{ $prefix }}-template" class="label">{{ __('Pakai jenis isian yang sudah ada') }}</label>
            <select id="{{ $prefix }}-template" class="input" data-param-template-select>
                <option value="">{{ __('— Pilih template —') }}</option>
            </select>
        </div>
        <button type="button" class="btn-secondary !py-2" data-param-pick>{{ __('Tambahkan') }}</button>
        <button type="button" class="btn-secondary !py-2" data-param-add>{{ __('+ Parameter baru') }}</button>
    </div>

    <div class="space-y-3" data-param-rows></div>
    <p class="mt-2 text-xs text-slate-500">{{ __('Setiap jenis isian yang disimpan juga masuk ke template, supaya bisa dipakai lagi di tugas lain. Status tugas dihitung dari semua parameter: ada Pending = Pending; semua terisi dan normal = Done.') }}</p>

    <template data-param-template>
        <div class="grid gap-3 rounded-xl border border-slate-200 bg-white p-3 sm:grid-cols-12" data-param-row>
            <div class="sm:col-span-5">
                <label class="label">{{ __('Nama parameter') }}</label>
                <input name="parameters[__i__][name]" data-field="name" maxlength="120" required class="input" placeholder="{{ __('mis. Voltase accu') }}">
            </div>
            <div class="sm:col-span-4">
                <label class="label">{{ __('Jenis') }}</label>
                <select name="parameters[__i__][type]" data-field="type" class="input">
                    @foreach ($labels as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end justify-end gap-1 sm:col-span-3">
                <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-full text-slate-500 hover:bg-slate-100" data-param-up aria-label="{{ __('Naikkan') }}">↑</button>
                <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-full text-slate-500 hover:bg-slate-100" data-param-down aria-label="{{ __('Turunkan') }}">↓</button>
                <button type="button" class="inline-flex h-9 items-center rounded-full px-3 text-sm font-semibold text-rose-700 hover:bg-rose-50" data-param-remove>{{ __('Hapus') }}</button>
            </div>
            <div class="hidden sm:col-span-3" data-types="number meter">
                <label class="label">{{ __('Satuan') }}</label>
                <input name="parameters[__i__][unit]" data-field="unit" maxlength="30" class="input" placeholder="V, bar, °C">
            </div>
            <div class="hidden sm:col-span-4" data-types="number meter">
                <label class="label">{{ __('Batas bawah') }}</label>
                <input name="parameters[__i__][min_value]" data-field="min_value" type="number" step="any" class="input">
            </div>
            <div class="hidden sm:col-span-5" data-types="number meter">
                <label class="label">{{ __('Batas atas') }}</label>
                <input name="parameters[__i__][max_value]" data-field="max_value" type="number" step="any" class="input">
            </div>
            <div class="hidden sm:col-span-6" data-types="option">
                <label class="label">{{ __('Pilihan (dipisah koma)') }}</label>
                <input name="parameters[__i__][options]" data-field="options" maxlength="500" class="input" placeholder="Full, Low">
            </div>
            <div class="hidden sm:col-span-6" data-types="option">
                <label class="label">{{ __('Nilai normal') }}</label>
                <input name="parameters[__i__][normal_option]" data-field="normal_option" maxlength="100" class="input" placeholder="Full">
            </div>
        </div>
    </template>
</div>
<script>
    if (!window.__paramEditor) {
        window.__paramEditor = true;

        const syncRow = (row) => {
            const type = row.querySelector('[data-field="type"]').value;
            row.querySelectorAll('[data-types]').forEach((box) => box.classList.toggle('hidden', !box.dataset.types.split(' ').includes(type)));
        };

        const addRow = (root, row = {}) => {
            const index = (root.__counter = (root.__counter ?? 0) + 1);
            const node = root.querySelector('template[data-param-template]').content.firstElementChild.cloneNode(true);
            node.querySelectorAll('[name]').forEach((field) => { field.name = field.name.replace('__i__', index); });
            ['name', 'type', 'unit', 'min_value', 'max_value', 'options', 'normal_option'].forEach((key) => {
                const field = node.querySelector(`[data-field="${key}"]`);
                if (field) field.value = row[key] ?? (key === 'type' ? 'status' : '');
            });
            root.querySelector('[data-param-rows]').appendChild(node);
            syncRow(node);
        };

        const setRows = (root, rows) => {
            root.querySelector('[data-param-rows]').innerHTML = '';
            const list = rows.length ? rows : [{ name: 'Status', type: 'status' }];
            list.forEach((row) => addRow(root, row));
        };

        const templatesOf = (root) => JSON.parse(root.dataset.templates || '[]');
        const fillTemplates = (root) => {
            const select = root.querySelector('[data-param-template-select]');
            select.innerHTML = '<option value="">— ' + (window.t ? window.t('Pilih template') : 'Pilih template') + ' —</option>';
            templatesOf(root).forEach((template, index) => {
                const option = document.createElement('option');
                option.value = String(index);
                option.textContent = `${template.name} (${template.type})`;
                select.appendChild(option);
            });
        };

        window.__paramEditorInit = (root) => {
            root.paramEditor = { setRows: (rows) => setRows(root, rows) };
            fillTemplates(root);
            setRows(root, JSON.parse(root.dataset.rows || '[]'));
        };

        document.addEventListener('change', (event) => {
            const field = event.target.closest?.('[data-field="type"]');
            if (field) syncRow(field.closest('[data-param-row]'));
        });

        document.addEventListener('click', (event) => {
            const root = event.target.closest?.('[data-param-editor]');
            if (!root) return;
            const row = event.target.closest('[data-param-row]');
            if (event.target.closest('[data-param-add]')) addRow(root, {});
            else if (event.target.closest('[data-param-pick]')) {
                const index = root.querySelector('[data-param-template-select]').value;
                if (index !== '') addRow(root, templatesOf(root)[Number(index)]);
            } else if (event.target.closest('[data-param-remove]')) row.remove();
            else if (event.target.closest('[data-param-up]') && row.previousElementSibling) row.parentNode.insertBefore(row, row.previousElementSibling);
            else if (event.target.closest('[data-param-down]') && row.nextElementSibling) row.parentNode.insertBefore(row.nextElementSibling, row);
        });
    }
    window.__paramEditorInit(document.getElementById('{{ $prefix }}-editor'));
</script>
