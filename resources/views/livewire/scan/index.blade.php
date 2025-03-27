<?php

use App\Models\Scan;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

new class extends Component {
    use WithPagination;

    public string $barcode = '';
    public int $quantity = 1;
    public bool $showEmailModal = false;
    public string $emailAddress = '';
    public string $dateFrom = '';
    public string $dateTo = '';

    public function rules()
    {
        return [
            'barcode' => 'required',
            'quantity' => 'required|numeric|min:1',
            'emailAddress' => 'sometimes|required|email',
        ];
    }

    public function mount()
    {
        // Set default date range to today
        $this->dateFrom = now()->format('Y-m-d');
        $this->dateTo = now()->format('Y-m-d');
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

    public function getScansProperty()
    {
        return Scan::latest()->take(10)->get();
    }

    public function delete($scanId)
    {
        $scan = Scan::find($scanId);
        if ($scan) {
            $scan->delete();
        }
    }

    public function exportCsv()
    {
        // Get scans based on date range if provided
        $query = Scan::query();

        if ($this->dateFrom) {
            $query->whereDate('created_at', '>=', $this->dateFrom);
        }

        if ($this->dateTo) {
            $query->whereDate('created_at', '<=', $this->dateTo);
        }

        $scans = $query->orderBy('created_at', 'desc')->get();

        // Generate CSV content
        $csvContent = "Barcode,Quantity,Date\n";

        foreach ($scans as $scan) {
            $csvContent .= "{$scan->barcode},{$scan->quantity},{$scan->created_at}\n";
        }

        // Generate a unique filename
        $filename = 'scans_' . now()->format('Y-m-d_His') . '.csv';

        // Store the CSV file temporarily
        Storage::put($filename, $csvContent);

        // Return the download response
        return Storage::download($filename, $filename);
    }

    public function openEmailModal()
    {
        $this->showEmailModal = true;
    }

    public function closeEmailModal()
    {
        $this->showEmailModal = false;
        $this->reset('emailAddress');
    }

    public function emailCsv()
    {
        $this->validate([
            'emailAddress' => 'required|email',
        ]);

        // Get scans based on date range if provided
        $query = Scan::query();

        if ($this->dateFrom) {
            $query->whereDate('created_at', '>=', $this->dateFrom);
        }

        if ($this->dateTo) {
            $query->whereDate('created_at', '<=', $this->dateTo);
        }

        $scans = $query->orderBy('created_at', 'desc')->get();

        // Generate CSV content
        $csvContent = "Barcode,Quantity,Date\n";

        foreach ($scans as $scan) {
            $csvContent .= "{$scan->barcode},{$scan->quantity},{$scan->created_at}\n";
        }

        // Generate a unique filename
        $filename = 'scans_' . now()->format('Y-m-d_His') . '.csv';

        // Store the CSV file temporarily
        Storage::put('public/exports/' . $filename, $csvContent);

        // Send email with attachment
        Mail::send([], [], function ($message) use ($filename) {
            $message->to($this->emailAddress)
                ->subject('Barcode Scans Export')
                ->attach(storage_path('app/public/exports/' . $filename), [
                    'as' => $filename,
                    'mime' => 'text/csv',
                ]);

            $message->setBody('Please find attached the barcode scans export.', 'text/html');
        });

        // Clean up the file
        Storage::delete('public/exports/' . $filename);

        // Close modal and show success message
        $this->closeEmailModal();
        session()->flash('message', 'CSV file has been emailed successfully!');
    }
}; ?>

<div>
    <flux:fieldset>
        <flux:legend>Scan a Barcode</flux:legend>
        <div class="space-y-6">
            <flux:input name="Barcode" wire:model.live="barcode" autofocus="true" x-on:focus="$el.select()" x-data x-on:barcode-saved.window="$el.focus()"/>
            <flux:input name="Quantity" type="numeric" wire:model="quantity"/>
        </div>
    </flux:fieldset>

    <!-- Export Controls -->
    <div class="mt-6 flex flex-wrap gap-4 items-end">
        <div>
            <label for="dateFrom" class="block text-sm font-medium text-gray-700 dark:text-gray-300">From Date</label>
            <input type="date" id="dateFrom" wire:model="dateFrom" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-800 dark:border-gray-700">
        </div>

        <div>
            <label for="dateTo" class="block text-sm font-medium text-gray-700 dark:text-gray-300">To Date</label>
            <input type="date" id="dateTo" wire:model="dateTo" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-800 dark:border-gray-700">
        </div>

        <div class="flex space-x-2">
            <button wire:click="exportCsv" class="inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-white dark:text-gray-800 uppercase tracking-widest hover:bg-gray-700 dark:hover:bg-white focus:bg-gray-700 dark:focus:bg-white active:bg-gray-900 dark:active:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                Download CSV
            </button>

            <button wire:click="openEmailModal" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 focus:bg-indigo-500 active:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                Email CSV
            </button>
        </div>
    </div>

    <!-- Flash Message -->
    @if (session()->has('message'))
        <div class="mt-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">
            <span class="block sm:inline">{{ session('message') }}</span>
        </div>
    @endif

    <!-- Recent Scans Table -->
    <div class="mt-8">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Recent Scans</h2>

        <div class="mt-4 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Barcode</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Quantity</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Time</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                @foreach($this->scans as $scan)
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $scan->barcode }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $scan->quantity }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $scan->created_at->diffForHumans() }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                            <button wire:click="delete({{ $scan->id }})" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">
                                Delete
                            </button>
                        </td>
                    </tr>
                @endforeach

                @if($this->scans->isEmpty())
                    <tr>
                        <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                            No scans found.
                        </td>
                    </tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>

    <!-- Email Modal -->
    @if($showEmailModal)
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50">
            <div class="bg-white dark:bg-gray-800 rounded-lg overflow-hidden shadow-xl transform transition-all sm:max-w-lg sm:w-full">
                <div class="px-6 py-4">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Email CSV Export</h3>

                    <div class="mt-4">
                        <label for="emailAddress" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email Address</label>
                        <input type="email" id="emailAddress" wire:model="emailAddress" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-800 dark:border-gray-700" placeholder="Enter email address">
                        @error('emailAddress') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="px-6 py-4 bg-gray-50 dark:bg-gray-700 text-right">
                    <button wire:click="closeEmailModal" class="inline-flex items-center px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 disabled:opacity-25 transition ease-in-out duration-150">
                        Cancel
                    </button>

                    <button wire:click="emailCsv" class="ml-3 inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 focus:bg-indigo-500 active:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                        Send Email
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
