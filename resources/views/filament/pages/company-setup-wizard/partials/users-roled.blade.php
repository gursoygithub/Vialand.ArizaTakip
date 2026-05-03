@php
    /** @var array $users */
    $users = $users ?? [];
@endphp

@if (empty($users))
    <div class="text-sm text-gray-500">Bu şirketin henüz rol atanmış kullanıcısı yok.</div>
@else
    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-3 py-2 text-left font-semibold">İsim</th>
                    <th class="px-3 py-2 text-left font-semibold">E-posta</th>
                    <th class="px-3 py-2 text-left font-semibold">Rol</th>
                    <th class="px-3 py-2 text-left font-semibold">Ekstra Şirket</th>
                    <th class="px-3 py-2 text-right font-semibold">İşlem</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach ($users as $user)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                        <td class="px-3 py-2 font-medium">{{ $user['name'] }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ $user['email'] }}</td>
                        <td class="px-3 py-2">
                            <span class="inline-block bg-primary-100 dark:bg-primary-900/40 text-primary-800 dark:text-primary-200 px-2 py-0.5 rounded text-xs font-medium">
                                {{ $user['role'] }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-gray-600">{{ $user['extra_companies'] }}</td>
                        <td class="px-3 py-2 text-right">
                            <div class="inline-flex gap-2">
                                {{ ($this->assignRoleAction)(['user_id' => $user['id']]) }}
                                {{ ($this->grantExtraCompanyAction)(['user_id' => $user['id']]) }}
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
