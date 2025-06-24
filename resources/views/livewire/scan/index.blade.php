<?php

use App\Models\Scan;
use Livewire\Volt\Component;

new class extends Component {

    public string $barcode = '';
    public int $quantity = 1;

    protected function rules()
    {
        return [
            'barcode' => 'required|numeric|min_digits:13',
            'quantity' => 'required|numeric|min:1', // Added validation for quantity
        ];
    }

    /**
     * Called once when the component is initially mounted on the page.
     * This is for initial autofocus when the page first loads.
     */
    public function mount()
    {
        // Dispatch an event to tell Alpine.js to focus the barcode input.
        // This makes sure it's focused when the page initially loads.
        $this->dispatch('focus-barcode-input');
    }

    /**
     * Called when a property bound with wire:model updates.
     * We're specifically interested when the 'barcode' property changes.
     */
    public function updated(string $propertyName)
    {
        // Check if the updated property is 'barcode' and it's not empty.
        // This prevents saving on quantity changes or empty barcode input.
        if ($propertyName === 'barcode' && !empty($this->barcode)) {
            $this->save();
        }
    }

    /**
     * Saves the scan to the database and resets the form.
     */
    public function save()
    {
        // Validate the input data against the defined rules.
        $this->validate();

        // Create a new Scan record in the database.
        Scan::create([
            'barcode' => $this->barcode,
            'quantity' => $this->quantity
        ]);

        // Reset the component's public properties, clearing the form fields.
        $this->reset();
        $this->dispatch('play-success-sound');

        // After saving and resetting, dispatch an event to re-focus the barcode input.
        // This ensures the input is ready for the next scan after an AJAX update.
        $this->dispatch('focus-barcode-input');
    }
}; ?>

{{-- The Blade/HTML part of your Volt component --}}
<div>

    <div x-data="{
    init() {
        window.addEventListener('play-success-sound', () => {
            this.$refs.successSound.play();
        });
        window.addEventListener('play-error-sound', () => {
            this.$refs.errorSound.play();
        });
    }
}">
        <audio x-ref="successSound" src="https://images.caecus.net/assets/sounds/success.mp3" preload="auto"></audio>
         <audio x-ref="errorSound" src="https://images.caecus.net/caecus/assets/sounds/error.mp3" preload="auto"></audio>
    </div>

    <flux:fieldset>
        <flux:legend>Scan a Barcode</flux:legend>
        <form wire:submit="save" autocomplete="off">
            <div class="space-y-6">
                <flux:input
                        icon="barcode"
                        label="Barcode"
                        name="barcode"
                        type="text"
                        wire:model.live.debounce.300ms="barcode"
                        x-data="{}" {{-- Alpine.js context for x-ref and event listener --}}
                        x-ref="barcodeInput" {{-- Reference to easily access this input in Alpine --}}
                        wire:loading.attr="disabled" {{-- Disable input while Livewire is busy --}}
                        {{-- Alpine.js listens for our custom event and focuses the input --}}
                        @focus-barcode-input.window="setTimeout(() => $refs.barcodeInput.focus(), 0)"
                />
                {{-- Error message for barcode, good practice for validation feedback --}}
                @error('barcode') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror

                <flux:input
                        label="Quantity"
                        name="quantity"
                        type="numeric"
                        wire:model="quantity"
                        wire:loading.attr="disabled"
                />
                {{-- Error message for quantity --}}
                @error('quantity') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror
            </div>

            <div
                    class="w-full py-2 px-4 border-b-green-700 bg-green-400 rounded-md mt-4 shadow"
                    wire:loading="save"
                    wire:target="save, barcode" {{-- Indicate what triggers this loading state --}}
            >
                <p class="text-lg text-green-900 flex gap-4 align-middle">
                    <flux:icon.loading />
                    Saving Scan...
                </p>
            </div>
        </form>
    </flux:fieldset>
</div>