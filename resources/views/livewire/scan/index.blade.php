<?php

use App\Models\Scan;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

new class extends Component {

    public string $barcode = '';
    public int $quantity = 1;

    protected function rules()
    {
        return [
            'barcode' => 'required',
            'quantity' => 'required',
        ];
    }

    public function updatedBarcode()
    {
        if (!empty($this->barcode)) {
            $this->save();
        }
    }

    public function save()
    {
        $this->validate();

        $scan = Scan::create([
            'barcode' => $this->barcode,
            'quantity' => $this->quantity
        ]);

        $this->reset('barcode');
        $this->dispatch('focus-barcode');
    }
}; ?>

<div>
    <flux:fieldset>
        <flux:legend>Scan a Barcode</flux:legend>
        <form wire:submit="save">
            <div class="space-y-6" x-data x-init-="$watch('$wire.barcode', () => $refs.barcodeInput.focus())">
                <flux:input label="Barcode" name="barcode" wire:model.live.debounce.1000="barcode" autofocus x-refs="barcodeInput" wire:loading.attr="disabled"/>
                <flux:input label="Quantity" name="quantity" type="numeric" wire:model="quantity" wire:loading.attr="disabled"/>
            </div>
            <div class="w-full py-2 px-4 border-b-green-700 bg-green-400 rounded-md mt-4 shadow" wire:loading="save">
                <p class="text-lg text-green-900">
                    Saving Scan...
                </p>
            </div>
        </form>
    </flux:fieldset>
</div>
