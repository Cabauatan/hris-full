<x-mail::layout>
<x-slot:header>
<x-mail::header :url="$frontendUrl">HRIS</x-mail::header>
</x-slot:header>

# {{ $title }}

{{ $bodyText }}

<x-mail::button :url="$actionUrl">Open HRIS</x-mail::button>

For your privacy, sign in to HRIS to view the complete details.

<x-slot:footer>
<x-mail::footer>© {{ date('Y') }} HRIS. All rights reserved.</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
