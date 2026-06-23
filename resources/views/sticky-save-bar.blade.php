@php
use Cocosmos\FilamentStickySaveBar\Enums\Position;
@endphp

@if($enabled)
<style>
    [x-cloak] { display: none !important; }

    .ssb-bar {
        position: fixed;
        left: var(--ssb-left, 0px);
        right: var(--ssb-right, 0px);
        z-index: 60;
        pointer-events: none;
        transition: opacity 0.25s ease, transform 0.25s ease;
        opacity: 0;
    }
    .ssb-bar--bottom {
        bottom: 0;
        transform: translateY(0.5rem);
    }
    .ssb-bar--top {
        top: var(--ssb-top, 0px);
        transform: translateY(-0.5rem);
    }
    .ssb-bar--visible {
        opacity: 1;
        transform: translateY(0);
        pointer-events: auto;
    }
    .ssb-bar__inner {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 0.625rem 1.5rem;
        background: white;
        box-shadow: 0 -2px 8px 0 rgb(0 0 0 / 0.07), 0 -1px 0 0 rgb(0 0 0 / 0.04);
    }
    .ssb-bar--top .ssb-bar__inner {
        box-shadow: 0 2px 8px 0 rgb(0 0 0 / 0.07), 0 1px 0 0 rgb(0 0 0 / 0.04);
    }
    .dark .ssb-bar__inner {
        background: rgb(17 24 39);
        box-shadow: 0 -2px 8px 0 rgb(0 0 0 / 0.35), 0 -1px 0 0 rgb(255 255 255 / 0.04);
    }
    .ssb-label {
        font-size: 0.8125rem;
        font-weight: 500;
        color: rgb(107 114 128);
        white-space: nowrap;
    }
    .dark .ssb-label {
        color: rgb(156 163 175);
    }
    .ssb-actions {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-shrink: 0;
    }
    .ssb-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 0.5rem;
        padding: 0.4375rem 0.875rem;
        font-size: 0.875rem;
        font-weight: 600;
        line-height: 1.25rem;
        transition: background-color 0.15s, box-shadow 0.15s;
        cursor: pointer;
        border: none;
        outline: none;
        white-space: nowrap;
        font-family: inherit;
    }
    .ssb-btn:focus-visible {
        outline: 2px solid;
        outline-offset: 2px;
    }
    .ssb-btn--primary {
        background-color: var(--primary-600);
        color: white;
        box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.1);
    }
    .ssb-btn--primary:hover {
        background-color: var(--primary-500);
    }
    .ssb-btn--primary:focus-visible {
        outline-color: var(--primary-600);
    }
    .ssb-btn--gray {
        background-color: transparent;
        color: rgb(55 65 81);
        box-shadow: inset 0 0 0 1px rgb(209 213 219);
    }
    .ssb-btn--gray:hover {
        background-color: rgb(249 250 251);
    }
    .ssb-btn--gray:focus-visible {
        outline-color: rgb(107 114 128);
    }
    .dark .ssb-btn--gray {
        color: rgb(209 213 219);
        box-shadow: inset 0 0 0 1px rgb(75 85 99);
    }
    .dark .ssb-btn--gray:hover {
        background-color: rgb(31 41 55);
    }
</style>

<div
    x-data="window.__stickySaveBar('{{ $showOn->value }}', '{{ $position->value }}')"
    x-cloak
    class="ssb-bar ssb-bar--{{ $position->value }}"
    :class="{ 'ssb-bar--visible': visible }"
