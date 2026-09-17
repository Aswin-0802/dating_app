{{--
    Toast region. Rendered once in the layout; everything pushes into the shared
    Alpine store, so Livewire components, plain JS and server flashes all use one
    channel.
--}}

<div
    x-data
    class="pointer-events-none fixed bottom-4 right-4 z-[100] flex w-full max-w-sm flex-col gap-2"
    aria-live="polite"
    aria-atomic="true"
>
    <template x-for="toast in $store.toasts.items" :key="toast.id">
        <div
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0 translate-y-1"
            class="pointer-events-auto flex items-start gap-3 rounded-lg border border-border bg-popover p-3 text-popover-foreground shadow-lg"
            :class="{
                'border-l-2 border-l-success': toast.type === 'success',
                'border-l-2 border-l-destructive': toast.type === 'error',
                'border-l-2 border-l-warning': toast.type === 'warning',
            }"
        >
            <div
                class="mt-px shrink-0"
                :class="{
                    'text-success': toast.type === 'success',
                    'text-destructive': toast.type === 'error',
                    'text-warning': toast.type === 'warning',
                    'text-info': toast.type === 'info',
                }"
            >
                <template x-if="toast.type === 'success'">
                    <x-ui.icon name="check-circle" size="sm" />
                </template>
                <template x-if="toast.type === 'error'">
                    <x-ui.icon name="x-circle" size="sm" />
                </template>
                <template x-if="toast.type === 'warning'">
                    <x-ui.icon name="warning" size="sm" />
                </template>
                <template x-if="toast.type === 'info'">
                    <x-ui.icon name="info" size="sm" />
                </template>
            </div>

            <p class="min-w-0 flex-1 text-sm" x-text="toast.message"></p>

            <button
                type="button"
                @click="$store.toasts.dismiss(toast.id)"
                class="shrink-0 rounded-sm p-0.5 text-muted-foreground transition-colors hover:text-foreground"
            >
                <x-ui.icon name="x-mark" size="xs" />
                <span class="sr-only">Dismiss</span>
            </button>
        </div>
    </template>
</div>
