<div x-data="confirmModal">
    <x-modal name="confirmation" width="sm" close="cancel()">
        <x-slot:title><span x-text="title">Confirm action</span></x-slot:title>
        <x-slot:icon><i class="fas" :class="iconClass()"></i></x-slot:icon>
        <p x-text="message"></p>
        <p x-show="details" class="surface-panel-alt mt-3 rounded-lg p-3" x-text="details"></p>
        <x-slot:footer>
            <span class="flex-1"></span>
            <x-button variant="secondary" size="sm" @click="cancel()"><span x-text="cancelText">Cancel</span></x-button>
            <x-button x-show="type === 'danger'" variant="danger" size="sm" @click="confirm()"><span x-text="confirmText">Confirm</span></x-button>
            <x-button x-show="type !== 'danger'" size="sm" @click="confirm()"><span x-text="confirmText">Confirm</span></x-button>
        </x-slot:footer>
    </x-modal>
</div>
