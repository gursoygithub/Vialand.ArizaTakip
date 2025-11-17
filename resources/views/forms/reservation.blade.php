@extends('layouts.app')

@section('title', 'Misafir Kayıt Formu')
@section('sidebar-title', 'Misafir Kayıt Formu')

@section('content')
    @if($errors->any)
        @foreach($errors->all() as $error)
            <span class="text-danger">{{$error}}</span>
        @endforeach
    @endif
    <form action="{{route("reservation-form.store")}}" method="POST" class="space-y-5" x-data="{ guests: [{ name: '', surname: '', tc: '' }] }">
        @csrf

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Ad</label>
                <input type="text" name="name" value="{{old("name")}}" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-blue-500 focus:border-blue-500 @error("name") is-invalid @enderror" placeholder="Personel Adı" required>
                @error('name') <div class="text-danger fs-7">{{$message}}</div> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Soyad</label>
                <input type="text" name="surname" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Personel Soyadı" required>
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">Şirket Adı</label>
            <select name="company" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-blue-500 focus:border-blue-500" required>
                <option value="">Bir şirket seçin</option>
                <option value="ABC Teknoloji">ABC Teknoloji</option>
                <option value="XYZ Danışmanlık">XYZ Danışmanlık</option>
                <option value="Global Yazılım">Global Yazılım</option>
                <option value="Diğer">Diğer</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">Email Adresi</label>
            <input type="email" name="email" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-blue-500 focus:border-blue-500 @error("name") is-invalid @enderror" placeholder="Personel Email Adresi" required>
            @error('email') <div class="text-danger fs-7">{{$message}}</div> @enderror
        </div>

{{--        <div>--}}
{{--            <label class="block text-sm font-medium text-gray-700">Telefon Numarası</label>--}}
{{--            <input type="number" name="phone" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-blue-500 focus:border-blue-500 @error("name") is-invalid @enderror" required>--}}
{{--            @error('phone') <div class="">{{$message}}</div> @enderror--}}
{{--        </div>--}}

        <div>
            <label class="block text-sm font-medium text-gray-700">Telefon Numarası</label>
            <input type="text" name="phone" maxlength="10"
                   pattern="0[0-9]{9}"
                   value="{{ old('phone') }}"
                   class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-blue-500 focus:border-blue-500 @error('phone') border-red-500 @enderror"
                   placeholder="05xxxxxxxxx" required>
            @error('phone')
            <div class="text-red-600 font-bold text-sm mt-1">{{ $message }}</div>
            @enderror
        </div>


        <div>
            <label class="block text-sm font-medium text-gray-700">Ziyaret Tarihi</label>
            <input type="date" name="visit_date" class="mt-1 block w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-blue-500 focus:border-blue-500" required>
        </div>

        <div>
            <label class="block text-lg font-semibold text-gray-800 mb-2">Gelecek Misafirler</label>

            <template x-for="(guest, index) in guests" :key="index">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
                    <input type="text" :name="`guests[${index}][name]`" x-model="guest.name" :value="guest.name" placeholder="Misafir Adı"
                           class="border border-gray-300 rounded-lg px-3 py-2 focus:ring-blue-500 focus:border-blue-500" required>
                    <input type="text" :name="`guests[${index}][surname]`" x-model="guest.surname" :value="guest.surname" placeholder="Misafir Soyadı"
                           class="border border-gray-300 rounded-lg px-3 py-2 focus:ring-blue-500 focus:border-blue-500" required>
                    <div class="flex gap-2">
                        <input type="text" :name="`guests[${index}][tc_no]`" x-model="guest.tc_no" :value="guest.tc_no" placeholder="Misafir TC No"
                               class="border border-gray-300 rounded-lg px-3 py-2 w-full focus:ring-blue-500 focus:border-blue-500" maxlength="11" pattern="\d{11}" required>
                        <button type="button" @click="guests.splice(index, 1)"
                                class="text-red-500 hover:text-red-700 transition font-bold">&times;</button>
                    </div>
                </div>
            </template>

            <button type="button" @click="guests.push({ name: '', surname: '', tc_no: '' })"
                    class="bg-blue-100 text-blue-800 px-4 py-2 rounded-md hover:bg-blue-200 transition text-sm">
                + Misafir Ekle
            </button>
        </div>


        <div class="pt-4">
            <button type="submit" class="w-full bg-blue-600 text-white py-2 px-4 rounded-lg hover:bg-blue-700 transition">Kaydet</button>
        </div>
    </form>

    <!-- Alpine.js -->
    <script src="//unpkg.com/alpinejs" defer></script>
@endsection
