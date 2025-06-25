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
            'quantity' => 'required|numeric|min:1',
        ];
    }

    /**
     * Called once when the component is initially mounted on the page.
     * This is for initial autofocus when the page first loads.
     */
    public function mount()
    {
        $this->dispatch('focus-barcode-input');
    }

    /**
     * Called when a property bound with wire:model updates.
     * We're specifically interested when the 'barcode' property changes.
     */
    public function updated(string $propertyName)
    {
        if ($propertyName === 'barcode' && !empty($this->barcode)) {
            $this->save();
        }
    }

    /**
     * Saves the scan to the database and resets the form.
     */
    public function save()
    {
        $this->validate();
        Scan::create([
            'barcode' => $this->barcode,
            'quantity' => $this->quantity
        ]);
        $this->reset();
        $this->dispatch('play-success-sound');
        $this->dispatch('focus-barcode-input');
    }
}; ?>

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
                        x-data="{}"
                        x-ref="barcodeInput"
                        wire:loading.attr="disabled"
                        @focus-barcode-input.window="$refs.barcodeInput.focus()"
                        autofocus
                />

                <flux:input
                        label="Quantity"
                        name="quantity"
                        type="numeric"
                        wire:model="quantity"
                        wire:loading.attr="disabled"
                />
            </div>

            <div
                    class="w-full py-2 px-4 border-b-green-700 bg-green-400 rounded-md mt-4 shadow"
                    wire:loading="save"
                    wire:target="save"
            >
                <p class="text-lg text-green-900 flex gap-4 align-middle">
                    <flux:icon.loading/>
                    Saving Scan...
                </p>
            </div>
        </form>
    </flux:fieldset>

    <div class="mt-4">
        <div class="flex mb-4 gap-2 items-center">
            <flux:icon.info class="size-4" />
            <flux:heading color="blue">Helpful Tip</flux:heading>
        </div>
        <flux:text>Before scanning make sure the barcode text box is highlighted. If not select the input. It will display a border to indicated selection.</flux:text>
    </div>
</div>