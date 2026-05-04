@php
    /** @var array $users */
    /** @var int $employee_count */
    /** @var int $matched_users_count */
    $users = $users ?? [];
    $employeeCount = (int) ($employee_count ?? 0);
    $matchedUsersCount = (int) ($matched_users_count ?? 0);
@endphp

@if (empty($users))
    @if ($employeeCount === 0)
        <div class="text-sm text-amber-700 dark:text-amber-300">
            Bu şirkete bağlı çalışan kaydı yok. Önce <code class="text-xs bg-amber-100 dark:bg-amber-900/40 px-1 py-0.5 rounded">employee:sync</code> komutunu çalıştırarak çalışan listesini güncelleyin.
        </div>
    @elseif ($matchedUsersCount === 0)
        <div class="text-sm text-amber-700 dark:text-amber-300">
            Bu şirkette {{ number_format($employeeCount) }} çalışan var ama henüz hiç giriş yapılmamış. Kullanıcılar ilk LDAP girişinde oluşur — sonra bu listede görünür.
        </div>
    @else
        <div class="text-sm text-gray-500">Rol bekleyen kullanıcı yok. Bu şirketin tüm kullanıcılarına rol atanmış görünüyor.</div>
    @endif
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
