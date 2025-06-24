<?php

use App\Models\Scan;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

new class extends Component {

    use WithPagination;

    // Public properties that Livewire can bind to
    public bool $showEmailModal = false;
    public string $emailAddress = '';
    public string $dateFrom = '';
    public string $dateTo = '';

    // Add a property to hold aggregated scans for the CSV, separate from the table's paginated scans
    // This is not strictly necessary as getScans() re-runs when needed, but can clarify intent.
    // public $aggregatedScans = []; // We'll generate this on demand in getScans()

    // Add a loading state for the email button, as email can take time
    public bool $isSendingEmail = false;


    /**
     * Define validation rules for properties.
     */
    protected function rules()
    {
        return [
            'emailAddress' => 'required|email',
            'dateFrom' => 'nullable|date', // Make dates nullable and validate format
            'dateTo' => 'nullable|date|after_or_equal:dateFrom', // 'after_or_equal' is important for date ranges
        ];
    }

    /**
     * Lifecycle hook: Runs once when the component is initially mounted.
     * Sets default date range.
     */
    public function mount()
    {
        // Set default date range to the start and end of today
        $this->dateFrom = now()->startOfDay()->format('Y-m-d');
        $this->dateTo = now()->endOfDay()->format('Y-m-d');
    }

    /**
     * React to changes in public properties.
     * This will automatically update the table when date filters change.
     */
    public function updated($propertyName)
    {
        // When date filters change, reset pagination to the first page.
        // This is crucial, otherwise you might be on page 2 but the new filter has no page 2 results.
        if (in_array($propertyName, ['dateFrom', 'dateTo'])) {
            $this->resetPage();
        }
    }

    /**
     * Compute and return data for the view (e.g., table data).
     * This method is called automatically by Livewire whenever reactive properties change.
     */
    public function with()
    {
        // Start building the query for paginated scans (for the table)
        $query = Scan::query();

        // Apply date filters if they are set
        if (!empty($this->dateFrom)) {
            $query->whereDate('created_at', '>=', $this->dateFrom);
        }

        if (!empty($this->dateTo)) {
            $query->whereDate('created_at', '<=', $this->dateTo);
        }

        // Return the paginated results for the table.
        // orderBy is good here to ensure consistent pagination.
        return [
            'scans' => $query->orderBy('created_at', 'desc')->paginate(10),
        ];
    }

    /**
     * Deletes a specific scan record and refreshes the table.
     */
    public function delete($scanId)
    {
        $scan = Scan::find($scanId);
        if ($scan) {
            $scan->delete();
            session()->flash('message', 'Scan deleted successfully.'); // Add a feedback message
        }

        // We don't need to call $this->with() directly.
        // Livewire will re-run 'with()' automatically due to the model change (if using traits).
        // If not using model observers/traits, you might need to manually refresh: $this->resetPage();
        $this->resetPage(); // Reset pagination to ensure we're on a valid page after delete
    }

    /**
     * Helper method to get the filtered and aggregated scans for export/email.
     * Returns the collection of scans, not a filename.
     */
    protected function getFilteredAndAggregatedScans()
    {
        $query = Scan::query();

        // Apply date filters
        if (!empty($this->dateFrom)) {
            $query->whereDate('created_at', '>=', $this->dateFrom);
        }

        if (!empty($this->dateTo)) {
            $query->whereDate('created_at', '<=', $this->dateTo);
        }

        // Aggregate barcodes and sum quantities
        // Make sure column names match your CSV header and intended output
        $scans = $query
            ->selectRaw('barcode, SUM(quantity) as total_quantity, MAX(created_at) as last_scan_date')
            ->groupBy('barcode')
            ->orderBy('last_scan_date', 'desc') // Order by the last scan date for aggregated data
            ->get();

        return $scans;
    }

    /**
     * Generates CSV content from aggregated scans.
     * @param Collection $scans The collection of aggregated scan data.
     * @return string The CSV content.
     */
    protected function generateCsvContent($scans): string
    {
        // Ensure headers accurately reflect the aggregated data
        $csvContent = "Barcode,Total Quantity,Last Scan Date\n";

        foreach ($scans as $scan) {
            // Format the date for readability in CSV
            $formattedDate = \Carbon\Carbon::parse($scan->last_scan_date)->format('Y-m-d H:i:s');
            $csvContent .= "\"{$scan->barcode}\",{$scan->total_quantity},\"{$formattedDate}\"\n";
        }

        return $csvContent;
    }


    /**
     * Handles the download of the CSV file.
     * This will stream the file directly to the browser without saving to disk first.
     */
    public function exportCsv(): StreamedResponse
    {
        $this->validate(['dateFrom', 'dateTo']); // Validate dates before exporting

        $aggregatedScans = $this->getFilteredAndAggregatedScans();
        $csvContent = $this->generateCsvContent($aggregatedScans);

        $filename = 'scans_' . now()->format('Y-m-d_His') . '.csv';

        // Stream the CSV content directly. This is more efficient and doesn't require temporary file storage.
        return response()->streamDownload(function () use ($csvContent) {
            echo $csvContent;
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }


    /**
     * Opens the email modal.
     */
    public function openEmailModal()
    {
        // Validate dates here too, before opening modal if they're used for content
        $this->validate(['dateFrom', 'dateTo']);
        $this->showEmailModal = true;
    }

    /**
     * Closes the email modal and resets email address.
     */
    public function closeEmailModal()
    {
        $this->showEmailModal = false;
        $this->reset('emailAddress'); // Clear email address on close
    }

    /**
     * Emails the CSV file as an attachment.
     */
    public function emailCsv()
    {
        $this->isSendingEmail = true; // Set loading state

        try {
            // Validate email address and date filters
            $this->validate(); // This will use the rules defined in protected function rules()

            $aggregatedScans = $this->getFilteredAndAggregatedScans();
            $csvContent = $this->generateCsvContent($aggregatedScans);

            $filename = 'scans_' . now()->format('Y-m-d_His') . '.csv';
            $tempFilePath = 'exports/' . $filename; // Use a dedicated temporary public path

            // Store the CSV file temporarily in a publicly accessible storage (for email attachment)
            // Using 'local' disk often maps to storage/app, but Mail::attach needs a path.
            // For attachments, best practice is to put it where Mail::send can read it.
            // If using S3 or similar, this would involve different config.
            // For local, ensure it's in storage/app/exports or storage/app/public/exports
            Storage::disk('local')->put($tempFilePath, $csvContent); // Store in storage/app/exports/

            // Send email with attachment
            Mail::raw('Please find attached the barcode scans export.', function ($message) use ($tempFilePath, $filename) {
                $message->to($this->emailAddress)
                    ->subject('Barcode Scans Export')
                    ->attach(Storage::disk('local')->path($tempFilePath), [ // Get full path from storage disk
                        'as' => $filename,
                        'mime' => 'text/csv',
                    ]);
            });

            session()->flash('message', 'CSV file has been emailed successfully!');

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Re-throw validation exception to be caught by Livewire for displaying errors
            throw $e;
        } catch (\Exception $e) {
            session()->flash('error', 'Failed to send email: ' . $e->getMessage());
            // Log the error for debugging
            \Log::error('Email CSV error: ' . $e->getMessage(), ['email' => $this->emailAddress, 'file' => $filename]);
        } finally {
            // Clean up the temporary file, regardless of success or failure
            if (isset($tempFilePath) && Storage::disk('local')->exists($tempFilePath)) {
                Storage::disk('local')->delete($tempFilePath);
            }
            $this->closeEmailModal();
            $this->isSendingEmail = false; // Reset loading state
        }
    }
}; ?>

<div>
    {{-- Session Message Display --}}
    @if (session()->has('message'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">
            <strong class="font-bold">Success!</strong>
            <span class="block sm:inline">{{ session('message') }}</span>
            <span class="absolute top-0 bottom-0 right-0 px-4 py-3">
                <svg wire:click="$set('message', null)" class="fill-current h-6 w-6 text-green-500" role="button" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><title>Close</title><path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.103l-2.651 3.746a1.2 1.2 0 0 1-1.697-1.697l2.758-3.896L5.65 6.151a1.2 1.2 0 0 1 1.697-1.697L10 8.897l2.651-3.746a1.2 1.2 0 0 1 1.697 1.697l-2.758 3.896 2.758 3.896z"/></svg>
            </span>
        </div>
    @endif
    @if (session()->has('error'))
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
            <strong class="font-bold">Error!</strong>
            <span class="block sm:inline">{{ session('error') }}</span>
            <span class="absolute top-0 bottom-0 right-0 px-4 py-3">
                <svg wire:click="$set('error', null)" class="fill-current h-6 w-6 text-red-500" role="button" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><title>Close</title><path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.103l-2.651 3.746a1.2 1.2 0 0 1-1.697-1.697l2.758-3.896L5.65 6.151a1.2 1.2 0 0 1 1.697-1.697L10 8.897l2.651-3.746a1.2 1.2 0 0 1 1.697 1.697l-2.758 3.896 2.758 3.896z"/></svg>
            </span>
        </div>
    @endif


    <!-- Export Controls -->
    <div class="mt-6 flex flex-wrap gap-4 items-end">
        <div>
            <flux:input label="From" wire:model.live="dateFrom" type="date"/>
            @error('dateFrom') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
        </div>

        <div>
            <flux:input label="To" wire:model.live="dateTo" type="date"/>
            @error('dateTo') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
        </div>

        <div class="flex space-x-2">
            <flux:button wire:click="exportCsv()" variant="primary" wire:loading.attr="disabled" wire:target="exportCsv">
                <flux:icon.loading class="animate-spin" wire:loading wire:target="exportCsv"/>
                Download CSV
            </flux:button>

            <flux:button wire:click="openEmailModal()" wire:loading.attr="disabled" wire:target="openEmailModal">
                <flux:icon.loading class="animate-spin" wire:loading wire:target="openEmailModal"/>
                Email CSV
            </flux:button>
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
                {{-- Add wire:key to the loop for performance and stability with Livewire --}}
                @forelse($scans as $scan)
                    <tr class="hover:bg-zinc-900/10" wire:key="{{$scan->id}}">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $scan->barcode }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $scan->quantity }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ $scan->created_at->diffForHumans() }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                            {{-- wire:confirm for deletion is a great UX improvement --}}
                            <flux:button wire:click="delete('{{$scan->id}}')" wire:confirm="Are you sure you want to delete this scan?" variant="danger">
                                Delete
                            </flux:button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                            No scans found for the selected date range.
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
    {{-- Use x-show and Alpine.js for modal visibility, it's generally smoother --}}
    <div x-data="{ show: @entangle('showEmailModal').live }" x-show="show" class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50">
        <div class="bg-white dark:bg-gray-800 rounded-lg overflow-hidden shadow-xl transform transition-all sm:max-w-lg sm:w-full"
             x-transition:enter="ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
             x-transition:leave="ease-in duration-200"
             x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
             x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">
            <div class="px-6 py-4">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Email CSV Export</h3>

                <div class="mt-4">
                    <label for="emailAddress" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email
                        Address</label>
                    <input type="email" id="emailAddress" wire:model.live="emailAddress" {{-- Use .live for real-time validation --}}
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
                        wire:loading.attr="disabled" wire:target="emailCsv" {{-- Disable and show loading for email button --}}
                        class="ml-3 inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 focus:bg-indigo-500 active:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                    <flux:icon.loading class="animate-spin" wire:loading wire:target="emailCsv"/>
                    Send Email
                </button>
            </div>
        </div>
    </div>
</div>