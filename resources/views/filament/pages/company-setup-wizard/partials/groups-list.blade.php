@php
    /** @var array $groups */
    $groups = $groups ?? [];
@endphp

@if (empty($groups))
    <div class="text-sm text-gray-500">Bu şirket için henüz grup tanımlı değil. "Yeni Grup Ekle" butonu ile başlayın.</div>
@else
    <div class="space-y-3">
        @foreach ($groups as $group)
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4">
                <div class="flex items-start justify-between gap-4 flex-wrap">
                    <div>
                        <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $group['name'] }}</div>
                        <div class="text-xs text-gray-500 mt-1">
                            <span class="inline-block mr-3">📍 {{ $group['area'] }}</span>
                            <span class="inline-block mr-3">🛠 {{ $group['unit'] }}</span>
                            <span class="inline-block mr-3">👤 {{ $group['supervisor'] }}</span>
                            <span class="inline-block">{{ $group['member_count'] }} üye</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        {{ ($this->editGroupAction)(['group_id' => $group['id']]) }}
                        {{ ($this->addMembersAction)(['group_id' => $group['id']]) }}
                        {{ ($this->deleteGroupAction)(['group_id' => $group['id']]) }}
                    </div>
                </div>

                @if (!empty($group['members']))
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($group['members'] as $member)
                            <div class="inline-flex items-center gap-1 bg-gray-100 dark:bg-gray-700 rounded-full px-3 py-1 text-xs">
                                <span>{{ $member['name'] }}</span>
                                <button
                                    type="button"
                                    wire:click="mountAction('removeMember', { member_id: {{ $member['member_id'] }} })"
                                    class="ml-1 text-red-500 hover:text-red-700"
                                    title="Çıkar"
                                >
                                    ×
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif
