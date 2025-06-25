<?php

use App\Models\Scan;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Carbon\Carbon; // Ensure Carbon is imported for date manipulation

new class extends Component {

    use WithPagination;

    public bool $showEmailModal = false;
    public string $emailAddress = '';
    public string $dateFrom = '';
    public string $dateTo = '';
    public string $search = '';
    public array $perPageOptions = [1,5,10,25,50,100,250,500];
    public int $perPage = 25;

    public bool $isSendingEmail = false;

    // You can keep rules() for the benefit of wire:model.live validation
    // and for when you want to validate all properties at once, like in emailCsv
    protected function rules()
    {
        return [
            'emailAddress' => 'required|email',
            'dateFrom' => 'nullable|date',
            'dateTo' => 'nullable|date|after_or_equal:dateFrom',
            'search' => 'nullable|string|max:255',
        ];
    }

    public function mount()
    {
        $this->dateFrom = now()->startOfDay()->format('Y-m-d');
        $this->dateTo = now()->endOfDay()->format('Y-m-d');
    }

    public function updated($propertyName)
    {
        if (in_array($propertyName, ['dateFrom', 'dateTo'])) {
            $this->resetPage();
            // Validate dates on update to provide immediate feedback for date range errors
            try {
                $this->validate([
                    'dateFrom' => 'nullable|date',
                    'dateTo' => 'nullable|date|after_or_equal:dateFrom',
                ]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                // Livewire handles showing validation errors automatically.
                // Just let the exception propagate.
            }
        }
    }

    public function with()
    {
        $query = Scan::query();

        if (!empty($this->dateFrom)) {
            $query->where('created_at', '>=', Carbon::parse($this->dateFrom)->startOfDay());
        }

        if (!empty($this->dateTo)) {
            $query->where('created_at', '<=', Carbon::parse($this->dateTo)->endOfDay());
        }

        if(!empty($this->search)) {
            $query->where('barcode', 'like', "%{$this->search}");
        }

        return [
            'scans' => $query->orderBy('created_at', 'desc')->paginate($this->perPage),
            'perPageOptions' => $this->perPageOptions,
        ];
    }

    public function delete($scanId)
    {
        $scan = Scan::find($scanId);
        if ($scan) {
            $scan->delete();
            session()->flash('message', 'Scan deleted successfully.');
        }
        $this->resetPage();
    }

    protected function getFilteredAndAggregatedScans()
    {
        $query = Scan::query();

        if (!empty($this->dateFrom)) {
            $query->where('created_at', '>=', Carbon::parse($this->dateFrom)->startOfDay());
        }

        if (!empty($this->dateTo)) {
            $query->where('created_at', '<=', Carbon::parse($this->dateTo)->endOfDay());
        }

        $scans = $query
            ->selectRaw('barcode, SUM(quantity) as total_quantity, MAX(created_at) as last_scan_date')
            ->groupBy('barcode')
            ->orderBy('last_scan_date', 'desc')
            ->get();

        return $scans;
    }

    protected function generateCsvContent($scans): string
    {
        $csvContent = "Barcode,Total Quantity,Last Scan Date\n";
        foreach ($scans as $scan) {
            $formattedDate = \Carbon\Carbon::parse($scan->last_scan_date)->format('Y-m-d H:i:s');
            $barcode = str_replace('"', '""', $scan->barcode);
            $csvContent .= "\"{$barcode}\",{$scan->total_quantity},\"{$formattedDate}\"\n";
        }
        return $csvContent;
    }

    public function exportCsv(): StreamedResponse
    {
        // Explicitly validate properties and their values for this action
        $this->validate([
            'dateFrom' => $this->rules()['dateFrom'], // Get rule directly from rules()
            'dateTo' => $this->rules()['dateTo'],     // Get rule directly from rules()
        ]);

        $aggregatedScans = $this->getFilteredAndAggregatedScans();
        $csvContent = $this->generateCsvContent($aggregatedScans);
        $filename = 'scans_' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($csvContent) {
            echo $csvContent;
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function openEmailModal()
    {
        // Explicitly validate properties and their values for this action
        $this->validate([
            'dateFrom' => $this->rules()['dateFrom'],
            'dateTo' => $this->rules()['dateTo'],
        ]);

        $this->showEmailModal = true;
    }

    public function closeEmailModal()
    {
        $this->showEmailModal = false;
        $this->reset('emailAddress');
    }

    public function emailCsv()
    {
        $this->isSendingEmail = true;

        try {
            // Explicitly validate all properties for this action
            $this->validate([
                'emailAddress' => $this->rules()['emailAddress'],
                'dateFrom' => $this->rules()['dateFrom'],
                'dateTo' => $this->rules()['dateTo'],
            ]);

            $aggregatedScans = $this->getFilteredAndAggregatedScans();
            $csvContent = $this->generateCsvContent($aggregatedScans);

            $filename = 'scans_' . now()->format('Y-m-d_His') . '.csv';
            $tempFilePath = 'exports/' . $filename;

            Storage::disk('local')->put($tempFilePath, $csvContent);

            Mail::raw('Please find attached the barcode scans export.', function ($message) use ($tempFilePath, $filename) {
                $message->to($this->emailAddress)
                    ->subject('Barcode Scans Export')
                    ->attach(Storage::disk('local')->path($tempFilePath), [
                        'as' => $filename,
                        'mime' => 'text/csv',
                    ]);
            });

            session()->flash('message', 'CSV file has been emailed successfully!');

        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            session()->flash('error', 'Failed to send email: ' . $e->getMessage());
            \Log::error('Email CSV error: ' . $e->getMessage(), ['email' => $this->emailAddress, 'file' => $filename]);
        } finally {
            if (isset($tempFilePath) && Storage::disk('local')->exists($tempFilePath)) {
                Storage::disk('local')->delete($tempFilePath);
            }
            $this->closeEmailModal();
            $this->isSendingEmail = false;
        }
    }
};

?>

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
            <flux:input label="Search" wire:model.live="search"/>
        </div>
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
        <div class="my-4 flex justify-between">
            <div>
                <flux:select wire:model.live="perPage">
                    @foreach($perPageOptions as $option)
                        <flux:select.option>{{$option}}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
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
                    <flux:input label="Email address" wire:model="emailAddress" type="email"/>
                </div>
            </div>

            <div class="px-6 py-4 bg-gray-50 dark:bg-gray-700 text-right">
                <flux:button type="button" wire:click="closeEmailModal">Cancel</flux:button>
                <flux:button type="button" wire:click="emailCsv">Send Email</flux:button>
            </div>
        </div>
    </div>
</div>