<x-layouts.admin :title="$title">
    <div class="rounded-lg bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Type</th>
                    <th class="px-4 py-3">Parent</th>
                    <th class="px-4 py-3">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($units as $unit)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $unit->name }}</td>
                        <td class="px-4 py-3">{{ $unit->unit_type }}</td>
                        <td class="px-4 py-3">{{ $unit->parent?->name ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $unit->status }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No organizational units yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $units->links() }}</div>
</x-layouts.admin>
