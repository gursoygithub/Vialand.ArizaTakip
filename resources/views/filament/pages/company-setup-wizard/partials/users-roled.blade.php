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
        <div class="text-sm text-gray-500">Bu şirketin henüz rol atanmış kullanıcısı yok. Aşağıdaki "Rol Atanmamış Kullanıcılar" listesinden rol atayın.</div>
    @endif
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
