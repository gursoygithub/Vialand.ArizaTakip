@php
    /** @var array $users */
    $users = $users ?? [];
@endphp

@if (empty($users))
    <div class="text-sm text-gray-500">Rol bekleyen kullanıcı yok. Bu şirketin tüm kullanıcılarına rol atanmış görünüyor.</div>
@else
    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-3 py-2 text-left font-semibold">İsim</th>
                    <th class="px-3 py-2 text-left font-semibold">E-posta</th>
                    <th class="px-3 py-2 text-left font-semibold">Unvan</th>
                    <th class="px-3 py-2 text-left font-semibold">Son Senkr.</th>
                    <th class="px-3 py-2 text-right font-semibold">İşlem</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach ($users as $user)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                        <td class="px-3 py-2 font-medium">{{ $user['name'] }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ $user['email'] }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ $user['title'] }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ $user['last_ldap_sync'] }}</td>
                        <td class="px-3 py-2 text-right">
                            {{ ($this->assignRoleAction)(['user_id' => $user['id']]) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
