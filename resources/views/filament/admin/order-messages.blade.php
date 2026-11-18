@php
    $conversation = $getRecord()->conversation()->first();
    $messages = $conversation === null
        ? collect()
        : $conversation->messages()->with('author:id,name,role')->latest('id')->limit(200)->get()->reverse()->values();
@endphp

{{-- Read only: staff can read an order's chat but do not take part in it. --}}
<x-messages.thread :messages="$messages" :viewer-id="0"
                   :empty="__('The buyer and the seller have not written to each other.')" />
