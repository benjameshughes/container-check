<?php

use App\Models\Scan;
use Livewire\Volt\Component;

new class extends Component {
    public string $barcode = '';
    public int $quantity = 1;

    public function rules()
    {
        return [
            'barcode' => 'required|numeric',
            'quantity' => 'required|numeric|min:1',
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
            'quantity' => $this->quantity // Use the quantity from the input
        ]);

        $this->reset('barcode');
        // Focus back on the barcode input after saving
        $this->dispatch('focus-barcode');
    }
}; ?>

<div>
    <flux:fieldset>
        <flux:legend>Scan a Barcode</flux:legend>
        <div class="space-y-6">
            <flux:input name="Barcode" wire:model.live="barcode" autofocus="true" x-on:focus="$el.select()" x-data x-on:barcode-saved.window="$el.focus()"/>
            <flux:error name="barcode"/>
            <flux:input name="Quantity" type="numeric" wire:model="quantity"/>
        </div>
    </flux:fieldset>
</div>