>
    <div class="ssb-bar__inner">
        <span class="ssb-label">{{ $label }}</span>

        <div class="ssb-actions">
            @if($withDiscard)
            <button type="button" x-on:click="discard()" class="ssb-btn ssb-btn--gray">
                {{ __('sticky-save-bar::sticky-save-bar.discard') }}
            </button>
            @endif

            @if($withCancel)
            <button type="button" x-on:click="cancel()" class="ssb-btn ssb-btn--gray">
                {{ __('sticky-save-bar::sticky-save-bar.cancel') }}
            </button>
            @endif

            @if($withSaveAndClose)
            <button type="button" x-on:click="saveAndClose()" class="ssb-btn ssb-btn--primary">
                {{ __('sticky-save-bar::sticky-save-bar.save_and_close') }}
            </button>
            @endif

            <button type="button" x-on:click="save()" class="ssb-btn ssb-btn--primary">
                {{ __('sticky-save-bar::sticky-save-bar.save') }}
            </button>
        </div>
    </div>
</div>

<script>
window.__stickySaveBar = function (showOn, position) {
    return {
        visible: false,
        isDirty: false,
        buttonsOffscreen: false,

        _form: null,
        _component: null,
        _componentId: null,
        _observer: null,
        _mainCtnObserver: null,
        _dirtyHandler: null,
        _externalHandler: null,
        _submitHandler: null,
        _commitUnsub: null,
        _savePending: false,
        _baseline: null,
        _baselineTimer: null,
        _recomputeTimer: null,
        _userTouched: false,

        init() {
            // Livewire may or may not be ready when Alpine calls init().
            if (window.Livewire) {
                this._setup();
            }

            document.addEventListener('livewire:init', () => {
                if (! this._form) {
                    this._setup();
                }
            });

            document.addEventListener('livewire:navigated', () => {
                this._cleanup();
                this._setup();
            });
        },

        _setup() {
            // The bar is for resource/page content forms only. Filament's
            // "simple" pages — login, register, password reset, email
            // verification — also render a wire:submit form, but the bar must
            // never attach to those (typing your password should not raise an
            // "unsaved changes" save bar).
            if (document.querySelector('.fi-simple-layout, .fi-simple-page')) {
                return;
            }

            this._form = document.querySelector('form[wire\\:submit]');

            if (! this._form) {
                return;
            }

            this._componentId = this._findFormWireId();
            this._component = this._componentId ? Livewire.find(this._componentId) : null;

            // Per-page opt-out via HasStickySaveBarDisabled trait.
            if (this._component) {
                try {
                    if (this._component.stickySaveBarEnabled === false) {
                        return;
                    }
                } catch {
                    // Property does not exist — proceed.
                }
            }

            // Track dirty state by VALUE: any interaction recomputes dirty by
            // diffing the form's current state against the baseline captured on
            // load, so editing a field then reverting it back to its original
            // value clears the bar again.
            //
            // The compared state is the live Livewire form data (see
            // _serializeForm), which covers every Filament field — text, multi-
            // select, slider, color, tags, key-value, file uploads, rich editor.
            // We listen broadly (click/keyup/pointerup, not only input/change)
            // because custom components mutate state on a click or pointer release
            // (toggle, remove-a-tag, delete-a-row, drag a slider) without firing
            // input/change. A trailing recompute lets the entangled data settle
            // for components that update a tick later.
            //
            // We deliberately do NOT scope to this._form.contains(e.target):
            // Filament teleports dropdown/popover controls (multi-select, color,
            // date pickers) outside the <form> element, so a containment check
            // would miss them. Diffing only THIS component's data is what keeps an
            // unrelated form/modal from marking us dirty — its changes aren't in
            // our `data`, so the diff stays equal.
            this._dirtyHandler = () => {
                this._userTouched = true;
                this._recomputeDirty();

                clearTimeout(this._recomputeTimer);
                this._recomputeTimer = setTimeout(() => this._recomputeDirty(), 80);
            };

            ['input', 'change', 'click', 'keyup', 'pointerup'].forEach((evt) => {
                document.addEventListener(evt, this._dirtyHandler);
            });

            // Public hook for custom components whose state changes via Livewire
            // actions (reorder, move up/down, toggle-in-a-list, etc.) instead of
            // a native input/change event. Dispatch a bubbling CustomEvent:
            //   $dispatch('sticky-save-bar:mark-dirty')  — force the bar to show
            //   $dispatch('sticky-save-bar:check')       — recompute from values
            //   $dispatch('sticky-save-bar:reset')       — re-baseline & hide
            this._externalHandler = (e) => {
                if (e.type === 'sticky-save-bar:mark-dirty') {
                    this._userTouched = true;
                    this.isDirty = true;
                    this._updateVisibility();
                } else if (e.type === 'sticky-save-bar:check') {
                    this._userTouched = true;
                    this._recomputeDirty();
                } else if (e.type === 'sticky-save-bar:reset') {
                    this._userTouched = false;
                    this.isDirty = false;
                    this._captureBaseline();
                    this._updateVisibility();
                }
            };

            window.addEventListener('sticky-save-bar:mark-dirty', this._externalHandler);
            window.addEventListener('sticky-save-bar:check', this._externalHandler);
            window.addEventListener('sticky-save-bar:reset', this._externalHandler);

            // Capture the clean baseline. Rich editors and some fields hydrate a
            // tick after load, so re-capture briefly until the user interacts —
            // otherwise late hydration would look like an edit on first compare.
            this._captureBaseline();
            this._baselineTimer = setInterval(() => {
                if (this._userTouched) {
                    clearInterval(this._baselineTimer);
                    this._baselineTimer = null;
                    return;
                }

                this._captureBaseline();
            }, 250);
            setTimeout(() => {
                if (this._baselineTimer) {
                    clearInterval(this._baselineTimer);
                    this._baselineTimer = null;
                }
            }, 2000);

            // Detect form submit so we can clear dirty state after the commit succeeds.
            this._submitHandler = () => {
                this._savePending = true;
            };

            this._form.addEventListener('submit', this._submitHandler);

            if (this._componentId) {
                this._commitUnsub = Livewire.hook('commit', ({ component, succeed }) => {
                    if (component.id !== this._componentId) {
                        return;
                    }

                    if (this._savePending) {
                        succeed(() => {
                            this._savePending = false;
                            this._userTouched = false;
                            this.isDirty = false;
                            // The saved values are the new clean baseline.
                            this._captureBaseline();
                            this._updateVisibility();
                        });
                    } else {
                        // Recompute (value-diff) AFTER the commit applies, to catch
                        // changes that only land server-side — e.g. a file upload
                        // completing. This is safe against the original false-
                        // positive bug precisely because it diffs the actual form
                        // data: a non-mutating commit (a header action, a polled
                        // refresh) leaves the data equal to baseline → not dirty.
                        succeed(() => {
                            if (this._userTouched) {
                                this._recomputeDirty();
                            }
                        });
                    }
                });
            }

            this._setupObserver();
            this._setupMainCtnObserver();
        },

        _setupMainCtnObserver() {
            let mainCtn = document.querySelector('.fi-main-ctn');

            if (! mainCtn) {
                return;
            }

            this._updateBarOffset(mainCtn);

            this._mainCtnObserver = new ResizeObserver(() => {
                this._updateBarOffset(mainCtn);
            });

            this._mainCtnObserver.observe(mainCtn);
        },

        _updateBarOffset(mainCtn) {
            let rect = mainCtn.getBoundingClientRect();
            this.$el.style.setProperty('--ssb-left', rect.left + 'px');
            this.$el.style.setProperty('--ssb-right', (document.documentElement.clientWidth - rect.right) + 'px');
            this.$el.style.setProperty('--ssb-top', rect.top + 'px');
        },

        // Serialize the tracked form's state into a comparable string. Filament
        // entangles its form state into the Livewire `data` property and keeps it
        // live (updated as you type / interact, before any server commit), so this
        // is the single source of truth for EVERY field type — text, multi-select,
        // slider, color, tags, key-value, file uploads, rich editor — without any
        // per-component DOM scraping. Same source at baseline and compare time, so
        // reverting a value diffs back to equal.
        _serializeForm() {
            if (! this._componentId) {
                return '';
            }

            try {
                const component = Livewire.find(this._componentId);
                const data = component ? component.get('data') : null;

                return data == null ? '' : JSON.stringify(data);
            } catch {
                return '';
            }
        },

        _captureBaseline() {
            this._baseline = this._serializeForm();
        },

        _recomputeDirty() {
            // No baseline yet (user typed before hydration settled) — treat as dirty.
            this.isDirty = this._baseline === null
                ? true
                : this._serializeForm() !== this._baseline;

            this._updateVisibility();
        },

        _findFormWireId() {
            const form = document.querySelector('form[wire\\:submit]');

            if (! form) {
                return null;
            }

            // Walk up the DOM to find the nearest Livewire component root (wire:id).
            let el = form.parentElement;

            while (el) {
                const wireId = el.getAttribute('wire:id');

                if (wireId) {
                    return wireId;
                }

                el = el.parentElement;
            }

            return null;
        },

        _setupObserver() {
            // Filament v5 renders the schema actions container with class fi-sc-actions.
            const target =
                document.querySelector('.fi-sc-actions') ||
                document.querySelector('form[wire\\:submit] button[type="submit"]');

            if (! target) {
                // No anchored target — show whenever dirty.
                this.buttonsOffscreen = true;
                this._updateVisibility();
                return;
            }

            // Seed initial state synchronously before the observer fires.
            const rect = target.getBoundingClientRect();
            this.buttonsOffscreen = rect.bottom > window.innerHeight || rect.top < 0;
            this._updateVisibility();

            this._observer = new IntersectionObserver(
                ([entry]) => {
                    this.buttonsOffscreen = ! entry.isIntersecting;
                    this._updateVisibility();
                },
                { threshold: 0.1 }
            );

            this._observer.observe(target);
        },

        _updateVisibility() {
            const shouldShow = showOn === 'always'
                ? this.buttonsOffscreen
                : showOn === 'dirty-always'
                    ? this.isDirty
                    : this.buttonsOffscreen && this.isDirty;

            // Never overlay an open modal.
            const modalOpen = !! document.querySelector('.fi-modal-window');

            this.visible = shouldShow && ! modalOpen;
        },

        _cleanup() {
            this._observer?.disconnect();
            this._observer = null;

            this._mainCtnObserver?.disconnect();
            this._mainCtnObserver = null;

            this._commitUnsub?.();
            this._commitUnsub = null;

            if (this._dirtyHandler) {
                ['input', 'change', 'click', 'keyup', 'pointerup'].forEach((evt) => {
                    document.removeEventListener(evt, this._dirtyHandler);
                });
                this._dirtyHandler = null;
            }

            if (this._recomputeTimer) {
                clearTimeout(this._recomputeTimer);
                this._recomputeTimer = null;
            }

            if (this._externalHandler) {
                window.removeEventListener('sticky-save-bar:mark-dirty', this._externalHandler);
                window.removeEventListener('sticky-save-bar:check', this._externalHandler);
                window.removeEventListener('sticky-save-bar:reset', this._externalHandler);
                this._externalHandler = null;
            }

            if (this._form && this._submitHandler) {
                this._form.removeEventListener('submit', this._submitHandler);
                this._submitHandler = null;
            }

            if (this._baselineTimer) {
                clearInterval(this._baselineTimer);
                this._baselineTimer = null;
            }

            this._form = null;
            this._component = null;
            this._componentId = null;
            this._savePending = false;
            this._baseline = null;
            this._userTouched = false;
            this.visible = false;
            this.isDirty = false;
            this.buttonsOffscreen = false;
        },

        save() {
            this._form?.requestSubmit();
        },

        saveAndClose() {
            if (! this._form) {
                return;
            }

            const componentId = this._componentId;

            const unsub = Livewire.hook('commit', ({ component, succeed }) => {
                if (component.id !== componentId) {
                    return;
                }

                succeed(() => {
                    unsub?.();
                    window.history.back();
                });
            });

            this._form.requestSubmit();
        },

        cancel() {
            window.history.back();
        },

        discard() {
            window.location.reload();
        },
    };
};
</script>
@endif
