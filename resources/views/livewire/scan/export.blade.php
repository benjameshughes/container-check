<?php

use App\Models\Scan;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

new class extends Component {

    use WithPagination;

    public bool $showEmailModal = false;
    public string $emailAddress = '';
    public string $dateFrom = '';
    public string $dateTo = '';

    protected function rules()
    {
        return [
            //
        ];
    }

    public function mount()
    {
        // Set default date range to today
        $this->dateFrom = now()->format('Y-m-d');
        $this->dateTo = now()->format('Y-m-d');
    }

    public function with()
    {
        return [
            'scans' => Scan::paginate(10),
        ];
    }

    public function delete($scanId)
    {
        $scan = Scan::find($scanId);
        if ($scan) {
            $scan->delete();
        }
    }

    protected function getScans()
    {
        // Get scans based on date range if provided
        $query = Scan::query();

        if ($this->dateFrom) {
            $query->whereDate('created_at', '>=', $this->dateFrom);
        }

        if ($this->dateTo) {
            $query->whereDate('created_at', '<=', $this->dateTo);
        }

        // Aggregate barcodes and sum quantities
        $scans = $query
            ->selectRaw('barcode, SUM(quantity) as total_quantity, MAX(created_at) as last_scan_date')
            ->groupBy('barcode')
            ->orderBy('last_scan_date', 'desc')
            ->whereDate('created_at', '>=', $this->dateFrom)
            ->whereDate('created_at', '<=', $this->dateTo)
            ->get();

        // Generate CSV content
        $csvContent = "Barcode,Quantity,Last Scan Date\n";

        foreach ($scans as $scan) {
            $csvContent .= "{$scan->barcode},{$scan->total_quantity},{$scan->last_scan_date}\n";
        }

        // Generate a unique filename
        $filename = 'scans_' . now()->format('Y-m-d_His') . '.csv';

        // Store the CSV file temporarily
        Storage::put($filename, $csvContent);

        return $filename;
    }


    public function exportCsv()
    {
        $filename = $this->getScans();

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

        $filename = $this->getScans();

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
    <!-- Export Controls -->
    <div class="mt-6 flex flex-wrap gap-4 items-end">
        <div>
            <flux:input label="From" wire:model="dateFrom" type="date" />
        </div>

        <div>
            <flux:input label="To" wire:model="dateTo" type="date" />
        </div>

        <div class="flex space-x-2">
            <flux:button wire:click="exportCsv()" variant="primary">Download CSV</flux:button>

            <flux:button wire:click="openEmailModal()">Email CSV</flux:button>
        </div>
    </div>

    <!-- Recent Scans Table -->
    <div class="mt-8">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Recent Scans</h2>

        <div class="mt-4 bg-white dark:bg-black overflow-hidden shadow-sm sm:rounded-lg">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-zinc-900">
                <thead class="bg-gray-50 dark:bg-zinc-900">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                        Barcode
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                        Quantity
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                        Time
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                        Actions
                    </th>
                </tr>
                </thead>
                <tbody class="bg-white dark:bg-zinc-700 divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse($scans as $scan)
                    <tr class="hover:bg-zinc-900/10" wire:key="{{$scan->id}}">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $scan->barcode }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $scan->quantity }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $scan->created_at->diffForHumans() }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                            <flux:button wire:click="delete('{{$scan->id}}')" variant="danger">Delete</flux:button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                            No scans found.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="my-4">
            {{$scans->links()}}
        </div>
    </div>

    <!-- Email Modal -->
    @if($showEmailModal)
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50">
            <div class="bg-white dark:bg-gray-800 rounded-lg overflow-hidden shadow-xl transform transition-all sm:max-w-lg sm:w-full">
                <div class="px-6 py-4">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Email CSV Export</h3>

                    <div class="mt-4">
                        <label for="emailAddress" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email
                            Address</label>
                        <input type="email" id="emailAddress" wire:model="emailAddress"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-800 dark:border-gray-700"
                               placeholder="Enter email address">
                        @error('emailAddress') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="px-6 py-4 bg-gray-50 dark:bg-gray-700 text-right">
                    <button wire:click="closeEmailModal"
                            class="inline-flex items-center px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 disabled:opacity-25 transition ease-in-out duration-150">
                        Cancel
                    </button>

                    <button wire:click="emailCsv"
                            class="ml-3 inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 focus:bg-indigo-500 active:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                        Send Email
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
